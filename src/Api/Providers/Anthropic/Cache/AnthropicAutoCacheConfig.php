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

namespace Hyperf\Odin\Api\Providers\Anthropic\Cache;

/**
 * Anthropic 提示词缓存自动配置.
 *
 * 策略参数与 AWS Bedrock 保持一致，额外支持 Anthropic 特有的 TTL 配置。
 */
class AnthropicAutoCacheConfig
{
    /**
     * 最大缓存点数量（Anthropic 上限为 4）.
     */
    private int $maxCachePoints;

    /**
     * 缓存点最小生效 tokens 阈值.
     * claude-3-5/3-7 系列最低 1024，claude-3 系列最低 2048.
     */
    private int $minCacheTokens;

    /**
     * 刷新缓存点的最小增量 tokens 阈值.
     * 超过此阈值后重新评估缓存点位置.
     */
    private int $refreshPointMinTokens;

    /**
     * 缓存点命中最小次数.
     * 达到最小命中次数后才进行缓存点评估.
     */
    private int $minHitCount;

    /**
     * 缓存 TTL，支持 "5m"（5 分钟）或 "1h"（1 小时）.
     * Anthropic 目前仅支持 ephemeral 类型，默认 TTL 为 5 分钟.
     */
    private string $cacheTtl;

    public function __construct(
        int $maxCachePoints = 4,
        int $minCacheTokens = 1024,
        int $refreshPointMinTokens = 5000,
        int $minHitCount = 3,
        string $cacheTtl = '5m'
    ) {
        $this->maxCachePoints = $maxCachePoints;
        $this->minCacheTokens = $minCacheTokens;
        $this->refreshPointMinTokens = $refreshPointMinTokens;
        $this->minHitCount = $minHitCount;
        $this->cacheTtl = $cacheTtl;
    }

    public function getMaxCachePoints(): int
    {
        return $this->maxCachePoints;
    }

    public function getMinCacheTokens(): int
    {
        return $this->minCacheTokens;
    }

    public function getRefreshPointMinTokens(): int
    {
        return $this->refreshPointMinTokens;
    }

    public function getMinHitCount(): int
    {
        return $this->minHitCount;
    }

    public function getCacheTtl(): string
    {
        return $this->cacheTtl;
    }

    /**
     * 构建 cache_control 对象，用于注入到请求内容块中.
     */
    public function buildCacheControl(): array
    {
        $cacheControl = ['type' => 'ephemeral'];

        // 仅当 TTL 为 1 小时时显式设置，5 分钟为默认值无需传递
        if ($this->cacheTtl === '1h') {
            $cacheControl['ttl'] = '1h';
        }

        return $cacheControl;
    }
}
