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

namespace Hyperf\Odin\Api\Transport;

use IteratorAggregate;

/**
 * 标记接口：产出 SSEEvent 事件流.
 *
 * 实现此接口的迭代器（如 SSEClient、SwowSSEClient）表明其 getIterator()
 * 产出的元素类型为 SSEEvent，ChatCompletionStreamResponse 会使用
 * iterateWithSSEClient() 路径对其进行解析.
 *
 * @extends IteratorAggregate<int, SSEEvent>
 */
interface SseEventProducerInterface extends IteratorAggregate
{
    /**
     * 提前关闭流迭代，通知迭代器在下一次 yield 前退出.
     */
    public function closeEarly(): void;
}
