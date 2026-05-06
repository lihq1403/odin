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

use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Api\Request\CompletionRequest;
use Hyperf\Odin\Api\Request\EmbeddingRequest;
use Hyperf\Odin\Api\Request\MultiModalEmbeddingItem;
use Hyperf\Odin\Api\Request\MultiModalEmbeddingRequest;
use Hyperf\Odin\Api\Request\ThinkingConfig;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Api\Response\ChatCompletionResponse;
use Hyperf\Odin\Api\Response\ChatCompletionStreamResponse;
use Hyperf\Odin\Api\Response\EmbeddingResponse;
use Hyperf\Odin\Api\Response\TextCompletionResponse;
use Hyperf\Odin\Contract\Api\ClientInterface;
use Hyperf\Odin\Contract\Mcp\McpServerManagerInterface;
use Hyperf\Odin\Contract\Message\MessageInterface;
use Hyperf\Odin\Contract\Model\EmbeddingInterface;
use Hyperf\Odin\Contract\Model\ModelInterface;
use Hyperf\Odin\Exception\LLMException\LLMModelException;
use Hyperf\Odin\Exception\LLMException\LLMNetworkException;
use Hyperf\Odin\Exception\LLMException\Model\LLMEmbeddingNotSupportedException;
use Hyperf\Odin\Exception\LLMException\Model\LLMFunctionCallNotSupportedException;
use Hyperf\Odin\Exception\LLMException\Model\LLMModalityNotSupportedException;
use Hyperf\Odin\Exception\OdinException;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Message\UserMessage;
use Hyperf\Retry\Retry;
use Hyperf\Retry\RetryContext;
use Psr\Log\LoggerInterface;

/**
 * 模型抽象基类，实现模型的通用行为.
 */
abstract class AbstractModel implements ModelInterface, EmbeddingInterface
{
    /**
     * API请求选项.
     */
    protected ApiOptions $apiRequestOptions;

    /**
     * 模型选项.
     */
    protected ModelOptions $modelOptions;

    protected bool $streamIncludeUsage = false;

    protected bool $includeBusinessParams = false;

    protected ?McpServerManagerInterface $mcpServerManager = null;

    protected array $chatCompletionRequestOptionKeyMaps = [];

    /**
     * 构造函数.
     */
    public function __construct(
        protected string $model,
        protected array $config,
        protected ?LoggerInterface $logger = null
    ) {
        // 初始化，如果有需要修改的再修改
        $this->modelOptions = new ModelOptions();
        $this->apiRequestOptions = new ApiOptions();
    }

    public function registerMcpServerManager(?McpServerManagerInterface $mcpServerManager): void
    {
        $this->mcpServerManager = $mcpServerManager;
    }

    public function getMcpServerManager(): ?McpServerManagerInterface
    {
        return $this->mcpServerManager;
    }

    public function chatWithRequest(ChatCompletionRequest $request): ChatCompletionResponse
    {
        return $this->callWithNetworkRetry(function () use ($request) {
            $request->setOptionKeyMaps($this->chatCompletionRequestOptionKeyMaps);
            $this->registerMcp($request);
            $request->setModel($this->model);
            $this->checkFunctionCallSupport($request->getTools());
            $this->checkMultiModalSupport($request->getMessages());
            $this->checkFixedTemperature($request);
            $this->applyThinkingBudgetFromOptions($request);

            // 验证请求参数（包括消息序列）
            $request->validate();

            $request->setStream(false);

            $client = $this->getClient();
            $request->setModel($client->normalizeModelName($request->getModel()));
            $response = $client->chatCompletions($request);

            // 统一检查响应内容是否为空
            $this->validateResponseContent($response);

            return $response;
        });
    }

    public function chatStreamWithRequest(ChatCompletionRequest $request): ChatCompletionStreamResponse
    {
        return $this->callWithNetworkRetry(function () use ($request) {
            $request->setOptionKeyMaps($this->chatCompletionRequestOptionKeyMaps);
            $this->registerMcp($request);
            $request->setModel($this->model);
            $this->checkFunctionCallSupport($request->getTools());
            $this->checkMultiModalSupport($request->getMessages());
            $this->checkFixedTemperature($request);
            $this->applyThinkingBudgetFromOptions($request);

            // 验证请求参数（包括消息序列）
            $request->validate();

            $request->setStream(true);
            $request->setStreamIncludeUsage($this->streamIncludeUsage);

            $client = $this->getClient();
            $request->setModel($client->normalizeModelName($request->getModel()));
            return $client->chatCompletionsStream($request);
        });
    }

