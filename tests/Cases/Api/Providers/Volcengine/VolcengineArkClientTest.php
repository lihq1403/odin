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

namespace HyperfTest\Odin\Cases\Api\Providers\Volcengine;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Hyperf\Context\ApplicationContext;
use Hyperf\Odin\Api\Providers\OpenAI\OpenAIConfig;
use Hyperf\Odin\Api\Providers\Volcengine\VolcengineArkClient;
use Hyperf\Odin\Api\Request\VolcengineMultiModalEmbeddingRequest;
use Hyperf\Odin\Event\AfterEmbeddingsEvent;
use HyperfTest\Odin\Cases\AbstractTestCase;
use Mockery;
use Mockery\MockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;

/**
 * @internal
 * @covers \Hyperf\Odin\Api\Providers\Volcengine\VolcengineArkClient
 */
class VolcengineArkClientTest extends AbstractTestCase
{
    private const BASE_URL = 'https://ark.example.com';

    private ?ContainerInterface $originalContainer = null;

    private bool $hadOriginalContainer = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadOriginalContainer = ApplicationContext::hasContainer();
        if ($this->hadOriginalContainer) {
            $this->originalContainer = ApplicationContext::getContainer();
        }
    }

    protected function tearDown(): void
    {
        $this->restoreApplicationContext();
        Mockery::close();
        parent::tearDown();
    }

    public function testMultimodalEmbeddingsDispatchesAfterEmbeddingsEventWithUsage(): void
    {
        $businessParams = [
            'business_id' => 'billing-test',
            'model_id' => 'doubao-embedding-vision',
        ];
        $this->expectAfterEmbeddingsEvent($businessParams, [
            'prompt_tokens' => 12,
            'total_tokens' => 12,
        ]);

        $client = $this->createClientWithResponse([
            'data' => [
                'embedding' => [0.1, 0.2, 0.3],
            ],
            'model' => 'ep-test',
            'usage' => [
                'prompt_tokens' => 12,
                'total_tokens' => 12,
            ],
        ]);

        $request = $this->createEmbeddingRequest($businessParams);
        $response = $client->multimodalEmbeddings($request);

        $this->assertSame(12, $response->getUsage()?->getPromptTokens());
        $this->assertSame(12, $response->getUsage()?->getTotalTokens());
        $this->assertSame([0.1, 0.2, 0.3], $response->getData()[0]->getEmbedding());
    }

    public function testMultimodalEmbeddingsDispatchesAfterEmbeddingsEventWithoutSyntheticUsage(): void
    {
        $businessParams = [
            'business_id' => 'billing-test-no-usage',
            'model_id' => 'doubao-embedding-vision',
        ];
        $this->expectAfterEmbeddingsEvent($businessParams, null);

        $client = $this->createClientWithResponse([
            'data' => [
                'embedding' => [0.4, 0.5, 0.6],
            ],
            'model' => 'ep-test',
        ]);

        $request = $this->createEmbeddingRequest($businessParams);
        $response = $client->multimodalEmbeddings($request);

        $this->assertNull($response->getUsage());
        $this->assertSame([0.4, 0.5, 0.6], $response->getData()[0]->getEmbedding());
    }

    private function createClientWithResponse(array $responseBody): VolcengineArkClient
    {
        $config = new OpenAIConfig(
            apiKey: 'test-api-key',
            baseUrl: self::BASE_URL,
        );
        $client = new VolcengineArkClient($config);

        /** @var GuzzleClient&MockInterface $httpClient */
        $httpClient = Mockery::mock(GuzzleClient::class);
        $httpClient->shouldReceive('post')
            ->once()
            ->with(
                self::BASE_URL . '/embeddings/multimodal',
                Mockery::on(fn (array $options): bool => $this->assertRequestOptions($options))
            )
            ->andReturn(new Response(200, [], json_encode($responseBody, JSON_THROW_ON_ERROR)));

        $this->setNonpublicPropertyValue($client, 'client', $httpClient);

        return $client;
    }

    private function createEmbeddingRequest(array $businessParams): VolcengineMultiModalEmbeddingRequest
    {
        $request = new VolcengineMultiModalEmbeddingRequest(
            input: 'hello billing',
            model: 'ep-test',
            encoding_format: 'float',
        );
        $request->setBusinessParams($businessParams);
        $request->setIncludeBusinessParams(true);

        return $request;
    }

    private function expectAfterEmbeddingsEvent(array $businessParams, ?array $usage): void
    {
        /** @var EventDispatcherInterface&MockInterface $dispatcher */
        $dispatcher = Mockery::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function (object $event) use ($businessParams, $usage): bool {
                $this->assertInstanceOf(AfterEmbeddingsEvent::class, $event);
                /** @var AfterEmbeddingsEvent $event */
                $this->assertSame('ep-test', $event->getEmbeddingRequest()->getModel());
                $this->assertSame($businessParams, $event->getEmbeddingRequest()->getBusinessParams());

                if ($usage === null) {
                    $this->assertNull($event->getEmbeddingResponse()->getUsage());
                    return true;
                }

                $eventUsage = $event->getEmbeddingResponse()->getUsage();
                $this->assertNotNull($eventUsage);
                $this->assertSame($usage['prompt_tokens'], $eventUsage->getPromptTokens());
                $this->assertSame($usage['total_tokens'], $eventUsage->getTotalTokens());

                return true;
            }))
            ->andReturnUsing(static fn (object $event): object => $event);

        /** @var ContainerInterface&MockInterface $container */
        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')
            ->with(EventDispatcherInterface::class)
            ->andReturnTrue();
        $container->shouldReceive('get')
            ->with(EventDispatcherInterface::class)
            ->andReturn($dispatcher);

        ApplicationContext::setContainer($container);
    }

    private function assertRequestOptions(array $options): bool
    {
        $payload = $options[RequestOptions::JSON] ?? [];

        $this->assertSame('ep-test', $payload['model'] ?? null);
        $this->assertSame([
            [
                'type' => 'text',
                'text' => 'hello billing',
            ],
        ], $payload['input'] ?? null);
        $this->assertSame('float', $payload['encoding_format'] ?? null);
        $this->assertSame('doubao-embedding-vision', $payload['business_params']['model_id'] ?? null);

        return true;
    }

    private function restoreApplicationContext(): void
    {
        $reflection = new ReflectionClass(ApplicationContext::class);
        $property = $reflection->getProperty('container');
        $property->setAccessible(true);
        $property->setValue(null, $this->hadOriginalContainer ? $this->originalContainer : null);
    }
}
