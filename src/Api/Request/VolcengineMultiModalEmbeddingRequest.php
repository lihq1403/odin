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

namespace Hyperf\Odin\Api\Request;

use GuzzleHttp\RequestOptions;
use Hyperf\Odin\Exception\InvalidArgumentException;

class VolcengineMultiModalEmbeddingRequest extends EmbeddingRequest
{
    public function validate(): void
    {
        parent::validate();

        if (! is_string($this->getInput())) {
            throw new InvalidArgumentException('Input must be a string');
        }
    }

    public function createOptions(): array
    {
        $this->validate();

        $payload = [
            'model' => $this->getModel(),
            'input' => [
                [
                    'type' => 'text',
                    'text' => $this->getInput(),
                ],
            ],
            'encoding_format' => $this->getEncodingFormat(),
        ];

        if ($this->getDimensions() !== null && isset($this->getDimensions()[0])) {
            $payload['dimensions'] = (int) $this->getDimensions()[0];
        }

        if ($this->getUser() !== null) {
            $payload['user'] = $this->getUser();
        }

        if ($this->isIncludeBusinessParams() && ! empty($this->getBusinessParams())) {
            $payload['business_params'] = $this->getBusinessParams();
        }

        return [
            RequestOptions::JSON => $payload,
        ];
    }
}
