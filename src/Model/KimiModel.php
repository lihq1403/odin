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

namespace Hyperf\Odin\Model;

use Hyperf\Odin\Contract\Api\ClientInterface;
use Hyperf\Odin\Factory\ClientFactory;

/**
 * Kimi（月之暗面）模型实现.
 *
 * 底层使用专用 Kimi Client，会在发请求前将 tool_call_id 自动转换为
 * Kimi 要求的 function.<function_name>:<tool_call_num> 格式，
 * 同时支持 reasoning_content 多轮保留。
 *
 * @see https://platform.moonshot.cn/docs/api/
 */
class KimiModel extends AbstractModel
{
    protected bool $streamIncludeUsage = true;

    /**
     * 获取客户端实例.
     */
    protected function getClient(): ClientInterface
    {
        $config = $this->config;
        $this->processApiBaseUrl($config);

        return ClientFactory::createClient(
            'kimi',
            $config,
            $this->getApiRequestOptions(),
            $this->logger
        );
    }

    /**
     * 获取 API 版本路径.
     */
    protected function getApiVersionPath(): string
    {
        return 'v1';
    }
}
