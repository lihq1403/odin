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

namespace Hyperf\Odin\Contract\Model;

use Hyperf\Odin\Api\Request\MultiModalEmbeddingItem;
use Hyperf\Odin\Api\Response\EmbeddingResponse;
use Hyperf\Odin\Model\Embedding;

interface EmbeddingInterface
{
    public function embedding(string $input): Embedding;

    public function embeddings(array|string $input, ?string $encoding_format = 'float', ?string $user = null, array $businessParams = []): EmbeddingResponse;

    /**
     * 多模态嵌入：单组输入，返回一个 Embedding 便于直接使用.
     * 多模态融合场景（文本 + 图片 + 视频 → 1 个向量）使用此方法.
     *
     * @param array<int, MultiModalEmbeddingItem> $items
     */
    public function multimodalEmbedding(array $items): Embedding;

    /**
     * 多模态嵌入批量版本：支持多组输入，返回完整响应（含 usage）.
     * 每组返回一个独立向量；目前仅 DashScope 支持多组批量，其余提供商仅支持单组.
     *
     * @param array<int, array<int, MultiModalEmbeddingItem>> $inputs
     */
    public function multimodalEmbeddings(array $inputs): EmbeddingResponse;

    public function getModelName(): string;

    public function getVectorSize(): int;
}
