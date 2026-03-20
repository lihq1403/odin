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

namespace HyperfTest\Odin\Cases\Api\Providers\AwsBedrock\Cache;

use Hyperf\Odin\Api\Providers\AwsBedrock\Cache\AutoCacheConfig;
use Hyperf\Odin\Api\Providers\AwsBedrock\Cache\Strategy\DynamicCacheStrategy;
use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Message\SystemMessage;
use Hyperf\Odin\Message\UserMessage;
use HyperfTest\Odin\Cases\AbstractTestCase;
use HyperfTest\Odin\Mock\Cache;

/**
 * 测试 DynamicCacheStrategy 缓存点是否正确添加.
 *
 * @internal
 * @covers \Hyperf\Odin\Api\Providers\AwsBedrock\Cache\Strategy\DynamicCacheStrategy
 */
class DynamicCacheStrategyTest extends AbstractTestCase
{
    private Cache $mockCache;

    private DynamicCacheStrategy $strategy;

    /** minCacheTokens=500, refreshPointMinTokens=2000, maxCachePoints=4 */
    private AutoCacheConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockCache = new Cache();
        $this->strategy = new DynamicCacheStrategy($this->mockCache);
        $this->config = new AutoCacheConfig(4, 500, 2000);
    }

    // -------------------------------------------------------------------------
    // 有 SystemMessage 的场景
    // -------------------------------------------------------------------------

    /**
     * 有 SystemMessage 且 system token 超过阈值时，system 消息应当设置缓存点.
     */
    public function testSystemMessageGetsCachePointWhenTokensAboveThreshold(): void
    {
        $system = (new SystemMessage('你是一个助手'))->setTokenEstimate(600);
        $user = (new UserMessage('你好'))->setTokenEstimate(100);

        $request = new ChatCompletionRequest([$system, $user], 'claude-3');
        $this->strategy->apply($this->config, $request);

        $this->assertNotNull($system->getCachePoint(), '系统消息应设置缓存点');
        $this->assertNull($user->getCachePoint(), '用户消息不应设置缓存点（增量 token 未达阈值）');
    }

    /**
     * 有 SystemMessage，且对话消息增量 token 超阈值时，最后一条消息也应设置缓存点.
     */
    public function testLastMessageGetsCachePointWhenIncrementalTokensAboveThreshold(): void
    {
        $system = (new SystemMessage('你是一个助手'))->setTokenEstimate(600);
        $user1 = (new UserMessage('第一个问题'))->setTokenEstimate(1000);
        $asst1 = (new AssistantMessage('第一个回答'))->setTokenEstimate(1500);
        $user2 = (new UserMessage('第二个问题'))->setTokenEstimate(200);

        $request = new ChatCompletionRequest([$system, $user1, $asst1, $user2], 'claude-3');
        $this->strategy->apply($this->config, $request);

        // system token=600 >= 500 → 固定缓存点在 system
        $this->assertNotNull($system->getCachePoint(), '系统消息应设置缓存点');
        // 固定点之后增量 = user1(1000)+asst1(1500)+user2(200) = 2700 >= 2000 → 最后一条消息也设缓存点
        $this->assertNotNull($user2->getCachePoint(), '最后一条用户消息应设置缓存点');
        $this->assertNull($user1->getCachePoint(), '中间消息不应设置缓存点');
        $this->assertNull($asst1->getCachePoint(), '中间消息不应设置缓存点');
    }

    // -------------------------------------------------------------------------
    // 无 SystemMessage 的场景（Bug 3 + Bug 4 修复验证）
    // -------------------------------------------------------------------------

    /**
     * 无 SystemMessage，缓存点应正确打在最后一条消息上，而非倒数第二条.
     *
     * 验证 Bug 3（索引偏移）和 Bug 4（稀疏数组 getLastMessageIndex）的修复.
     * cachePointMessages = {0:tools, 2:user1, 3:asst, 4:user2}
     * count()-1=3 对应 asst（错误），max(keys)=4 对应 user2（正确）.
     */
    public function testCachePointOnLastMessageWithoutSystemMessage(): void
    {
        $user1 = (new UserMessage('第一条消息'))->setTokenEstimate(3000);
        $asst = (new AssistantMessage('回答'))->setTokenEstimate(500);
        $user2 = (new UserMessage('第二条消息'))->setTokenEstimate(200);

        $request = new ChatCompletionRequest([$user1, $asst, $user2], 'claude-3');
        $this->strategy->apply($this->config, $request);

        // tools+system=0，没有固定缓存点
        // 增量 tokens 从 index 0 开始 = user1(3000)+asst(500)+user2(200) = 3700 >= 2000
        // 最后一条消息是 user2（cachePointMessages[4]），不是 asst（cachePointMessages[3]）
        $this->assertNotNull($user2->getCachePoint(), '最后一条消息应设置缓存点');
        $this->assertNull($asst->getCachePoint(), 'asst 不是最后一条消息，不应设置缓存点');
        $this->assertNull($user1->getCachePoint(), 'user1 不是最后一条消息，不应设置缓存点');
        $this->assertFalse($request->isToolsCache(), '无 tools 时不应设置 tools 缓存');
    }

    /**
     * 无 SystemMessage 且只有一条消息，缓存点应正确打在该消息上.
     */
    public function testCachePointOnSingleMessageWithoutSystemMessage(): void
    {
        $user = (new UserMessage('一条很长的消息'))->setTokenEstimate(3000);

        $request = new ChatCompletionRequest([$user], 'claude-3');
        $this->strategy->apply($this->config, $request);

        $this->assertNotNull($user->getCachePoint(), '唯一一条消息增量 token 够，应设置缓存点');
    }

    /**
     * 无 SystemMessage 时，token 不足阈值，不应设置任何缓存点.
     */
    public function testNoCachePointsWithoutSystemMessageWhenTokensBelowThreshold(): void
    {
        $user1 = (new UserMessage('短消息1'))->setTokenEstimate(100);
        $user2 = (new UserMessage('短消息2'))->setTokenEstimate(50);

        $request = new ChatCompletionRequest([$user1, $user2], 'claude-3');
        $this->strategy->apply($this->config, $request);

        $this->assertNull($user1->getCachePoint());
        $this->assertNull($user2->getCachePoint());
    }

    // -------------------------------------------------------------------------
    // Bug 1 修复验证：addFixedCachePointIndex 不重复添加已有固定机位
    // -------------------------------------------------------------------------

    /**
     * 多轮对话中，第二轮加载了上一轮的固定缓存点后，不应重复添加.
     *
     * 验证 Bug 1 修复：guard 条件改为检查 getCachePointIndex()，确保已有固定点时跳过评估.
     */
    public function testFixedCachePointNotDuplicatedInSecondRound(): void
    {
        $config = new AutoCacheConfig(4, 500, 5000); // refreshPointMinTokens 设高，避免增量点干扰

        // 第一轮：system + user1
        $system = (new SystemMessage('系统指令'))->setTokenEstimate(600);
        $user1 = (new UserMessage('问题1'))->setTokenEstimate(200);
        $request1 = new ChatCompletionRequest([$system, $user1], 'claude-3');
        $strategy = new DynamicCacheStrategy($this->mockCache);
        $strategy->apply($config, $request1);

        // 第一轮：system 应有缓存点
        $this->assertNotNull($system->getCachePoint(), '第一轮 system 消息应有缓存点');

        // 第二轮：相同 system + user1 + asst1 + user2（模拟多轮对话追加消息）
        $system2 = (new SystemMessage('系统指令'))->setTokenEstimate(600);
        $user1b = (new UserMessage('问题1'))->setTokenEstimate(200);
        $asst1 = (new AssistantMessage('回答1'))->setTokenEstimate(300);
        $user2 = (new UserMessage('问题2'))->setTokenEstimate(100);
        $request2 = new ChatCompletionRequest([$system2, $user1b, $asst1, $user2], 'claude-3');
        $strategy->apply($config, $request2);

        // 第二轮：从历史加载的缓存点中已有 index=1（system），guard 应阻止重复评估
        // system2 仍应有缓存点（从历史恢复）
        $this->assertNotNull($system2->getCachePoint(), '第二轮 system 消息应从历史恢复缓存点');
        // 增量 tokens < 5000，不添加新缓存点
        $this->assertNull($user2->getCachePoint(), '增量 token 未达阈值，最后消息不应有缓存点');
    }

    // -------------------------------------------------------------------------
    // tools 缓存
    // -------------------------------------------------------------------------

    /**
     * tools token 超过阈值且无 system 时，应设置 tools 缓存标记.
     */
    public function testToolsCacheSetWhenToolsTokensAboveThresholdAndNoSystem(): void
    {
        $user = (new UserMessage('使用工具'))->setTokenEstimate(100);
        $request = new ChatCompletionRequest([$user], 'claude-3');
        // 通过 reflection 直接设置 toolsTokenEstimate，模拟 tools 存在
        $this->setNonpublicPropertyValue($request, 'toolsTokenEstimate', 600);

        $this->strategy->apply($this->config, $request);

        $this->assertTrue($request->isToolsCache(), '无 system 且 tools token 足够时应设置 tools 缓存');
    }
}
