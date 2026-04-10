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
 * Anthropic 原厂模型实现.
 *
 * 支持 Anthropic Messages API 原生格式，包含：
 * - Extended Thinking（claude-3-5/claude-3-7 系列）
 * - Prompt Caching（自动缓存策略，与 AWS Bedrock 一致）
 * - 工具调用
 * - 多模态输入（图片）
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
class AnthropicModel extends AbstractModel
{
    protected bool $streamIncludeUsage = true;

    /**
     * 获取客户端实例.
     */
    protected function getClient(): ClientInterface
    {
        $config = $this->config;

        return ClientFactory::createClient(
            'anthropic',
            $config,
            $this->getApiRequestOptions(),
            $this->logger
        );
    }

    /**
     * 返回 Anthropic API 版本路径，与其他服务商保持一致，由 AbstractModel 统一处理路径去重.
     */
    protected function getApiVersionPath(): string
    {
        return '/v1';
    }
}
