<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Skills;

class SkillCatalogEntry
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
    ) {
    }
}
