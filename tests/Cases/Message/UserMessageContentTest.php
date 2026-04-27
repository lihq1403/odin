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

use Hyperf\Odin\Message\UserMessageContent;
use HyperfTest\Odin\Cases\AbstractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 用户消息内容类测试.
 * @internal
 */
#[CoversClass(UserMessageContent::class)]
class UserMessageContentTest extends AbstractTestCase
{
    /**
     * 测试文本类型内容.
     */
    public function testTextContent()
    {
        // 使用普通构造方法
        $content = new UserMessageContent('text');
        $content->setText('这是一段文本');

        $this->assertSame('text', $content->getType());
        $this->assertSame('这是一段文本', $content->getText());
        $this->assertTrue($content->isValid());

        // 使用静态工厂方法
        $content2 = UserMessageContent::text('文本内容');
        $this->assertSame('text', $content2->getType());
        $this->assertSame('文本内容', $content2->getText());
        $this->assertTrue($content2->isValid());

        // 测试 toArray
        $array = $content->toArray();
        $this->assertIsArray($array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('text', $array);
        $this->assertSame('text', $array['type']);
        $this->assertSame('这是一段文本', $array['text']);
    }

    /**
     * 测试图片类型内容.
     */
    public function testImageContent()
    {
        // 使用普通构造方法
        $content = new UserMessageContent('image_url');
        $content->setImageUrl('https://example.com/test.jpg');

        $this->assertSame('image_url', $content->getType());
        $this->assertSame('https://example.com/test.jpg', $content->getImageUrl());
        $this->assertTrue($content->isValid());

        // 使用静态工厂方法
        $content2 = UserMessageContent::imageUrl('https://example.com/image2.jpg');
        $this->assertSame('image_url', $content2->getType());
        $this->assertSame('https://example.com/image2.jpg', $content2->getImageUrl());
        $this->assertTrue($content2->isValid());

        // 测试 toArray
        $array = $content->toArray();
        $this->assertIsArray($array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('image_url', $array);
        $this->assertSame('image_url', $array['type']);
        $this->assertIsArray($array['image_url']);
        $this->assertArrayHasKey('url', $array['image_url']);
        $this->assertSame('https://example.com/test.jpg', $array['image_url']['url']);
    }

    /**
     * 测试视频类型内容.
     */
    public function testVideoContent()
    {
        // 使用普通构造方法
        $content = new UserMessageContent('video_url');
        $content->setVideoUrl('https://example.com/test.mp4');

        $this->assertSame('video_url', $content->getType());
        $this->assertSame('https://example.com/test.mp4', $content->getVideoUrl());
        $this->assertNull($content->getFps());
        $this->assertTrue($content->isValid());

        // 使用静态工厂方法，不传 fps
        $content2 = UserMessageContent::videoUrl('https://example.com/video2.mp4');
        $this->assertSame('video_url', $content2->getType());
        $this->assertSame('https://example.com/video2.mp4', $content2->getVideoUrl());
        $this->assertNull($content2->getFps());
        $this->assertTrue($content2->isValid());

        // 测试 toArray（无 fps）
        $array = $content->toArray();
        $this->assertIsArray($array);
        $this->assertSame('video_url', $array['type']);
        $this->assertIsArray($array['video_url']);
        $this->assertArrayHasKey('url', $array['video_url']);
        $this->assertSame('https://example.com/test.mp4', $array['video_url']['url']);
        $this->assertArrayNotHasKey('fps', $array['video_url']);
    }

    /**
     * 测试视频类型内容携带 fps 参数.
     */
    public function testVideoContentWithFps()
    {
        // 使用静态工厂方法，指定 fps
        $content = UserMessageContent::videoUrl('https://example.com/video.mp4', 2.0);
        $this->assertSame(2.0, $content->getFps());

        // 测试 toArray（含 fps）
        $array = $content->toArray();
        $this->assertArrayHasKey('fps', $array['video_url']);
        $this->assertSame(2.0, $array['video_url']['fps']);

        // 通过 setFps 修改
        $content->setFps(1.0);
        $this->assertSame(1.0, $content->getFps());

        // 通过 setFps(null) 清除
        $content->setFps(null);
        $this->assertNull($content->getFps());
        $arrayWithoutFps = $content->toArray();
        $this->assertArrayNotHasKey('fps', $arrayWithoutFps['video_url']);
    }

    /**
     * 测试视频类型 base64 内容.
     */
    public function testVideoBase64Content()
    {
        $base64Url = 'data:video/mp4;base64,AAAAAA==';
        $content = UserMessageContent::videoUrl($base64Url);

        $this->assertSame($base64Url, $content->getVideoUrl());
        $this->assertTrue($content->isValid());

        $array = $content->toArray();
        $this->assertSame($base64Url, $array['video_url']['url']);
    }

    /**
     * 测试验证功能.
     */
    public function testValidation()
    {
        // 未设置内容的文本类型不应该有效
        $invalidText = new UserMessageContent('text');
        $this->assertFalse($invalidText->isValid());

        // 未设置URL的图片类型不应该有效
        $invalidImage = new UserMessageContent('image_url');
        $this->assertFalse($invalidImage->isValid());

        // 未设置URL的视频类型不应该有效
        $invalidVideo = new UserMessageContent('video_url');
        $this->assertFalse($invalidVideo->isValid());

        // 未知类型不应该有效
        $invalidType = new UserMessageContent('unknown_type');
        $this->assertFalse($invalidType->isValid());
    }
}
