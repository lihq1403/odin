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
 * 系统消息类.
 *
 * 用于表示系统级别的指令或消息
 */
class SystemMessage extends AbstractMessage
{
    /**
     * 角色固定为系统
     */
    protected Role $role = Role::System;

    /**
     * 从数组创建消息实例.
     *
     * @param array $message 消息数组
     * @return self 消息实例
     */
    public static function fromArray(array $message): self
    {
        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $text = '';
            foreach ($content as $item) {
                if (isset($item['text'])) {
                    $text .= $item['text'];
                }
            }
            $content = $text;
        }

        return new self($content);
    }

    public function toArray(): array
    {
        // 有缓存点时，由 CachePoint 负责将 content 包装为带 cache_control 的内容块（OpenRouter/Anthropic 格式）
        if ($this->cachePoint !== null) {
            return [
                'role' => $this->role->value,
                'content' => $this->cachePoint->wrapContentWithCacheControl($this->content),
            ];
        }
        return [
            'role' => $this->role->value,
            'content' => $this->content,
        ];
    }
}
