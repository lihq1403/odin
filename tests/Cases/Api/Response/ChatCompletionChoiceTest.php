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

namespace HyperfTest\Odin\Cases\Api\Response;

use Hyperf\Odin\Api\Response\ChatCompletionChoice;
use Hyperf\Odin\Message\AssistantMessage;
use HyperfTest\Odin\Cases\AbstractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ChatCompletionChoice::class)]
class ChatCompletionChoiceTest extends AbstractTestCase
{
    /**
     * 流式 delta 仅含 reasoning（OpenRouter 风格）时应进入 AssistantMessage.reasoning_content.
     */
    public function testDeltaOpenRouterReasoningMapsToReasoningContent(): void
    {
        $choice = ChatCompletionChoice::fromArray([
            'index' => 0,
            'delta' => [
                'role' => 'assistant',
                'content' => '',
                'reasoning' => '片段甲',
            ],
            'finish_reason' => null,
        ]);

        $message = $choice->getMessage();
        $this->assertInstanceOf(AssistantMessage::class, $message);
        assert($message instanceof AssistantMessage);
        $this->assertSame('片段甲', $message->getReasoningContent());
    }

    /**
     * 已有 reasoning_content 时不应被 delta.reasoning 覆盖.
     */
    public function testDeltaReasoningContentTakesPrecedenceOverReasoning(): void
    {
        $choice = ChatCompletionChoice::fromArray([
            'index' => 0,
            'delta' => [
                'role' => 'assistant',
                'content' => '',
                'reasoning_content' => '官方字段',
                'reasoning' => '厂商字段',
            ],
            'finish_reason' => null,
        ]);

        $message = $choice->getMessage();
        $this->assertInstanceOf(AssistantMessage::class, $message);
        assert($message instanceof AssistantMessage);
        $this->assertSame('官方字段', $message->getReasoningContent());
    }

    /**
     * 无 reasoning 时仍可仅靠 reasoning_details 供 AssistantMessage 抽取（与 OpenRouter 双字段并行帧一致）.
     */
    public function testDeltaReasoningDetailsOnlyStillExtractsReasoningContent(): void
    {
        $choice = ChatCompletionChoice::fromArray([
            'index' => 0,
            'delta' => [
                'role' => 'assistant',
                'content' => '',
                'reasoning_details' => [
                    ['type' => 'reasoning.text', 'text' => '仅 details', 'format' => 'unknown', 'index' => 0],
                ],
            ],
            'finish_reason' => null,
        ]);

        $message = $choice->getMessage();
        $this->assertInstanceOf(AssistantMessage::class, $message);
        assert($message instanceof AssistantMessage);
        $this->assertSame('仅 details', $message->getReasoningContent());
    }
}
