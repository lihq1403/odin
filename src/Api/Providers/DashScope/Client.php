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

namespace Hyperf\Odin\Api\Providers\DashScope;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Hyperf\Odin\Api\Providers\AbstractClient;
use Hyperf\Odin\Api\Providers\DashScope\Cache\DashScopeCachePointManager;
use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Api\Request\EmbeddingRequest;
use Hyperf\Odin\Api\Request\MultiModalEmbeddingItem;
use Hyperf\Odin\Api\Request\MultiModalEmbeddingRequest;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Api\Response\ChatCompletionResponse;
use Hyperf\Odin\Api\Response\ChatCompletionStreamResponse;
use Hyperf\Odin\Api\Response\EmbeddingResponse;
use Hyperf\Odin\Event\AfterChatCompletionsEvent;
use Hyperf\Odin\Event\AfterChatCompletionsStreamEvent;
use Hyperf\Odin\Event\AfterEmbeddingsEvent;
use Hyperf\Odin\Utils\EventUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Client extends AbstractClient
{
    private ?DashScopeCachePointManager $cachePointManager = null;

    public function __construct(
        DashScopeConfig $config,
        ?ApiOptions $requestOptions = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($config, $requestOptions, $logger);

        // 总是初始化缓存点管理器
        $this->cachePointManager = new DashScopeCachePointManager($config->getAutoCacheConfig());
    }

    public function chatCompletions(ChatCompletionRequest $chatRequest): ChatCompletionResponse
    {
        $chatRequest->validate();
        $startTime = microtime(true);

        try {
            // 应用缓存点配置（自动或手动验证）
            $this->cachePointManager->configureCachePoints($chatRequest);

            $options = $chatRequest->createOptions();

            // 将通用 thinking 字段转换为千问格式（enable_thinking + thinking_budget）
            $this->processThinkingConfig($chatRequest, $options);

            // 处理缓存点转换并决定是否添加缓存控制头部
            $hasCachePoints = $this->processCachePoints($chatRequest, $options);

            $url = $this->buildChatCompletionsUrl();
            $requestId = $this->addRequestIdToOptions($options);

            // 根据是否有缓存点添加缓存控制头部
            if ($hasCachePoints) {
                $this->addCacheControlHeader($options);
            }

            $this->logRequest('DashScopeChatRequest', $url, $options, $requestId);

            $response = $this->client->post($url, $options);
            $duration = $this->calculateDuration($startTime);

            // 转换DashScope响应格式为标准格式
            $standardResponse = ResponseHandler::convertResponse($response);
            $chatResponse = new ChatCompletionResponse($standardResponse, $this->logger);

            $this->logResponse('DashScopeChatResponse', $requestId, $duration, [
                'content' => $chatResponse->getContent(),
                'usage' => $chatResponse->getUsage(),
                'response_headers' => $response->getHeaders(),
            ]);

            EventUtil::dispatch(new AfterChatCompletionsEvent($chatRequest, $chatResponse, $duration));

            return $chatResponse;
        } catch (Throwable $e) {
            $context = $this->createExceptionContext($url ?? '', $options ?? [], 'completions');

            throw $this->convertException($e, $context);
        }
    }

    public function chatCompletionsStream(ChatCompletionRequest $chatRequest): ChatCompletionStreamResponse
    {
        $chatRequest->validate();
        $chatRequest->setStream(true);

        $this->cachePointManager->configureCachePoints($chatRequest);

        $options = $chatRequest->createOptions();

        // 将通用 thinking 字段转换为千问格式（enable_thinking + thinking_budget）
        $this->processThinkingConfig($chatRequest, $options);

        $hasCachePoints = $this->processCachePoints($chatRequest, $options);

        $url = $this->buildChatCompletionsUrl();
        $requestId = $this->addRequestIdToOptions($options);

        // 根据是否有缓存点添加缓存控制头部
        if ($hasCachePoints) {
            $this->addCacheControlHeader($options);
        }

        $this->logRequest('DashScopeChatStreamRequest', $url, $options, $requestId);

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

            $this->logResponse('DashScopeChatStreamResponse', $requestId, $firstResponseDuration, [
                'first_response_ms' => $firstResponseDuration,
                'response_headers' => $response->getHeaders(),
                'transport' => $transport,
            ]);

            return $chatCompletionStreamResponse;
        } catch (Throwable $e) {
            throw $this->convertException($e, $this->createExceptionContext($url, $options, 'stream'));
        }
    }

    /**
     * 文本嵌入：DashScope 多模态嵌入模型已不支持标准 /embeddings 端点，
     * 统一转发到多模态接口，并关闭融合模式（与 OpenAI 语义保持一致：每条文本独立生成向量）.
     * DashScope 在 enable_fusion=false 时支持批量输入，单次请求即可返回多个独立向量.
     */
    public function embeddings(EmbeddingRequest $embeddingRequest): EmbeddingResponse
    {
        $input = $embeddingRequest->getInput();
        $texts = is_array($input) ? $input : [$input];

        // 每条文本独立一组，由 multimodalEmbeddings 批量发送（enable_fusion=false）
        $inputs = array_map(
            static fn (string $text) => [MultiModalEmbeddingItem::text($text)],
            $texts
        );

        $request = new MultiModalEmbeddingRequest(
            inputs: $inputs,
            model: $embeddingRequest->getModel(),
            encodingFormat: $embeddingRequest->getEncodingFormat(),
            enableFusion: false,
        );

        return $this->multimodalEmbeddings($request);
    }

    /**
     * 多模态嵌入：调用 DashScope 原生多模态嵌入端点.
     *
     * - 单组（非批量）：按 request 的 enableFusion 决定是否启用融合模式
     * - 多组（批量）：展平所有组的 items 到 contents，固定使用 enable_fusion=false，
     *   DashScope 为每个 content item 独立返回一个向量
     */
    public function multimodalEmbeddings(MultiModalEmbeddingRequest $request): EmbeddingResponse
    {
        $request->validate();

        $isBatch = $request->isBatch();

        if ($isBatch) {
            // 展平所有组的 items，每个 item 独立返回一个向量
            $allItems = array_merge(...$request->getInputs());
            $contents = $this->serializeItems($allItems);
            $enableFusion = false;
        } else {
            $contents = $this->serializeItems($request->getFirstGroupItems());
            $enableFusion = $request->isEnableFusion();
        }

        $payload = [
            'model' => $request->getModel(),
            'input' => [
                'contents' => $contents,
            ],
        ];

        if ($request->getDimensions() !== null) {
            $payload['parameters']['dimension'] = $request->getDimensions();
        }

        if ($request->getInstruct() !== null) {
            $payload['parameters']['instruct'] = $request->getInstruct();
        }

        if ($enableFusion) {
            $payload['parameters']['enable_fusion'] = true;
        }

        $options = [RequestOptions::JSON => $payload];
        $requestId = $this->addRequestIdToOptions($options);
        $url = $this->buildMultimodalEmbeddingsUrl();

        $this->logRequest('DashScopeMultimodalEmbeddingsRequest', $url, $options, $requestId);

        $startTime = microtime(true);
        try {
            $response = $this->client->post($url, $options);
            $duration = $this->calculateDuration($startTime);

            $content = json_decode($response->getBody()->getContents(), true);
            $embeddingResponse = $this->buildEmbeddingResponse(
                $content['output']['embeddings'] ?? [],
                $request->getModel(),
                $content['usage'] ?? null,
                $response
            );

            $this->logResponse('DashScopeMultimodalEmbeddingsResponse', $requestId, $duration, [
                'data' => $embeddingResponse->toLogArray(),
                'response_headers' => $response->getHeaders(),
            ]);

            EventUtil::dispatch(new AfterEmbeddingsEvent($request, $embeddingResponse, $duration));

            return $embeddingResponse;
        } catch (Throwable $e) {
            throw $this->convertException($e, $this->createExceptionContext($url, $options, 'multimodal_embeddings'));
        }
    }

    protected function getAuthHeaders(): array
    {
        $headers = [];
        /** @var DashScopeConfig $config */
        $config = $this->config;

        if ($config->getApiKey()) {
            $headers['Authorization'] = 'Bearer ' . $config->getApiKey();
        }

        return $headers;
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
     * 构建 DashScope 原生多模态嵌入 API 的 URL.
     * 多模态嵌入端点固定在 /api/v1 路径下，与 chat 使用的 /compatible-mode/v1 不同，
     * 需要从 base_url 中提取 host 后单独拼接.
     */
    protected function buildMultimodalEmbeddingsUrl(): string
    {
        $baseUrl = rtrim($this->config->getBaseUrl(), '/');
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';
        $host = parse_url($baseUrl, PHP_URL_HOST) ?? 'dashscope.aliyuncs.com';
        return $scheme . '://' . $host . '/api/v1/services/embeddings/multimodal-embedding/multimodal-embedding';
    }

    /**
     * 将 MultiModalEmbeddingItem[] 序列化为 DashScope contents 格式.
     *
     * @param array<int, MultiModalEmbeddingItem> $items
     * @return array<int, array<string, mixed>>
     */
    private function serializeItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = match ($item->getType()) {
                MultiModalEmbeddingItem::TYPE_TEXT => [
                    'text' => $item->getText(),
                ],
                MultiModalEmbeddingItem::TYPE_IMAGE => [
                    'image' => $item->getImageUrl(),
                ],
                MultiModalEmbeddingItem::TYPE_VIDEO => [
                    'video' => $item->getVideoUrl(),
                ],
                default => [],
            };
        }
        return $result;
    }

    /**
     * 将 DashScope 原生响应归一化为标准 EmbeddingResponse.
     *
     * @param array<int, array<string, mixed>> $embeddings DashScope output.embeddings
     * @param null|array<string, mixed> $usage
     */
    private function buildEmbeddingResponse(
        array $embeddings,
        string $model,
        ?array $usage,
        ?ResponseInterface $baseResponse = null
    ): EmbeddingResponse {
        $data = [];
        foreach ($embeddings as $index => $item) {
            if (isset($item['embedding']) && is_array($item['embedding'])) {
                $data[] = [
                    'object' => 'embedding',
                    'embedding' => $item['embedding'],
                    'index' => $index,
                ];
            }
        }

        $normalizedUsage = null;
        if (is_array($usage)) {
            $normalizedUsage = [
                'prompt_tokens' => $usage['input_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? ($usage['input_tokens'] ?? 0),
            ];
            if (! empty($usage['input_tokens_details']) && is_array($usage['input_tokens_details'])) {
                $normalizedUsage['prompt_tokens_details'] = $usage['input_tokens_details'];
            }
        }

        $payload = [
            'object' => 'list',
            'data' => $data,
            'model' => $model,
        ];
        if ($normalizedUsage !== null) {
            $payload['usage'] = $normalizedUsage;
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $baseResponse instanceof ResponseInterface
            ? $baseResponse->withBody(Utils::streamFor($body))
            : new Response(200, [], Utils::streamFor($body));

        return new EmbeddingResponse($response, $this->logger);
    }

    /**
     * 将通用 thinking 字段替换为千问原生格式。
     *
     * createOptions() 生成的是 Bedrock 格式（json.thinking.type/budget_tokens），
     * 千问需要顶层扁平字段 enable_thinking + thinking_budget，此方法完成替换。
     */
    private function processThinkingConfig(ChatCompletionRequest $request, array &$options): void
    {
        // 移除 createOptions() 写入的 Bedrock 格式 thinking 字段
        unset($options['json']['thinking']);

        $thinking = $request->getThinking();
        if ($thinking === null) {
            return;
        }

        foreach ($thinking->toQwenFormat() as $key => $value) {
            $options['json'][$key] = $value;
        }
    }

    /**
     * 将 Odin 的 CachePoint 转换为 DashScope 的 cache_control 格式.
     *
     * @return bool 是否有缓存点被处理
     */
    private function processCachePoints(ChatCompletionRequest $request, array &$options): bool
    {
        if (! isset($options['json']['messages'])) {
            return false;
        }

        $messages = $request->getMessages();
        $jsonMessages = &$options['json']['messages'];
        $hasCachePoints = false;

        foreach ($messages as $index => $message) {
            $cachePoint = $message->getCachePoint();

            if ($cachePoint && $cachePoint->getType() === 'ephemeral') {
                $this->addCacheControlToMessage($jsonMessages[$index]);
                $hasCachePoints = true;
            }
        }

        return $hasCachePoints;
    }

    /**
     * 为消息添加 cache_control 标记.
     */
    private function addCacheControlToMessage(array &$message): void
    {
        if (is_string($message['content'])) {
            $message['content'] = [
                [
                    'type' => 'text',
                    'text' => $message['content'],
                ],
            ];
        }

        if (is_array($message['content']) && ! empty($message['content'])) {
            $lastIndex = count($message['content']) - 1;
            $message['content'][$lastIndex]['cache_control'] = [
                'type' => 'ephemeral',
            ];
        }
    }

    /**
     * 添加缓存控制头部.
     */
    private function addCacheControlHeader(array &$options): void
    {
        if (! isset($options['headers'])) {
            $options['headers'] = [];
        }

        $options['headers']['X-DashScope-CacheControl'] = 'enable';
    }
}
