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

use Generator;
use GuzzleHttp\Psr7\Response;
use Hyperf\Odin\Api\Transport\SSEClient;
use Hyperf\Odin\Api\Transport\SSEEvent;
use Hyperf\Odin\Api\Transport\SseEventProducerInterface;
use Hyperf\Odin\Event\AfterChatCompletionsStreamEvent;
use Hyperf\Odin\Exception\LLMException;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Utils\EventUtil;
use Hyperf\Odin\Utils\LoggingConfigHelper;
use Hyperf\Odin\Utils\StreamChunkParseFailureContext;
use Hyperf\Odin\Utils\TimeUtil;
use IteratorAggregate;
use JsonException;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

class ChatCompletionStreamResponse extends AbstractResponse implements Stringable
{
    protected ?string $id = null;

    protected ?string $object = null;

    protected ?int $created = null;

    protected ?string $model = null;

    /**
     * @var array<ChatCompletionChoice>
     */
    protected array $choices = [];

    /**
     * 兼容多种类型的 SSE 事件迭代器（产出 SSEEvent）.
     */
    protected ?SseEventProducerInterface $sseClient = null;

    /**
     * 支持 IteratorAggregate 接口的自定义格式迭代器（如 Gemini StreamConverter）.
     */
    protected ?IteratorAggregate $iterator = null;

    protected AfterChatCompletionsStreamEvent $afterChatCompletionsStreamEvent;

    /**
     * 构造函数.
     *
     * @param null|PsrResponseInterface $response HTTP 响应对象
     * @param null|LoggerInterface $logger 日志记录器
     * @param null|IteratorAggregate|SseEventProducerInterface $streamIterator 流式迭代器
     */
    public function __construct(?PsrResponseInterface $response = null, ?LoggerInterface $logger = null, $streamIterator = null)
    {
        // SseEventProducerInterface 优先：产出 SSEEvent，走 iterateWithSSEClient 路径
        if ($streamIterator instanceof SseEventProducerInterface) {
            $this->sseClient = $streamIterator;
        } elseif ($streamIterator instanceof IteratorAggregate) {
            $this->iterator = $streamIterator;
        }

        if ($response === null) {
            if (! $this->sseClient && ! $this->iterator) {
                throw new LLMException('Stream iterator is required');
            }
            $response = new Response(200);
        }

        parent::__construct($response, $logger);
    }

    public function __toString(): string
    {
        return 'Stream Response';
    }

    public function getStreamIterator(): Generator
    {
        // 优先使用 IteratorAggregate
        if ($this->iterator) {
            return $this->iterateWithCustomIterator();
        }

        // 其次使用 SSEClient
        if ($this->sseClient) {
            return $this->iterateWithSSEClient();
        }

        // 最后使用传统方式
        return $this->iterateWithLegacyMethod();
    }

    public function setAfterChatCompletionsStreamEvent(AfterChatCompletionsStreamEvent $afterChatCompletionsStreamEvent): void
    {
        $this->afterChatCompletionsStreamEvent = $afterChatCompletionsStreamEvent;
    }

