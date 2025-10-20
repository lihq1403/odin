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

$userMessage = new UserMessage();
$userMessage->addContent(UserMessageContent::text('请分析下面图片中的内容，并描述其主要元素和可能的用途。'));
$userMessage->addContent(UserMessageContent::imageUrl('https://tos-tools.tos-cn-beijing.volces.com/misc/sample1.jpg'));

$start = microtime(true);

// 使用非流式API调用
$response = $model->chat([$userMessage]);

// 输出完整响应
$message = $response->getFirstChoice()->getMessage();
if ($message instanceof AssistantMessage) {
    echo $message->getReasoningContent() ?? $message->getContent();
}

echo PHP_EOL;
echo '耗时' . (microtime(true) - $start) . '秒' . PHP_EOL;
