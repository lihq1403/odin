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

namespace Hyperf\Odin\Model;

use Hyperf\Odin\Api\Request\EmbeddingRequest;
use Hyperf\Odin\Api\Response\EmbeddingResponse;
use Hyperf\Odin\Contract\Api\ClientInterface;
use Hyperf\Odin\Factory\ClientFactory;

class VolcengineMultiModalEmbeddingModel extends DoubaoModel
{
    public function embeddings(array|string $input, ?string $encoding_format = 'float', ?string $user = null, array $businessParams = []): EmbeddingResponse
    {
        $this->checkEmbeddingSupport();

        $client = $this->getClient();
        $embeddingRequest = new EmbeddingRequest(
            input: $input,
            model: $this->model,
            encoding_format: $encoding_format,
            user: $user,
            dimensions: $this->buildDimensions(),
        );
        $embeddingRequest->setBusinessParams($businessParams);
        $embeddingRequest->setIncludeBusinessParams($this->includeBusinessParams);

        return $client->embeddings($embeddingRequest);
    }

    protected function getClient(): ClientInterface
    {
        $config = $this->config;
        $this->processApiBaseUrl($config);

        return ClientFactory::createVolcengineArkClient(
            $config,
            $this->getApiRequestOptions(),
            $this->logger
        );
    }

    private function buildDimensions(): ?array
    {
        $vectorSize = $this->getModelOptions()->getVectorSize();
        if ($vectorSize <= 0) {
            return null;
        }

        return [$vectorSize];
    }
}
