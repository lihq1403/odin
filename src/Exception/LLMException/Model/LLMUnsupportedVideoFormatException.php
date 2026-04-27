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

namespace Hyperf\Odin\Exception\LLMException\Model;

use Hyperf\Odin\Exception\LLMException\ErrorMessage;
use Hyperf\Odin\Exception\LLMException\LLMModelException;
use Throwable;

/**
 * Exception thrown when an unsupported video format is used in video understanding requests.
 *
 * 当在视频理解请求中使用不支持的视频格式时抛出的异常。
 */
class LLMUnsupportedVideoFormatException extends LLMModelException
{
    /**
     * 错误码，基于模型错误基数.
     */
    private const ERROR_CODE = 13;

    /**
     * The unsupported file extension.
     */
    protected ?string $fileExtension = null;

    /**
     * The video URL that caused the error.
     */
    protected ?string $videoUrl = null;

    /**
     * The unsupported content type.
     */
    protected ?string $contentType = null;

    /**
     * Create a new unsupported video format exception.
     *
     * @param string $message Exception message
     * @param null|Throwable $previous Previous exception
     * @param null|string $fileExtension The unsupported file extension
     * @param null|string $videoUrl The video URL that caused the error
     * @param null|string $contentType The unsupported content type
     * @param int $statusCode HTTP status code
     */
    public function __construct(
        string $message = ErrorMessage::UNSUPPORTED_VIDEO_FORMAT,
        ?Throwable $previous = null,
        ?string $fileExtension = null,
        ?string $videoUrl = null,
        ?string $contentType = null,
        int $statusCode = 400
    ) {
        $this->fileExtension = $fileExtension;
        $this->videoUrl = $videoUrl;
        $this->contentType = $contentType;

        parent::__construct($message, self::ERROR_CODE, $previous, 0, null, $statusCode);
    }

    /**
     * Get the unsupported file extension.
     */
    public function getFileExtension(): ?string
    {
        return $this->fileExtension;
    }

    /**
     * Get the video URL that caused the error.
     */
    public function getVideoUrl(): ?string
    {
        return $this->videoUrl;
    }

    /**
     * Get the unsupported content type.
     */
    public function getContentType(): ?string
    {
        return $this->contentType;
    }
}
