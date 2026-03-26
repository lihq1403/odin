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

namespace HyperfTest\Odin\Cases\Api\Request;

use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Api\Request\ThinkingConfig;
use Hyperf\Odin\Message\UserMessage;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ThinkingConfigTest extends TestCase
{
    // -----------------------------------------------------------------------
    // 工厂方法
    // -----------------------------------------------------------------------

    public function testEnabledFactory(): void
    {
        $config = ThinkingConfig::enabled(4000);

        $this->assertTrue($config->isEnabled());
        $this->assertSame(4000, $config->getBudgetTokens());
        $this->assertSame('low', $config->getLevel());
    }

    public function testEnabledFactoryWithCustomLevel(): void
    {
        $config = ThinkingConfig::enabled(2000, 'high');

        $this->assertTrue($config->isEnabled());
        $this->assertSame(2000, $config->getBudgetTokens());
        $this->assertSame('high', $config->getLevel());
    }

    public function testDisabledFactory(): void
    {
        $config = ThinkingConfig::disabled();

        $this->assertFalse($config->isEnabled());
        $this->assertNull($config->getBudgetTokens());
    }

    // -----------------------------------------------------------------------
    // toBedrockFormat
    // -----------------------------------------------------------------------

    public function testToBedrockFormatEnabled(): void
    {
        $config = ThinkingConfig::enabled(4000);
        $format = $config->toBedrockFormat();

        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 4000], $format);
    }

    public function testToBedrockFormatDisabled(): void
    {
        $config = ThinkingConfig::disabled();
        $format = $config->toBedrockFormat();

        $this->assertSame(['type' => 'disabled'], $format);
    }

    public function testToBedrockFormatEnabledWithoutBudgetDefaultsTo1024(): void
    {
        // 未指定 budget_tokens 时，Bedrock 格式使用最小有效值 1024
        $config = ThinkingConfig::fromArray(['type' => 'enabled']);
        $format = $config->toBedrockFormat();

        $this->assertSame('enabled', $format['type']);
        $this->assertSame(1024, $format['budget_tokens']);
    }

    public function testToBedrockFormatMinusOneBudgetDefaultsTo1024(): void
    {
        // -1 是 Gemini 的动态分配标记，转为 Bedrock 格式时自动替换为 1024
        $config = ThinkingConfig::enabled();
        $format = $config->toBedrockFormat();

        $this->assertSame('enabled', $format['type']);
        $this->assertSame(1024, $format['budget_tokens']);
    }

    // -----------------------------------------------------------------------
    // toGeminiFormat - gemini-2.x 模型
    // -----------------------------------------------------------------------

    public function testToGeminiFormatGemini2WithBudget(): void
    {
        $config = ThinkingConfig::enabled(5000);
        $format = $config->toGeminiFormat('gemini-2.0-flash');

        $this->assertSame(['thinkingBudget' => 5000], $format);
    }

    public function testToGeminiFormatGemini2DynamicBudget(): void
    {
        $config = ThinkingConfig::enabled(-1);
        $format = $config->toGeminiFormat('gemini-2.5-pro');

        $this->assertSame(['thinkingBudget' => -1], $format);
    }

    public function testToGeminiFormatGemini2WithModelsPrefix(): void
    {
        $config = ThinkingConfig::enabled(3000);
        $format = $config->toGeminiFormat('models/gemini-2.0-flash');

        $this->assertSame(['thinkingBudget' => 3000], $format);
    }

    // -----------------------------------------------------------------------
    // toGeminiFormat - 旧版 Gemini 模型（非 gemini-2.x）
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // toGeminiFormat - gemini-3.x 模型
    // -----------------------------------------------------------------------

    public function testToGeminiFormatGemini3DefaultLevel(): void
    {
        $config = ThinkingConfig::enabled();
        $format = $config->toGeminiFormat('gemini-3-flash-preview');

        $this->assertSame(['thinkingLevel' => 'low'], $format);
    }

    public function testToGeminiFormatGemini3HighLevel(): void
    {
        $config = ThinkingConfig::enabled(1000, 'high');
        $format = $config->toGeminiFormat('gemini-3-pro-preview');

        $this->assertSame(['thinkingLevel' => 'high'], $format);
    }

    public function testToGeminiFormatGemini3MediumLevel(): void
    {
        $config = ThinkingConfig::enabled(1000, 'medium');
        $format = $config->toGeminiFormat('gemini-3-pro-preview');

        $this->assertSame(['thinkingLevel' => 'medium'], $format);
    }

    public function testToGeminiFormatGemini3NoIncludeThoughts(): void
    {
        // gemini-3 不应包含 includeThoughts 字段
        $config = ThinkingConfig::enabled(1000, 'high');
        $format = $config->toGeminiFormat('gemini-3-flash-preview');

        $this->assertArrayNotHasKey('includeThoughts', $format);
        $this->assertArrayNotHasKey('thinkingBudget', $format);
    }

    public function testToGeminiFormatDisabledReturnsEmpty(): void
    {
        $config = ThinkingConfig::disabled();
        $format = $config->toGeminiFormat('gemini-2.0-flash');

        $this->assertSame([], $format);
    }

    // -----------------------------------------------------------------------
    // fromArray 向后兼容
    // -----------------------------------------------------------------------

    public function testFromArrayBedrockEnabledFormat(): void
    {
        $config = ThinkingConfig::fromArray(['type' => 'enabled', 'budget_tokens' => 4000]);

        $this->assertTrue($config->isEnabled());
        $this->assertSame(4000, $config->getBudgetTokens());
    }

    public function testFromArrayBedrockDisabledFormat(): void
    {
        $config = ThinkingConfig::fromArray(['type' => 'disabled']);

        $this->assertFalse($config->isEnabled());
    }

    public function testFromArrayGeminiLegacyFormat(): void
    {
        $config = ThinkingConfig::fromArray(['thinking_budget' => 3000, 'level' => 'HIGH']);

        $this->assertTrue($config->isEnabled());
        $this->assertSame(3000, $config->getBudgetTokens());
        $this->assertSame('high', $config->getLevel());
    }

    public function testFromArrayGeminiLegacyFormatLowercaseLevel(): void
    {
        $config = ThinkingConfig::fromArray(['thinking_budget' => 1000, 'level' => 'low']);

        $this->assertTrue($config->isEnabled());
        $this->assertSame('low', $config->getLevel());
    }

    public function testFromArrayInvalidLevelFallsBackToLow(): void
    {
        $config = ThinkingConfig::fromArray(['budget_tokens' => 500, 'level' => 'MEDIUM']);

        $this->assertSame('low', $config->getLevel());
    }

    public function testFromArrayEnabledWithoutBudget(): void
    {
        $config = ThinkingConfig::fromArray(['type' => 'enabled']);

        $this->assertTrue($config->isEnabled());
        $this->assertNull($config->getBudgetTokens());
    }

    public function testFromArrayUnrecognizedFormatFallsBackToDisabled(): void
    {
        $config = ThinkingConfig::fromArray(['foo' => 'bar']);

        $this->assertFalse($config->isEnabled());
    }

    // -----------------------------------------------------------------------
    // ChatCompletionRequest 中的兼容性（array 自动转换）
    // -----------------------------------------------------------------------

    public function testSetThinkingWithArrayIsCompatible(): void
    {
        $request = new ChatCompletionRequest(
            [new UserMessage('hi')],
            'test-model'
        );

        $request->setThinking(['type' => 'enabled', 'budget_tokens' => 2000]);

        $thinking = $request->getThinking();
        $this->assertInstanceOf(ThinkingConfig::class, $thinking);
        $this->assertTrue($thinking->isEnabled());
        $this->assertSame(2000, $thinking->getBudgetTokens());
    }

    public function testSetThinkingWithNullClearsConfig(): void
    {
        $request = new ChatCompletionRequest(
            [new UserMessage('hi')],
            'test-model'
        );

        $request->setThinking(ThinkingConfig::enabled(1000));
        $request->setThinking(null);

        $this->assertNull($request->getThinking());
    }
}
