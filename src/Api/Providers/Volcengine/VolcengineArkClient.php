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

namespace Hyperf\Odin\Api\Providers\Volcengine;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Hyperf\Odin\Api\Providers\OpenAI\Client;
use Hyperf\Odin\Api\Request\EmbeddingRequest;
use Hyperf\Odin\Api\Request\VolcengineMultiModalEmbeddingRequest;
use Hyperf\Odin\Api\Response\EmbeddingResponse;
use Hyperf\Odin\Event\AfterEmbeddingsEvent;
use Hyperf\Odin\Utils\EventUtil;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class VolcengineArkClient extends Client
{
    public function embeddings(EmbeddingRequest $embeddingRequest): EmbeddingResponse
    {
        return $this->multimodalEmbeddings(
            $this->buildMultiModalEmbeddingRequest(
                $embeddingRequest
            )
        );
    }

    public function multimodalEmbeddings(VolcengineMultiModalEmbeddingRequest $embeddingRequest): EmbeddingResponse
    {
        $embeddingRequest->validate();
        $options = $embeddingRequest->createOptions();
        $requestId = $this->addRequestIdToOptions($options);
        $url = $this->buildMultiModalEmbeddingsUrl();

        $this->logRequest('VolcengineMultiModalEmbeddingsRequest', $url, $options, $requestId);

        $startTime = microtime(true);

        try {
            $response = $this->client->post($url, $options);
            $duration = $this->calculateDuration($startTime);

            $content = json_decode($response->getBody()->getContents(), true);
            $embeddingResponse = $this->buildCompatibleEmbeddingResponse(
                $this->normalizeMultiModalData($content['data'] ?? null),
                $content['model'] ?? $embeddingRequest->getModel(),
                $this->normalizeUsage($content['usage'] ?? null),
                $response
            );

            $this->logResponse('VolcengineMultiModalEmbeddingsResponse', $requestId, $duration, [
                'data' => $embeddingResponse->toArray(),
                'response_headers' => $embeddingResponse->getOriginResponse()->getHeaders(),
            ]);

            EventUtil::dispatch(new AfterEmbeddingsEvent($embeddingRequest, $embeddingResponse, $duration));

            return $embeddingResponse;
        } catch (Throwable $exception) {
            throw $this->convertException($exception, $this->createExceptionContext($url, $options, 'embeddings'));
        }
    }

    protected function buildMultiModalEmbeddingsUrl(): string
    {
        return $this->getBaseUri() . '/embeddings/multimodal';
    }

    private function normalizeMultiModalData(mixed $data): array
    {
        if (is_array($data) && isset($data['embedding']) && is_array($data['embedding'])) {
            return [[
                'object' => 'embedding',
                'embedding' => $data['embedding'],
                'index' => 0,
            ]];
        }

        return [];
    }

    private function normalizeUsage(mixed $usage): ?array
    {
        return is_array($usage) ? $usage : null;
    }

    private function buildMultiModalEmbeddingRequest(
        EmbeddingRequest $embeddingRequest
    ): VolcengineMultiModalEmbeddingRequest {
        $request = new VolcengineMultiModalEmbeddingRequest(
            input: $embeddingRequest->getInput(),
            model: $embeddingRequest->getModel(),
            encoding_format: $embeddingRequest->getEncodingFormat(),
            user: $embeddingRequest->getUser(),
            dimensions: $embeddingRequest->getDimensions(),
        );
        $request->setBusinessParams($embeddingRequest->getBusinessParams());
        $request->setIncludeBusinessParams($embeddingRequest->isIncludeBusinessParams());

        return $request;
    }

    private function buildCompatibleEmbeddingResponse(array $data, string $model, ?array $usage, ?ResponseInterface $baseResponse = null): EmbeddingResponse
    {
        $payload = [
            'object' => 'list',
            'data' => $data,
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
