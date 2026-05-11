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

namespace Hyperf\Odin\Api\Providers\Doubao;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Hyperf\Odin\Api\Providers\AbstractClient;
use Hyperf\Odin\Api\Request\EmbeddingRequest;
use Hyperf\Odin\Api\Request\MultiModalEmbeddingItem;
use Hyperf\Odin\Api\Request\MultiModalEmbeddingRequest;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Api\Response\EmbeddingResponse;
use Hyperf\Odin\Event\AfterEmbeddingsEvent;
use Hyperf\Odin\Exception\InvalidArgumentException;
use Hyperf\Odin\Utils\EventUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Doubao API 客户端，继承 AbstractClient，重写多模态嵌入方法.
 */
class Client extends AbstractClient
{
    public function __construct(
        DoubaoConfig $config,
        ?ApiOptions $requestOptions = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($config, $requestOptions ?? new ApiOptions(), $logger);
    }

    /**
     * 文本嵌入：Doubao embedding-vision 系列模型已不支持标准 /embeddings 端点，
     * 统一转发到多模态接口 /embeddings/multimodal.
     * Doubao 多模态接口每次请求仅返回一个向量，不支持批量输入，
     * 批量场景请由应用层自行 foreach 调用.
     */
    public function embeddings(EmbeddingRequest $embeddingRequest): EmbeddingResponse
    {
        $input = $embeddingRequest->getInput();

        if (is_array($input)) {
            throw new InvalidArgumentException(
                'Doubao multimodal embedding does not support batch input. Please call embeddings() once per text.'
            );
        }

        $request = new MultiModalEmbeddingRequest(
            inputs: [[MultiModalEmbeddingItem::text($input)]],
            model: $embeddingRequest->getModel(),
            encodingFormat: $embeddingRequest->getEncodingFormat(),
        );
        $request->setBusinessParams($embeddingRequest->getBusinessParams());
        $request->setIncludeBusinessParams($embeddingRequest->isIncludeBusinessParams());

        return $this->multimodalEmbeddings($request);
    }

    /**
     * 多模态嵌入：支持文本、图片、视频输入.
     * 调用 Doubao /embeddings/multimodal 端点.
     * 仅支持单组输入（每次请求返回一个融合向量），批量请由应用层循环调用.
     */
    public function multimodalEmbeddings(MultiModalEmbeddingRequest $request): EmbeddingResponse
    {
        if ($request->isBatch()) {
            throw new InvalidArgumentException(
                'Doubao multimodal embedding does not support batch requests. Please call multimodalEmbeddings() once per group.'
            );
        }

        $request->validate();

        $payload = [
            'model' => $request->getModel(),
            'input' => $this->serializeItems($request->getFirstGroupItems()),
            'encoding_format' => $request->getEncodingFormat() ?? 'float',
        ];

        if ($request->getDimensions() !== null) {
            $payload['dimensions'] = $request->getDimensions();
        }

        if ($request->getInstruct() !== null) {
            $payload['instructions'] = $request->getInstruct();
        }

        if ($request->isIncludeBusinessParams() && ! empty($request->getBusinessParams())) {
            $payload['business_params'] = $request->getBusinessParams();
        }

        $options = [RequestOptions::JSON => $payload];
        $requestId = $this->addRequestIdToOptions($options);
        $url = $this->buildMultimodalEmbeddingsUrl();

        $this->logRequest('DoubaoMultimodalEmbeddingsRequest', $url, $options, $requestId);

        $startTime = microtime(true);
        try {
            $response = $this->client->post($url, $options);
            $duration = $this->calculateDuration($startTime);

            $content = json_decode($response->getBody()->getContents(), true);
            $embeddingResponse = $this->buildEmbeddingResponse(
                $content['data'] ?? null,
                $content['model'] ?? $request->getModel(),
                $content['usage'] ?? null,
                $response
            );

            $this->logResponse('DoubaoMultimodalEmbeddingsResponse', $requestId, $duration, [
                'data' => $embeddingResponse->toLogArray(),
                'response_headers' => $response->getHeaders(),
            ]);

            EventUtil::dispatch(new AfterEmbeddingsEvent($request, $embeddingResponse, $duration));

            return $embeddingResponse;
        } catch (Throwable $e) {
            throw $this->convertException($e, $this->createExceptionContext($url, $options, 'multimodal_embeddings'));
        }
    }

    protected function buildChatCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/chat/completions';
    }

    protected function buildEmbeddingsUrl(): string
    {
        return $this->getBaseUri() . '/embeddings';
    }

    protected function buildCompletionsUrl(): string
    {
        return $this->getBaseUri() . '/completions';
    }

    protected function buildMultimodalEmbeddingsUrl(): string
    {
        return $this->getBaseUri() . '/embeddings/multimodal';
    }

    protected function getAuthHeaders(): array
    {
        /** @var DoubaoConfig $config */
        $config = $this->config;
        $headers = [];
        if ($config->getApiKey()) {
            $headers['Authorization'] = 'Bearer ' . $config->getApiKey();
        }
        return $headers;
    }

    /**
     * 将 MultiModalEmbeddingItem[] 序列化为 Volcengine 多模态嵌入格式.
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
                    'type' => 'text',
                    'text' => $item->getText(),
                ],
                MultiModalEmbeddingItem::TYPE_IMAGE => [
                    'type' => 'image_url',
                    'image_url' => ['url' => $item->getImageUrl()],
                ],
                MultiModalEmbeddingItem::TYPE_VIDEO => [
                    'type' => 'video_url',
                    'video_url' => ['url' => $item->getVideoUrl()],
                ],
                default => [],
            };
        }
        return $result;
    }

    /**
     * 将 Volcengine 响应归一化为标准 EmbeddingResponse.
     * Volcengine 多模态嵌入返回 data 为单个对象 {embedding: [...], index: 0}.
     *
     * @param null|array<string, mixed> $usage
     */
    private function buildEmbeddingResponse(
        mixed $data,
        string $model,
        ?array $usage,
        ?ResponseInterface $baseResponse = null
    ): EmbeddingResponse {
        // Volcengine 返回 data 为对象（非数组），归一化为 OpenAI list 格式
        $normalizedData = [];
        if (is_array($data) && isset($data['embedding']) && is_array($data['embedding'])) {
            $normalizedData = [[
                'object' => 'embedding',
                'embedding' => $data['embedding'],
                'index' => 0,
            ]];
        }

        $payload = [
            'object' => 'list',
            'data' => $normalizedData,
            'model' => $model,
        ];
        if ($usage !== null) {
            $payload['usage'] = $usage;
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $baseResponse instanceof ResponseInterface
            ? $baseResponse->withBody(Utils::streamFor($body))
            : new Response(200, [], Utils::streamFor($body));

        return new EmbeddingResponse($response, $this->logger);
    }
}
