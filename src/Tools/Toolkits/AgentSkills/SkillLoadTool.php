<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

use function in_array;

class SkillLoadTool extends Tool implements HasRunKey
{
    use TrackByInputs;

    protected ?ActiveSkill $activeSkill = null;

    /**
     * @param string[] $skillNames
     */
    public function __construct(
        protected SkillRepositoryInterface $repository,
        protected array $skillNames,
    ) {
        parent::__construct('skill_load', 'Load the complete instructions for an available skill.');
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The name of the skill to load.',
                required: true,
                enum: $this->skillNames,
            ),
        ];
    }

    public function __invoke(string $name): string
    {
        if (!in_array($name, $this->skillNames, true)) {
            return "Skill \"{$name}\" is not available.";
        }

        $instructions = $this->repository->load($name);
        $this->activeSkill = new ActiveSkill(
            name: $name,
            tool: $this->getName(),
            inputs: $this->getInputs(),
            instructions: $instructions,
        );

        return $instructions;
    }

    public function getActiveSkill(): ?ActiveSkill
    {
        return $this->activeSkill;
    }

    public function markSkillAlreadyActive(): void
    {
        $this->setResult("Skill \"{$this->activeSkill?->name}\" is already active.");
    }
}
