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

namespace Hyperf\Odin\Api\Providers\Kimi;

use GuzzleHttp\RequestOptions;
use Hyperf\Odin\Api\Providers\AbstractClient;
use Hyperf\Odin\Api\Providers\DeepSeek\ReasoningContentCache;
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
 * Kimi（月之暗面）API Client.
 *
 * Kimi 的 tool_call_id 必须遵循 function.<function_name>:<tool_call_num> 格式。
 * 由于框架内部会将含冒号的 ID 标准化为 MD5，本 Client 在发送请求前会将序列化后的
 * messages 数组中的 tool_call_id 还原为 Kimi 要求的格式。
 *
 * 同时支持 reasoning_content 多轮保留（与 DeepSeek 相同的缓存机制）。
 *
 * @see https://platform.moonshot.cn/docs/api/
 */
class Client extends AbstractClient
{
    protected KimiConfig $kimiConfig;

    public function __construct(KimiConfig $config, ?ApiOptions $requestOptions = null, ?LoggerInterface $logger = null)
    {
        $this->kimiConfig = $config;
        if (! $requestOptions) {
            $requestOptions = new ApiOptions();
        }
        parent::__construct($config, $requestOptions, $logger);
    }

    /**
     * Chat completions，发送前将 tool_call_id 替换为 Kimi 格式.
     */
    public function chatCompletions(ChatCompletionRequest $chatRequest): ChatCompletionResponse
    {
        $this->restoreReasoningContentFromCache($chatRequest);

        $chatRequest->validate();
        $options = $chatRequest->createOptions();

        // 将序列化后 messages 中的 tool_call_id 替换为 Kimi 要求的格式
        $this->replaceToolCallIds($options[RequestOptions::JSON]['messages']);

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
     * Chat completions stream，发送前将 tool_call_id 替换为 Kimi 格式.
     */
    public function chatCompletionsStream(ChatCompletionRequest $chatRequest): ChatCompletionStreamResponse
    {
        $this->restoreReasoningContentFromCache($chatRequest);

        $chatRequest->setStream(true);
        $chatRequest->validate();
        $options = $chatRequest->createOptions();

        // 将序列化后 messages 中的 tool_call_id 替换为 Kimi 要求的格式
        $this->replaceToolCallIds($options[RequestOptions::JSON]['messages']);

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

            /** @var AfterChatCompletionsStreamEvent $event */
            $event = $chatCompletionStreamResponse->getAfterChatCompletionsStreamEvent();
            $event->addCallback(function ($event) {
                $this->cacheReasoningContentFromResponse($event->completionResponse);
            });

            return $chatCompletionStreamResponse;
        } catch (Throwable $e) {
            throw $this->convertException($e, $this->createExceptionContext($url, $options, 'stream'));
        }
    }

    /**
     * 构建 chat completions API URL.
     */
    protected function buildChatCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/chat/completions';
    }

    /**
     * 构建 embeddings API URL.
     */
    protected function buildEmbeddingsUrl(): string
    {
        return $this->getBaseUri() . '/embeddings';
    }

    /**
     * 构建 completions API URL.
     */
    protected function buildCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/completions';
    }

    /**
     * 获取认证头.
     */
    protected function getAuthHeaders(): array
    {
        $headers = [];

        if ($this->kimiConfig->getApiKey()) {
            $headers['Authorization'] = 'Bearer ' . $this->kimiConfig->getApiKey();
        }

        return $headers;
    }

    /**
     * 将序列化后的 messages 数组中的 tool_call_id 替换为 Kimi 要求的格式.
     *
     * Kimi 要求格式：function.<function_name>:<tool_call_num>
     *
     * 其中 tool_call_num 是**按函数名独立计数**的，每种函数名从 0 开始递增。
     * 例如：function.get_weather:0、function.get_weather:1、function.search:0
     *
     * 算法：按函数名分别维护 counter，顺序扫描 messages：
     * - 遇到 assistant 消息的每个 tool_call，以该函数名的当前计数重建 ID，计数递增，
     *   同时记录 原始ID → Kimi格式ID 的映射；
     * - 遇到 tool 消息，通过映射将 tool_call_id 替换为 Kimi 格式 ID.
     *
     * @param array<int, array<string, mixed>> $messages 序列化后的 messages 数组（引用传递）
     */
    private function replaceToolCallIds(array &$messages): void
    {
        // 按函数名维护各自的递增计数，每种函数名从 0 开始
        $functionCounters = [];
        // 映射：当前存储的 ID（可能是 MD5 或原始格式）→ Kimi 要求的格式 ID
        $idMapping = [];

        foreach ($messages as &$message) {
            $role = $message['role'] ?? '';

            if ($role === 'assistant' && ! empty($message['tool_calls'])) {
                foreach ($message['tool_calls'] as &$toolCall) {
                    $originalId = $toolCall['id'] ?? '';
                    $functionName = $toolCall['function']['name'] ?? 'unknown';

                    $count = $functionCounters[$functionName] ?? 0;
                    $kimiId = 'function.' . $functionName . ':' . $count;
                    $functionCounters[$functionName] = $count + 1;

                    $idMapping[$originalId] = $kimiId;
                    $toolCall['id'] = $kimiId;
                }
                unset($toolCall);
            } elseif ($role === 'tool') {
                $currentId = $message['tool_call_id'] ?? '';
                if (isset($idMapping[$currentId])) {
                    $message['tool_call_id'] = $idMapping[$currentId];
                }
            }
        }
        unset($message);
    }

    /**
     * 发请求前：为缺少 reasoning_content 的 AssistantMessage（含工具调用）从缓存恢复思考内容.
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
            $message->setReasoningContent($cached ?? '');
        }
    }

    /**
     * 收到响应后：将含工具调用的响应中的 reasoning_content 缓存，供下一轮请求恢复使用.
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
