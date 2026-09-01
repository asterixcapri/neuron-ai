<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

class ActiveSkill
{
    /**
     * @param array<string, mixed> $inputs
     */
    public function __construct(
        public readonly string $name,
        public readonly string $tool,
        public readonly array $inputs,
        public readonly string $instructions,
    ) {
    }
}
