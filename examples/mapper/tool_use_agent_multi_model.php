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
use Hyperf\Odin\Logger;
use Hyperf\Odin\Memory\MemoryManager;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Message\SystemMessage;
use Hyperf\Odin\Message\UserMessage;
use Hyperf\Odin\ModelMapper;
use Hyperf\Odin\Tool\AbstractTool;
use Hyperf\Odin\Tool\Definition\ToolDefinition;
use Hyperf\Odin\Tool\Definition\ToolParameters;

ClassLoader::init();
$container = ApplicationContext::setContainer(new Container((new DefinitionSourceFactory())()));
$modelMapper = $container->get(ModelMapper::class);
$logger = new Logger();

// 解析模型 ID 列表
$modelIdsRaw = \Hyperf\Support\env('MODEL_MAPPER_TEST_MODEL_IDS', '[]');
$modelIds = json_decode($modelIdsRaw, true);
if (! is_array($modelIds) || empty($modelIds)) {
    echo "MODEL_MAPPER_TEST_MODEL_IDS 未配置或格式错误，请检查 .env\n";
    exit(1);
}

// 工具定义（无状态，所有模型共用）
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
            'a' => [
                'type' => 'number',
                'description' => '第一个操作数',
            ],
            'b' => [
                'type' => 'number',
                'description' => '第二个操作数',
            ],
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

$databaseTool = new ToolDefinition(
    name: 'database',
    description: '查询数据库中的信息',
    parameters: ToolParameters::fromArray([
        'type' => 'object',
        'properties' => [
            'table' => [
                'type' => 'string',
                'enum' => ['users', 'products', 'orders'],
                'description' => '要查询的数据表',
            ],
            'id' => [
                'type' => 'integer',
                'description' => '记录ID',
            ],
        ],
        'required' => ['table', 'id'],
    ]),
    toolHandler: function ($params) {
        $database = [
            'users' => [
                1 => ['name' => '张三', 'age' => 28, 'email' => 'zhangsan@example.com'],
                2 => ['name' => '李四', 'age' => 32, 'email' => 'lisi@example.com'],
                3 => ['name' => '王五', 'age' => 45, 'email' => 'wangwu@example.com'],
            ],
            'products' => [
                1 => ['name' => '笔记本电脑', 'price' => 6999, 'stock' => 50],
                2 => ['name' => '智能手机', 'price' => 3999, 'stock' => 100],
                3 => ['name' => '平板电脑', 'price' => 2999, 'stock' => 75],
            ],
            'orders' => [
                1 => ['user_id' => 1, 'product_id' => 2, 'quantity' => 1, 'total' => 3999],
                2 => ['user_id' => 2, 'product_id' => 1, 'quantity' => 2, 'total' => 13998],
                3 => ['user_id' => 3, 'product_id' => 3, 'quantity' => 1, 'total' => 2999],
            ],
        ];
        $table = $params['table'];
        $id = $params['id'];
        return isset($database[$table][$id])
            ? ['data' => $database[$table][$id]]
            : ['error' => "在表 {$table} 中未找到ID为 {$id} 的记录"];
    }
);

$recommendTool = new ToolDefinition(
    name: 'recommend',
    description: '根据用户偏好推荐内容',
    parameters: ToolParameters::fromArray([
        'type' => 'object',
        'properties' => [
            'category' => [
                'type' => 'string',
                'enum' => ['电影', '书籍', '音乐', '餐厅'],
                'description' => '推荐类别',
            ],
            'user_preference' => [
                'type' => 'string',
                'description' => '用户偏好关键词',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => '返回推荐数量',
                'default' => 3,
            ],
        ],
        'required' => ['category', 'user_preference'],
    ]),
    toolHandler: function ($params) {
        $recommendations = [
            '电影' => [
                '科幻' => ['星际穿越', '银翼杀手2049', '头号玩家', '火星救援', '黑客帝国'],
                '动作' => ['速度与激情', '碟中谍', '复仇者联盟', '黑暗骑士', '007:幽灵党'],
            ],
            '书籍' => [
                '科幻' => ['三体', '基地', '沙丘', '神经漫游者', '火星救援'],
                '小说' => ['百年孤独', '追风筝的人', '活着', '围城', '平凡的世界'],
            ],
        ];
        $category = $params['category'];
        $preference = $params['user_preference'];
        $limit = $params['limit'] ?? 3;
        if (isset($recommendations[$category])) {
            foreach ($recommendations[$category] as $key => $items) {
                if (str_contains($key, $preference) || str_contains($preference, $key)) {
                    return ['recommendations' => array_slice($items, 0, $limit)];
                }
            }
            $first = array_key_first($recommendations[$category]);
            return ['recommendations' => array_slice($recommendations[$category][$first], 0, $limit)];
        }
        return ['error' => "不支持的推荐类别: {$category}"];
    }
);

