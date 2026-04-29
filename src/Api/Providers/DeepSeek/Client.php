<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Odin\Api\Providers\DeepSeek;

use GuzzleHttp\RequestOptions;
use Hyperf\Odin\Api\Providers\AbstractClient;
use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Api\Response\ChatCompletionResponse;
use Hyperf\Odin\Api\Response\ChatCompletionStreamResponse;
use Hyperf\Odin\Event\AfterChatCompletionsEvent;
use Hyperf\Odin\Event\AfterChatCompletionsStreamEvent;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Utils\EventUtil;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * DeepSeek API client.
 *
 * Supports both standard chat and reasoning (thinking) mode.
 * When thinking mode is enabled, the response will include reasoning_content
 * which represents the model's internal thinking process.
 *
 * Key behaviors for thinking mode:
 * - During tool calling within a single question, reasoning_content is preserved and cached
 * - When continuing a tool calling sequence, cached reasoning_content is restored to messages
 * - When a new question starts, previous reasoning_content is not needed
 *
 * @see https://api-docs.deepseek.com/zh-cn/guides/thinking_mode
 */
class Client extends AbstractClient
{
    protected DeepSeekConfig $deepSeekConfig;

    public function __construct(DeepSeekConfig $config, ?ApiOptions $requestOptions = null, ?LoggerInterface $logger = null)
    {
        $this->deepSeekConfig = $config;
        if (! $requestOptions) {
            $requestOptions = new ApiOptions();
        }
        parent::__construct($config, $requestOptions, $logger);
    }

    /**
     * Chat completions with thinking mode support.
     */
    public function chatCompletions(ChatCompletionRequest $chatRequest): ChatCompletionResponse
    {
        $this->restoreReasoningContentFromCache($chatRequest);

        $chatRequest->validate();
        $options = $chatRequest->createOptions();

        // 将通用 thinking 字段转换为 DeepSeek 格式
        $this->processThinkingConfig($chatRequest, $options);

        $url = $this->buildChatCompletionsUrl();
        $requestId = $this->addRequestIdToOptions($options);

        $this->logRequest('ChatCompletionsRequest', $url, $options, $requestId);

        $startTime = microtime(true);
        try {
            $response = $this->client->post($url, $options);
            $duration = $this->calculateDuration($startTime);
            $chatCompletionResponse = new ChatCompletionResponse($response, $this->logger);

            $this->logResponse('ChatCompletionsResponse', $requestId, $duration, [
                'content' => $chatCompletionResponse->getContent(),
                'response_headers' => $response->getHeaders(),
                'usage' => $chatCompletionResponse->getUsage()?->toArray(),
            ]);

            EventUtil::dispatch(new AfterChatCompletionsEvent($chatRequest, $chatCompletionResponse, $duration));

            $this->cacheReasoningContentFromResponse($chatCompletionResponse);
            return $chatCompletionResponse;
        } catch (Throwable $e) {
            throw $this->convertException($e, $this->createExceptionContext($url, $options, 'completions'));
        }
    }

    /**
     * Chat completions stream with thinking mode support.
     */
    public function chatCompletionsStream(ChatCompletionRequest $chatRequest): ChatCompletionStreamResponse
    {
        $this->restoreReasoningContentFromCache($chatRequest);

        $chatRequest->setStream(true);
        $chatRequest->validate();
        $options = $chatRequest->createOptions();

        // 将通用 thinking 字段转换为 DeepSeek 格式
        $this->processThinkingConfig($chatRequest, $options);

        $url = $this->buildChatCompletionsUrl();
        $requestId = $this->addRequestIdToOptions($options);

        $this->logRequest('ChatCompletionsStreamRequest', $url, $options, $requestId);

        $startTime = microtime(true);
        try {
            $options[RequestOptions::STREAM] = true;
            $options[RequestOptions::TIMEOUT] = $this->requestOptions->getStreamFirstChunkTimeout();

            ['response' => $response, 'duration' => $firstResponseDuration, 'transport' => $transport]
                = $this->sendRawStreamRequest($url, $options, $startTime);

            $iterator = $this->buildSSEIterator($response, $transport);

            $chatCompletionStreamResponse = new ChatCompletionStreamResponse($response, $this->logger, $iterator);
            $chatCompletionStreamResponse->setAfterChatCompletionsStreamEvent(
                new AfterChatCompletionsStreamEvent($chatRequest, $firstResponseDuration)
            );

            $this->logResponse('ChatCompletionsStreamResponse', $requestId, $firstResponseDuration, [
                'first_response_ms' => $firstResponseDuration,
                'response_headers' => $response->getHeaders(),
                'transport' => $transport,
            ]);

            // Add callback to cache reasoning_content after stream completion
            /** @var AfterChatCompletionsStreamEvent $event */
            $event = $chatCompletionStreamResponse->getAfterChatCompletionsStreamEvent();
            $event?->addCallback(function ($event) {
                $this->cacheReasoningContentFromResponse($event->completionResponse);
            });

            return $chatCompletionStreamResponse;
        } catch (Throwable $e) {
            throw $this->convertException($e, $this->createExceptionContext($url, $options, 'stream'));
        }
    }

