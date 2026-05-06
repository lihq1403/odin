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

namespace Hyperf\Odin\Utils;

/**
 * 模型相关的工具类.
 */
class ModelUtil
{
    /**
     * 检查是否为qwen系列模型.
     */
    public static function isQwenModel(string $model): bool
    {
        return str_contains(strtolower($model), 'qwen');
    }

    /**
     * 检查是否为deepseek系列模型.
     */
    public static function isDeepSeekModel(string $model): bool
    {
        return str_contains(strtolower($model), 'deepseek');
    }

    /**
     * 检查是否为kimi系列模型.
     */
    public static function isKimiModel(string $model): bool
    {
        return str_contains(strtolower($model), 'kimi');
    }

    /**
     * 检查是否为 Doubao 系列模型.
     * 匹配 doubao- 前缀，以及 ep- 前缀的火山方舟 Endpoint ID 格式.
     */
    public static function isDoubaoModel(string $model): bool
    {
        $lower = strtolower($model);
        return str_starts_with($lower, 'doubao-') || str_starts_with($lower, 'ep-');
    }

    /**
     * 检查是否为 Claude 系列模型.
     * 除标准 "claude" 前缀外，也匹配 sonnet / opus / haiku 等 Claude 专属系列名，
     * 以兼容 MaaS_Cl_Sonnet_4.6_20260217 这类自定义命名格式.
     */
    public static function isClaudeModel(string $model): bool
    {
        $lower = strtolower($model);
        return str_contains($lower, 'claude')
            || str_contains($lower, 'sonnet')
            || str_contains($lower, 'opus')
            || str_contains($lower, 'haiku');
    }

    /**
     * 获取模型提供商类型.
     *
     * @return string 返回 'dashscope'、'openai'、'deepseek' 等提供商标识
     */
    public static function getProviderType(string $model): string
    {
        if (self::isQwenModel($model)) {
            return 'dashscope';
        }

        if (self::isDeepSeekModel($model)) {
            return 'deepseek';
        }

        if (self::isKimiModel($model)) {
            return 'kimi';
        }

        if (self::isDoubaoModel($model)) {
            return 'doubao';
        }

        return 'openai'; // 默认为 OpenAI
    }
}