class CurrentTimeTool extends AbstractTool
{
    public function getName(): string
    {
        return 'current_time';
    }

    public function getDescription(): string
    {
        return '获取当前系统时间，不需要任何参数';
    }

    public function getParameters(): ?ToolParameters
    {
        return ToolParameters::fromArray([
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ]);
    }

    protected function handle(array $parameters): array
    {
        return [
            'current_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'timestamp' => time(),
        ];
    }
}

$currentTimeTool = new CurrentTimeTool();

$tools = [
    $calculatorTool->getName() => $calculatorTool,
    $databaseTool->getName() => $databaseTool,
    $recommendTool->getName() => $recommendTool,
    $currentTimeTool->getName() => $currentTimeTool,
];

$userPrompt = '先获取当前系统时间，再计算 7 的 3 次方，然后查询用户ID为2的信息，最后根据查询结果推荐一些科幻电影。请详细说明每一步。在最后进行总结';

// 汇总结果
$results = [];
$total = count($modelIds);

echo str_repeat('=', 60) . "\n";
echo "工具调用非流式多模型验证 (共 {$total} 个模型)\n";
echo str_repeat('=', 60) . "\n\n";

foreach ($modelIds as $index => $modelId) {
    $num = $index + 1;
    echo str_repeat('-', 60) . "\n";
    echo "[{$num}/{$total}] 模型: {$modelId}\n";
    echo str_repeat('-', 60) . "\n";

    $start = microtime(true);
    $status = 'PASS';
    $errorMsg = '';
    $content = '';

    try {
        $model = $modelMapper->getModel($modelId);

        $memory = new MemoryManager();
        $memory->addSystemMessage(new SystemMessage('你是一个能够使用工具的AI助手，当需要使用工具时，请明确指出工具的作用和使用步骤。'));

        $agent = new ToolUseAgent(
            model: $model,
            memory: $memory,
            tools: $tools,
            temperature: 0.6,
            logger: $logger
        );

        $response = $agent->chat(new UserMessage($userPrompt));
        $message = $response->getFirstChoice()->getMessage();
        if ($message instanceof AssistantMessage) {
            $content = $message->getContent();
            echo $content . "\n";
        }
    } catch (Throwable $e) {
        $status = 'FAIL';
        $errorMsg = $e->getMessage();
        echo "ERROR: {$errorMsg}\n";
    }

    $elapsed = round(microtime(true) - $start, 2);
    $results[] = [
        'model' => $modelId,
        'status' => $status,
        'elapsed' => $elapsed,
        'error' => $errorMsg,
    ];

    echo "\n[{$status}] 耗时: {$elapsed}s\n\n";
}

// 汇总
echo str_repeat('=', 60) . "\n";
echo "验证结果汇总\n";
echo str_repeat('=', 60) . "\n";
$passed = 0;
$failed = 0;
foreach ($results as $r) {
    $flag = $r['status'] === 'PASS' ? 'PASS' : 'FAIL';
    $line = sprintf('  [%s] %-55s %ss', $flag, $r['model'], $r['elapsed']);
    echo $line . "\n";
    if ($r['error']) {
        echo '         ' . $r['error'] . "\n";
    }
    $r['status'] === 'PASS' ? ++$passed : ++$failed;
}
echo str_repeat('-', 60) . "\n";
echo "通过: {$passed} / 失败: {$failed} / 共计: {$total}\n";
