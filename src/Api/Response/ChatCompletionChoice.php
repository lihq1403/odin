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

namespace Hyperf\Odin\Api\Response;

use Hyperf\Odin\Contract\Message\MessageInterface;
use Hyperf\Odin\Utils\MessageUtil;

class ChatCompletionChoice
{
    public function __construct(
        public MessageInterface $message,
        public ?int $index = null,
        public ?string $logprobs = null,
        public ?string $finishReason = null
    ) {}

    public static function fromArray(array $choice): self
    {
        $message = $choice['message'] ?? [];
        if (isset($choice['delta'])) {
            $delta = $choice['delta'];
            // OpenRouter / mimo 等流式帧常用 delta.reasoning 增量推送；与非空 reasoning_content 冲突时保留后者
            $reasoningContent = $delta['reasoning_content'] ?? null;
            if (($reasoningContent === null || (is_string($reasoningContent) && trim($reasoningContent) === ''))
                && isset($delta['reasoning']) && is_string($delta['reasoning']) && trim($delta['reasoning']) !== '') {
                $reasoningContent = $delta['reasoning'];
            }
            $message = [
                'role' => $delta['role'] ?? 'assistant',
                'content' => $delta['content'] ?? '',
                'reasoning_content' => $reasoningContent,
                'tool_calls' => $delta['tool_calls'] ?? [],
            ];
            // 透传 reasoning_details（Gemini OpenAI 兼容格式），用于多轮时回传签名
            if (isset($delta['reasoning_details']) && is_array($delta['reasoning_details'])) {
                $message['reasoning_details'] = $delta['reasoning_details'];
            }
        }

        return new self(MessageUtil::createFromArray($message), $choice['index'] ?? null, $choice['logprobs'] ?? null, $choice['finish_reason'] ?? null);
    }

    public function getMessage(): MessageInterface
    {
        return $this->message;
    }

    public function getIndex(): ?int
    {
        return $this->index;
    }

    public function getLogprobs(): ?string
    {
        return $this->logprobs;
    }

    public function getFinishReason(): ?string
    {
        return $this->normalizeFinishReason($this->finishReason);
    }

    public function isFinishedByToolCall(): bool
    {
        return $this->getFinishReason() === 'tool_calls';
    }

    public function setMessage(MessageInterface $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function setIndex(?int $index): self
    {
        $this->index = $index;
        return $this;
    }

    public function setLogprobs(?string $logprobs): self
    {
        $this->logprobs = $logprobs;
        return $this;
    }

    public function setFinishReason(?string $finishReason): self
    {
        $this->finishReason = $finishReason;
        return $this;
    }

    /**
     * 将不同LLM提供商的finish_reason值映射为OpenAI标准值
     */
    private function normalizeFinishReason(?string $finishReason): ?string
    {
        if ($finishReason === null) {
            return null;
        }

        return match ($finishReason) {
            'tool_use' => 'tool_calls',      // Claude: 工具调用
            'end_turn', 'stop_sequence' => 'stop',            // Claude: 正常结束// 停止序列
            'max_tokens' => 'length',        // 长度限制
            default => $finishReason,        // 保持其他值不变
        };
    }
}
