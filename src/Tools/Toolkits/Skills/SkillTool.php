<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Skills;

use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

use function in_array;

class SkillTool extends Tool implements HasRunKey
{
    use TrackByInputs;

    /**
     * @param string[] $skillNames
     */
    public function __construct(
        protected SkillRepositoryInterface $repository,
        protected array $skillNames,
    ) {
        parent::__construct('skill', 'Load an available skill\'s instructions or one textual supporting file.');
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
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'An optional file path relative to the skill directory.',
                required: false,
            ),
        ];
    }

    public function __invoke(string $name, ?string $path = null): string
    {
        if (!in_array($name, $this->skillNames, true)) {
            return "Skill \"{$name}\" is not available.";
        }

        return $this->repository->read($name, $path);
    }
}
