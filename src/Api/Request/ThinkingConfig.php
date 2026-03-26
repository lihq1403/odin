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

namespace Hyperf\Odin\Api\Request;

/**
 * 统一的思考参数值对象。
 *
 * 各服务商原生格式差异较大，本类提供统一入口，内部负责向各服务商格式转换：
 * - AWS Bedrock / OpenAI-Claude 兼容端点：type + budget_tokens
 * - Gemini gemini-2.x：thinkingBudget
 * - Gemini 旧模型：includeThoughts + thinkingLevel（level 字段仅此处生效）
 * - 其他 OpenAI 兼容服务商（DeepSeek/DashScope/Doubao 等）：type + budget_tokens 透传
 */
class ThinkingConfig
{
    /**
     * @param bool $enabled 是否开启思考模式
     * @param null|int $budgetTokens 思考 token 预算（null 表示不限制）
     * @param string $level 思考深度，仅对 Gemini 旧模型（非 gemini-2.x）生效，取值 low/high，默认 low
     */
    private function __construct(
        private readonly bool $enabled,
        private readonly ?int $budgetTokens = null,
        private readonly string $level = 'low',
    ) {}

    /**
     * 创建启用思考模式的配置。
     *
     * @param int $budgetTokens 思考 token 预算
     * @param string $level 思考深度，仅对 Gemini 旧模型生效，取值 low/high，默认 low
     */
    public static function enabled(int $budgetTokens = -1, string $level = 'low'): self
    {
        return new self(true, $budgetTokens, $level);
    }

    /**
     * 创建禁用思考模式的配置。
     */
    public static function disabled(): self
    {
        return new self(false);
    }

    /**
     * 从旧版裸数组格式构建（向后兼容）。
     *
     * 兼容以下格式：
     * - AWS Bedrock/OpenAI: ['type' => 'enabled'|'disabled', 'budget_tokens' => int]
     * - Gemini: ['thinking_budget' => int, 'level' => 'HIGH'|'LOW'|'high'|'low']
     */
    public static function fromArray(array $thinking): self
    {
        // 判断是否禁用
        if (isset($thinking['type']) && $thinking['type'] === 'disabled') {
            return self::disabled();
        }

        // 解析 budget_tokens（Bedrock/OpenAI 格式）或 thinking_budget（Gemini 旧格式）
        $budgetTokens = $thinking['budget_tokens'] ?? $thinking['thinking_budget'] ?? null;

        // 解析 level（Gemini 旧模型专用）
        $level = isset($thinking['level']) ? strtolower((string) $thinking['level']) : 'low';
        if (! in_array($level, ['low', 'high'], true)) {
            $level = 'low';
        }

        if ($budgetTokens !== null) {
            return self::enabled((int) $budgetTokens, $level);
        }

        // 有 type=enabled 但没有 budget_tokens，视为启用但不限制 token
        if (isset($thinking['type']) && $thinking['type'] === 'enabled') {
            return new self(true, null, $level);
        }

        // 兜底：有数组但无法识别格式，视为禁用
        return self::disabled();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getBudgetTokens(): ?int
    {
        return $this->budgetTokens;
    }

    /**
     * 获取思考深度。
     * 仅对 Gemini 旧模型（非 gemini-2.x）生效，其他服务商忽略此字段。
     */
    public function getLevel(): string
    {
        return $this->level;
    }

    /**
     * 转换为 AWS Bedrock / OpenAI-Claude 兼容格式。
     *
     * 输出：['type' => 'enabled'|'disabled', 'budget_tokens' => int]
     *
     * budgetTokens 为 null 或 -1 时使用最小有效值 1024（Bedrock 要求正整数）。
     */
    public function toBedrockFormat(): array
    {
        if (! $this->enabled) {
            return ['type' => 'disabled'];
        }

        $budget = ($this->budgetTokens === null || $this->budgetTokens === -1)
            ? 1024
            : $this->budgetTokens;

        return ['type' => 'enabled', 'budget_tokens' => $budget];
    }

    /**
     * 转换为 Gemini generationConfig.thinkingConfig 格式。
     *
     * - gemini-2.x：thinkingBudget（数值，-1 表示动态分配）
     * - gemini-3.x 及更新版本：仅 thinkingLevel（小写），无需其他字段
     *
     * @param string $model 模型名称，用于区分版本
     */
    public function toGeminiFormat(string $model): array
    {
        if (! $this->enabled) {
            return [];
        }

        // 去除 models/ 前缀
        $normalizedModel = str_starts_with($model, 'models/') ? substr($model, strlen('models/')) : $model;

        if (str_starts_with($normalizedModel, 'gemini-2')) {
            // gemini-2.x：使用 thinkingBudget，-1 表示动态分配
            $budget = $this->budgetTokens ?? -1;
            return ['thinkingBudget' => $budget];
        }

        // gemini-3.x 及更新版本：仅需 thinkingLevel（小写）
        $level = strtolower($this->level);
        if (! in_array($level, ['high', 'medium', 'low'], true)) {
            $level = 'low';
        }
        return ['thinkingLevel' => $level];
    }
}
