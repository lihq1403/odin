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

use Hyperf\Odin\Contract\Api\ConfigInterface;

/**
 * Kimi（月之暗面）API 配置.
 *
 * @see https://platform.moonshot.cn/docs/api/
 */
class KimiConfig implements ConfigInterface
{
    public string $baseUrl;

    public string $apiKey;

    /**
     * 是否跳过 API Key 验证.
     */
    protected bool $skipApiKeyValidation = false;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.moonshot.cn',
        bool $skipApiKeyValidation = false,
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->skipApiKeyValidation = $skipApiKeyValidation;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function shouldSkipApiKeyValidation(): bool
    {
        return $this->skipApiKeyValidation;
    }

    public static function fromArray(array $config): self
    {
        return new self(
            $config['api_key'] ?? '',
            $config['base_url'] ?? 'https://api.moonshot.cn',
            $config['skip_api_key_validation'] ?? false,
        );
    }

    public function toArray(): array
    {
        return [
            'api_key' => $this->apiKey,
            'base_url' => $this->baseUrl,
            'skip_api_key_validation' => $this->skipApiKeyValidation,
        ];
    }
}