    /**
     * 聊天补全API.
     */
    public function chat(
        array $messages,
        float $temperature = 0.9,
        int $maxTokens = 0,
        array $stop = [],
        array $tools = [],
        float $frequencyPenalty = 0.0,
        float $presencePenalty = 0.0,
        array $businessParams = [],
    ): ChatCompletionResponse {
        $chatRequest = new ChatCompletionRequest($messages, $this->model, $temperature, $maxTokens, $stop, $tools, false);
        $chatRequest->setOptionKeyMaps($this->chatCompletionRequestOptionKeyMaps);
        $chatRequest->setFrequencyPenalty($frequencyPenalty);
        $chatRequest->setPresencePenalty($presencePenalty);
        $chatRequest->setBusinessParams($businessParams);
        $chatRequest->setIncludeBusinessParams($this->includeBusinessParams);
        return $this->chatWithRequest($chatRequest);
    }

    /**
     * 流式聊天补全API.
     */
    public function chatStream(
        array $messages,
        float $temperature = 0.9,
        int $maxTokens = 0,
        array $stop = [],
        array $tools = [],
        float $frequencyPenalty = 0.0,
        float $presencePenalty = 0.0,
        array $businessParams = [],
    ): ChatCompletionStreamResponse {
        $chatRequest = new ChatCompletionRequest($messages, $this->model, $temperature, $maxTokens, $stop, $tools, true);
        $chatRequest->setOptionKeyMaps($this->chatCompletionRequestOptionKeyMaps);
        $chatRequest->setFrequencyPenalty($frequencyPenalty);
        $chatRequest->setPresencePenalty($presencePenalty);
        $chatRequest->setBusinessParams($businessParams);
        $chatRequest->setStreamIncludeUsage($this->streamIncludeUsage);
        $chatRequest->setIncludeBusinessParams($this->includeBusinessParams);
        return $this->chatStreamWithRequest($chatRequest);
    }

    /**
     * 文本补全API.
     */
    public function completions(
        string $prompt,
        float $temperature = 0.9,
        int $maxTokens = 16,
        array $stop = [],
        float $frequencyPenalty = 0.0,
        float $presencePenalty = 0.0,
        array $businessParams = [],
    ): TextCompletionResponse {
        $client = $this->getClient();
        $chatRequest = new CompletionRequest($this->model, $prompt, $temperature, $maxTokens, $stop);
        $chatRequest->setFrequencyPenalty($frequencyPenalty);
        $chatRequest->setPresencePenalty($presencePenalty);
        $chatRequest->setBusinessParams($businessParams);
        $chatRequest->setIncludeBusinessParams($this->includeBusinessParams);
        return $client->completions($chatRequest);
    }

    /**
     * 生成文本的嵌入向量.
     */
    public function embedding(array|string $input, ?string $encoding_format = 'float', ?string $user = null): Embedding
    {
        $response = $this->embeddings($input, $encoding_format, $user);

        // 从响应中提取嵌入向量
        $embeddings = [];
        foreach ($response->getData() as $embedding) {
            $embeddings[] = $embedding->getEmbedding();
        }

        // 通常只有一个嵌入向量，但如果有多个，我们只使用第一个
        if (empty($embeddings)) {
            return new Embedding([]);
        }

        return new Embedding($embeddings[0]);
    }

    public function embeddings(array|string $input, ?string $encoding_format = 'float', ?string $user = null, array $businessParams = []): EmbeddingResponse
    {
        // 检查模型是否支持嵌入功能
        $this->checkEmbeddingSupport();

        $client = $this->getClient();
        $embeddingRequest = new EmbeddingRequest(
            input: $input,
            model: $this->model
        );
        $embeddingRequest->setBusinessParams($businessParams);
        $embeddingRequest->setIncludeBusinessParams($this->includeBusinessParams);

        return $client->embeddings($embeddingRequest);
    }

