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

namespace Hyperf\Odin\Api\Providers\AwsBedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use Hyperf\Odin\Api\Providers\AbstractClient;
use Hyperf\Odin\Api\Providers\AwsBedrock\Cache\AutoCacheConfig;
use Hyperf\Odin\Api\Providers\HttpHandlerFactory;
use Hyperf\Odin\Api\Request\ChatCompletionRequest;
use Hyperf\Odin\Api\Request\EmbeddingRequest;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Api\Response\ChatCompletionResponse;
use Hyperf\Odin\Api\Response\ChatCompletionStreamResponse;
use Hyperf\Odin\Api\Response\EmbeddingResponse;
use Hyperf\Odin\Contract\Message\MessageInterface;
use Hyperf\Odin\Event\AfterChatCompletionsEvent;
use Hyperf\Odin\Event\AfterChatCompletionsStreamEvent;
use Hyperf\Odin\Exception\LLMException;
use Hyperf\Odin\Exception\LLMException\Api\LLMInvalidRequestException;
use Hyperf\Odin\Exception\LLMException\Api\LLMRateLimitException;
use Hyperf\Odin\Exception\LLMException\LLMApiException;
use Hyperf\Odin\Exception\LLMException\Network\LLMReadTimeoutException;
use Hyperf\Odin\Message\AssistantMessage;
use Hyperf\Odin\Message\SystemMessage;
use Hyperf\Odin\Message\ToolMessage;
use Hyperf\Odin\Message\UserMessage;
use Hyperf\Odin\Utils\EventUtil;
use Hyperf\Odin\Utils\LoggingConfigHelper;
use Hyperf\Odin\Utils\LogUtil;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class Client extends AbstractClient
{
    /**
     * AWS Bedrock 运行时客户端.
     */
    protected BedrockRuntimeClient $bedrockClient;

    protected ConverterInterface $converter;

    /**
     * 构造函数.
     */
    public function __construct(AwsBedrockConfig $config, ?ApiOptions $requestOptions = null, ?LoggerInterface $logger = null)
    {
        if (! $requestOptions) {
            $requestOptions = new ApiOptions();
        }
        $this->converter = $this->createConverter();
        parent::__construct($config, $requestOptions, $logger);
    }

    public function chatCompletions(ChatCompletionRequest $chatRequest): ChatCompletionResponse
    {
        $chatRequest->validate();
        $startTime = microtime(true);

        try {
            $modelId = $chatRequest->getModel();
            $requestBody = $this->prepareRequestBody($chatRequest);

            // 生成请求ID
            $requestId = $this->generateRequestId();

            $args = [
                'body' => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
                'modelId' => $modelId,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                '@http' => $this->getHttpArgs(
                    false,
                    $this->requestOptions->getProxy()
                ),
            ];

            // 记录请求前日志
            $this->logger?->info('AwsBedrockChatRequest', LoggingConfigHelper::filterAndFormatLogData([
                'request_id' => $requestId,
                'model_id' => $modelId,
                'args' => $args,
            ], $this->requestOptions));

            // 调用模型
            $result = $this->bedrockClient->invokeModel($args);

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000); // 毫秒

            $responseBody = json_decode($result['body']->getContents(), true);

            // 转换为符合PSR-7标准的Response对象
            $psrResponse = ResponseHandler::convertToPsrResponse($responseBody, $chatRequest->getModel());
            $chatCompletionResponse = new ChatCompletionResponse($psrResponse, $this->logger);

            $performanceFlag = LogUtil::getPerformanceFlag($duration);
            $logData = [
                'request_id' => $requestId,
                'model_id' => $modelId,
                'duration_ms' => $duration,
                'content' => $chatCompletionResponse->getContent(),
                'usage' => $responseBody['usage'] ?? [],
                'response_headers' => $result['@metadata']['headers'] ?? [],
                'performance_flag' => $performanceFlag,
            ];

            $this->logger?->info('AwsBedrockChatResponse', LoggingConfigHelper::filterAndFormatLogData($logData, $this->requestOptions));

            EventUtil::dispatch(new AfterChatCompletionsEvent($chatRequest, $chatCompletionResponse, $duration));

            return $chatCompletionResponse;
        } catch (AwsException $e) {
            throw $this->convertAwsException($e);
        } catch (Throwable $e) {
            throw $this->convertException($e);
        }
    }

    public function chatCompletionsStream(ChatCompletionRequest $chatRequest): ChatCompletionStreamResponse
    {
        $startTime = microtime(true);

        try {
            // 验证请求参数
            $chatRequest->validate();

            // 获取模型ID和转换请求参数
            $modelId = $chatRequest->getModel();
            $requestBody = $this->prepareRequestBody($chatRequest);

            // 生成请求ID
            $requestId = $this->generateRequestId();

            $args = [
                'body' => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
                'modelId' => $modelId,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                '@http' => $this->getHttpArgs(true, $this->requestOptions->getProxy()),
            ];

            // 记录请求前日志
            $this->logger?->info('AwsBedrockStreamRequest', LoggingConfigHelper::filterAndFormatLogData([
                'request_id' => $requestId,
                'model_id' => $modelId,
                'args' => $args,
            ], $this->requestOptions));

            // 使用流式响应调用模型
            $result = $this->bedrockClient->invokeModelWithResponseStream($args);

            $firstResponseTime = microtime(true);
            $firstResponseDuration = round(($firstResponseTime - $startTime) * 1000); // 毫秒

            // 记录首次响应日志
            $performanceFlag = LogUtil::getPerformanceFlag($firstResponseDuration);
            $logData = [
                'request_id' => $requestId,
                'model_id' => $modelId,
                'first_response_ms' => $firstResponseDuration,
                'response_headers' => $result['@metadata']['headers'] ?? [],
                'performance_flag' => $performanceFlag,
            ];

            $this->logger?->info('AwsBedrockStreamFirstResponse', LoggingConfigHelper::filterAndFormatLogData($logData, $this->requestOptions));

            // 创建 AWS Bedrock 格式转换器，负责将 AWS Bedrock 格式转换为 OpenAI 格式
            $bedrockConverter = new AwsBedrockFormatConverter($result, $this->logger);

            $chatCompletionStreamResponse = new ChatCompletionStreamResponse(logger: $this->logger, streamIterator: $bedrockConverter);
            $chatCompletionStreamResponse->setAfterChatCompletionsStreamEvent(new AfterChatCompletionsStreamEvent($chatRequest, $firstResponseDuration));

            return $chatCompletionStreamResponse;
        } catch (AwsException $e) {
            throw $this->convertAwsException($e);
        } catch (Throwable $e) {
            throw $this->convertException($e);
        }
    }

    public function embeddings(EmbeddingRequest $embeddingRequest): EmbeddingResponse
    {
        // Embedding实现将在后续添加
        throw new RuntimeException('Embeddings are not implemented yet');
    }

    protected function createConverter(): ConverterInterface
    {
        return new InvokeConverter();
    }

    /**
     * 初始化客户端.
     *
     * 重写父类的方法，因为 AWS Bedrock 使用 SDK 而不是 HTTP 客户端
     */
    protected function initClient(): void
    {
        // AWS Bedrock 不需要调用父类的 initClient 方法，因为它不使用 HTTP 客户端

        /** @var AwsBedrockConfig $config */
        $config = $this->config;

        // 准备客户端配置
        $clientConfig = [
            'version' => 'latest',
            'region' => $config->region,
            'credentials' => [
                'key' => $config->accessKey,
                'secret' => $config->secretKey,
            ],
        ];

        // 从 requestOptions 获取 HTTP 处理器配置
        $handlerType = $this->requestOptions->getHttpHandler();
        if ($handlerType !== 'auto') {
            // 使用 http_handler 而不是 handler，因为我们要处理 PSR-7 HTTP 请求
            $clientConfig['http_handler'] = HttpHandlerFactory::create($handlerType);
        }

        // 初始化 AWS Bedrock 客户端
        $this->bedrockClient = new BedrockRuntimeClient($clientConfig);
    }

    protected function buildChatCompletionsUrl(): string
    {
        // AWS Bedrock不使用HTTP URL，它使用AWS SDK
        return '';
    }

    protected function buildEmbeddingsUrl(): string
    {
        // AWS Bedrock不使用HTTP URL，它使用AWS SDK
        return '';
    }

    /**
     * 构建文本补全API的URL.
     */
    protected function buildCompletionsUrl(): string
    {
        // AWS Bedrock不使用HTTP URL，它使用AWS SDK
        return '';
    }

    /**
     * 获取认证头信息.
     */
    protected function getAuthHeaders(): array
    {
        // AWS Bedrock不使用标准的认证头，而是通过AWS SDK认证
        return [];
    }

    /**
     * 转换通用异常为LLM异常.
     */
    protected function convertException(Throwable $exception, array $context = []): LLMException
    {
        $message = $exception->getMessage();
        $code = (int) $exception->getCode();

        // 判断异常类型并返回对应的LLM异常
        if (str_contains($message, 'timed out')) {
            return new LLMReadTimeoutException($message, $exception);
        }

        if (str_contains($message, 'rate limit') || str_contains($message, 'throttled')) {
            return new LLMRateLimitException($message, $exception, $code);
        }

        if ($code >= 400 && $code < 500) {
            return new LLMInvalidRequestException($message, $exception, $code);
        }

        if ($code >= 500) {
            // 对于服务器错误，使用通用API异常
            return new LLMApiException($message, $code, $exception, 0, $code);
        }

        // 默认返回通用异常
        return new LLMApiException($message, $code, $exception);
    }

    /**
     * 准备HTTP配置参数.
     */
    protected function getHttpArgs(bool $stream = false, ?string $proxy = null): array
    {
        $http = [];
        if ($stream) {
            $http['stream'] = true;
        }
        if ($proxy) {
            $http['proxy'] = $proxy;
        }
        return $http;
    }

    /**
     * 转换AWS异常为LLM异常.
     */
    protected function convertAwsException(AwsException $e): LLMException
    {
        return $this->convertException($e, [
            'aws_error_type' => $e->getAwsErrorType(),
            'aws_error_code' => $e->getAwsErrorCode(),
        ]);
    }

    protected function isAutoCache(): bool
    {
        /** @var AwsBedrockConfig $config */
        $config = $this->config;
        return $config->isAutoCache();
    }

    protected function getAutoCacheConfig(): AutoCacheConfig
    {
        /** @var AwsBedrockConfig $config */
        $config = $this->config;
        return $config->getAutoCacheConfig();
    }

    /**
     * 准备请求体数据.
     */
    private function prepareRequestBody(ChatCompletionRequest $chatRequest): array
    {
        $messages = [];
        $systemMessage = '';

        foreach ($chatRequest->getMessages() as $message) {
            // 跳过非MessageInterface实例
            if (! $message instanceof MessageInterface) {
                continue;
            }

            // 根据消息类型分别处理
            match (true) {
                // 1. 处理系统消息 - 单独提取
                $message instanceof SystemMessage => $systemMessage = $this->converter->convertSystemMessage($message),

                // 2. 处理工具结果消息 - 转换为tool_result格式
                $message instanceof ToolMessage => $messages[] = $this->converter->convertToolMessage($message),

                // 3. 处理助手消息 - 可能包含工具调用
                $message instanceof AssistantMessage => $messages[] = $this->converter->convertAssistantMessage($message),

                // 4. 处理其他类型消息(主要是用户消息)
                $message instanceof UserMessage => $messages[] = $this->converter->convertUserMessage($message),
            };
        }

        // 获取请求参数
        $maxTokens = $chatRequest->getMaxTokens();
        $temperature = $chatRequest->getTemperature();
        $stop = $chatRequest->getStop();

        // 准备请求体 - 符合Claude API格式
        $requestBody = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'max_tokens' => $maxTokens > 0 ? $maxTokens : 8192,
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        // 添加停止词
        if (! empty($stop)) {
            $requestBody['stop_sequences'] = $stop;
        }

        // 添加系统提示
        if (! empty($systemMessage)) {
            $requestBody['system'] = $systemMessage;
        }

        // 添加工具调用支持
        if (! empty($chatRequest->getTools())) {
            $requestBody['tools'] = $this->converter->convertTools($chatRequest->getTools());
            // 添加工具选择策略
            if (! empty($requestBody['tools'])) {
                $requestBody['tool_choice']['type'] = 'auto';
            }
        }

        return $requestBody;
    }
}
