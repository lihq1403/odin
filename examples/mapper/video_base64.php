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

use GuzzleHttp\Client;
use Hyperf\Context\ApplicationContext;
use Hyperf\Di\ClassLoader;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Odin\Logger;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Message\UserMessage;
use Hyperf\Odin\Message\UserMessageContent;
use Hyperf\Odin\ModelMapper;

ClassLoader::init();
$container = ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));

// 创建日志记录器
$logger = new Logger();

// 初始化模型
$modelId = \Hyperf\Support\env('MODEL_MAPPER_TEST_MODEL_ID', '');
$modelMapper = $container->get(ModelMapper::class);
$model = $modelMapper->getModel($modelId);

// 将视频 URL 下载并转换为 base64 格式
// 注意：视频文件通常较大，base64 编码后体积会增加约 33%，请确认 API 支持的请求大小上限
$videoUrl = 'https://ark-project.tos-cn-beijing.volces.com/doc_video/ark_vlm_video_input.mp4';
$httpClient = new Client(['timeout' => 120, 'connect_timeout' => 10]);
$videoData = $httpClient->get($videoUrl)->getBody()->getContents();
$base64Video = base64_encode($videoData);
$dataUrl = 'data:video/mp4;base64,' . $base64Video;

echo '已将视频转换为 base64 格式，数据长度: ' . strlen($dataUrl) . ' 字节' . PHP_EOL;

$userMessage = new UserMessage();
$userMessage->addContent(UserMessageContent::text('请分析这段视频的内容，描述其主要场景、人物或事物，以及视频所表达的主题。'));
$userMessage->addContent(UserMessageContent::videoUrl($dataUrl));

$start = microtime(true);

// 使用非流式 API 调用
$response = $model->chat([$userMessage]);

// 输出完整响应
$message = $response->getFirstChoice()->getMessage();
if ($message instanceof AssistantMessage) {
    echo $message->getReasoningContent() ?? $message->getContent();
}

echo PHP_EOL;
echo '耗时' . (microtime(true) - $start) . '秒' . PHP_EOL;
