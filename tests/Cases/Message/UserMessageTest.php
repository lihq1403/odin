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

namespace HyperfTest\Odin\Cases\Message;

use Hyperf\Odin\Message\Role;
use Hyperf\Odin\Message\UserMessage;
use Hyperf\Odin\Message\UserMessageContent;
use HyperfTest\Odin\Cases\AbstractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 用户消息类测试.
 * @internal
 */
#[CoversClass(UserMessage::class)]
class UserMessageTest extends AbstractTestCase
{
    /**
     * 测试用户消息的角色.
     */
    public function testRole()
    {
        $message = new UserMessage('用户消息');
        $this->assertSame(Role::User, $message->getRole());
    }

    /**
     * 测试简单文本内容的用户消息.
     */
    public function testSimpleContent()
    {
        $message = new UserMessage('用户消息内容');
        $this->assertSame('用户消息内容', $message->getContent());

        // 测试 toArray
        $array = $message->toArray();
        $this->assertArrayHasKey('role', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayNotHasKey('identifier', $array);
        $this->assertSame(Role::User->value, $array['role']);
        $this->assertSame('用户消息内容', $array['content']);
    }

    /**
     * 测试多模态内容.
     */
    public function testMultimodalContent()
    {
        // 创建一个带有文本和图像的用户消息
        $message = new UserMessage();
        $message->addContent(UserMessageContent::text('这是文本内容'));
        $message->addContent(UserMessageContent::imageUrl('https://example.com/image.jpg'));

        // 测试内容列表
        $contents = $message->getContents();
        $this->assertIsArray($contents);
        $this->assertCount(2, $contents);

        // 测试 toArray
        $array = $message->toArray();
        $this->assertArrayHasKey('role', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayNotHasKey('identifier', $array);
        $this->assertIsArray($array['content']);
        $this->assertCount(2, $array['content']);

        // 检查内容项的结构
        $this->assertSame('text', $array['content'][0]['type']);
        $this->assertSame('这是文本内容', $array['content'][0]['text']);
        $this->assertSame('image_url', $array['content'][1]['type']);
        $this->assertSame('https://example.com/image.jpg', $array['content'][1]['image_url']['url']);
    }

    /**
     * 测试从数组创建用户消息.
     */
    public function testFromArrayWithSimpleContent()
    {
        $array = [
            'content' => '用户消息内容',
        ];

        $message = UserMessage::fromArray($array);

        $this->assertInstanceOf(UserMessage::class, $message);
        $this->assertSame('用户消息内容', $message->getContent());
        $this->assertSame('', $message->getIdentifier());
        $this->assertNull($message->getContents()); // 简单内容不会创建 contents 数组

        // 手动设置标识符并验证
        $message->setIdentifier('user-123');
        $this->assertSame('user-123', $message->getIdentifier());
    }

    /**
     * 测试从数组创建带有多模态内容的用户消息.
     */
    public function testFromArrayWithMultimodalContent()
    {
        $array = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => '这是文本',
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'https://example.com/image.jpg',
                    ],
                ],
            ],
        ];

        $message = UserMessage::fromArray($array);

        $this->assertInstanceOf(UserMessage::class, $message);
        $this->assertSame('', $message->getIdentifier());

        // 手动设置标识符并验证
        $message->setIdentifier('user-multi-123');
        $this->assertSame('user-multi-123', $message->getIdentifier());

        // 检查多模态内容
        $contents = $message->getContents();
        $this->assertIsArray($contents);
        $this->assertCount(2, $contents);

        // 检查内容格式
        $contentArray = $message->toArray();
        $this->assertArrayHasKey('content', $contentArray);
        $this->assertIsArray($contentArray['content']);
        $this->assertCount(2, $contentArray['content']);
        $this->assertSame('text', $contentArray['content'][0]['type']);
        $this->assertSame('这是文本', $contentArray['content'][0]['text']);
    }

    /**
     * 测试从数组创建带有视频内容的用户消息.
     */
    public function testFromArrayWithVideoContent()
    {
        $array = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => '请分析这段视频',
                ],
                [
                    'type' => 'video_url',
                    'video_url' => [
                        'url' => 'https://example.com/video.mp4',
                        'fps' => 2.0,
                    ],
                ],
            ],
        ];

        $message = UserMessage::fromArray($array);

        $contents = $message->getContents();
        $this->assertIsArray($contents);
        $this->assertCount(2, $contents);

        $this->assertSame(UserMessageContent::TEXT, $contents[0]->getType());
        $this->assertSame('请分析这段视频', $contents[0]->getText());

        $this->assertSame(UserMessageContent::VIDEO_URL, $contents[1]->getType());
        $this->assertSame('https://example.com/video.mp4', $contents[1]->getVideoUrl());
        $this->assertSame(2.0, $contents[1]->getFps());

        // 验证 toArray 能正确序列化回去
        $serialized = $message->toArray();
        $this->assertSame('video_url', $serialized['content'][1]['type']);
        $this->assertSame('https://example.com/video.mp4', $serialized['content'][1]['video_url']['url']);
        $this->assertSame(2.0, $serialized['content'][1]['video_url']['fps']);
    }

    /**
     * 测试从数组创建不含 fps 的视频内容.
     */
    public function testFromArrayWithVideoContentWithoutFps()
    {
        $array = [
            'content' => [
                [
                    'type' => 'video_url',
                    'video_url' => [
                        'url' => 'https://example.com/video.mp4',
                    ],
                ],
            ],
        ];

        $message = UserMessage::fromArray($array);
        $contents = $message->getContents();

        $this->assertIsArray($contents);
        $this->assertCount(1, $contents);
        $this->assertNull($contents[0]->getFps());

        // fps 为 null 时序列化结果中不包含 fps 字段
        $serialized = $message->toArray();
        $this->assertArrayNotHasKey('fps', $serialized['content'][0]['video_url']);
    }

    /**
     * 测试 hasVideoMultiModal 检测.
     */
    public function testHasVideoMultiModal()
    {
        $message = new UserMessage();
        $this->assertFalse($message->hasVideoMultiModal());

        $message->addContent(UserMessageContent::text('文本'));
        $this->assertFalse($message->hasVideoMultiModal());

        $message->addContent(UserMessageContent::videoUrl('https://example.com/video.mp4'));
        $this->assertTrue($message->hasVideoMultiModal());
        $this->assertFalse($message->hasImageMultiModal());
    }

    /**
     * 带 cache_control 的文本块；以及无 type 仅有 text 的块（与 ToolMessage 等格式对齐）.
     */
    public function testFromArrayWithCacheControlAndTextOnlyBlocks()
    {
        $message = UserMessage::fromArray([
            'content' => [
                [
                    'type' => 'text',
                    'text' => '第一段',
                    'cache_control' => ['type' => 'ephemeral'],
                ],
                [
                    'text' => '第二段',
                ],
            ],
        ]);

        $contents = $message->getContents();
        $this->assertIsArray($contents);
        $this->assertCount(2, $contents);
        $this->assertSame('第一段', $contents[0]->getText());
        $this->assertSame('第二段', $contents[1]->getText());
    }
}
