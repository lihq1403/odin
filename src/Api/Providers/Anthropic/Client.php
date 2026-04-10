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

namespace Hyperf\Odin\Api\Providers\Anthropic;

use GuzzleHttp\RequestOptions;
use Hyperf\Odin\Api\Providers\AbstractClient;
use Hyperf\Odin\Api\Providers\Anthropic\Cache\AnthropicCachePointManager;
use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Api\Response\ChatCompletionResponse;
use Hyperf\Odin\Api\Response\ChatCompletionStreamResponse;
use Hyperf\Odin\Event\AfterChatCompletionsEvent;
use Hyperf\Odin\Event\AfterChatCompletionsStreamEvent;
use Hyperf\Odin\Utils\EventUtil;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Anthropic Messages API 客户端.
 *
 * 覆写 chatCompletions 和 chatCompletionsStream，使用 RequestConverter / ResponseConverter /
 * StreamConverter 完成格式转换，不再走 AbstractClient 默认的 OpenAI 兼容路径。
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
class Client extends AbstractClient
{
    protected AnthropicConfig $anthropicConfig;

    public function __construct(AnthropicConfig $config, ?ApiOptions $requestOptions = null, ?LoggerInterface $logger = null)
    {
        $this->anthropicConfig = $config;
        if (! $requestOptions) {
            $requestOptions = new ApiOptions();
        }
        parent::__construct($config, $requestOptions, $logger);
    }

    /**
     * 非流式聊天补全.
     */
    public function chatCompletions(ChatCompletionRequest $chatRequest): ChatCompletionResponse
    {
        $chatRequest->validate();
        $startTime = microtime(true);

        // 配置缓存点（如启用）
        $this->configureCachePointsIfEnabled($chatRequest);

        $url = $this->buildChatCompletionsUrl();

        try {
            // 转换为 Anthropic 请求体
            $anthropicRequest = RequestConverter::convert($chatRequest, $this->anthropicConfig->getAnthropicVersion());

            $options = [
                RequestOptions::JSON => $anthropicRequest,
                RequestOptions::HEADERS => $this->getHeaders(),
            ];

            $requestId = $this->addRequestIdToOptions($options);
            $this->logRequest('AnthropicChatRequest', $url, $options, $requestId);

            $rawResponse = $this->client->post($url, $options);
            $duration = $this->calculateDuration($startTime);

            // 解析响应体并转换为 OpenAI 格式
            $anthropicResponse = json_decode($rawResponse->getBody()->getContents(), true);
            $psr7Response = ResponseConverter::convert($anthropicResponse ?? []);

            $chatCompletionResponse = new ChatCompletionResponse($psr7Response, $this->logger);

            $this->logResponse('AnthropicChatResponse', $requestId, $duration, [
                'content' => $chatCompletionResponse->getFirstChoice()?->getMessage()?->toArray(),
                'usage' => $chatCompletionResponse->getUsage()?->toArray(),
                'response_headers' => $rawResponse->getHeaders(),
            ]);

            EventUtil::dispatch(new AfterChatCompletionsEvent($chatRequest, $chatCompletionResponse, $duration));

            return $chatCompletionResponse;
        } catch (Throwable $e) {
            throw $this->convertException($e, $this->createExceptionContext($url, [], 'completions'));
        }
    }

    /**
     * 流式聊天补全.
     */
    public function chatCompletionsStream(ChatCompletionRequest $chatRequest): ChatCompletionStreamResponse
    {
        $chatRequest->validate();
        $chatRequest->setStream(true);
        $startTime = microtime(true);

        // 配置缓存点（如启用）
        $this->configureCachePointsIfEnabled($chatRequest);

        $url = $this->buildChatCompletionsUrl();

        try {
            // 转换为 Anthropic 请求体
            $anthropicRequest = RequestConverter::convert($chatRequest, $this->anthropicConfig->getAnthropicVersion());

            $options = [
                RequestOptions::JSON => $anthropicRequest,
                RequestOptions::STREAM => true,
                RequestOptions::TIMEOUT => $this->requestOptions->getStreamFirstChunkTimeout(),
                RequestOptions::HEADERS => $this->getHeaders(),
            ];

            $requestId = $this->addRequestIdToOptions($options);
            $this->logRequest('AnthropicChatStreamRequest', $url, $options, $requestId);

            // 使用基类的流式发送方法（自动选择 Swow / OdinSimpleCurl / Guzzle）
            ['response' => $response, 'duration' => $firstResponseDuration, 'transport' => $transport]
                = $this->sendRawStreamRequest($url, $options, $startTime);

            $streamConverter = new StreamConverter($response, $this->logger);

            $chatCompletionStreamResponse = new ChatCompletionStreamResponse(
                logger: $this->logger,
                streamIterator: $streamConverter
            );

            $chatCompletionStreamResponse->setAfterChatCompletionsStreamEvent(
                new AfterChatCompletionsStreamEvent($chatRequest, $firstResponseDuration)
            );

            $this->logResponse('AnthropicChatStreamResponse', $requestId, $firstResponseDuration, [
                'first_response_ms' => $firstResponseDuration,
                'response_headers' => $response->getHeaders(),
                'transport' => $transport,
            ]);

            return $chatCompletionStreamResponse;
        } catch (Throwable $e) {
            throw $this->convertException($e, $this->createExceptionContext($url, [], 'stream'));
        }
    }

    /**
     * 构建 Anthropic Messages API URL.
     */
    protected function buildChatCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/messages';
    }

    /**
     * 构建嵌入 API URL（Anthropic 不支持，仅实现接口要求）.
     */
    protected function buildEmbeddingsUrl(): string
    {
        return $this->getBaseUri() . '/embeddings';
    }

    /**
     * 构建文本补全 API URL（Anthropic 不支持，仅实现接口要求）.
     */
    protected function buildCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/complete';
    }

    /**
     * 获取 Anthropic 认证请求头.
     *
     * Anthropic 使用 x-api-key 替代 Authorization: Bearer，同时需要 anthropic-version 头。
     */
    protected function getAuthHeaders(): array
    {
        $headers = [];

        if ($this->anthropicConfig->getApiKey()) {
            $headers['x-api-key'] = $this->anthropicConfig->getApiKey();
        }

        $headers['anthropic-version'] = $this->anthropicConfig->getAnthropicVersion();

        return $headers;
    }

    /**
     * 若配置启用了自动缓存，对请求配置缓存点.
     */
    private function configureCachePointsIfEnabled(ChatCompletionRequest $chatRequest): void
    {
        if (! $this->anthropicConfig->isAutoCache()) {
            return;
        }

        $cachePointManager = new AnthropicCachePointManager(
            $this->anthropicConfig->getAutoCacheConfig()
        );
        $cachePointManager->configureCachePoints($chatRequest);
    }
}