    public function getAfterChatCompletionsStreamEvent(): ?AfterChatCompletionsStreamEvent
    {
        return $this->afterChatCompletionsStreamEvent ?? null;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getObject(): ?string
    {
        return $this->object;
    }

    public function setObject(?string $object): self
    {
        $this->object = $object;
        return $this;
    }

    public function getCreated(): ?int
    {
        return $this->created;
    }

    public function setCreated(int|string|null $created): self
    {
        $this->created = (int) $created;
        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function getChoices(): array
    {
        return $this->choices;
    }

    public function setChoices(array $choices): self
    {
        $this->choices = $choices;
        return $this;
    }

    protected function parseContent(): self
    {
        return $this;
    }

    /**
     * 获取流式处理检查点间隔数量.
     */
    protected function getCheckpointInterval(): int
    {
        return 200;
    }

    /**
     * 判断是否应该记录检查点日志.
     */
    protected function shouldLogCheckpoint(int $chunkCount): bool
    {
        // 前5个块都记录
        if ($chunkCount <= 5) {
            return true;
        }

        // 之后每200个块记录一次
        return $chunkCount % $this->getCheckpointInterval() === 0;
    }

    /**
     * 使用自定义迭代器（IteratorAggregate）处理流数据.
     */
    private function iterateWithCustomIterator(): Generator
    {
        $startTime = microtime(true);
        $chunkCount = 0;
        $lastLogTime = $startTime;
        $lastChunkData = null;
        $lastFinishReason = null;
        $firstChunks = [];
        $lastChunks = [];
        $maxChunksToLog = 5;

        try {
            $this->logger?->info('StreamProcessingStartedWithCustomIterator', [
                'iterator_class' => get_class($this->iterator),
                'start_time' => $startTime,
            ]);

            foreach ($this->iterator->getIterator() as $data) {
                ++$chunkCount;
                // 处理结束标记
                if ($data === '[DONE]' || $data === json_encode('[DONE]')) {
                    $this->logger?->debug('StreamCompleted');
                    break;
                }

                // 解析 JSON 数据（如果数据是字符串）
                if (is_string($data)) {
                    try {
                        $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $e) {
                        $this->logger?->warning('InvalidJsonInStream', array_merge([
                            'chunk_count' => $chunkCount,
                        ], StreamChunkParseFailureContext::forRawLine($data, $e->getMessage())));
                        continue;
                    }
                }

                // 确保数据是有效的数组
                if (! is_array($data)) {
                    $this->logger?->warning('InvalidDataFormat', array_merge([
                        'chunk_count' => $chunkCount,
                    ], StreamChunkParseFailureContext::forInvalidChunkShape($data)));
                    continue;
                }

                // Store last valid chunk data
                $lastChunkData = $data;

                // Track finish_reason (the usage chunk at the end has empty choices)
                if (! empty($data['choices'][0]['finish_reason'])) {
                    $lastFinishReason = $data['choices'][0]['finish_reason'];
                }

                // Collect first and last raw chunks for debugging
                $chunkWithTime = [
                    'index' => $chunkCount,
                    'timestamp' => microtime(true),
                    'data' => $data,
                ];
                if ($chunkCount <= $maxChunksToLog) {
                    $firstChunks[] = $chunkWithTime;
                }
                $lastChunks[] = $chunkWithTime;
                if (count($lastChunks) > $maxChunksToLog) {
                    array_shift($lastChunks);
                }

                // Log checkpoint (first 5 chunks and every 200 chunks)
                if ($this->shouldLogCheckpoint($chunkCount)) {
                    $currentTime = microtime(true);

                    if ($chunkCount === 1) {
                        // First chunk gets detailed information
                        $this->logger?->info('FirstChunkReceivedFromCustomIterator', [
                            'chunk_count' => $chunkCount,
                            'id' => $data['id'] ?? null,
                            'model' => $data['model'] ?? null,
                            'choices_count' => count($data['choices'] ?? []),
                            'time_since_start_ms' => TimeUtil::calculateIntervalMs($startTime, $currentTime, 2),
                        ]);
                        $lastLogTime = $currentTime;
                    } else {
                        // Regular checkpoint
                        $this->logger?->info('StreamProcessingCheckpoint', [
                            'chunks_processed' => $chunkCount,
                            'interval_time_ms' => TimeUtil::calculateIntervalMs($lastLogTime, $currentTime, 2),
                            'total_time_ms' => TimeUtil::calculateDurationMs($startTime, 2),
                            'choices_accumulated' => count($this->choices),
                        ]);
                        $lastLogTime = $currentTime;
                    }
                }

                // 更新响应元数据
                $this->updateMetadata($data);

                // 生成ChatCompletionChoice对象
                yield from $this->yieldChoices($data['choices'] ?? []);
            }
        } catch (Throwable $e) {
            $this->logger?->error('ErrorProcessingCustomIterator', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // 重新抛出异常，让调用方可以处理
        } finally {
            // Log last chunk content if available
            if ($lastChunkData !== null) {
                $this->logger?->info('LastChunkReceivedFromCustomIterator', [
                    'chunk_count' => $chunkCount,
                    'id' => $lastChunkData['id'] ?? null,
                    'model' => $lastChunkData['model'] ?? null,
                    'choices' => $lastChunkData['choices'] ?? [],
                    'usage' => $lastChunkData['usage'] ?? null,
                    'finish_reason' => $lastFinishReason,
                ]);
            }

            // Log first and last raw chunks
            if (! empty($firstChunks)) {
                $this->logger?->info('FirstRawChunksFromCustomIterator', [
                    'total_chunks' => $chunkCount,
                    'chunks' => $firstChunks,
                ]);
            }
            if (! empty($lastChunks)) {
                $this->logger?->info('LastRawChunksFromCustomIterator', [
                    'total_chunks' => $chunkCount,
                    'chunks' => $lastChunks,
                ]);
            }

            // Log completion summary (always executed)
            $this->logger?->info('CustomIteratorStreamCompleted', [
                'total_chunks' => $chunkCount,
                'total_time_ms' => TimeUtil::calculateDurationMs($startTime, 2),
                'total_choices' => count($this->choices),
            ]);

            // Set duration and create completion response
            $this->handleStreamCompletion($startTime);
        }
    }

    /**
     * 使用SSEClient处理流数据.
     */
    private function iterateWithSSEClient(): Generator
    {
        $startTime = microtime(true);
        $chunkCount = 0;
        $lastLogTime = $startTime;
        $lastChunkData = null;
        $lastFinishReason = null;
        $firstChunks = [];
        $lastChunks = [];
        $maxChunksToLog = 5;

        try {
            $this->logger?->info('StreamProcessingStartedWithSseClient', [
                'client_class' => get_class($this->sseClient),
                'start_time' => $startTime,
            ]);

            /** @var SSEEvent $event */
            foreach ($this->sseClient->getIterator() as $event) {
                $data = $event->getData();

                // 处理结束标记
                if ($data === '[DONE]' || $event->getEvent() === 'done') {
                    $this->logger?->debug('SseStreamCompleted', [
                        'event_type' => $event->getEvent(),
                        'data' => $data,
                    ]);
                    // Signal the SSE client to close early to prevent waiting for more data
                    $this->sseClient->closeEarly();
                    break;
                }

                // 只处理数据事件
                if ($event->getEvent() !== 'message') {
                    $this->logger?->debug('SkippingNonMessageEvent', ['event' => $event->getEvent()]);
                    continue;
                }

                ++$chunkCount;

                // 确保数据是有效的数组
                if (! is_array($data)) {
                    $this->logger?->warning('InvalidDataFormat', array_merge([
                        'chunk_count' => $chunkCount,
                    ], StreamChunkParseFailureContext::forInvalidChunkShape($data)));
                    continue;
                }

                // Store last valid chunk data
                $lastChunkData = $data;

                // Track finish_reason (the usage chunk at the end has empty choices)
                if (! empty($data['choices'][0]['finish_reason'])) {
                    $lastFinishReason = $data['choices'][0]['finish_reason'];
                }

                // Collect first and last raw chunks for debugging
                $chunkWithTime = [
                    'index' => $chunkCount,
                    'timestamp' => microtime(true),
                    'data' => $data,
                ];
                if ($chunkCount <= $maxChunksToLog) {
                    $firstChunks[] = $chunkWithTime;
                }
                $lastChunks[] = $chunkWithTime;
                if (count($lastChunks) > $maxChunksToLog) {
                    array_shift($lastChunks);
                }

                // Log checkpoint (first 5 chunks and every 200 chunks)
                if ($this->shouldLogCheckpoint($chunkCount)) {
                    $currentTime = microtime(true);

                    if ($chunkCount === 1) {
                        // First chunk gets detailed information
                        $this->logger?->info('FirstChunkReceivedFromSseClient', [
                            'chunk_count' => $chunkCount,
                            'id' => $data['id'] ?? null,
                            'model' => $data['model'] ?? null,
                            'choices_count' => count($data['choices'] ?? []),
                            'time_since_start_ms' => TimeUtil::calculateIntervalMs($startTime, $currentTime, 2),
                        ]);
                        $lastLogTime = $currentTime;
                    } else {
                        // Regular checkpoint
                        $this->logger?->info('SseStreamProcessingCheckpoint', [
                            'chunks_processed' => $chunkCount,
                            'interval_time_ms' => TimeUtil::calculateIntervalMs($lastLogTime, $currentTime, 2),
                            'total_time_ms' => TimeUtil::calculateDurationMs($startTime, 2),
                            'choices_accumulated' => count($this->choices),
                        ]);
                        $lastLogTime = $currentTime;
                    }
                }

                // 更新响应元数据
                $this->updateMetadata($data);

                // 生成ChatCompletionChoice对象
                yield from $this->yieldChoices($data['choices'] ?? []);
            }
        } catch (Throwable $e) {
            $this->logger?->error('ErrorProcessingSseStream', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // 重新抛出异常，让调用方可以处理
        } finally {
            // Log last chunk content if available
            if ($lastChunkData !== null) {
                $this->logger?->info('LastChunkReceivedFromSseClient', [
                    'chunk_count' => $chunkCount,
                    'id' => $lastChunkData['id'] ?? null,
                    'model' => $lastChunkData['model'] ?? null,
                    'choices' => $lastChunkData['choices'] ?? [],
                    'usage' => $lastChunkData['usage'] ?? null,
                    'finish_reason' => $lastFinishReason,
                ]);
            }

            // Log first and last raw chunks
            if (! empty($firstChunks)) {
                $this->logger?->info('FirstRawChunksFromSseClient', [
                    'total_chunks' => $chunkCount,
                    'chunks' => $firstChunks,
                ]);
            }
            if (! empty($lastChunks)) {
                $this->logger?->info('LastRawChunksFromSseClient', [
                    'total_chunks' => $chunkCount,
                    'chunks' => $lastChunks,
                ]);
            }

            // Log completion summary (always executed)
            $this->logger?->info('SseClientStreamCompleted', [
                'total_chunks' => $chunkCount,
                'total_time_ms' => TimeUtil::calculateDurationMs($startTime, 2),
                'total_choices' => count($this->choices),
            ]);

            // Set duration and create completion response
            $this->handleStreamCompletion($startTime);
        }
    }

    /**
     * 更新响应元数据.
     */
    private function updateMetadata(array $data): void
    {
        $this->setId($data['id'] ?? null);
        $this->setObject($data['object'] ?? null);
        $this->setCreated($data['created'] ?? null);
        $this->setModel($data['model'] ?? null);
        if (! empty($data['usage'])) {
            $usage = $data['usage'];
            // 检测并转换DashScope格式的字段
            if ($this->isDashScopeUsage($usage)) {
                $usage = $this->convertDashScopeUsage($usage);
            }
            $this->setUsage(Usage::fromArray($usage));
        }
    }

    /**
     * 检测是否为DashScope格式的usage数据.
     */
    private function isDashScopeUsage(array $usage): bool
    {
        return isset($usage['prompt_tokens_details']['cache_creation_input_tokens'])
            || isset($usage['prompt_tokens_details']['cache_type'])
            || isset($usage['prompt_tokens_details']['cache_creation']);
    }

    /**
     * 转换DashScope格式的usage数据为标准格式.
     */
    private function convertDashScopeUsage(array $usage): array
    {
        if (isset($usage['prompt_tokens_details'])) {
            $promptTokensDetails = $usage['prompt_tokens_details'];

            // 1. 优先转换外层的 cache_creation_input_tokens -> cache_write_input_tokens
            if (isset($promptTokensDetails['cache_creation_input_tokens'])) {
                $usage['prompt_tokens_details']['cache_write_input_tokens'] = $promptTokensDetails['cache_creation_input_tokens'];
            }
            // 2. 如果外层没有，再尝试从内层 cache_creation 获取
            elseif (isset($promptTokensDetails['cache_creation']['ephemeral_5m_input_tokens'])) {
                $usage['prompt_tokens_details']['cache_write_input_tokens'] = $promptTokensDetails['cache_creation']['ephemeral_5m_input_tokens'];
            }
        }

        return $usage;
    }

    /**
     * 生成选择对象
     */
    private function yieldChoices(array $choices): Generator
    {
        foreach ($choices as $choice) {
            if (! is_array($choice)) {
                $this->logger?->warning('InvalidChoiceFormat', ['choice' => $choice]);
                continue;
            }
            $chatCompletionChoice = ChatCompletionChoice::fromArray($choice);
            $this->choices[] = $chatCompletionChoice;
            yield $chatCompletionChoice;
        }
    }

    /**
     * 使用传统方式处理流数据（后备方法）.
     */
    private function iterateWithLegacyMethod(): Generator
    {
        // 保留原有的实现作为后备
        $startTime = microtime(true);
        $chunkCount = 0;
        $lastLogTime = $startTime;
        $lastChunkData = null;
        $lastFinishReason = null;
        $firstChunks = [];
        $lastChunks = [];
        $maxChunksToLog = 5;
        $body = $this->originResponse->getBody();

        $this->logger?->info('StreamProcessingStartedWithLegacyMethod', [
            'response_status' => $this->originResponse->getStatusCode(),
            'content_type' => $this->originResponse->getHeaderLine('Content-Type'),
            'start_time' => $startTime,
        ]);

        $buffer = '';
        while (! $body->eof()) {
            $chunk = $body->read(4096);
            if (! $chunk) {
                break;
            }

            $buffer .= $chunk;
            // 处理接收到的数据块
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // 保留不完整的最后一行

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }

                if (str_starts_with($line, 'data:')) {
                    $line = substr($line, 5);
                }

                if (trim($line) === '[DONE]') {
                    break 2;
                }

                try {
                    $data = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
                    ++$chunkCount;

                    // Store last valid chunk data
                    $lastChunkData = $data;

                    // Track finish_reason (the usage chunk at the end has empty choices)
                    if (! empty($data['choices'][0]['finish_reason'])) {
                        $lastFinishReason = $data['choices'][0]['finish_reason'];
                    }

                    // Collect first and last raw chunks for debugging
                    $chunkWithTime = [
                        'index' => $chunkCount,
                        'timestamp' => microtime(true),
                        'data' => $data,
                    ];
                    if ($chunkCount <= $maxChunksToLog) {
                        $firstChunks[] = $chunkWithTime;
                    }
                    $lastChunks[] = $chunkWithTime;
                    if (count($lastChunks) > $maxChunksToLog) {
                        array_shift($lastChunks);
                    }

                    // Log checkpoint (first 5 chunks and every 200 chunks)
                    if ($this->shouldLogCheckpoint($chunkCount)) {
                        $currentTime = microtime(true);

                        if ($chunkCount === 1) {
                            // First chunk gets detailed information
                            $this->logger?->info('FirstChunkReceivedFromLegacyMethod', [
                                'chunk_count' => $chunkCount,
                                'id' => $data['id'] ?? null,
                                'model' => $data['model'] ?? null,
                                'choices_count' => count($data['choices'] ?? []),
                                'time_since_start_ms' => TimeUtil::calculateIntervalMs($startTime, $currentTime, 2),
                                'raw_line_length' => strlen(trim($line)),
                            ]);
                            $lastLogTime = $currentTime;
                        } else {
                            // Regular checkpoint
                            $this->logger?->info('LegacyStreamProcessingCheckpoint', [
                                'chunks_processed' => $chunkCount,
                                'interval_time_ms' => TimeUtil::calculateIntervalMs($lastLogTime, $currentTime, 2),
                                'total_time_ms' => TimeUtil::calculateDurationMs($startTime, 2),
                                'choices_accumulated' => count($this->choices),
                                'buffer_size' => strlen($buffer),
                            ]);
                            $lastLogTime = $currentTime;
                        }
                    }

                    $this->updateMetadata($data);
                    yield from $this->yieldChoices($data['choices'] ?? []);
                } catch (JsonException $e) {
                    $rawForLog = trim($line);
                    $this->logger?->warning('InvalidJsonResponse', array_merge([
                        'chunk_count' => $chunkCount,
                    ], StreamChunkParseFailureContext::forRawLine($rawForLog, $e->getMessage())));
                    continue;
                }
            }
        }

        // Log last chunk content if available
        if ($lastChunkData !== null) {
            $this->logger?->info('LastChunkReceivedFromLegacyMethod', [
                'chunk_count' => $chunkCount,
                'id' => $lastChunkData['id'] ?? null,
                'model' => $lastChunkData['model'] ?? null,
                'choices' => $lastChunkData['choices'] ?? [],
                'usage' => $lastChunkData['usage'] ?? null,
                'finish_reason' => $lastFinishReason,
            ]);
        }

        // Log first and last raw chunks
        if (! empty($firstChunks)) {
            $this->logger?->info('FirstRawChunksFromLegacyMethod', [
                'total_chunks' => $chunkCount,
                'chunks' => $firstChunks,
            ]);
        }
        if (! empty($lastChunks)) {
            $this->logger?->info('LastRawChunksFromLegacyMethod', [
                'total_chunks' => $chunkCount,
                'chunks' => $lastChunks,
            ]);
        }

        // Log completion summary
        $this->logger?->info('LegacyMethodStreamCompleted', [
            'total_chunks' => $chunkCount,
            'total_time_ms' => TimeUtil::calculateDurationMs($startTime, 2),
            'total_choices' => count($this->choices),
        ]);

        // Set duration and create completion response
        $this->handleStreamCompletion($startTime);
    }

    /**
     * Handle stream completion - create response and dispatch event.
     */
    private function handleStreamCompletion(float $startTime): void
    {
        if (! isset($this->afterChatCompletionsStreamEvent)) {
            return;
        }

        // Set duration and create completion response
        $this->afterChatCompletionsStreamEvent->setDuration(TimeUtil::calculateDurationMs($startTime));

        // Create and set the completed ChatCompletionResponse
        $completionResponse = $this->createChatCompletionResponse();
        $this->afterChatCompletionsStreamEvent->setCompletionResponse($completionResponse);

        $logData = [
            'content' => $completionResponse->getFirstChoice()?->getMessage()?->toArray(),
            'usage' => $completionResponse->getUsage()?->toArray(),
        ];
        $this->logger?->info('ChatCompletionsStreamResponse', LoggingConfigHelper::filterAndFormatLogData($logData));

        // Event listener will execute callbacks
        EventUtil::dispatch($this->afterChatCompletionsStreamEvent);
    }

    private function createChatCompletionResponse(): ChatCompletionResponse
    {
        // Create a merged choices array by combining content from the same index
        $mergedChoices = [];

        foreach ($this->choices as $choice) {
            $index = $choice->getIndex() ?? 0;

            if (! isset($mergedChoices[$index])) {
                // Initialize new choice with basic info
                $mergedChoices[$index] = [
                    'index' => $index,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'reasoning_content' => null,
                        'reasoning_details' => null,
                        'tool_calls' => [],
                    ],
                    'logprobs' => $choice->getLogprobs(),
                    'finish_reason' => null,
                ];
            }

            // Merge content
            $message = $choice->getMessage();
            // Append content
            $content = $message->getContent();
            if (! empty($content)) {
                $mergedChoices[$index]['message']['content'] .= $content;
            }

            // Handle reasoning content and reasoning_details for AssistantMessage
            if ($message instanceof AssistantMessage) {
                $reasoningContent = $message->getReasoningContent();
                if (! empty($reasoningContent)) {
                    if ($mergedChoices[$index]['message']['reasoning_content'] === null) {
                        $mergedChoices[$index]['message']['reasoning_content'] = '';
                    }
                    $mergedChoices[$index]['message']['reasoning_content'] .= $reasoningContent;
                }

                // reasoning_details 整体下发（含签名），取最后一个非空值
                $reasoningDetails = $message->getReasoningDetails();
                if ($reasoningDetails !== null) {
                    $mergedChoices[$index]['message']['reasoning_details'] = $reasoningDetails;
                }

                // Merge tool calls
                $toolCalls = $message->getToolCalls();
                if (! empty($toolCalls)) {
                    foreach ($toolCalls as $toolCall) {
                        $toolCallId = $toolCall->getId();
                        $streamIndex = $toolCall->getMetadata('stream_index');
                        $streamArgs = $toolCall->getStreamArguments();
                        $existingToolCallFound = false;

                        if ($toolCallId !== '') {
                            // id 存在时严格按 id 匹配。
                            // Gemini 等模型每个 tool call chunk 都携带完整 id，但所有 chunk 共用 index: 0，
                            // 若优先按 stream_index 匹配会将不同 tool call 的 arguments 错误地追加到同一条目。
                            foreach ($mergedChoices[$index]['message']['tool_calls'] as &$existingToolCall) {
                                if ($existingToolCall['id'] === $toolCallId) {
                                    $existingToolCall['function']['arguments'] = ($existingToolCall['function']['arguments'] ?? '') . $streamArgs;
                                    $existingToolCallFound = true;
                                    break;
                                }
                            }
                            unset($existingToolCall);
                        } elseif ($streamIndex !== null) {
                            // id 为空时才使用 stream_index 匹配（标准 OpenAI 流式 argument fragment 帧，
                            // 续传帧不携带 id，只能通过 index 定位对应条目）。
                            foreach ($mergedChoices[$index]['message']['tool_calls'] as &$existingToolCall) {
                                if (($existingToolCall['stream_index'] ?? null) === $streamIndex) {
                                    $existingToolCall['function']['arguments'] = ($existingToolCall['function']['arguments'] ?? '') . $streamArgs;
                                    $existingToolCallFound = true;
                                    break;
                                }
                            }
                            unset($existingToolCall);
                        }

                        // 新工具调用条目（初始帧）
                        if (! $existingToolCallFound) {
                            $mergedChoices[$index]['message']['tool_calls'][] = [
                                'id' => $toolCallId,
                                'type' => $toolCall->getType(),
                                'stream_index' => $streamIndex,
                                'function' => [
                                    'name' => $toolCall->getName(),
                                    'arguments' => $streamArgs,
                                ],
                            ];
                        }
                    }
                }
            }

            // Update finish reason if provided
            if ($choice->getFinishReason()) {
                $mergedChoices[$index]['finish_reason'] = $choice->getFinishReason();
            }
        }

        // Clean up empty fields and internal tracking fields
        foreach ($mergedChoices as &$choice) {
            if (empty($choice['message']['reasoning_content'])) {
                $choice['message']['reasoning_content'] = null;
            }
            if (empty($choice['message']['reasoning_details'])) {
                unset($choice['message']['reasoning_details']);
            }
            if (empty($choice['message']['tool_calls'])) {
                unset($choice['message']['tool_calls']);
            } else {
                // 移除内部使用的 stream_index 字段，不暴露到最终响应
                foreach ($choice['message']['tool_calls'] as &$tc) {
                    unset($tc['stream_index']);
                }
            }
        }

        // Sort choices by index
        ksort($mergedChoices);
        $mergedChoices = array_values($mergedChoices);

        // Create response content similar to regular chat completion response
        $responseContent = [
            'id' => $this->getId(),
            'object' => $this->getObject() ?: 'chat.completion',
            'created' => $this->getCreated(),
            'model' => $this->getModel() ?? $this->afterChatCompletionsStreamEvent->getCompletionRequest()->getModel(),
            'choices' => $mergedChoices,
        ];

        // Add usage if available
        if ($this->getUsage()) {
            $responseContent['usage'] = $this->getUsage()->toArray();
        }

        // Create a mock response with the merged content
        $jsonContent = json_encode($responseContent);
        $mockResponse = new Response(200, ['Content-Type' => 'application/json'], $jsonContent);

        // Create and return ChatCompletionResponse
        return new ChatCompletionResponse($mockResponse, $this->logger);
    }
}
