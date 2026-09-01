<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use function json_encode;
use function hash;

/**
 * Trait for tools that want input-based run key generation.
 *
 * Provides a getRunKey() implementation that combines the tool name with
 * its input parameters. Tools can use this trait as-is, or override
 * getRunKey() to select which parameters matter for their use case.
 *
 * @example Selecting key parameters
 * <code>
 * public function getRunKey(): string
 * {
 *     return $this->getName() . ':' . $this->getInput('file_path') . ':' . $this->getInput('offset');
 * }
 * </code>
 *
 * @mixin HasRunKey&ToolInterface
 */
trait TrackByInputs
{
    public function getRunKey(): string
    {
        return $this->buildRunKey($this->getInputs());
    }

    /** @param array<string, mixed> $inputs */
    protected function buildRunKey(array $inputs): string
    {
        return $this->getName() . ':' . hash('sha1', json_encode($inputs));
    }
}
