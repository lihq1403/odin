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

use Hyperf\Odin\Exception\LLMException\Model\LLMUnsupportedImageFormatException;
use Hyperf\Odin\Exception\LLMException\Model\LLMUnsupportedVideoFormatException;
use Hyperf\Odin\Message\UserMessage;
use Hyperf\Odin\Message\UserMessageContent;

/**
 * Simple validator for vision and video understanding messages.
 *
 * 视觉与视频理解消息的简单验证器。
 */
class VisionMessageValidator
{
    /**
     * Validate images and videos in a single user message.
     *
     * 验证单个用户消息中的图片和视频。
     *
     * @param UserMessage $message User message to validate
     * @throws LLMUnsupportedImageFormatException
     * @throws LLMUnsupportedVideoFormatException
     */
    public static function validateUserMessage(UserMessage $message): void
    {
        $contents = $message->getContents();

        // No contents to validate
        if (empty($contents)) {
            return;
        }

        foreach ($contents as $content) {
            if ($content->getType() === UserMessageContent::IMAGE_URL) {
                $imageUrl = $content->getImageUrl();
                if (! empty($imageUrl)) {
                    ImageFormatValidator::validateImageUrl($imageUrl);
                }
            } elseif ($content->getType() === UserMessageContent::VIDEO_URL) {
                $videoUrl = $content->getVideoUrl();
                if (! empty($videoUrl)) {
                    VideoFormatValidator::validateVideoUrl($videoUrl);
                }
            }
        }
    }
}
