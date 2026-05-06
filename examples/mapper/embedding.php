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
use Hyperf\Odin\Exception\InvalidArgumentException;
use Hyperf\Odin\Logger;
use Hyperf\Odin\ModelMapper;

ClassLoader::init();
$container = ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));

$logger = new Logger();

// 通过环境变量指定模型，例如：
//   MODEL_MAPPER_EMBEDDING_TEST_MODEL_ID=doubao-embedding-vision-250615
//   MODEL_MAPPER_EMBEDDING_TEST_MODEL_ID=text-embedding-v3
$modelId = \Hyperf\Support\env('MODEL_MAPPER_EMBEDDING_TEST_MODEL_ID', '');
$modelMapper = $container->get(ModelMapper::class);
$model = $modelMapper->getEmbeddingModel($modelId);

// -----------------------------------------------------------------------
// 单条嵌入
// -----------------------------------------------------------------------
echo '--- 单条嵌入 ---' . PHP_EOL;
$start = microtime(true);
$singleResponse = $model->embeddings('这是一段测试文本，用于验证嵌入接口是否正常工作。');
$elapsed = microtime(true) - $start;
$firstItem = $singleResponse->getData()[0] ?? null;
printf('维度: %d  耗时: %.3fs%s', $firstItem ? count($firstItem->getEmbedding()) : 0, $elapsed, PHP_EOL);
echo '向量前 5 位: ' . ($firstItem ? implode(', ', array_slice($firstItem->getEmbedding(), 0, 5)) : '-') . PHP_EOL;
echo '  usage: ' . json_encode($singleResponse->toArray()['usage'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL;

echo PHP_EOL;

// -----------------------------------------------------------------------
// 批量嵌入
// -----------------------------------------------------------------------
// 批量嵌入（逐条调用，适配 Doubao 不支持批量的限制；DashScope 支持单次批量）
// -----------------------------------------------------------------------
echo '--- 批量嵌入 ---' . PHP_EOL;
$inputs = [
    '以图搜图场景下的查询文本',
    '跨模态语义检索示例',
    '商品描述：白色运动鞋，轻量透气',
];
$start = microtime(true);
try {
    $response = $model->embeddings($inputs);
    printf('共 %d 条  耗时: %.3fs%s', count($response->getData()), microtime(true) - $start, PHP_EOL);
    foreach ($response->getData() as $item) {
        printf(
            '  [%d] 维度: %d  前 3 位: %s%s',
            $item->getIndex(),
            count($item->getEmbedding()),
            implode(', ', array_slice($item->getEmbedding(), 0, 3)),
            PHP_EOL
        );
    }
    $usage = $response->toArray()['usage'] ?? [];
    echo '  usage: ' . json_encode($usage, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (InvalidArgumentException $e) {
    // 部分模型（如 Doubao）不支持批量，需由调用方自行逐条调用
    echo '  [提示] 该模型不支持批量嵌入: ' . $e->getMessage() . PHP_EOL;
}
