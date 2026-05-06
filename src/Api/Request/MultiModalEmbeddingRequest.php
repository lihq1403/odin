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

namespace Hyperf\Odin\Api\Request;

use Hyperf\Odin\Contract\Api\Request\RequestInterface;
use Hyperf\Odin\Exception\InvalidArgumentException;

/**
 * vendor-agnostic 多模态嵌入请求，持有输入组列表及公共参数.
 * 各 Client 实现自行将 inputs 序列化为对应厂商格式.
 *
 * 每个元素（组）代表一次独立的嵌入任务：
 *   - 单组单 item：单个文本 / 图片 / 视频的独立向量
 *   - 单组多 item：多模态融合向量（如文本 + 图片 → 1 个向量）
 *   - 多组：批量模式，每组返回一个独立向量（目前仅 DashScope 支持）
 */
class MultiModalEmbeddingRequest implements RequestInterface
{
    /**
     * @param array<int, array<int, MultiModalEmbeddingItem>> $inputs 输入组列表，每组为一次嵌入任务
     * @param string $model 模型 ID
     * @param null|string $encodingFormat 编码格式
     * @param null|int $dimensions 向量维度（可选）
     * @param null|string $instruct 任务指令，引导模型如何生成嵌入（可选）
     * @param bool $enableFusion 是否启用融合向量模式，将同组内所有输入合并为一个向量，默认 true。
     *                           仅 DashScope 部分模型（如 qwen3-vl-embedding）生效，Doubao 始终为融合模式。
     *                           多组（批量）模式下该参数无效，固定使用 non-fusion 模式。
     */
    public function __construct(
        private readonly array $inputs,
        private readonly string $model,
        private readonly ?string $encodingFormat = 'float',
        private readonly ?int $dimensions = null,
        private readonly ?string $instruct = null,
        private readonly bool $enableFusion = true,
    ) {}

    public function validate(): void
    {
        if (empty($this->model)) {
            throw new InvalidArgumentException('Model is required.');
        }

        if (empty($this->inputs)) {
            throw new InvalidArgumentException('Inputs are required.');
        }

        foreach ($this->inputs as $group) {
            if (empty($group)) {
                throw new InvalidArgumentException('Each input group must contain at least one item.');
            }
            foreach ($group as $item) {
                $item->validate();
            }
        }
    }

    /**
     * 各 Client 自行序列化，此处返回空数组作为占位.
     *
     * @return array<string, mixed>
     */
    public function createOptions(): array
    {
        return [];
    }

    /**
     * @return array<int, array<int, MultiModalEmbeddingItem>>
     */
    public function getInputs(): array
    {
        return $this->inputs;
    }

    /**
     * 取第一组 items，用于只支持单组的 Client 实现.
     *
     * @return array<int, MultiModalEmbeddingItem>
     */
    public function getFirstGroupItems(): array
    {
        return $this->inputs[0] ?? [];
    }

    /**
     * 是否为批量模式（多组）.
     */
    public function isBatch(): bool
    {
        return count($this->inputs) > 1;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getEncodingFormat(): ?string
    {
        return $this->encodingFormat;
    }

    public function getDimensions(): ?int
    {
        return $this->dimensions;
    }

    /**
     * 获取任务指令.
     */
    public function getInstruct(): ?string
    {
        return $this->instruct;
    }

    /**
     * 是否启用融合向量模式（仅单组有效）.
     */
    public function isEnableFusion(): bool
    {
        return $this->enableFusion;
    }
}
