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

class ModelOptions
{
    /**
     * @var bool 是否支持聊天功能
     */
    protected bool $chat = true;

    /**
     * @var bool 是否支持嵌入功能
     */
    protected bool $embedding = false;

    /**
     * @var bool 是否支持多模态
     */
    protected bool $multiModal = false;

    /**
     * @var bool 是否支持function_call功能
     */
    protected bool $functionCall = false;

    /**
     * @var int 向量大小
     */
    protected int $vectorSize = 0;

    /**
     * @var null|float 固定温度
     */
    protected ?float $fixedTemperature = null;

    /**
     * @var null|float 默认温度。即推荐温度
     */
    protected ?float $defaultTemperature = null;

    protected ?int $maxTokens = null;

    protected ?int $maxOutputTokens = null;

    /**
     * level 到 thinking_budget 的映射表。
     * 用于不支持原生 level 的模型（如 Qwen3.x），当 ThinkingConfig 传入了 level 但没有显式 budget 时，
     * 从此表查找对应的 token 预算。仅在 odin.php 中配置了此项时生效。
     *
     * 示例：['low' => 8000, 'medium' => 12000, 'high' => 16000]
     *
     * @var array<string, int>
     */
    protected array $thinkingBudgetLevels = [];

    public function __construct(array $options = [])
    {
        if (isset($options['chat'])) {
            $this->chat = (bool) $options['chat'];
        }

        if (isset($options['embedding'])) {
            $this->embedding = (bool) $options['embedding'];
        }

        if (isset($options['multi_modal'])) {
            $this->multiModal = (bool) $options['multi_modal'];
        }

        if (isset($options['function_call'])) {
            $this->functionCall = (bool) $options['function_call'];
        }

        if (isset($options['vector_size'])) {
            $this->vectorSize = (int) $options['vector_size'];
        }

        if (isset($options['fixed_temperature'])) {
            $this->fixedTemperature = (float) $options['fixed_temperature'];
        }

        if (isset($options['default_temperature'])) {
            $this->defaultTemperature = (float) $options['default_temperature'];
        }

        if (isset($options['max_tokens'])) {
            $this->maxTokens = (int) $options['max_tokens'];
        }

        if (isset($options['max_output_tokens'])) {
            $this->maxOutputTokens = (int) $options['max_output_tokens'];
        }

        if (isset($options['thinking_budget_levels']) && is_array($options['thinking_budget_levels'])) {
            foreach ($options['thinking_budget_levels'] as $level => $budget) {
                $this->thinkingBudgetLevels[strtolower((string) $level)] = (int) $budget;
            }
        }
    }

    /**
     * 从配置数组创建实例.
     */
    public static function fromArray(array $options = []): self
    {
        return new self($options);
    }

    /**
     * 将选项转换为数组.
     */
    public function toArray(): array
    {
        return [
            'chat' => $this->chat,
            'embedding' => $this->embedding,
            'multi_modal' => $this->multiModal,
            'function_call' => $this->functionCall,
            'vector_size' => $this->vectorSize,
            'fixed_temperature' => $this->fixedTemperature,
            'default_temperature' => $this->defaultTemperature,
            'max_tokens' => $this->maxTokens,
            'max_output_tokens' => $this->maxOutputTokens,
            'thinking_budget_levels' => $this->thinkingBudgetLevels,
        ];
    }

    /**
     * 获取是否支持聊天.
     */
    public function isChat(): bool
    {
        return $this->chat;
    }

    /**
     * 获取是否支持嵌入.
     */
    public function isEmbedding(): bool
    {
        return $this->embedding;
    }

    /**
     * 获取是否支持多模态
     */
    public function isMultiModal(): bool
    {
        return $this->multiModal;
    }

    /**
     * 获取是否支持function_call功能.
     */
    public function supportsFunctionCall(): bool
    {
        return $this->functionCall;
    }

    /**
     * 获取向量大小.
     */
    public function getVectorSize(): int
    {
        return $this->vectorSize;
    }

    public function setChat(bool $chat): void
    {
        $this->chat = $chat;
    }

    public function setEmbedding(bool $embedding): void
    {
        $this->embedding = $embedding;
    }

    public function setMultiModal(bool $multiModal): void
    {
        $this->multiModal = $multiModal;
    }

    public function setFunctionCall(bool $functionCall): void
    {
        $this->functionCall = $functionCall;
    }

    public function setVectorSize(int $vectorSize): void
    {
        $this->vectorSize = $vectorSize;
    }

    public function getFixedTemperature(): ?float
    {
        return $this->fixedTemperature;
    }

    public function setFixedTemperature(?float $fixedTemperature): void
    {
        $this->fixedTemperature = $fixedTemperature;
    }

    public function getDefaultTemperature(): ?float
    {
        return $this->defaultTemperature;
    }

    public function setDefaultTemperature(?float $defaultTemperature): void
    {
        $this->defaultTemperature = $defaultTemperature;
    }

    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function setMaxTokens(?int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;
    }

    public function getMaxOutputTokens(): ?int
    {
        return $this->maxOutputTokens;
    }

    public function setMaxOutputTokens(?int $maxOutputTokens): void
    {
        $this->maxOutputTokens = $maxOutputTokens;
    }

    /**
     * @return array<string, int>
     */
    public function getThinkingBudgetLevels(): array
    {
        return $this->thinkingBudgetLevels;
    }

    /**
     * @param array<string, int> $thinkingBudgetLevels
     */
    public function setThinkingBudgetLevels(array $thinkingBudgetLevels): void
    {
        $this->thinkingBudgetLevels = $thinkingBudgetLevels;
    }
}
