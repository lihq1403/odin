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

        return 'openai'; // 默认为 OpenAI
    }
}
