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

use Hyperf\Odin\Api\Providers\AbstractApi;
use Hyperf\Odin\Api\Providers\OpenAI\OpenAIConfig;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Exception\LLMException\Configuration\LLMInvalidApiKeyException;
use Hyperf\Odin\Exception\LLMException\Configuration\LLMInvalidEndpointException;
use Psr\Log\LoggerInterface;

class VolcengineArk extends AbstractApi
{
    /**
     * @var VolcengineArkClient[]
     */
    protected array $clients = [];

    public function getClient(OpenAIConfig $config, ?ApiOptions $requestOptions = null, ?LoggerInterface $logger = null): VolcengineArkClient
    {
        if (empty($config->getApiKey()) && ! $config->shouldSkipApiKeyValidation()) {
            throw new LLMInvalidApiKeyException('API密钥不能为空', null, 'VolcengineArk');
        }

        if (empty($config->getBaseUrl())) {
            throw new LLMInvalidEndpointException('基础URL不能为空', null, $config->getBaseUrl());
        }

        $requestOptions = $requestOptions ?? new ApiOptions();

        $key = md5(json_encode($config->toArray()) . json_encode($requestOptions->toArray()));
        if (($this->clients[$key] ?? null) instanceof VolcengineArkClient) {
            return $this->clients[$key];
        }

        $client = new VolcengineArkClient($config, $requestOptions, $logger);
        $this->clients[$key] = $client;
        return $client;
    }
}
