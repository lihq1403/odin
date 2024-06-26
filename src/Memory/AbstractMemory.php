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

namespace Hyperf\Odin\Memory;

abstract class AbstractMemory implements MemoryInterface
{
    protected array $conversations = [];

    public function count(): int
    {
        return count($this->conversations);
    }
}
