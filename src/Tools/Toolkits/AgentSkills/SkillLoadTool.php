<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ProvidesConversationInstructions;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

use function in_array;

class SkillLoadTool extends Tool implements HasRunKey, ProvidesConversationInstructions
{
    use TrackByInputs;

    protected ?string $loadedSkill = null;

    protected ?string $loadedInstructions = null;

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

        $this->loadedSkill = $name;
        $this->loadedInstructions = $this->repository->load($name);

        return $this->loadedInstructions;
    }

    public function getConversationInstructionKey(): ?string
    {
        return $this->loadedSkill;
    }

    public function getConversationInstructions(): ?string
    {
        return $this->loadedInstructions;
    }

    public function markConversationInstructionsAlreadyActive(): void
    {
        $this->setResult("Skill \"{$this->loadedSkill}\" is already active.");
    }
}
