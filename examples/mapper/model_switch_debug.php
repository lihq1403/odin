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
use Hyperf\Odin\Agent\Tool\ToolUseAgent;
use Hyperf\Odin\Api\Response\ChatCompletionChoice;
use Hyperf\Odin\Logger;
use Hyperf\Odin\Memory\MemoryManager;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Message\SystemMessage;
use Hyperf\Odin\Message\UserMessage;
use Hyperf\Odin\ModelMapper;
use Hyperf\Odin\Tool\Definition\ToolDefinition;
use Hyperf\Odin\Tool\Definition\ToolParameters;

ClassLoader::init();
$container = ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));

// 从环境变量读取两个模型 ID
$model1Id = \Hyperf\Support\env('SWITCH_DEBUG_MODEL_1', '');
$model2Id = \Hyperf\Support\env('SWITCH_DEBUG_MODEL_2', '');

if (empty($model1Id) || empty($model2Id)) {
    echo "[ERROR] 请设置环境变量 SWITCH_DEBUG_MODEL_1 和 SWITCH_DEBUG_MODEL_2\n";
    echo "示例: SWITCH_DEBUG_MODEL_1=claude-3-5-sonnet SWITCH_DEBUG_MODEL_2=deepseek-r1 php examples/mapper/model_switch_debug.php\n";
    exit(1);
}

echo "===== 模型切换调试脚本 =====\n";
echo "第一个模型: {$model1Id}\n";
echo "第二个模型: {$model2Id}\n";
echo "============================\n\n";

$logger = new Logger();
$modelMapper = $container->get(ModelMapper::class);
$model1 = $modelMapper->getModel($model1Id);
$model2 = $modelMapper->getModel($model2Id);

// 共享同一个 MemoryManager
$memory = new MemoryManager();
$memory->addSystemMessage(new SystemMessage('你是一个能够使用工具的AI助手。现在时间为：' . date('Y-m-d H:i:s')));

// 计算器工具（用于触发工具调用，制造含 reasoning_content 的历史消息）
$calculatorTool = new ToolDefinition(
    name: 'calculator',
    description: '用于执行基本数学运算的计算器工具',
    parameters: ToolParameters::fromArray([
        'type' => 'object',
        'properties' => [
            'operation' => [
                'type' => 'string',
                'enum' => ['add', 'subtract', 'multiply', 'divide', 'power'],
                'description' => '要执行的数学运算类型',
            ],
            'a' => ['type' => 'number', 'description' => '第一个操作数'],
            'b' => ['type' => 'number', 'description' => '第二个操作数'],
        ],
        'required' => ['operation', 'a', 'b'],
    ]),
    toolHandler: function ($params) {
        $a = $params['a'];
        $b = $params['b'];
        return match ($params['operation']) {
            'add' => ['result' => $a + $b],
            'subtract' => ['result' => $a - $b],
            'multiply' => ['result' => $a * $b],
            'divide' => $b == 0 ? ['error' => '除数不能为零'] : ['result' => $a / $b],
            'power' => ['result' => pow($a, $b)],
            default => ['error' => '未知操作'],
        };
    }
);

$tools = [$calculatorTool->getName() => $calculatorTool];

// -----------------------------------------------------------------------
// 第一轮：使用 model1，触发工具调用，让 memory 中产生含 reasoning_content 的消息
// -----------------------------------------------------------------------
echo "===== 第一轮：使用模型 [{$model1Id}] =====\n";

$agent1 = new ToolUseAgent(
    model: $model1,
    memory: $memory,
    tools: $tools,
    temperature: 0.6,
    logger: $logger,
);

$start = microtime(true);
$userMessage1 = new UserMessage('用计算器计算 128 的 2 次方，然后告诉我结果。');
$response1 = $agent1->chatStreamed($userMessage1);

$content1 = '';
/** @var ChatCompletionChoice $choice */
foreach ($response1 as $choice) {
    /** @var AssistantMessage $message */
    $message = $choice->getMessage();
    $delta = $message->getReasoningContent() ?: $message->getContent();
    if ($delta !== null) {
        echo $delta;
        $content1 .= $delta;
    }
}
echo "\n";
echo sprintf("[第一轮耗时: %.2f 秒]\n", microtime(true) - $start);

// 打印第一轮结束后的 memory 快照，检查 reasoning_content 是否被正确写入
echo "\n===== Memory 快照（第一轮结束后）=====\n";
$messages = $memory->getMessages();
foreach ($messages as $i => $msg) {
    $role = $msg->getRole();
    $preview = mb_substr((string) ($msg->getContent() ?? ''), 0, 80);
    $preview = str_replace(["\n", "\r"], ' ', $preview);
    $hasReasoning = ($msg instanceof AssistantMessage && $msg->hasReasoningContent()) ? ' [有 reasoning_content]' : '';
    echo sprintf("  [%d] %s: %s...%s\n", $i, $role->value, $preview, $hasReasoning);
}
echo "=========================================\n\n";

// -----------------------------------------------------------------------
// 第二轮：切换到 model2，共享同一个 memory，模拟线上模型切换场景
// -----------------------------------------------------------------------
echo "===== 第二轮：切换至模型 [{$model2Id}] =====\n";

$agent2 = new ToolUseAgent(
    model: $model2,
    memory: $memory,
    tools: $tools,
    temperature: 0.6,
    logger: $logger,
);

$start = microtime(true);
$userMessage2 = new UserMessage('在刚才的结果基础上再乘以 3，结果是多少？');

try {
    $response2 = $agent2->chatStreamed($userMessage2);

    $content2 = '';
    foreach ($response2 as $choice) {
        $message = $choice->getMessage();
        $delta = $message->getReasoningContent() ?: $message->getContent();
        if ($delta !== null) {
            echo $delta;
            $content2 .= $delta;
        }
    }
    echo "\n";
    echo sprintf("[第二轮耗时: %.2f 秒]\n", microtime(true) - $start);
} catch (Throwable $e) {
    echo "\n[ERROR] 第二轮请求失败:\n";
    echo '  类型: ' . get_class($e) . "\n";
    echo '  消息: ' . $e->getMessage() . "\n";

    // 打印切换时的 memory 详情，帮助定位 reasoning_content 问题
    echo "\n===== 请求失败时的 Memory 详情 =====\n";
    $messages = $memory->getMessages();
    foreach ($messages as $i => $msg) {
        $role = $msg->getRole();
        echo sprintf("  [%d] role=%s\n", $i, $role->value);
        if ($msg instanceof AssistantMessage) {
            echo '      has_reasoning_content=' . ($msg->hasReasoningContent() ? 'true' : 'false') . "\n";
            if ($msg->hasReasoningContent()) {
                $rc = mb_substr((string) $msg->getReasoningContent(), 0, 120);
                echo '      reasoning_content_preview=' . $rc . "\n";
            }
            $toolCalls = $msg->getToolCalls();
            if (! empty($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    echo '      tool_call_id=' . $tc->getId() . "\n";
                }
            }
        }
        $content = mb_substr((string) ($msg->getContent() ?? ''), 0, 80);
        $content = str_replace(["\n", "\r"], ' ', $content);
        echo "      content_preview={$content}\n";
    }
    echo '========================================' . "\n";
}
