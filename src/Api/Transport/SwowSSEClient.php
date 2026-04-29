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

namespace Hyperf\Odin\Api\Transport;

use Generator;
use Hyperf\Odin\Api\RequestOptions\ApiOptions;
use Hyperf\Odin\Exception\LLMException\Api\LLMInvalidRequestException;
use Hyperf\Odin\Exception\LLMException\LLMApiException;
use Hyperf\Odin\Exception\LLMException\LLMNetworkException;
use Hyperf\Odin\Exception\RuntimeException;
use Hyperf\Odin\Utils\LogUtil;
use Hyperf\Odin\Utils\ProxyUtil;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Swow\Psr7\Client\MagicClient;
use Swow\Psr7\Psr7;
use Throwable;

/**
 * 基于 Swow 原生 HTTP 客户端的 SSE 流式传输实现.
 *
 * 使用 Swow\Psr7\Client 替代 curl，在协程层面原生处理 chunked 响应和 SSE 解析，
 * 理论上比 OdinSimpleCurl（curl + Channel）延迟更低、资源开销更小.
 *
 * 仅在 Swow 扩展可用且处于协程上下文时使用.
 *
 * 使用方式（有 ApiOptions 时优先用封装方法，避免 recv 毫秒与流式配置不一致）：
 *   $response = SwowSSEClient::buildSwowResponseFromApiOptions($url, $headers, $body, $proxyUrl, $apiOptions);
 *   $client   = new SwowSSEClient($response, $timeoutConfig, $logger);
 */
class SwowSSEClient implements SseEventProducerInterface
{
    private bool $shouldClose = false;

    private ?StreamExceptionDetector $exceptionDetector = null;

    /**
     * @param ResponseInterface $response 已建立连接的 Swow PSR-7 响应（由 buildSwowResponse 返回）
     * @param array<string, mixed> $timeoutConfig 超时配置，键与 ApiOptions::getTimeout() 一致
     * @param int $readSize 每次 socket read 的字节数，见 ApiOptions::getBufferSize()
     */
    public function __construct(
        private readonly ResponseInterface $response,
        ?array $timeoutConfig = null,
        ?LoggerInterface $logger = null,
        private readonly int $readSize = 32,
    ) {
        if ($timeoutConfig !== null) {
            $this->exceptionDetector = new StreamExceptionDetector($timeoutConfig, $logger);
        }
    }

    /**
     * 检查当前运行时是否满足使用 SwowSSEClient 的条件.
     * 需要 Swow 扩展已加载且相关类存在.
     */
    public static function isSupported(): bool
    {
        return class_exists('Swow\Psr7\Client\MagicClient')
            && class_exists('Swow\Psr7\Psr7')
            && class_exists('Swow\Psr7\Message\EventStreamEvent');
    }

    /**
     * 使用 ApiOptions 建立 Swow 流式 HTTP 请求（连接超时 + 与流式配置对齐的 recv 超时）.
     *
     * 业务侧若自行调用 {@see buildSwowResponse}，易漏传或误传 recv 毫秒；应优先使用本方法。
     *
     * @param array<string, string> $headers 请求头（不含 Host，内部自动补充）
     * @param null|string $proxyUrl 代理 URL；为 null 时直连
     *
     * @throws LLMNetworkException
     * @throws LLMApiException
     * @throws LLMInvalidRequestException
     */
    public static function buildSwowResponseFromApiOptions(
        string $url,
        array $headers,
        string $body,
        ?string $proxyUrl,
        ApiOptions $apiOptions,
    ): ResponseInterface {
        return self::buildSwowResponse(
            $url,
            $headers,
            $body,
            $proxyUrl,
            (int) ($apiOptions->getConnectionTimeout() * 1000),
            (int) ($apiOptions->getSwowStreamRecvTimeoutSeconds() * 1000),
        );
    }

    /**
     * 建立 Swow HTTP 连接并发送请求，返回 PSR-7 响应.
     *
     * 响应 body（ChunkedBodyStream）内部通过闭包持有对 Swow Client（Socket）的引用，
     * 因此 Client 不会被 GC，调用方无需在外部持久化 $client 变量.
     *
     * @param array<string, string> $headers 请求头（不含 Host，内部自动补充）
     * @param null|string $proxyUrl 代理 URL（与 ApiOptions::getProxy() 同源）；为 null 时直连
     * @param null|int $connectTimeoutMs TCP 连接超时（毫秒），null 表示不限制
     * @param null|int $recvTimeoutMs MagicClient 接收消息超时（毫秒），null 表示不设置；在分块/SSE 读时
     *                                往往作用于多次「等下一截数据」。建议用 ApiOptions::getSwowStreamRecvTimeoutSeconds()
     *                                （stream_first 与 stream_chunk 取较大值），避免块间空闲被首包超时误伤。
     * @throws LLMNetworkException
     * @throws LLMApiException
     * @throws LLMInvalidRequestException
     */
    public static function buildSwowResponse(
        string $url,
        array $headers,
        string $body,
        ?string $proxyUrl = null,
        ?int $connectTimeoutMs = null,
        ?int $recvTimeoutMs = null,
    ): ResponseInterface {
        LogUtil::getHyperfLogger()?->info('SwowSSEClient::buildSwowResponse');

        [$scheme, $host, $port] = self::parseUrl($url);

        $hostHeader = $host;
        if (! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $hostHeader .= ':' . $port;
        }

        // 用户自定义头优先，默认头兜底
        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
            'Connection' => 'keep-alive',
        ];
        $mergedHeaders = array_merge($defaultHeaders, $headers);
        $requestHeaders = array_merge(['Host' => $hostHeader], $mergedHeaders);

