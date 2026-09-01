<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

use function array_map;
use function implode;

class SkillToolkit extends AbstractToolkit
{
    public function __construct(protected SkillRepositoryInterface $repository)
    {
    }

    public function guidelines(): ?string
    {
        $catalog = array_map(
            fn (SkillMetadata $skill): string => "- {$skill->name}: {$skill->description}",
            $this->repository->catalog(),
        );

        if ($catalog === []) {
            return 'No skills are currently available.';
        }

        return "Available skills:\n".implode("\n", $catalog)."\nLoad a relevant skill before following its instructions.";
    }

    public function provide(): array
    {
        $names = array_map(
            fn (SkillMetadata $skill): string => $skill->name,
            $this->repository->catalog(),
        );

        return [new SkillLoadTool($this->repository, $names)];
    }
}
