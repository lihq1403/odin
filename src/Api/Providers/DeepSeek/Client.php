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

use Hyperf\Odin\Api\Providers\AbstractClient;
use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Api\Response\ChatCompletionResponse;
use Hyperf\Odin\Api\Response\ChatCompletionStreamResponse;
use Hyperf\Odin\Event\AfterChatCompletionsStreamEvent;
use Hyperf\Odin\Message\AssistantMessage;
use Psr\Log\LoggerInterface;

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
        $response = parent::chatCompletions($chatRequest);
        $this->cacheReasoningContentFromResponse($response);
        return $response;
    }

    /**
     * Chat completions stream with thinking mode support.
     */
    public function chatCompletionsStream(ChatCompletionRequest $chatRequest): ChatCompletionStreamResponse
    {
        $this->restoreReasoningContentFromCache($chatRequest);
        $response = parent::chatCompletionsStream($chatRequest);

        // Add callback to cache reasoning_content after stream completion
        /** @var AfterChatCompletionsStreamEvent $event */
        $event = $response->getAfterChatCompletionsStreamEvent();
        $event?->addCallback(function ($event) {
            $this->cacheReasoningContentFromResponse($event->completionResponse);
        });

        return $response;
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
