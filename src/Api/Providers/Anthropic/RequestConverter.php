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

use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Contract\Message\MessageInterface;
use Hyperf\Odin\Contract\Tool\ToolInterface;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Message\CachePoint;
use Hyperf\Odin\Message\SystemMessage;
use Hyperf\Odin\Message\ToolMessage;
use Hyperf\Odin\Message\UserMessage;
use Hyperf\Odin\Message\UserMessageContent;
use Hyperf\Odin\Tool\Definition\ToolDefinition;
use stdClass;

/**
 * 将 ChatCompletionRequest 转换为 Anthropic Messages API 原生格式.
 *
 * 主要差异点：
 * 1. system 消息提取为顶层字段（数组格式的内容块）
 * 2. 请求端点为 /v1/messages，不是 /v1/chat/completions
 * 3. max_tokens 必填
 * 4. 工具格式：input_schema 替代 parameters
 * 5. 连续的 ToolMessage 合并为单个 user 消息
 * 6. 缓存：cache_control 注入到各内容块中
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
class RequestConverter
{
    /**
     * 转换为 Anthropic 请求体数组.
     *
     * @param string $anthropicVersion API 版本（用于 anthropic-beta header）
     */
    public static function convert(ChatCompletionRequest $request, string $anthropicVersion = '2023-06-01'): array
    {
        $anthropicRequest = [];

        // 模型
        $anthropicRequest['model'] = $request->getModel();

        // max_tokens 必填，默认 4096
        $maxTokens = $request->getMaxTokens();
        $anthropicRequest['max_tokens'] = $maxTokens > 0 ? $maxTokens : 4096;

        // 温度（可选）
        $temperature = $request->getTemperature();
        if ($temperature > 0) {
            $anthropicRequest['temperature'] = $temperature;
        }

        // 停止词（可选）
        $stop = $request->getStop();
        if (! empty($stop)) {
            $anthropicRequest['stop_sequences'] = $stop;
        }

        // 流式输出标志
        if ($request->isStream()) {
            $anthropicRequest['stream'] = true;
        }

        // 工具
        $tools = $request->getTools();
        if (! empty($tools)) {
            $convertedTools = self::convertTools($tools, $request->isToolsCache());
            if (! empty($convertedTools)) {
                $anthropicRequest['tools'] = $convertedTools;
            }
        }

        // 提取 system 消息并转换消息列表
        ['system' => $system, 'messages' => $messages] = self::convertMessages($request->getMessages());

        if (! empty($system)) {
            $anthropicRequest['system'] = $system;
        }

        $anthropicRequest['messages'] = $messages;

        // Extended Thinking
        $thinking = $request->getThinking();
        if ($thinking !== null && $thinking->isEnabled()) {
            $budgetTokens = $thinking->getBudgetTokens();
            $thinkingParam = ['type' => 'enabled'];
            if ($budgetTokens !== null && $budgetTokens > 0) {
                $thinkingParam['budget_tokens'] = $budgetTokens;
            } else {
                // Anthropic 要求 budget_tokens 为正整数，无有效值时给默认值
                $thinkingParam['budget_tokens'] = 4096;
            }
            $anthropicRequest['thinking'] = $thinkingParam;

            // Thinking 模式要求温度为 1（官方限制）
            $anthropicRequest['temperature'] = 1;
        }

        return $anthropicRequest;
    }

    /**
     * 转换工具列表为 Anthropic 格式.
     *
     * Anthropic tool 格式：
     * {"name": ..., "description": ..., "input_schema": {...}}
     * 若 toolsCache 为 true，最后一个工具追加 cache_control.
     *
     * @param array<array|ToolDefinition|ToolInterface> $tools
     */
    public static function convertTools(array $tools, bool $toolsCache = false): array
    {
        $converted = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $tool = $tool->toToolDefinition();
            }
            if (! $tool instanceof ToolDefinition) {
                continue;
            }

            $toolItem = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
            ];

            $parameters = $tool->getParameters();
            if ($parameters !== null) {
                $toolItem['input_schema'] = $parameters->toArray();
            } else {
                $toolItem['input_schema'] = [
                    'type' => 'object',
                    'properties' => new stdClass(),
                ];
            }

            $converted[] = $toolItem;
        }

        // 若启用工具缓存，在最后一个工具上追加 cache_control
        if ($toolsCache && ! empty($converted)) {
            $lastIndex = count($converted) - 1;
            $converted[$lastIndex]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $converted;
    }

    /**
     * 提取 system 消息并转换消息列表.
     *
     * @param array<MessageInterface> $messages
     * @return array{system: array, messages: array}
     */
    public static function convertMessages(array $messages): array
    {
        $systemBlocks = [];
        $systemCachePoint = null;
        $anthropicMessages = [];

        // 收集连续 ToolMessage 的缓冲区
        $toolMessageBuffer = [];

        foreach ($messages as $message) {
            if (! $message instanceof MessageInterface) {
                continue;
            }

            // 提取 SystemMessage
            if ($message instanceof SystemMessage) {
                if (trim($message->getContent()) !== '') {
                    $systemBlocks[] = ['type' => 'text', 'text' => $message->getContent()];
                }
                // 记录最后一个有缓存点的 SystemMessage
                if ($message->getCachePoint() !== null) {
                    $systemCachePoint = $message->getCachePoint();
                }
                continue;
            }

            // ToolMessage 收集到缓冲区
            if ($message instanceof ToolMessage) {
                $toolMessageBuffer[] = $message;
                continue;
            }

            // 遇到非 ToolMessage 时，先把缓冲区的 ToolMessage 合并为一条 user 消息输出
            if (! empty($toolMessageBuffer)) {
                $anthropicMessages[] = self::mergeToolMessages($toolMessageBuffer);
                $toolMessageBuffer = [];
            }

            $converted = match (true) {
                $message instanceof UserMessage => self::convertUserMessage($message),
                $message instanceof AssistantMessage => self::convertAssistantMessage($message),
                default => null,
            };

            if ($converted !== null) {
                $anthropicMessages[] = $converted;
            }
        }

        // 处理末尾残余的 ToolMessage 缓冲区
        if (! empty($toolMessageBuffer)) {
            $anthropicMessages[] = self::mergeToolMessages($toolMessageBuffer);
        }

        // 构建 system 字段：若有缓存点，在最后一个 text 块追加 cache_control
        $system = self::buildSystemField($systemBlocks, $systemCachePoint);

        return [
            'system' => $system,
            'messages' => $anthropicMessages,
        ];
    }

    /**
     * 将多个连续 ToolMessage 合并为单条 Anthropic user 消息.
     *
     * @param ToolMessage[] $toolMessages
     */
    private static function mergeToolMessages(array $toolMessages): array
    {
        $contentBlocks = [];
        $hasCachePoint = false;

        foreach ($toolMessages as $toolMessage) {
            $contentBlocks[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolMessage->getToolCallId(),
                'content' => $toolMessage->getContent(),
            ];
            if ($toolMessage->getCachePoint() !== null) {
                $hasCachePoint = true;
            }
        }

        // 若最后一条 ToolMessage 带缓存点，在最后一个 tool_result 块追加 cache_control
        if ($hasCachePoint && ! empty($contentBlocks)) {
            $lastIndex = count($contentBlocks) - 1;
            $contentBlocks[$lastIndex]['cache_control'] = ['type' => 'ephemeral'];
        }

        return [
            'role' => 'user',
            'content' => $contentBlocks,
        ];
    }

    /**
     * 转换 UserMessage 为 Anthropic 格式.
     */
    private static function convertUserMessage(UserMessage $message): array
    {
        $contentBlocks = [];

        if ($message->getContents() !== null) {
            // 多模态内容
            foreach ($message->getContents() as $content) {
                $type = $content->getType();
                if ($type === UserMessageContent::TEXT) {
                    $contentBlocks[] = ['type' => 'text', 'text' => $content->getText()];
                } elseif ($type === UserMessageContent::IMAGE_URL) {
                    $imageBlock = self::convertImageUrl($content->getImageUrl());
                    if ($imageBlock !== null) {
                        $contentBlocks[] = $imageBlock;
                    }
                }
            }
        } else {
            $text = $message->getContent();
            $contentBlocks[] = ['type' => 'text', 'text' => $text];
        }

        // 注入缓存点
        if ($message->getCachePoint() !== null && ! empty($contentBlocks)) {
            $lastIndex = count($contentBlocks) - 1;
            // 只在 text 块追加 cache_control（image 块不支持）
            if ($contentBlocks[$lastIndex]['type'] === 'text') {
                $contentBlocks[$lastIndex]['cache_control'] = ['type' => 'ephemeral'];
            } else {
                // 往前找最后一个 text 块
                for ($i = count($contentBlocks) - 1; $i >= 0; --$i) {
                    if ($contentBlocks[$i]['type'] === 'text') {
                        $contentBlocks[$i]['cache_control'] = ['type' => 'ephemeral'];
                        break;
                    }
                }
            }
        }

        // 若只有一个 text 块且无缓存点，可以用字符串形式（更简洁）
        if (count($contentBlocks) === 1 && $contentBlocks[0]['type'] === 'text' && ! isset($contentBlocks[0]['cache_control'])) {
            return [
                'role' => 'user',
                'content' => $contentBlocks[0]['text'],
            ];
        }

        return [
            'role' => 'user',
            'content' => $contentBlocks,
        ];
    }

    /**
     * 转换 AssistantMessage 为 Anthropic 格式.
     */
    private static function convertAssistantMessage(AssistantMessage $message): array
    {
        $contentBlocks = [];

        // thinking 内容（Extended Thinking 响应回传）
        $reasoningContent = $message->getReasoningContent();
        if ($reasoningContent !== null && $reasoningContent !== '') {
            $contentBlocks[] = [
                'type' => 'thinking',
                'thinking' => $reasoningContent,
            ];
        }

        // 文本内容
        $text = $message->getContent();
        if ($text !== '') {
            $contentBlocks[] = ['type' => 'text', 'text' => $text];
        }

        // 工具调用
        if ($message->hasToolCalls()) {
            foreach ($message->getToolCalls() as $toolCall) {
                $input = $toolCall->getArguments();
                if (empty($input)) {
                    $input = new stdClass();
                }
                $contentBlocks[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall->getId(),
                    'name' => $toolCall->getName(),
                    'input' => $input,
                ];
            }
        }

        // 注入缓存点（不在 thinking 块上加 cache_control，Anthropic 限制）
        if ($message->getCachePoint() !== null && ! empty($contentBlocks)) {
            for ($i = count($contentBlocks) - 1; $i >= 0; --$i) {
                if ($contentBlocks[$i]['type'] !== 'thinking') {
                    $contentBlocks[$i]['cache_control'] = ['type' => 'ephemeral'];
                    break;
                }
            }
        }

        // 若只有单个 text 块且无缓存点，用字符串格式
        if (count($contentBlocks) === 1 && $contentBlocks[0]['type'] === 'text' && ! isset($contentBlocks[0]['cache_control'])) {
            return [
                'role' => 'assistant',
                'content' => $contentBlocks[0]['text'],
            ];
        }

        return [
            'role' => 'assistant',
            'content' => $contentBlocks,
        ];
    }

    /**
     * 构建 Anthropic 顶层 system 字段.
     *
     * @param array<array{type: string, text: string}> $blocks 文本内容块数组
     * @param null|CachePoint $cachePoint 缓存点配置
     * @return array|string 若只有一个无缓存点的块，返回字符串；否则返回块数组
     */
    private static function buildSystemField(array $blocks, ?CachePoint $cachePoint): array|string
    {
        if (empty($blocks)) {
            return [];
        }

        // 若有缓存点，在最后一个块追加 cache_control
        if ($cachePoint !== null) {
            $lastIndex = count($blocks) - 1;
            $cacheControl = ['type' => 'ephemeral'];
            if ($cachePoint->getTtl() !== null) {
                $cacheControl['ttl'] = $cachePoint->getTtl();
            }
            $blocks[$lastIndex]['cache_control'] = $cacheControl;
        }

        // 无缓存点且只有单个块时，返回字符串（更简洁）
        if (count($blocks) === 1 && ! isset($blocks[0]['cache_control'])) {
            return $blocks[0]['text'];
        }

        return $blocks;
    }

    /**
     * 将图片 URL 转换为 Anthropic image 内容块.
     *
     * 支持 base64 data URL 格式：data:image/{type};base64,{data}
     * 不支持格式返回 null.
     *
     * @return null|array{type: string, source: array}
     */
    private static function convertImageUrl(string $imageUrl): ?array
    {
        if (str_starts_with($imageUrl, 'data:image/') && str_contains($imageUrl, ';base64,')) {
            [$metaPart, $base64Data] = explode(',', $imageUrl, 2);
            preg_match('/data:(image\/[^;]+)/', $metaPart, $matches);
            $mimeType = $matches[1] ?? 'image/jpeg';

            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimeType,
                    'data' => $base64Data,
                ],
            ];
        }

        // HTTP(S) URL，使用 url 类型
        if (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://')) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => $imageUrl,
                ],
            ];
        }

        return null;
    }
}
