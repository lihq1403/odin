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

namespace Hyperf\Odin\Message;

class UserMessageContent
{
    public const TEXT = 'text';

    public const IMAGE_URL = 'image_url';

    public const VIDEO_URL = 'video_url';

    private string $type;

    private string $text = '';

    /**
     * 可以是链接，可以是 base64.
     */
    private string $imageUrl = '';

    /**
     * 可以是公网 URL，也可以是 base64（data:video/...;base64,...）.
     */
    private string $videoUrl = '';

    /**
     * 视频帧采样率，非必填，为 null 时不传给 API.
     */
    private ?float $fps = null;

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function text(string $text): self
    {
        return (new self(self::TEXT))->setText($text);
    }

    public static function imageUrl(string $url): self
    {
        return (new self(self::IMAGE_URL))->setImageUrl($url);
    }

    /**
     * 创建视频 URL 内容块.
     *
     * @param string $url 公网视频 URL 或 base64 编码（data:video/...;base64,...）
     * @param null|float $fps 视频帧采样率，不传则由模型自动决定
     */
    public static function videoUrl(string $url, ?float $fps = null): self
    {
        $instance = (new self(self::VIDEO_URL))->setVideoUrl($url);
        if ($fps !== null) {
            $instance->setFps($fps);
        }
        return $instance;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): self
    {
        $this->text = trim($text);
        return $this;
    }

    public function getImageUrl(): string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = trim($imageUrl);
        return $this;
    }

    public function getVideoUrl(): string
    {
        return $this->videoUrl;
    }

    public function setVideoUrl(string $videoUrl): self
    {
        $this->videoUrl = trim($videoUrl);
        return $this;
    }

    public function getFps(): ?float
    {
        return $this->fps;
    }

    public function setFps(?float $fps): self
    {
        $this->fps = $fps;
        return $this;
    }

    public function isValid(): bool
    {
        return match ($this->type) {
            self::TEXT => $this->text !== '',
            self::IMAGE_URL => $this->imageUrl !== '',
            self::VIDEO_URL => $this->videoUrl !== '',
            default => false,
        };
    }

    public function toArray(): array
    {
        return match ($this->type) {
            self::TEXT => [
                'type' => 'text',
                'text' => $this->text,
            ],
            self::IMAGE_URL => [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $this->imageUrl,
                ],
            ],
            self::VIDEO_URL => [
                'type' => 'video_url',
                'video_url' => array_filter(
                    [
                        'url' => $this->videoUrl,
                        'fps' => $this->fps,
                    ],
                    fn ($v) => $v !== null
                ),
            ],
            default => [],
        };
    }
}
