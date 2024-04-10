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

namespace Hyperf\Support;

use Closure;

/**
 * Return the default value of the given value.
 */
function value(mixed $value, ...$args)
{
    return $value instanceof Closure ? $value(...$args) : $value;
}

/**
 * Gets the value of an environment variable.
 *
 * @param string $key
 * @param null|mixed $default
 */
function env($key, $default = null)
{
    $value = getenv($key);
    if ($value === false) {
        return value($default);
    }
    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'empty':
        case '(empty)':
            return '';
        case 'null':
        case '(null)':
            return null;
    }
    if (($valueLength = strlen($value)) > 1 && $value[0] === '"' && $value[$valueLength - 1] === '"') {
        return substr($value, 1, -1);
    }
    return $value;
}
