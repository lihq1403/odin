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

namespace HyperfTest\Cases\Utils;

use Hyperf\Odin\Utils\StreamChunkParseFailureContext;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class StreamChunkParseFailureContextTest extends TestCase
{
    public function testForRawLineShortPayloadNoTail(): void
    {
        $raw = '{"a":1}';
        $ctx = StreamChunkParseFailureContext::forRawLine($raw, 'syntax error');
        $this->assertSame('syntax error', $ctx['error']);
        $this->assertSame(strlen($raw), $ctx['raw_byte_length']);
        $this->assertSame(hash('sha256', $raw), $ctx['raw_sha256_hex']);
        $this->assertSame($raw, $ctx['raw_preview_head']);
        $this->assertFalse($ctx['raw_preview_truncated']);
        $this->assertArrayNotHasKey('raw_preview_tail', $ctx);
    }

    public function testForRawLineLongPayloadHasTailAndTruncationFlag(): void
    {
        $head = str_repeat('A', 65536);
        $middle = str_repeat('B', 1000);
        $tail = str_repeat('C', 5000);
        $raw = $head . $middle . $tail;
        $ctx = StreamChunkParseFailureContext::forRawLine($raw, 'boom');
        $this->assertTrue($ctx['raw_preview_truncated']);
        $this->assertSame(65536, strlen((string) $ctx['raw_preview_head']));
        $this->assertArrayHasKey('raw_preview_tail', $ctx);
        $this->assertLessThanOrEqual(4096, strlen((string) $ctx['raw_preview_tail']));
        $this->assertStringEndsWith('C', (string) $ctx['raw_preview_tail']);
    }

    public function testForInvalidChunkShapeWithNull(): void
    {
        $ctx = StreamChunkParseFailureContext::forInvalidChunkShape(null);
        $this->assertSame('null', $ctx['decoded_shape']);
        $this->assertSame('stream_chunk_not_array_json_serialized', $ctx['error']);
    }
}