    /**
     * Build the chat completions API URL.
     */
    protected function buildChatCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/chat/completions';
    }

    /**
     * Build the embeddings API URL.
     */
    protected function buildEmbeddingsUrl(): string
    {
        return $this->getBaseUri() . '/embeddings';
    }

    /**
     * Build the completions API URL.
     */
    protected function buildCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/completions';
    }

    /**
     * Get authentication headers.
     */
    protected function getAuthHeaders(): array
    {
        $headers = [];

        if ($this->deepSeekConfig->getApiKey()) {
            $headers['Authorization'] = 'Bearer ' . $this->deepSeekConfig->getApiKey();
        }

        return $headers;
    }

    /**
     * 将通用 thinking 字段替换为 DeepSeek 原生格式。
     *
     * createOptions() 生成的是 Bedrock 格式（json.thinking.type/budget_tokens），
     * DeepSeek 需要：
     * - thinking.type = enabled/disabled（无 budget_tokens）
     * - reasoning_effort = high/max（顶层字段，由 level 映射）
     */
    private function processThinkingConfig(ChatCompletionRequest $request, array &$options): void
    {
        // 移除 createOptions() 写入的 Bedrock 格式 thinking 字段
        unset($options['json']['thinking']);

        $thinking = $request->getThinking();
        if ($thinking === null) {
            return;
        }

        foreach ($thinking->toDeepSeekFormat() as $key => $value) {
            $options['json'][$key] = $value;
        }
    }

    /**
     * 发请求前：为缺少 reasoning_content 的 AssistantMessage（含工具调用）从缓存恢复思考内容.
     * 以该 assistant 消息中所有 tool_call_id 的组合作为 key，一次 assistant 消息只查一次缓存.
     */
    private function restoreReasoningContentFromCache(ChatCompletionRequest $chatRequest): void
    {
        $messages = $chatRequest->getMessages();
        if (empty($messages)) {
            return;
        }

        foreach ($messages as $message) {
            if (! $message instanceof AssistantMessage
                || ! $message->hasToolCalls()
                || $message->hasReasoningContent()) {
                continue;
            }
            $toolCallIds = array_map(fn ($tc) => $tc->getId(), $message->getToolCalls());
            $assistantKey = ReasoningContentCache::generateAssistantKey($toolCallIds);
            $cached = ReasoningContentCache::get($assistantKey);
            if ($cached !== null) {
                $message->setReasoningContent($cached);
            }
        }
    }

    /**
     * 收到响应后：将含工具调用的响应中的 reasoning_content 缓存，供下一轮请求恢复使用.
     * 以该 assistant 消息中所有 tool_call_id 的组合作为 key，只存一份.
     */
    private function cacheReasoningContentFromResponse(ChatCompletionResponse $response): void
    {
        $choice = $response->getFirstChoice();
        if ($choice === null) {
            return;
        }

        $message = $choice->getMessage();
        if (! $message instanceof AssistantMessage
            || ! $message->hasReasoningContent()
            || ! $message->hasToolCalls()) {
            return;
        }

        $reasoningContent = $message->getReasoningContent();
        if ($reasoningContent === null) {
            return;
        }

        $toolCallIds = array_map(fn ($tc) => $tc->getId(), $message->getToolCalls());
        $assistantKey = ReasoningContentCache::generateAssistantKey($toolCallIds);
        ReasoningContentCache::store($assistantKey, $reasoningContent);
    }
}
