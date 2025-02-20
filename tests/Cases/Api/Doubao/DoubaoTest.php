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

namespace HyperfTest\Odin\Cases\Api\Doubao;

use Hyperf\Odin\Api\Doubao\Client;
use Hyperf\Odin\Api\Doubao\Doubao;
use Hyperf\Odin\Api\Doubao\DoubaoConfig;
use HyperfTest\Odin\Cases\AbstractTestCase;

/**
 * @internal
 * @coversNothing
 */
class DoubaoTest extends AbstractTestCase
{
    public function testGetClientWithNewConfig()
    {
        $config = new DoubaoConfig('test_api_key', 'https://custom.url/', 'test_model');
        $skylark = new Doubao();

        $client = $skylark->getClient($config);

        $this->assertInstanceOf(Client::class, $client);
    }

    public function testGetClientWithExistingConfig()
    {
        $config = new DoubaoConfig('test_api_key', 'https://custom.url/', 'test_model');
        $skylark = new Doubao();

        $client1 = $skylark->getClient($config);
        $client2 = $skylark->getClient($config);

        $this->assertSame($client1, $client2);
    }
}
