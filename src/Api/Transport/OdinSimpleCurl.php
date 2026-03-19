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

use GuzzleHttp\Psr7\Response;
use Hyperf\Odin\Exception\LLMException\Api\LLMInvalidRequestException;
use Hyperf\Odin\Exception\LLMException\LLMApiException;
use Hyperf\Odin\Exception\LLMException\LLMConfigurationException;
use Hyperf\Odin\Exception\LLMException\LLMNetworkException;
use Hyperf\Odin\Exception\LLMException\Network\LLMConnectionTimeoutException;
use Hyperf\Odin\Exception\LLMException\Network\LLMReadTimeoutException;
use Hyperf\Odin\Exception\RuntimeException;

class OdinSimpleCurl
{
    public static function send(string $url, array $options, bool $skipContentTypeCheck = false): Response
    {
        $options['url'] = $url;

        // 在传入 stream URL 之前，将 json 字段提前序列化为 body 字符串。
        // 若直接传 json 数组，stream_open 内部会经过 json_decode(..., true) 反序列化，
        // 导致 stdClass 实例（如空 properties {}）被还原为 PHP 空数组 []，
        // 最终再次 json_encode 时输出 [] 而非 {}，引发 provider 的 schema 校验失败。
        if (isset($options['json'])) {
            $options['body'] = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            unset($options['json']);
        }

        $stream = @fopen('OdinSimpleCurl://' . json_encode($options), 'r', false);

        if ($stream === false) {
            $error = error_get_last();
            throw new LLMNetworkException(
                'Failed to open SimpleCURL stream: ' . ($error['message'] ?? 'Unknown error')
            );
        }

        $metadata = stream_get_meta_data($stream);
        $wrapper = $metadata['wrapper_data'] ?? null;

        if (! $wrapper instanceof SimpleCURLClient) {
            fclose($stream);
            throw new LLMConfigurationException('Invalid stream wrapper: expected SimpleCURLClient instance');
        }

        $metadataInfo = $wrapper->stream_metadata();
        $statusCode = $metadataInfo['http_code'] ?? 0;
        $responseHeaders = $metadataInfo['headers'] ?? [];

        if (isset($metadataInfo['error'])) {
            fclose($stream);
            $curlCode = $metadataInfo['error_code'] ?? 0;
            $errorMessage = $metadataInfo['error'];

            if ($curlCode === 28) {
                throw new LLMReadTimeoutException(
                    "Connection timeout: {$errorMessage}",
                    new RuntimeException($errorMessage, $curlCode)
                );
            }

            if (in_array($curlCode, [6, 7, 52, 56])) {
                throw new LLMNetworkException(
                    "Network connection error: {$errorMessage}",
                    $curlCode,
                    new RuntimeException($errorMessage, $curlCode)
                );
            }

            if ($curlCode === 35) {
                throw new LLMNetworkException(
                    "SSL/TLS error: {$errorMessage}",
                    $curlCode,
                    new RuntimeException($errorMessage, $curlCode)
                );
            }

            throw new LLMNetworkException(
                "HTTP request failed: {$errorMessage} (code: {$curlCode})",
                $curlCode,
                new RuntimeException($errorMessage, $curlCode)
            );
        }

        if ($statusCode === 0) {
            fclose($stream);
            throw new LLMConnectionTimeoutException(
                'Connection error: No valid HTTP response received from server',
                new RuntimeException('Invalid HTTP status code: 0')
            );
        }

        if ($statusCode >= 400) {
            $errorBody = stream_get_contents($stream);
            fclose($stream);

            $errorMessage = "HTTP {$statusCode} error";

            if (! empty($errorBody)) {
                $errorData = @json_decode($errorBody, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($errorData['error'])) {
                    if (is_array($errorData['error'])) {
                        $errorMessage .= ": {$errorData['error']['message']}";

                        // 提取 OpenRouter 等代理层封装的底层 provider 原始错误
                        if (isset($errorData['error']['metadata'])) {
                            $metadata = $errorData['error']['metadata'];
                            if (isset($metadata['raw'])) {
                                $errorMessage .= " | provider_raw: {$metadata['raw']}";
                            }
                            if (isset($metadata['provider_name'])) {
                                $errorMessage .= " | provider: {$metadata['provider_name']}";
                            }
                        }
                        if (isset($errorData['error']['code']) && $errorData['error']['code'] !== $statusCode) {
                            $errorMessage .= " | error_code: {$errorData['error']['code']}";
                        }
                    } else {
                        $errorMessage .= ": {$errorData['error']}";
                    }
                } else {
                    // 非标准 JSON 或无 error 字段，直接截断追加原始 body
                    $truncatedBody = strlen($errorBody) > 500
                        ? substr($errorBody, 0, 500) . '...'
                        : $errorBody;
                    $errorMessage .= ": {$truncatedBody}";
                }

                // 始终在日志中记录完整的原始响应体，方便排查
                $logger = \Hyperf\Odin\Utils\LogUtil::getHyperfLogger();
                $logger?->warning('HTTP error response body', [
                    'status_code' => $statusCode,
                    'raw_body' => strlen($errorBody) > 2000 ? substr($errorBody, 0, 2000) . '...' : $errorBody,
                ]);
            }

            if ($statusCode >= 500) {
                throw new LLMApiException(
                    $errorMessage,
                    $statusCode,
                    new RuntimeException($errorMessage, $statusCode),
                    0,
                    $statusCode
                );
            }

            throw new LLMInvalidRequestException(
                $errorMessage,
                new RuntimeException($errorMessage, $statusCode),
                $statusCode
            );
        }

        if (! $skipContentTypeCheck) {
            $contentType = $responseHeaders['content-type'] ?? '';
            if (! empty($contentType) && ! str_contains($contentType, 'text/event-stream')) {
                $body = stream_get_contents($stream);
                fclose($stream);

                $errorMessage = "Expected 'text/event-stream' response but got '{$contentType}'. Response: "
                    . (strlen($body) > 200 ? substr($body, 0, 200) . '...' : $body);

                throw new LLMInvalidRequestException(
                    $errorMessage,
                    new RuntimeException($errorMessage),
                    400
                );
            }
        }

        return new Response($statusCode, $responseHeaders, $stream);
    }
}
