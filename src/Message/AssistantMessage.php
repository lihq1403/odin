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

use Hyperf\Odin\Api\Response\ToolCall;

/**
 * 助手消息类.
 *
 * 用于表示AI助手的回复，可包含内容、工具调用和推理过程
 * content 支持 OpenAI 最新格式：string 或 array of ContentPart (text/refusal)
 */
class AssistantMessage extends AbstractMessage
{
    /**
     * 角色固定为助手.
     */
    protected Role $role = Role::Assistant;

    /**
     * 工具调用列表.
     *
     * @var ToolCall[]
     */
    protected array $toolCalls = [];

    /**
     * 推理内容
     * 用于表示LLM的推理过程，非输出内容的一部分.
     */
    protected ?string $reasoningContent = null;

    /**
     * reasoning_details 原始数组（Gemini OpenAI 兼容格式）.
     * 存储 API 返回的 reasoning_details，多轮请求时原样回传以保留推理上下文.
     *
     * @var null|array<array{type: string, text?: string, signature?: string}>
     */
    protected ?array $reasoningDetails = null;

    /**
     * content 的 array 格式（OpenAI API）
     * 当非 null 时，toArray 输出 array 格式；getContent 从其中提取文本.
     *
     * @var null|array<array{type: string, text?: string, refusal?: string}>
     */
    protected ?array $contentParts = null;

    /**
     * 构造函数.
     *
     * @param string $content 消息内容
     * @param array<ToolCall> $toolsCall 工具调用列表
     * @param null|string $reasoningContent 推理内容
     */
    public function __construct(string $content, array $toolsCall = [], ?string $reasoningContent = null)
    {
        parent::__construct($content);
        $this->toolCalls = $this->normalizeToolCallIds($toolsCall);
        $this->reasoningContent = $reasoningContent;
    }

    /**
     * 从数组创建消息实例.
     *
     * @param array $message 消息数组，content 可为 string 或 array（OpenAI 格式）
     */
    public static function fromArray(array $message): self
    {
        $content = $message['content'] ?? '';
        $toolCalls = ToolCall::fromArray($message['tool_calls'] ?? []);
        $reasoningContent = $message['reasoning_content'] ?? null;
        // OpenRouter 等会在 message.reasoning 中直接返回思考文本（可能与 reasoning_details 重复或仅有其一）
        if (self::isReasoningContentEmpty($reasoningContent) && isset($message['reasoning']) && is_string($message['reasoning'])) {
            $trimmed = trim($message['reasoning']);
            if ($trimmed !== '') {
                $reasoningContent = $message['reasoning'];
            }
        }
        $reasoningDetails = isset($message['reasoning_details']) && is_array($message['reasoning_details'])
            ? $message['reasoning_details']
            : null;

        $contentParts = null;
        if (is_array($content) && ! empty($content)) {
            $contentParts = $content;
            $contentString = self::extractTextFromContentParts($content);
        } else {
            $contentString = is_string($content) ? $content : '';
        }

        $instance = new self($contentString, $toolCalls, $reasoningContent);
        $instance->reasoningDetails = $reasoningDetails;
        if ($contentParts !== null) {
            $instance->contentParts = $contentParts;
        }

        // 部分服务商仅在 message.reasoning_details 中返回推理文本（如 type 为 reasoning.text），统一汇总到 reasoning_content
        if ($reasoningDetails !== null && self::isReasoningContentEmpty($instance->reasoningContent)) {
            $extracted = self::extractReasoningTextFromDetails($reasoningDetails);
            if ($extracted !== '') {
                $instance->reasoningContent = $extracted;
            }
        }

        return $instance;
    }

    /**
     * 转换为数组.
     *
     * @return array 消息数组表示，content 保持 OpenAI 格式（string 或 array）
     */
    public function toArray(): array
    {
        $toolCalls = [];
        foreach ($this->toolCalls as $toolCall) {
            $toolCalls[] = $toolCall->toArray();
        }
        $content = $this->contentParts !== null ? $this->contentParts : $this->content;

        // 有缓存点时，由 CachePoint 负责将 content 包装为带 cache_control 的内容块（OpenRouter/Anthropic 格式）
        if ($this->cachePoint !== null) {
            $content = $this->cachePoint->wrapContentWithCacheControl($content);
        }

        $result = [
            'role' => $this->role->value,
            'content' => $content,
        ];
        if (! is_null($this->reasoningContent)) {
            $result['reasoning_content'] = $this->reasoningContent;
        }
        // 多轮时原样回传 reasoning_details，保留 Gemini 推理上下文签名
        if (! is_null($this->reasoningDetails)) {
            $result['reasoning_details'] = $this->reasoningDetails;
        }
        if (! empty($toolCalls)) {
            $result['tool_calls'] = $toolCalls;
        }
        return $result;
    }

