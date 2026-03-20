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

namespace Hyperf\Odin\Api\Providers\OpenAI;

use Hyperf\Odin\Api\Providers\AbstractClient;
use Hyperf\Odin\Api\Providers\AwsBedrock\Cache\AwsBedrockCachePointManager;
use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Api\Response\ChatCompletionResponse;
use Hyperf\Odin\Api\Response\ChatCompletionStreamResponse;
use Hyperf\Odin\Event\AfterChatCompletionsStreamEvent;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Utils\ModelUtil;
use Psr\Log\LoggerInterface;

class Client extends AbstractClient
{
    public function __construct(OpenAIConfig $config, ?ApiOptions $requestOptions = null, ?LoggerInterface $logger = null)
    {
        if (! $requestOptions) {
            $requestOptions = new ApiOptions();
        }
        parent::__construct($config, $requestOptions, $logger);
    }

    /**
     * Chat completions with reasoning_details support.
     */
    public function chatCompletions(ChatCompletionRequest $chatRequest): ChatCompletionResponse
    {
        $this->restoreReasoningDetailsFromCache($chatRequest);
        $this->applyClaudeCachePoints($chatRequest);
        $response = parent::chatCompletions($chatRequest);
        $this->cacheReasoningDetailsFromResponse($response);
        return $response;
    }

    /**
     * Chat completions stream with reasoning_details support.
     */
    public function chatCompletionsStream(ChatCompletionRequest $chatRequest): ChatCompletionStreamResponse
    {
        $this->restoreReasoningDetailsFromCache($chatRequest);
        $this->applyClaudeCachePoints($chatRequest);
        $response = parent::chatCompletionsStream($chatRequest);

        /** @var AfterChatCompletionsStreamEvent $event */
        $event = $response->getAfterChatCompletionsStreamEvent();
        $event?->addCallback(function ($event) {
            $this->cacheReasoningDetailsFromResponse($event->completionResponse);
        });

        return $response;
    }

    /**
     * 构建聊天补全API的URL.
     */
    protected function buildChatCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/chat/completions';
    }

    /**
     * 构建嵌入API的URL.
     */
    protected function buildEmbeddingsUrl(): string
    {
        return $this->getBaseUri() . '/embeddings';
    }

    /**
     * 构建文本补全API的URL.
     */
    protected function buildCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/completions';
    }

    /**
     * 获取认证头信息.
     */
    protected function getAuthHeaders(): array
    {
        $headers = [];
        /** @var OpenAIConfig $config */
        $config = $this->config;

        if ($config->getApiKey()) {
            $headers['Authorization'] = 'Bearer ' . $config->getApiKey();
        }

        if ($config->getOrganization()) {
            $headers['OpenAI-Organization'] = $config->getOrganization();
        }

        return $headers;
    }

    /**
     * 对 Claude 模型应用缓存点逻辑.
     * 仅当 OpenAIConfig 开启了 autoCache 且当前模型为 Claude 系列时执行.
     * 执行后，messages 上带有 CachePoint 的消息在序列化（toArray）时会自动输出 cache_control 字段.
     */
    private function applyClaudeCachePoints(ChatCompletionRequest $chatRequest): void
    {
        /** @var OpenAIConfig $config */
        $config = $this->config;
        if (! $config->isAutoCache()) {
            return;
        }
        if (! ModelUtil::isClaudeModel($chatRequest->getModel())) {
            return;
        }
        $cachePointManager = new AwsBedrockCachePointManager($config->getAutoCacheConfig());
        $cachePointManager->configureCachePoints($chatRequest);

        $this->logger?->debug('OpenAIClaudeCachePoints', [
            'model' => $chatRequest->getModel(),
            'cache_points' => $chatRequest->getCachePointInfo(),
            'tools_cache' => $chatRequest->isToolsCache() ? 1 : 0,
        ]);
    }

    /**
     * 发请求前：为缺少 reasoning_details 的 AssistantMessage（含工具调用）从缓存恢复签名数据.
     * 以该 assistant 消息中所有 tool_call_id 的组合作为 key，一次 assistant 消息只查一次缓存.
     */
    private function restoreReasoningDetailsFromCache(ChatCompletionRequest $chatRequest): void
    {
        foreach ($chatRequest->getMessages() as $message) {
            if (! $message instanceof AssistantMessage
                || ! $message->hasToolCalls()
                || $message->hasReasoningDetails()) {
                continue;
            }
            $toolCallIds = array_map(fn ($tc) => $tc->getId(), $message->getToolCalls());
            $assistantKey = ReasoningDetailsCache::generateAssistantKey($toolCallIds);
            $cached = ReasoningDetailsCache::get($assistantKey);
            if ($cached !== null) {
                $message->setReasoningDetails($cached);
            }
        }
    }

    /**
     * 收到响应后：将含工具调用的响应中的 reasoning_details 缓存，供下一轮请求恢复使用.
     * 以该 assistant 消息中所有 tool_call_id 的组合作为 key，只存一份.
     */
    private function cacheReasoningDetailsFromResponse(ChatCompletionResponse $response): void
    {
        $choice = $response->getFirstChoice();
        if ($choice === null) {
            return;
        }
        $message = $choice->getMessage();
        if (! $message instanceof AssistantMessage
            || ! $message->hasToolCalls()
            || ! $message->hasReasoningDetails()) {
            return;
        }
        $reasoningDetails = $message->getReasoningDetails();
        if ($reasoningDetails === null) {
            return;
        }
        $toolCallIds = array_map(fn ($tc) => $tc->getId(), $message->getToolCalls());
        $assistantKey = ReasoningDetailsCache::generateAssistantKey($toolCallIds);
        ReasoningDetailsCache::store($assistantKey, $reasoningDetails);
    }
}
