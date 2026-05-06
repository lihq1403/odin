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

use Hyperf\Odin\Api\Providers\OpenAI\OpenAIConfig;

/**
 * Doubao API 配置，与 OpenAI 鉴权结构相同.
 */
class DoubaoConfig extends OpenAIConfig
{
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://ark.cn-beijing.volces.com/api/v3',
        bool $skipApiKeyValidation = false,
    ) {
        parent::__construct(
            apiKey: $apiKey,
            organization: '',
            baseUrl: $baseUrl,
            skipApiKeyValidation: $skipApiKeyValidation,
        );
    }
}
