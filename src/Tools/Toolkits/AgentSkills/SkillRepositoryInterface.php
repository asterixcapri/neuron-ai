<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

interface SkillRepositoryInterface
{
    /**
     * @return SkillMetadata[]
     */
    public function catalog(): array;

    public function load(string $name): string;

    /**
     * @return string[]
     */
    public function diagnostics(): array;
}
