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

use Hyperf\Odin\Api\Providers\AwsBedrock\Cache\AutoCacheConfig;
use Hyperf\Odin\Contract\Api\ConfigInterface;

class OpenAIConfig implements ConfigInterface
{
    public string $baseUrl;

    public string $apiKey;

    protected string $organization;

    /**
     * 是否跳过API Key验证
     */
    protected bool $skipApiKeyValidation = false;

    /**
     * 是否启用自动缓存（仅对 Claude 模型生效）.
     */
    protected bool $autoCache = false;

    /**
     * 自动缓存配置.
     */
    protected AutoCacheConfig $autoCacheConfig;

    public function __construct(
        string $apiKey,
        string $organization = '',
        string $baseUrl = 'https://api.openai.com',
        bool $skipApiKeyValidation = false,
        bool $autoCache = false,
        ?AutoCacheConfig $autoCacheConfig = null,
    ) {
        $this->apiKey = $apiKey;
        $this->organization = $organization;
        $this->baseUrl = $baseUrl;
        $this->skipApiKeyValidation = $skipApiKeyValidation;
        $this->autoCache = $autoCache;
        $this->autoCacheConfig = $autoCacheConfig ?? new AutoCacheConfig();
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getOrganization(): string
    {
        return $this->organization;
    }

    public function shouldSkipApiKeyValidation(): bool
    {
        return $this->skipApiKeyValidation;
    }

    public function isAutoCache(): bool
    {
        return $this->autoCache;
    }

    public function getAutoCacheConfig(): AutoCacheConfig
    {
        return $this->autoCacheConfig;
    }

    public static function fromArray(array $config): self
    {
        $autoCacheConfig = null;
        if (isset($config['auto_cache_config']) && is_array($config['auto_cache_config'])) {
            $c = $config['auto_cache_config'];
            $autoCacheConfig = new AutoCacheConfig(
                $c['max_cache_points'] ?? 4,
                $c['min_cache_tokens'] ?? 2048,
                $c['refresh_point_min_tokens'] ?? 5000,
                $c['min_hit_count'] ?? 3,
            );
        }
        return new self(
            $config['api_key'] ?? '',
            $config['organization'] ?? '',
            $config['base_url'] ?? 'https://api.openai.com',
            $config['skip_api_key_validation'] ?? false,
            $config['auto_cache'] ?? false,
            $autoCacheConfig,
        );
    }

    public function toArray(): array
    {
        return [
            'api_key' => $this->apiKey,
            'organization' => $this->organization,
            'base_url' => $this->baseUrl,
            'skip_api_key_validation' => $this->skipApiKeyValidation,
            'auto_cache' => $this->autoCache,
        ];
    }
}
