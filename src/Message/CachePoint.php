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

/**
 * 缓存点定义，不同服务商有不同的序列化格式.
 *
 * - AWS Bedrock：使用 toArray() 输出 cachePoint 字段，由 ConverseConverter 负责写入请求体
 * - OpenRouter/Anthropic：使用 wrapContentWithCacheControl() 将 cache_control 注入到消息内容块中
 *
 * @document https://docs.aws.amazon.com/zh_cn/bedrock/latest/userguide/prompt-caching.html
 * @document https://openrouter.ai/docs/guides/best-practices/prompt-caching
 */
class CachePoint
{
    /**
     * 缓存点类型.
     * default: AWS Bedrock 默认缓存类型
     * ephemeral: Anthropic/OpenRouter 缓存类型.
     */
    private string $type;

    /**
     * 缓存 TTL，仅 Anthropic/OpenRouter 有效.
     * null: 使用默认 TTL（5 分钟）
     * "1h": 1 小时 TTL.
     */
    private ?string $ttl;

    public function __construct(string $type = 'default', ?string $ttl = null)
    {
        $this->type = $type;
        $this->ttl = $ttl;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTtl(): ?string
    {
        return $this->ttl;
    }

    /**
     * AWS Bedrock 格式，供 ConverseConverter 使用.
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
        ];
    }

    /**
     * OpenRouter/Anthropic 显式缓存格式：将消息内容包装为带有 cache_control 的内容块数组.
     *
     * - 若 content 为 string，转为 [["type"=>"text","text"=>...,"cache_control"=>{...}]]
     * - 若 content 已为 array，在最后一个 text 块上追加 cache_control
     *
     * @param array<int, array<string, mixed>>|string $content 原始消息内容
     * @return array<int, array<string, mixed>>
     */
    public function wrapContentWithCacheControl(array|string $content): array
    {
        $cacheControl = ['type' => 'ephemeral'];
        if ($this->ttl !== null) {
            $cacheControl['ttl'] = $this->ttl;
        }

        if (is_string($content)) {
            return [
                [
                    'type' => 'text',
                    'text' => $content,
                    'cache_control' => $cacheControl,
                ],
            ];
        }

        // 找到最后一个 text 块加上 cache_control
        for ($i = count($content) - 1; $i >= 0; --$i) {
            if (($content[$i]['type'] ?? '') === 'text') {
                $content[$i]['cache_control'] = $cacheControl;
                break;
            }
        }
        return $content;
    }
}
