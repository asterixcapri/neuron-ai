<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use RuntimeException;

use function in_array;

class SkillLoadResourceTool extends Tool implements HasRunKey
{
    use TrackByInputs;

    /**
     * @param string[] $skillNames
     */
    public function __construct(
        protected SkillRepositoryInterface $repository,
        protected array $skillNames,
    ) {
        parent::__construct('skill_load_resource', 'Load a textual resource belonging to an available skill.');
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The name of the skill that owns the resource.',
                required: true,
                enum: $this->skillNames,
            ),
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'The logical path of the resource relative to the skill directory.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $name, string $path): string
    {
        if (!in_array($name, $this->skillNames, true)) {
            return "Skill \"{$name}\" is not available.";
        }

        try {
            return $this->repository->loadResource($name, $path);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