    /**
     * 多模态嵌入：单组输入，返回一个 Embedding 便于直接使用.
     *
     * @param array<int, MultiModalEmbeddingItem> $items
     */
    public function multimodalEmbedding(array $items): Embedding
    {
        $response = $this->multimodalEmbeddings([$items]);
        $first = $response->getData()[0] ?? null;
        return new Embedding($first ? $first->getEmbedding() : []);
    }

    /**
     * 多模态嵌入批量版本：支持多组输入，每组返回一个独立向量.
     * 目前仅 DashScope 支持多组批量（非融合模式），其余提供商仅支持单组.
     *
     * @param array<int, array<int, MultiModalEmbeddingItem>> $inputs
     */
    public function multimodalEmbeddings(array $inputs): EmbeddingResponse
    {
        $this->checkEmbeddingSupport();

        $request = new MultiModalEmbeddingRequest(
            inputs: $inputs,
            model: $this->model,
        );

        return $this->getClient()->multimodalEmbeddings($request);
    }

    /**
     * 获取模型名称.
     *
     * @return string 模型名称
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * 获取向量大小.
     *
     * @return int 向量大小
     */
    public function getVectorSize(): int
    {
        return $this->modelOptions->getVectorSize();
    }

    /**
     * 设置模型选项.
     */
    public function setModelOptions(ModelOptions $modelOptions): self
    {
        $this->modelOptions = $modelOptions;
        return $this;
    }

    /**
     * 设置API请求选项.
     */
    public function setApiRequestOptions(ApiOptions $apiRequestOptions): self
    {
        $this->apiRequestOptions = $apiRequestOptions;
        return $this;
    }

    /**
     * 获取API请求选项.
     */
    public function getApiRequestOptions(): ApiOptions
    {
        return $this->apiRequestOptions;
    }

