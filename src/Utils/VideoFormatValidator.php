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

use Hyperf\Odin\Exception\LLMException\Model\LLMUnsupportedVideoFormatException;

/**
 * Simple video format validator for video understanding requests.
 *
 * 视频理解请求的简单视频格式验证器。
 */
class VideoFormatValidator
{
    /**
     * Supported video file extensions.
     *
     * @var string[]
     */
    private static array $supportedExtensions = [
        'mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v', '3gp', 'ts', 'mpeg', 'mpg',
    ];

    /**
     * Validate video URL format.
     * Only validates URLs that have file extensions.
     *
     * 验证视频URL格式。
     * 只验证有文件扩展名的URL。
     *
     * @param string $videoUrl The video URL to validate
     * @throws LLMUnsupportedVideoFormatException When extension exists but is not supported
     */
    public static function validateVideoUrl(string $videoUrl): void
    {
        // data URL（base64）跳过扩展名校验
        if (str_starts_with($videoUrl, 'data:')) {
            return;
        }

        $urlPath = parse_url($videoUrl, PHP_URL_PATH);
        if (! $urlPath) {
            return;
        }

        $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

        // 无扩展名时不报错
        if (empty($extension)) {
            return;
        }

        // 扩展名存在但不在支持列表中时抛出异常
        if (! in_array($extension, self::$supportedExtensions, true)) {
            throw new LLMUnsupportedVideoFormatException(
                sprintf('不支持的视频格式: .%s', $extension),
                null,
                $extension,
                $videoUrl
            );
        }
    }

    /**
     * Get all supported file extensions.
     *
     * 获取所有支持的文件扩展名。
     *
     * @return string[] Array of supported file extensions
     */
    public static function getSupportedExtensions(): array
    {
        return self::$supportedExtensions;
    }
}
