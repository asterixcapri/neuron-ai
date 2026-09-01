<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

interface SkillRepositoryInterface
{
    /** @return SkillCatalogEntry[] */
    public function catalog(): array;

    public function read(string $name): string;
}
