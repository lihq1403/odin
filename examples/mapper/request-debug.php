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
use Hyperf\Odin\Api\Response\ChatCompletionChoice;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\ModelMapper;
use Hyperf\Odin\Utils\MessageUtil;
use Hyperf\Odin\Utils\ToolUtil;

ClassLoader::init();

$container = ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));

// 读取当前目录下的 request.json
$requestFile = __DIR__ . '/request.json';
if (! file_exists($requestFile)) {
    echo 'request.json not found at: ' . $requestFile . PHP_EOL;
    exit(1);
}

$requestJson = file_get_contents($requestFile);
$requestData = json_decode($requestJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo 'Failed to parse request.json: ' . json_last_error_msg() . PHP_EOL;
    exit(1);
}

// 解析基础参数
$modelId = $requestData['model'] ?? '';
$temperature = (float) ($requestData['temperature'] ?? 0.7);
$maxTokens = (int) ($requestData['max_tokens'] ?? 0);
$isStream = (bool) ($requestData['stream'] ?? false);
$stop = $requestData['stop'] ?? [];

// 将 messages 数组转换为消息对象
$messages = array_values(array_filter(
    array_map(
        static fn (array $msg) => MessageUtil::createFromArray($msg),
        $requestData['messages'] ?? []
    )
));

// 将 tools 数组转换为 ToolDefinition 对象
$tools = [];
foreach ($requestData['tools'] ?? [] as $toolArray) {
    $toolDefinition = ToolUtil::createFromArray($toolArray);
    if ($toolDefinition !== null) {
        $tools[$toolDefinition->getName()] = $toolDefinition;
    }
}

// 初始化模型
$modelMapper = $container->get(ModelMapper::class);
$model = $modelMapper->getModel($modelId);

echo '--- Request Debug ---' . PHP_EOL;
echo 'Model: ' . $modelId . PHP_EOL;
echo 'Stream: ' . ($isStream ? 'true' : 'false') . PHP_EOL;
echo 'Temperature: ' . $temperature . PHP_EOL;
echo 'Max tokens: ' . $maxTokens . PHP_EOL;
echo 'Messages: ' . count($messages) . PHP_EOL;
echo 'Tools: ' . count($tools) . PHP_EOL;
echo '---------------------' . PHP_EOL . PHP_EOL;

$start = microtime(true);

if ($isStream) {
    $response = $model->chatStream(
        messages: $messages,
        temperature: $temperature,
        maxTokens: $maxTokens,
        stop: $stop,
        tools: $tools,
    );

    $inThinking = false;
    $thinkingEnded = false;

    /** @var ChatCompletionChoice $choice */
    foreach ($response->getStreamIterator() as $choice) {
        $message = $choice->getMessage();
        if ($message instanceof AssistantMessage) {
            $reasoningContent = $message->getReasoningContent();
            $content = $message->getContent();

            if ($reasoningContent !== null && $reasoningContent !== '') {
                if (! $inThinking) {
                    echo '<think>' . PHP_EOL;
                    $inThinking = true;
                }
                echo $reasoningContent;
            }

            if ($content !== null && $content !== '') {
                if ($inThinking && ! $thinkingEnded) {
                    echo PHP_EOL . '</think>' . PHP_EOL;
                    $thinkingEnded = true;
                }
                echo $content;
            }
        }
    }

    echo PHP_EOL;
    echo '--- Stream elapsed: ' . round(microtime(true) - $start, 3) . 's ---' . PHP_EOL;
} else {
    $response = $model->chat(
        messages: $messages,
        temperature: $temperature,
        maxTokens: $maxTokens,
        stop: $stop,
        tools: $tools,
    );

    $choice = $response->getFirstChoice();
    if ($choice !== null) {
        $message = $choice->getMessage();
        if ($message instanceof AssistantMessage) {
            $reasoningContent = $message->getReasoningContent();
            if ($reasoningContent !== null && $reasoningContent !== '') {
                echo '<think>' . PHP_EOL;
                echo $reasoningContent . PHP_EOL;
                echo '</think>' . PHP_EOL;
            }
            echo $message->getContent() . PHP_EOL;

            // 如果有工具调用，输出工具调用信息
            foreach ($message->getToolCalls() as $toolCall) {
                echo PHP_EOL . '--- Tool Call ---' . PHP_EOL;
                echo 'ID: ' . $toolCall->getId() . PHP_EOL;
                echo 'Name: ' . $toolCall->getName() . PHP_EOL;
                echo 'Arguments: ' . $toolCall->getSerializedArguments() . PHP_EOL;
            }
        }
    }

    echo PHP_EOL;
    echo '--- Non-stream elapsed: ' . round(microtime(true) - $start, 3) . 's ---' . PHP_EOL;
}
