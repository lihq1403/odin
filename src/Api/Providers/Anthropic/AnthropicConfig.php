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

use Hyperf\Odin\Api\Providers\Anthropic\Cache\AnthropicAutoCacheConfig;
use Hyperf\Odin\Contract\Api\ConfigInterface;

/**
 * Anthropic API 配置类.
 *
 * @see https://docs.anthropic.com/en/api/getting-started
 */
class AnthropicConfig implements ConfigInterface
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.anthropic.com',
        private string $anthropicVersion = '2023-06-01',
        private bool $skipApiKeyValidation = false,
        private bool $autoCache = false,
        private ?AnthropicAutoCacheConfig $autoCacheConfig = null,
    ) {
        if (! $this->autoCacheConfig) {
            $this->autoCacheConfig = new AnthropicAutoCacheConfig();
        }
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getAnthropicVersion(): string
    {
        return $this->anthropicVersion;
    }

    public function shouldSkipApiKeyValidation(): bool
    {
        return $this->skipApiKeyValidation;
    }

    public function isAutoCache(): bool
    {
        return $this->autoCache;
    }

    public function getAutoCacheConfig(): AnthropicAutoCacheConfig
    {
        return $this->autoCacheConfig ?? new AnthropicAutoCacheConfig();
    }

    public static function fromArray(array $config): self
    {
        $autoCacheConfig = null;
        if (isset($config['auto_cache_config']) && is_array($config['auto_cache_config'])) {
            $cacheConfig = $config['auto_cache_config'];
            $autoCacheConfig = new AnthropicAutoCacheConfig(
                maxCachePoints: (int) ($cacheConfig['max_cache_points'] ?? 4),
                minCacheTokens: (int) ($cacheConfig['min_cache_tokens'] ?? 1024),
                refreshPointMinTokens: (int) ($cacheConfig['refresh_point_min_tokens'] ?? 5000),
                minHitCount: (int) ($cacheConfig['min_hit_count'] ?? 3),
                cacheTtl: (string) ($cacheConfig['cache_ttl'] ?? '5m'),
            );
        }

        return new self(
            apiKey: $config['api_key'] ?? '',
            baseUrl: $config['base_url'] ?? 'https://api.anthropic.com',
            anthropicVersion: $config['anthropic_version'] ?? '2023-06-01',
            skipApiKeyValidation: (bool) ($config['skip_api_key_validation'] ?? false),
            autoCache: (bool) ($config['auto_cache'] ?? false),
            autoCacheConfig: $autoCacheConfig,
        );
    }

    public function toArray(): array
    {
        return [
            'api_key' => $this->apiKey,
            'base_url' => $this->baseUrl,
            'anthropic_version' => $this->anthropicVersion,
            'skip_api_key_validation' => $this->skipApiKeyValidation,
            'auto_cache' => $this->autoCache,
        ];
    }
}
