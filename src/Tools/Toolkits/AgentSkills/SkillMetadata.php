<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

class SkillMetadata
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?string $license = null,
        public readonly ?string $compatibility = null,
        public readonly array $metadata = [],
        public readonly ?string $allowedTools = null,
    ) {
    }
}
