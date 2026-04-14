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

namespace Hyperf\Odin\Utils;

/**
 * 流式 chunk / SSE 行 JSON 解码失败时，生成结构化诊断字段（头尾预览 + 长度 + 指纹）.
 */
final class StreamChunkParseFailureContext
{
    /** 单次日志中「头部」预览最大字节数 */
    private const HEAD_MAX_BYTES = 65536;

    /** 超长时在「尾部」再保留的字节数，便于发现截断、半包 JSON */
    private const TAIL_MAX_BYTES = 4096;

    /**
     * @return array<string, bool|int|string>
     */
    public static function forRawLine(string $rawLine, string $errorMessage): array
    {
        $byteLength = strlen($rawLine);
        $headMax = self::HEAD_MAX_BYTES;
        $head = $byteLength <= $headMax ? $rawLine : substr($rawLine, 0, $headMax);
        $result = [
            'error' => $errorMessage,
            'raw_byte_length' => $byteLength,
            'raw_sha256_hex' => hash('sha256', $rawLine),
            'raw_preview_head' => $head,
            'raw_preview_truncated' => $byteLength > strlen($head),
        ];
        if ($result['raw_preview_truncated']) {
            $tailLen = min(self::TAIL_MAX_BYTES, $byteLength);
            $result['raw_preview_tail'] = substr($rawLine, -$tailLen);
        }

        return $result;
    }

    /**
     * 迭代器产出非数组 chunk 时使用（先序列化再套用同一套预览规则）.
     *
     * @return array<string, bool|int|string>
     */
    public static function forInvalidChunkShape(mixed $value): array
    {
        if (is_string($value)) {
            return array_merge(
                ['decoded_shape' => 'string'],
                self::forRawLine($value, 'stream_chunk_not_array')
            );
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            $encoded = '';
        }

        return array_merge(
            ['decoded_shape' => get_debug_type($value)],
            self::forRawLine($encoded, 'stream_chunk_not_array_json_serialized')
        );
    }
}
