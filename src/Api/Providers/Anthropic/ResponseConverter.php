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

namespace Hyperf\Odin\Api\Providers\Anthropic;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * 将 Anthropic Messages API 响应转换为 OpenAI 兼容格式（PSR-7 Response）.
 *
 * Anthropic 原始响应结构：
 * {
 *   "id": "msg_xxx",
 *   "type": "message",
 *   "role": "assistant",
 *   "content": [
 *     {"type": "thinking", "thinking": "..."},
 *     {"type": "text", "text": "..."},
 *     {"type": "tool_use", "id": "...", "name": "...", "input": {...}}
 *   ],
 *   "stop_reason": "end_turn" | "max_tokens" | "stop_sequence" | "tool_use",
 *   "usage": {
 *     "input_tokens": 10,
 *     "output_tokens": 5,
 *     "cache_creation_input_tokens": 1000,
 *     "cache_read_input_tokens": 0
 *   }
 * }
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
class ResponseConverter
{
    /**
     * 将 Anthropic 响应体数组转换为 OpenAI 格式的 PSR-7 Response.
     *
     * @param array $anthropicResponse Anthropic 原始响应（json_decode 后的数组）
     */
    public static function convert(array $anthropicResponse): ResponseInterface
    {
        $openAIResponse = [
            'id' => $anthropicResponse['id'] ?? self::generateId(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $anthropicResponse['model'] ?? '',
            'choices' => [self::convertToChoice($anthropicResponse)],
            'usage' => self::convertUsage($anthropicResponse['usage'] ?? []),
        ];

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($openAIResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * 将 Anthropic 响应转换为 OpenAI choice 结构.
     */
    private static function convertToChoice(array $anthropicResponse): array
    {
        $contentBlocks = $anthropicResponse['content'] ?? [];
        $stopReason = $anthropicResponse['stop_reason'] ?? 'end_turn';

        $message = self::convertContentBlocks($contentBlocks);

        return [
            'index' => 0,
            'message' => $message,
            'finish_reason' => self::convertStopReason($stopReason),
        ];
    }

    /**
     * 将 Anthropic content blocks 数组转换为 OpenAI message 结构.
     *
     * @param array $contentBlocks Anthropic content blocks
     */
    private static function convertContentBlocks(array $contentBlocks): array
    {
        $textParts = [];
        $reasoningParts = [];
        $toolCalls = [];
        $toolCallIndex = 0;

        foreach ($contentBlocks as $block) {
            $type = $block['type'] ?? '';

            switch ($type) {
                case 'text':
                    $textParts[] = $block['text'] ?? '';
                    break;
                case 'thinking':
                    // Extended Thinking 内容 -> reasoning_content
                    $reasoningParts[] = $block['thinking'] ?? '';
                    break;
                case 'tool_use':
                    // 工具调用 -> OpenAI tool_calls 格式
                    $input = $block['input'] ?? [];
                    $argumentsJson = is_array($input) && empty($input)
                        ? '{}'
                        : json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $toolCalls[] = [
                        'index' => $toolCallIndex++,
                        'id' => $block['id'] ?? self::generateToolCallId(),
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'] ?? '',
                            'arguments' => $argumentsJson,
                        ],
                    ];
                    break;
            }
        }

        $message = ['role' => 'assistant'];

        // 拼接文本内容
        $message['content'] = implode('', $textParts);

        // 附加推理内容
        if (! empty($reasoningParts)) {
            $message['reasoning_content'] = implode("\n\n", $reasoningParts);
        }

        // 附加工具调用
        if (! empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
        }

        return $message;
    }

    /**
     * 将 Anthropic stop_reason 映射为 OpenAI finish_reason.
     */
    private static function convertStopReason(string $stopReason): string
    {
        return match ($stopReason) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool_calls',
            'stop_sequence' => 'stop',
            default => 'stop',
        };
    }

    /**
     * 将 Anthropic usage 字段转换为 OpenAI usage 格式.
     *
     * Anthropic usage 字段：
     * - input_tokens: 输入 token 数
     * - output_tokens: 输出 token 数
     * - cache_creation_input_tokens: 本次写入缓存的 token 数
     * - cache_read_input_tokens: 本次从缓存读取的 token 数
     */
    private static function convertUsage(array $usage): array
    {
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $cacheCreationTokens = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheReadTokens = (int) ($usage['cache_read_input_tokens'] ?? 0);

        $promptTokens = $inputTokens + $cacheCreationTokens + $cacheReadTokens;
        $totalTokens = $promptTokens + $outputTokens;

        $openAIUsage = [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
        ];

        // 缓存详情（与 AWS Bedrock 保持一致的字段命名）
        $promptTokensDetails = [];
        if ($cacheReadTokens > 0) {
            $promptTokensDetails['cached_tokens'] = $cacheReadTokens;
            $promptTokensDetails['cache_read_input_tokens'] = $cacheReadTokens;
        }
        if ($cacheCreationTokens > 0) {
            $promptTokensDetails['cache_write_input_tokens'] = $cacheCreationTokens;
        }
        if (! empty($promptTokensDetails)) {
            $openAIUsage['prompt_tokens_details'] = $promptTokensDetails;
        }

        return $openAIUsage;
    }

    /**
     * 生成唯一响应 ID.
     */
    private static function generateId(): string
    {
        return 'chatcmpl-' . bin2hex(random_bytes(12));
    }

    /**
     * 生成唯一工具调用 ID.
     */
    private static function generateToolCallId(): string
    {
        return 'call_' . bin2hex(random_bytes(12));
    }
}