    public function getModelOptions(): ModelOptions
    {
        return $this->modelOptions;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    protected function registerMcp(ChatCompletionRequest $request): void
    {
        if (! $this->modelOptions->supportsFunctionCall()) {
            return;
        }
        if (! $this->mcpServerManager) {
            return;
        }
        $this->mcpServerManager->discover();
        foreach ($this->mcpServerManager->getAllTools() as $tool) {
            $request->addTool($tool);
        }
    }

    /**
     * 检查模型是否支持函数调用.
     */
    protected function checkFunctionCallSupport(array $tools): void
    {
        if (! empty($tools) && ! $this->modelOptions->supportsFunctionCall()) {
            throw new LLMFunctionCallNotSupportedException(
                sprintf('模型 %s 不支持函数调用功能', $this->model),
                null,
                $this->model
            );
        }
    }

    /**
     * 检查模型是否支持多模态输入.
     * @param array<MessageInterface> $messages
     */
    protected function checkMultiModalSupport(array $messages): void
    {
        if ($this->containsMultiModalContent($messages) && ! $this->modelOptions->isMultiModal()) {
            throw new LLMModalityNotSupportedException(
                sprintf('模型 %s 不支持多模态输入', $this->model),
                null,
                $this->model
            );
        }
    }

    /**
     * 检查消息中是否包含多模态内容.
     *
     * @param array<MessageInterface> $messages 消息数组
     * @return bool 是否包含多模态内容
     */
    protected function containsMultiModalContent(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message instanceof UserMessage
                && ($message->hasImageMultiModal() || $message->hasVideoMultiModal())) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取客户端实例，由子类实现.
     */
    abstract protected function getClient(): ClientInterface;

    /**
     * 处理API基础URL，确保包含正确的版本路径.
     * 如果base_url已经包含版本路径，则不做任何处理
     * 如果没有包含，则根据模型类型添加相应的版本路径.
     */
    protected function processApiBaseUrl(array &$config): void
    {
        // 如果没有base_url，不做处理
        if (empty($config['base_url'])) {
            return;
        }

        // 检查base_url是否已包含API路径
        $baseUrl = $config['base_url'];
        if ($this->hasApiPathInBaseUrl($baseUrl)) {
            return;
        }

        // 获取当前模型的API版本路径
        $versionPath = $this->getApiVersionPath();
        if (! empty($versionPath)) {
            // 确保base_url和versionPath之间只有一个斜杠
            $baseUrl = rtrim($baseUrl, '/');
            $versionPath = ltrim($versionPath, '/');
            $config['base_url'] = $baseUrl . '/' . $versionPath;
        }
    }

    /**
     * 获取API版本路径.
     * 默认返回空，由子类覆盖实现.
     */
    protected function getApiVersionPath(): string
    {
        return '';
    }

    /**
     * 检查基础URL是否已包含API路径.
     */
    protected function hasApiPathInBaseUrl(string $baseUrl): bool
    {
        $urlParts = parse_url($baseUrl);
        return ! empty($urlParts['path']) && $urlParts['path'] !== '/';
    }

    /**
     * 检查模型是否支持嵌入功能.
     */
    protected function checkEmbeddingSupport(): void
    {
        if (! $this->modelOptions->isEmbedding()) {
            throw new LLMEmbeddingNotSupportedException(
                sprintf('模型 %s 不支持嵌入功能', $this->model),
                null,
                $this->model
            );
        }
    }

    private function callWithNetworkRetry(callable $callable): mixed
    {
        return Retry::max($this->apiRequestOptions->getNetworkRetryCount() + 1)
            ->backoff(1000)
            ->when(function (RetryContext $context) {
                // 第一次执行时允许尝试
                if ($context->isFirstTry()) {
                    return true;
                }

                $throwable = $context->lastThrowable;
                // 网络异常重试
                if ($throwable instanceof LLMNetworkException
                    || ($throwable && $throwable->getPrevious() instanceof LLMNetworkException)) {
                    return true;
                }
                // statusCode >= 500 的异常也可重试（服务端错误）
                if ($throwable instanceof OdinException && $throwable->getStatusCode() >= 500) {
                    return true;
                }
                return false;
            })
            ->call($callable);
    }

    private function checkFixedTemperature(ChatCompletionRequest $request): void
    {
        if ($this->getModelOptions()->getFixedTemperature()) {
            $request->setTemperature($this->getModelOptions()->getFixedTemperature());
        }
        if (! $request->getTemperature() && $this->modelOptions->getDefaultTemperature()) {
            $request->setTemperature($this->modelOptions->getDefaultTemperature());
        }
    }

    /**
     * 根据 model_options 的 thinking_budget_levels 配置自动补充思考预算：.
     *
     * 若请求已启用 ThinkingConfig 且带有 level 但无有效 budget（null 或 -1），
     * 从 thinking_budget_levels[level] 查找对应预算并重建 ThinkingConfig。
     * 未配置 thinking_budget_levels 或请求已有明确 budget 时不做任何处理。
     */
    private function applyThinkingBudgetFromOptions(ChatCompletionRequest $request): void
    {
        $existingThinking = $request->getThinking();
        if ($existingThinking === null || ! $existingThinking->isEnabled()) {
            return;
        }

        $levels = $this->modelOptions->getThinkingBudgetLevels();
        if (empty($levels)) {
            return;
        }

        $currentBudget = $existingThinking->getBudgetTokens();
        // 仅在没有有效预算时（null 或 -1），才用 level 映射表补充
        if ($currentBudget !== null && $currentBudget !== -1) {
            return;
        }

        $level = $existingThinking->getLevel();
        if (isset($levels[$level])) {
            $request->setThinking(ThinkingConfig::enabled($levels[$level], $level));
        }
    }

    /**
     * 验证非流式响应内容是否为空.
     */
    private function validateResponseContent(ChatCompletionResponse $response): void
    {
        /** @var AssistantMessage $message */
        $message = $response->getFirstChoice()?->getMessage();
        if (! $message instanceof AssistantMessage) {
            throw new LLMModelException('Model returned empty content response.', statusCode: 500);
        }
        if ($message->hasToolCalls()) {
            return;
        }
        // 如果有推理内容，即使 content 为空也是正常的（例如模型思考后因 MAX_TOKENS 限制未输出结果）
        if ($message->hasReasoningContent()) {
            return;
        }
        $content = $message->getContent();
        if ($content === '' || trim($content) === '') {
            throw new LLMModelException('Model returned empty content response', statusCode: 500);
        }
    }
}