    public function toArrayWithStream(): array
    {
        $toolCalls = [];
        foreach ($this->toolCalls as $toolCall) {
            $toolCalls[] = $toolCall->toArrayWithStream();
        }
        $content = $this->contentParts !== null ? $this->contentParts : $this->content;
        $result = [
            'role' => $this->role->value,
            'content' => $content,
        ];
        if (! is_null($this->reasoningContent)) {
            $result['reasoning_content'] = $this->reasoningContent;
        }
        if (! is_null($this->reasoningDetails)) {
            $result['reasoning_details'] = $this->reasoningDetails;
        }
        if (! empty($toolCalls)) {
            $result['tool_calls'] = $toolCalls;
        }
        return $result;
    }

    /**
     * 获取消息内容（字符串形式）.
     *
     * @return string 消息内容文本
     */
    public function getContent(): string
    {
        if ($this->contentParts !== null) {
            return self::extractTextFromContentParts($this->contentParts);
        }
        return $this->content;
    }

    /**
     * 设置消息内容为字符串时，清空 contentParts 以保持一致性.
     */
    public function setContent(string $content): self
    {
        $this->contentParts = null;
        parent::setContent($content);
        return $this;
    }

    /**
     * 是否有工具调用.
     */
    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    /**
     * 获取工具调用列表.
     *
     * @return array<ToolCall> 工具调用列表
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * 设置工具调用列表.
     *
     * @param array $toolCalls 工具调用列表
     * @return static 支持链式调用
     */
    public function setToolCalls(array $toolCalls): self
    {
        $this->toolCalls = $toolCalls;
        return $this;
    }

    /**
     * 获取推理内容.
     *
     * @return null|string 推理内容
     */
    public function getReasoningContent(): ?string
    {
        return $this->reasoningContent;
    }

    /**
     * 是否有推理内容.
     */
    public function hasReasoningContent(): bool
    {
        return ! is_null($this->reasoningContent);
    }

    /**
     * 设置推理内容.
     *
     * @param null|string $reasoningContent 推理内容
     * @return static 支持链式调用
     */
    public function setReasoningContent(?string $reasoningContent): self
    {
        $this->reasoningContent = $reasoningContent;
        return $this;
    }

    /**
     * 获取 reasoning_details 原始数组.
     *
     * @return null|array<array{type: string, text?: string, signature?: string}>
     */
    public function getReasoningDetails(): ?array
    {
        return $this->reasoningDetails;
    }

    /**
     * 设置 reasoning_details 原始数组.
     *
     * @param null|array<array{type: string, text?: string, signature?: string}> $reasoningDetails
     */
    public function setReasoningDetails(?array $reasoningDetails): self
    {
        $this->reasoningDetails = $reasoningDetails;
        return $this;
    }

    /**
     * 是否存在 reasoning_details（用于多轮请求时判断是否需要回传签名）.
     */
    public function hasReasoningDetails(): bool
    {
        return ! empty($this->reasoningDetails);
    }

    /**
     * 从 content parts array 提取文本.
     *
     * @param array<array{type?: string, text?: string, refusal?: string}> $contentParts
     */
    private static function extractTextFromContentParts(array $contentParts): string
    {
        $parts = [];
        foreach ($contentParts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $type = $part['type'] ?? null;
            if ($type === 'text' && isset($part['text'])) {
                $parts[] = $part['text'];
            } elseif ($type === 'refusal' && isset($part['refusal'])) {
                $parts[] = $part['refusal'];
            }
        }
        return implode('', $parts);
    }

    /**
     * 从 reasoning_details 中收集可见推理文本（逐项的 text 字段）.
     *
     * @param array<int, mixed> $reasoningDetails
     */
    private static function extractReasoningTextFromDetails(array $reasoningDetails): string
    {
        $parts = [];
        foreach ($reasoningDetails as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! isset($item['text']) || ! is_string($item['text'])) {
                continue;
            }
            if (trim($item['text']) === '') {
                continue;
            }
            $parts[] = $item['text'];
        }

        return implode("\n\n", $parts);
    }

    private static function isReasoningContentEmpty(?string $reasoningContent): bool
    {
        if ($reasoningContent === null) {
            return true;
        }

        return trim($reasoningContent) === '';
    }

    /**
     * 标准化 tool call IDs 以确保跨平台兼容性.
     *
     * @param array<ToolCall> $toolCalls 原始工具调用列表
     * @return array<ToolCall> 标准化后的工具调用列表
     */
    private function normalizeToolCallIds(array $toolCalls): array
    {
        foreach ($toolCalls as $toolCall) {
            $originalId = $toolCall->getId();
            $normalizedId = $this->normalizeToolCallId($originalId);

            if ($normalizedId !== $originalId) {
                $toolCall->setId($normalizedId);
            }
        }

        return $toolCalls;
    }
}
