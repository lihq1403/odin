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

use Hyperf\Odin\Exception\InvalidArgumentException;

/**
 * 多模态嵌入输入单元，支持文本、图片、视频三种类型.
 */
class MultiModalEmbeddingItem
{
    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    private function __construct(
        private readonly string $type,
        private readonly ?string $text = null,
        private readonly ?string $imageUrl = null,
        private readonly ?string $videoUrl = null,
    ) {}

    /**
     * 创建文本输入单元.
     */
    public static function text(string $text): self
    {
        return new self(type: self::TYPE_TEXT, text: $text);
    }

    /**
     * 创建图片输入单元.
     */
    public static function image(string $url): self
    {
        return new self(type: self::TYPE_IMAGE, imageUrl: $url);
    }

    /**
     * 创建视频输入单元.
     */
    public static function video(string $url): self
    {
        return new self(type: self::TYPE_VIDEO, videoUrl: $url);
    }

    /**
     * 获取类型：'text' | 'image' | 'video'.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * 获取文本内容（仅 type=text 时有值）.
     */
    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * 获取图片 URL（仅 type=image 时有值）.
     */
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    /**
     * 获取视频 URL（仅 type=video 时有值）.
     */
    public function getVideoUrl(): ?string
    {
        return $this->videoUrl;
    }

    /**
     * 判断是否为纯文本类型.
     */
    public function isTextOnly(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }

    /**
     * 验证数据完整性.
     */
    public function validate(): void
    {
        match ($this->type) {
            self::TYPE_TEXT => $this->text !== null && $this->text !== ''
                ? null
                : throw new InvalidArgumentException('Text embedding item requires non-empty text.'),
            self::TYPE_IMAGE => $this->imageUrl !== null && $this->imageUrl !== ''
                ? null
                : throw new InvalidArgumentException('Image embedding item requires non-empty image URL.'),
            self::TYPE_VIDEO => $this->videoUrl !== null && $this->videoUrl !== ''
                ? null
                : throw new InvalidArgumentException('Video embedding item requires non-empty video URL.'),
            default => throw new InvalidArgumentException("Unknown embedding item type: {$this->type}"),
        };
    }
}
