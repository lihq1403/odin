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

use Generator;
use IteratorAggregate;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Traversable;

/**
 * 将 Anthropic Messages API SSE 流转换为 OpenAI 兼容的 delta chunk 格式.
 *
 * Anthropic SSE 事件序列：
 *   message_start -> content_block_start -> content_block_delta(s)
 *   -> content_block_stop -> message_delta -> message_stop
 *
 * 支持内容块类型：
 *   - text：普通文本，映射到 delta.content
 *   - thinking：Extended Thinking，映射到 delta.reasoning_content
 *   - tool_use：工具调用，映射到 delta.tool_calls
 *
 * @see https://docs.anthropic.com/en/api/messages-streaming
 */
class StreamConverter implements IteratorAggregate
{
    private ResponseInterface $response;

    private ?LoggerInterface $logger;

    /**
     * 当前消息 ID（从 message_start 事件中获取）.
     */
    private string $messageId = '';

    /**
     * 当前模型名称.
     */
    private string $model = '';

    /**
     * 工具调用计数（0-based index，用于 OpenAI tool_calls.index）.
     */
    private int $toolCallIndex = -1;

    /**
     * 当前工具调用的 id 和 name（在 content_block_start 时记录）.
     */
    private string $currentToolCallId = '';

    private string $currentToolCallName = '';

    public function __construct(ResponseInterface $response, ?LoggerInterface $logger = null)
    {
        $this->response = $response;
        $this->logger = $logger;
    }

    public function getIterator(): Traversable
    {
        return $this->parseStream();
    }

    /**
     * 解析 SSE 流并转换为 OpenAI 格式的 chunk 字符串.
     */
    private function parseStream(): Generator
    {
        $stream = $this->response->getBody();
        $buffer = '';
        $created = time();

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            // 按行解析 SSE
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                // 跳过 event: 行（Anthropic SSE 包含 event 字段，只关注 data 字段）
                if (str_starts_with($line, 'event:')) {
                    continue;
                }

                // 处理 data: 行
                if (str_starts_with($line, 'data:')) {
                    $data = trim(substr($line, 5));

                    if ($data === '[DONE]') {
                        return;
                    }

                    try {
                        $event = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $e) {
                        $this->logger?->warning('AnthropicStreamJsonDecodeError', [
                            'error' => $e->getMessage(),
                            'line' => substr($data, 0, 200),
                        ]);
                        continue;
                    }

                    $openAIChunk = $this->processEvent($event, $created);
                    if ($openAIChunk !== null) {
                        yield $openAIChunk;
                    }
                }
            }
        }
    }

    /**
     * 处理单个 Anthropic SSE 事件，返回 OpenAI 格式的 JSON 字符串或 null.
     */
    private function processEvent(array $event, int $created): ?string
    {
        $eventType = $event['type'] ?? '';

        return match ($eventType) {
            'message_start' => $this->handleMessageStart($event, $created),
            'content_block_start' => $this->handleContentBlockStart($event, $created),
            'content_block_delta' => $this->handleContentBlockDelta($event, $created),
            'content_block_stop' => null,
            'message_delta' => $this->handleMessageDelta($event, $created),
            'message_stop' => null,
            default => null,
        };
    }

    /**
     * 处理 message_start 事件：输出初始 delta（role: assistant）.
     */
    private function handleMessageStart(array $event, int $created): string
    {
        $message = $event['message'] ?? [];
        $this->messageId = $message['id'] ?? ('anthropic-' . uniqid());
        $this->model = $message['model'] ?? '';

        return $this->formatChunk($created, [
            'role' => 'assistant',
            'content' => '',
        ]);
    }

    /**
     * 处理 content_block_start 事件.
     *
     * - text/thinking：重置当前块类型
     * - tool_use：初始化 tool call，输出工具名称 delta
     */
    private function handleContentBlockStart(array $event, int $created): ?string
    {
        $contentBlock = $event['content_block'] ?? [];
        $blockType = $contentBlock['type'] ?? 'unknown';

        if ($blockType === 'tool_use') {
            ++$this->toolCallIndex;
            $this->currentToolCallId = $contentBlock['id'] ?? ('call_' . uniqid());
            $this->currentToolCallName = $contentBlock['name'] ?? '';

            // 输出工具调用起始 delta
            return $this->formatChunk($created, [
                'tool_calls' => [
                    [
                        'index' => $this->toolCallIndex,
                        'id' => $this->currentToolCallId,
                        'type' => 'function',
                        'function' => [
                            'name' => $this->currentToolCallName,
                            'arguments' => '',
                        ],
                    ],
                ],
            ]);
        }

        return null;
    }

    /**
     * 处理 content_block_delta 事件.
     *
     * delta 类型：
     * - text_delta：文本增量 -> delta.content
     * - thinking_delta：思考增量 -> delta.reasoning_content
     * - input_json_delta：工具参数 JSON 片段 -> delta.tool_calls.function.arguments
     */
    private function handleContentBlockDelta(array $event, int $created): ?string
    {
        $delta = $event['delta'] ?? [];
        $deltaType = $delta['type'] ?? '';

        switch ($deltaType) {
            case 'text_delta':
                $text = $delta['text'] ?? '';
                if ($text === '') {
                    return null;
                }
                return $this->formatChunk($created, ['content' => $text]);
            case 'thinking_delta':
                $thinking = $delta['thinking'] ?? '';
                if ($thinking === '') {
                    return null;
                }
                return $this->formatChunk($created, ['reasoning_content' => $thinking]);
            case 'input_json_delta':
                $partialJson = $delta['partial_json'] ?? '';
                if ($partialJson === '') {
                    return null;
                }
                return $this->formatChunk($created, [
                    'tool_calls' => [
                        [
                            'index' => $this->toolCallIndex,
                            'function' => [
                                'arguments' => $partialJson,
                            ],
                        ],
                    ],
                ]);
        }

        return null;
    }

    /**
     * 处理 message_delta 事件：输出结束 delta（finish_reason 和 usage）.
     */
    private function handleMessageDelta(array $event, int $created): string
    {
        $delta = $event['delta'] ?? [];
        $usage = $event['usage'] ?? [];

        $stopReason = $delta['stop_reason'] ?? 'end_turn';
        $finishReason = $this->convertStopReason($stopReason);

        // usage 统计（与 ResponseConverter 保持一致的字段映射）
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        $chunk = [
            'id' => $this->messageId ?: ('anthropic-' . uniqid()),
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $this->model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => [],
                    'finish_reason' => $finishReason,
                ],
            ],
        ];

        if ($outputTokens > 0) {
            $chunk['usage'] = [
                'prompt_tokens' => 0,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $outputTokens,
            ];
        }

        return json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 格式化 OpenAI delta chunk JSON 字符串（不含 SSE 包装）.
     *
     * iterateWithCustomIterator 会直接 json_decode 迭代器产出的字符串，
     * 因此这里只返回裸 JSON，不加 "data: " 前缀和 "\n\n" 后缀。
     *
     * @param array $delta delta 内容（content / reasoning_content / tool_calls / role）
     */
    private function formatChunk(int $created, array $delta): string
    {
        $chunk = [
            'id' => $this->messageId ?: ('anthropic-' . uniqid()),
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $this->model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => $delta,
                    'finish_reason' => null,
                ],
            ],
        ];

        return json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 将 Anthropic stop_reason 转换为 OpenAI finish_reason.
     */
    private function convertStopReason(string $stopReason): string
    {
        return match ($stopReason) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'tool_use' => 'tool_calls',
            'stop_sequence' => 'stop',
            default => 'stop',
        };
    }
}
