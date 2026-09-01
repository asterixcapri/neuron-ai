<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

use function array_map;
use function implode;

class SkillToolkit extends AbstractToolkit
{
    /** @var SkillCatalogEntry[] */
    protected array $catalog;

    public function __construct(protected SkillRepositoryInterface $repository)
    {
        $this->catalog = $repository->catalog();
    }

    public function guidelines(): ?string
    {
        if ($this->catalog === []) {
            return null;
        }

        $catalog = array_map(
            fn (SkillCatalogEntry $skill): string => "- {$skill->name}: {$skill->description}",
            $this->catalog,
        );

        return "Available skills:\n".implode("\n", $catalog)
            ."\nUse the `skill` tool to load a relevant skill's complete instructions before following them.";
    }

    public function provide(): array
    {
        if ($this->catalog === []) {
            return [];
        }

        $names = array_map(
            fn (SkillCatalogEntry $skill): string => $skill->name,
            $this->catalog,
        );

        return [new SkillTool($this->repository, $names)];
    }
}
