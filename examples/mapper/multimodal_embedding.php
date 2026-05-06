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
! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 2));

require_once dirname(__FILE__, 3) . '/vendor/autoload.php';

use Hyperf\Context\ApplicationContext;
use Hyperf\Di\ClassLoader;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Odin\Api\Request\MultiModalEmbeddingItem;
use Hyperf\Odin\Logger;
use Hyperf\Odin\ModelMapper;

ClassLoader::init();
$container = ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));

$logger = new Logger();

// 通过环境变量指定多模态嵌入模型，例如：
//   Doubao:    MODEL_MAPPER_EMBEDDING_TEST_MODEL_ID=doubao-embedding-vision-250615
//   DashScope: MODEL_MAPPER_EMBEDDING_TEST_MODEL_ID=qwen3-vl-embedding
$modelId = \Hyperf\Support\env('MODEL_MAPPER_EMBEDDING_TEST_MODEL_ID', '');
$modelMapper = $container->get(ModelMapper::class);
$model = $modelMapper->getEmbeddingModel($modelId);

$imageUrl = 'https://tos-tools.tos-cn-beijing.volces.com/misc/sample1.jpg';
$videoUrl = 'https://help-static-aliyun-doc.aliyuncs.com/file-manage-files/zh-CN/20250107/lbcemt/new+video.mp4';

// -----------------------------------------------------------------------
// 纯文本嵌入（单组）
// -----------------------------------------------------------------------
echo '--- 纯文本（单组）---' . PHP_EOL;
$start = microtime(true);
$r = $model->multimodalEmbeddings([[MultiModalEmbeddingItem::text('这是一段测试文本，用于验证多模态嵌入接口。')]]);
$elapsed = microtime(true) - $start;
$firstItem = $r->getData()[0] ?? null;
printf('维度: %d  耗时: %.3fs%s', $firstItem ? count($firstItem->getEmbedding()) : 0, $elapsed, PHP_EOL);
echo '向量前 5 位: ' . ($firstItem ? implode(', ', array_slice($firstItem->getEmbedding(), 0, 5)) : '-') . PHP_EOL;
echo '  usage: ' . json_encode($r->toArray()['usage'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;

echo PHP_EOL;

// -----------------------------------------------------------------------
// 文本 + 图片融合嵌入（单组，多 item → 1 个融合向量）
// -----------------------------------------------------------------------
echo '--- 文本 + 图片融合（单组）---' . PHP_EOL;
$start = microtime(true);
$r = $model->multimodalEmbeddings([[
    MultiModalEmbeddingItem::text('商品描述：白色运动鞋，轻量透气，适合跑步和日常穿着'),
    MultiModalEmbeddingItem::image($imageUrl),
]]);
$elapsed = microtime(true) - $start;
$firstItem = $r->getData()[0] ?? null;
printf('维度: %d  耗时: %.3fs%s', $firstItem ? count($firstItem->getEmbedding()) : 0, $elapsed, PHP_EOL);
echo '向量前 5 位: ' . ($firstItem ? implode(', ', array_slice($firstItem->getEmbedding(), 0, 5)) : '-') . PHP_EOL;
echo '  usage: ' . json_encode($r->toArray()['usage'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;

echo PHP_EOL;

// -----------------------------------------------------------------------
// 批量多模态嵌入（多组，仅 DashScope 支持）
// 每组一个 item，DashScope 在单次请求内返回多个独立向量（enable_fusion=false）
// -----------------------------------------------------------------------
echo '--- 批量多模态嵌入（多组，仅 DashScope）---' . PHP_EOL;
$start = microtime(true);
$response = $model->multimodalEmbeddings([
    [MultiModalEmbeddingItem::text('以图搜图场景下的查询文本')],
    [MultiModalEmbeddingItem::image($imageUrl)],
    [MultiModalEmbeddingItem::text('另一段文本用于对比')],
]);
$elapsed = microtime(true) - $start;
printf('共 %d 条  耗时: %.3fs%s', count($response->getData()), $elapsed, PHP_EOL);
foreach ($response->getData() as $item) {
    printf(
        '  [%d] 维度: %d  前 3 位: %s%s',
        $item->getIndex(),
        count($item->getEmbedding()),
        implode(', ', array_slice($item->getEmbedding(), 0, 3)),
        PHP_EOL
    );
}
echo '  usage: ' . json_encode($response->toArray()['usage'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;