        // MagicClient 需要绝对 URI；代理由 setProxy + sendRequest 内建处理
        $request = Psr7::createRequest(
            method: 'POST',
            uri: $url,
            headers: $requestHeaders,
            body: $body,
        );

        $client = (new MagicClient())->setStreamingChunkedResponse(true);

        if ($connectTimeoutMs !== null) {
            $client->setConnectTimeout($connectTimeoutMs);
        }
        if ($recvTimeoutMs !== null) {
            $client->setRecvMessageTimeout($recvTimeoutMs);
        }

        $swowProxy = ProxyUtil::toSwowProxyArray($proxyUrl);
        if ($proxyUrl !== null && $proxyUrl !== '' && $swowProxy === null) {
            throw new LLMNetworkException("Swow 不支持的代理 URL 格式: {$proxyUrl}");
        }
        if ($swowProxy !== null) {
            $client->setProxy($swowProxy);
        }

        try {
            $response = $client->sendRequest($request);
        } catch (Throwable $e) {
            // 遍历异常链，提取 llhttp ParserException 中的原始解析错误信息。
            // 异常链结构：ClientRequestException -> ProtocolException -> ParserException(llhttp 原始错误)
            $chain = [];
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                $chain[] = get_class($cause) . ': ' . $cause->getMessage();
            }
            LogUtil::getHyperfLogger()?->warning('Swow HTTP 协议解析失败', [
                'url' => $url,
                'error_chain' => $chain,
            ]);
            throw new LLMNetworkException(
                'Swow HTTP 请求失败: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 500) {
            $errorBody = (string) $response->getBody();
            throw new LLMApiException(
                "HTTP {$statusCode} 服务端错误: {$errorBody}",
                $statusCode,
                new RuntimeException($errorBody, $statusCode),
                0,
                $statusCode
            );
        }

        if ($statusCode >= 400) {
            $errorBody = (string) $response->getBody();
            throw new LLMInvalidRequestException(
                "HTTP {$statusCode} 请求错误: {$errorBody}",
                new RuntimeException($errorBody, $statusCode),
                $statusCode
            );
        }

        return $response;
    }

    /**
     * 迭代 SSE 事件流，将 Swow EventStreamEvent 适配为项目内部的 SSEEvent.
     */
    public function getIterator(): Generator
    {
        // 使用 Swow 内置 SSE 解析器迭代事件流，readSize 由 ApiOptions::getBufferSize() 控制
        $eventStream = Psr7::readEventStream($this->response->getBody(), $this->readSize);

        foreach ($eventStream as $streamEvent) {
            if ($this->shouldClose) {
                break;
            }

            // 超时检测
            $this->exceptionDetector?->checkTimeout();

            $rawData = $streamEvent->data ?? '';
            $payload = trim((string) $rawData);

            if ($payload === '') {
                continue;
            }

            // [DONE] 标志着流结束，停止迭代
            if ($payload === '[DONE]') {
                break;
            }

            // 提取事件元数据
            $eventType = isset($streamEvent->event) ? (string) $streamEvent->event : 'message';
            $eventId = isset($streamEvent->id) ? (string) $streamEvent->id : null;
            $eventRetry = isset($streamEvent->retry) ? (int) $streamEvent->retry : null;

            $sseEvent = $this->adaptEvent($eventType, $eventId, $eventRetry, $payload);

            if ($sseEvent->isEmpty()) {
                continue;
            }

            $this->exceptionDetector?->onChunkReceived([
                'event_type' => $sseEvent->getEvent(),
                'event_id' => $sseEvent->getId(),
                'data_preview' => is_string($sseEvent->getData())
                    ? substr($sseEvent->getData(), 0, 200)
                    : (is_array($sseEvent->getData()) ? json_encode($sseEvent->getData()) : 'non-string-data'),
            ]);

            yield $sseEvent;
        }
    }

    /**
     * 提前关闭流迭代.
     */
    public function closeEarly(): void
    {
        $this->shouldClose = true;
    }

    /**
     * 解析 URL，返回 [scheme, host, port, path].
     *
     * @return array{0: string, 1: string, 2: int, 3: string}
     */
    private static function parseUrl(string $url): array
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['host'])) {
            throw new LLMNetworkException("无效的请求 URL: {$url}");
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = (string) $parsed['host'];
        $port = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string) ($parsed['path'] ?? '/');

        if ($path === '') {
            $path = '/';
        }

        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $path .= '?' . $parsed['query'];
        }

        return [$scheme, $host, $port, $path];
    }

    /**
     * 将从 Swow EventStreamEvent 提取的字段转换为内部 SSEEvent.
     * 对 data 字段进行 JSON 解码，保持与 SSEClient 一致的行为.
     */
    private function adaptEvent(string $event, ?string $id, ?int $retry, string $payload): SSEEvent
    {
        $decodedData = $payload;

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $decodedData = $decoded;
        } catch (JsonException) {
            // data 非 JSON 格式，保留原始字符串
        }

        return SSEEvent::fromArray([
            'event' => $event,
            'id' => $id,
            'retry' => $retry,
            'data' => $decodedData,
        ]);
    }
}
