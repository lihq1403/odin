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

use Hyperf\Odin\Api\Providers\AwsBedrock\Cache\AutoCacheConfig;
use Hyperf\Odin\Api\Providers\AwsBedrock\Cache\Strategy\DynamicCacheStrategy;
use Hyperf\Odin\Api\Providers\AwsBedrock\Cache\Strategy\NoneCacheStrategy;
use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use HyperfTest\Odin\Mock\Cache;
use Throwable;

use function Hyperf\Support\make;

/**
 * Anthropic 缓存点管理器.
 *
 * 复用 AWS Bedrock 的 DynamicCacheStrategy 策略逻辑（触发算法完全一致），
 * 差异仅在 RequestConverter 的序列化阶段：
 *   - AWS Bedrock 输出 cachePoint: {type: default}
 *   - Anthropic 输出 cache_control: {type: ephemeral}
 */
class AnthropicCachePointManager
{
    private AnthropicAutoCacheConfig $autoCacheConfig;

    public function __construct(AnthropicAutoCacheConfig $autoCacheConfig)
    {
        $this->autoCacheConfig = $autoCacheConfig;
    }

    /**
     * 分析请求并配置缓存点.
     *
     * @param ChatCompletionRequest $request 需要配置缓存点的请求对象（会直接修改此对象）
     */
    public function configureCachePoints(ChatCompletionRequest $request): void
    {
        // 重置现有缓存点设置
        $this->resetCachePoints($request);

        // 估算 token 数
        $request->calculateTokenEstimates();

        // 将 AnthropicAutoCacheConfig 转为 AwsBedrock AutoCacheConfig（策略层接受的类型）
        $awsConfig = new AutoCacheConfig(
            maxCachePoints: $this->autoCacheConfig->getMaxCachePoints(),
            minCacheTokens: $this->autoCacheConfig->getMinCacheTokens(),
            refreshPointMinTokens: $this->autoCacheConfig->getRefreshPointMinTokens(),
            minHitCount: $this->autoCacheConfig->getMinHitCount(),
        );

        // 若 token 数低于阈值，直接使用 NoneCacheStrategy 跳过
        $totalTokens = $request->getTotalTokenEstimate();
        if ($totalTokens < $awsConfig->getMinCacheTokens()) {
            return;
        }

        // 使用 DynamicCacheStrategy 计算缓存点（策略逻辑与 AWS Bedrock 完全相同）
        $strategy = $this->createDynamicStrategy();
        $strategy->apply($awsConfig, $request);
    }

    /**
     * 获取缓存 TTL 配置.
     */
    public function getCacheTtl(): string
    {
        return $this->autoCacheConfig->getCacheTtl();
    }

    /**
     * 创建 DynamicCacheStrategy 实例，优先通过 DI 容器创建.
     */
    private function createDynamicStrategy(): DynamicCacheStrategy
    {
        try {
            return make(DynamicCacheStrategy::class);
        } catch (Throwable) {
            // 测试环境或无协程容器时直接实例化（需要模拟 Cache）
            return $this->createFallbackStrategy();
        }
    }

    /**
     * 降级策略创建（测试或无 DI 容器场景）.
     */
    private function createFallbackStrategy(): DynamicCacheStrategy
    {
        // 使用与 AwsBedrockCachePointManager 相同的模拟缓存方式
        // phpcs:ignore
        $cache = new Cache();
        return new DynamicCacheStrategy($cache);
    }

    /**
     * 重置请求对象上的缓存点设置.
     */
    private function resetCachePoints(ChatCompletionRequest $request): void
    {
        $request->setToolsCache(false);
        foreach ($request->getMessages() as $message) {
            $message->setCachePoint(null);
        }
    }
}
