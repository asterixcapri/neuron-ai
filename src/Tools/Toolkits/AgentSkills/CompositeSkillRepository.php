<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

use RuntimeException;

use function array_keys;
use function array_map;
use function implode;
use function sort;
use function sprintf;
use function usort;

class CompositeSkillRepository implements SkillRepositoryInterface
{
    /** @var SkillMetadata[] */
    protected array $catalog = [];

    /** @var array<string, SkillRepositoryInterface> */
    protected array $owners = [];

    /** @var string[] */
    protected array $diagnostics = [];

    public function __construct(SkillRepositoryInterface ...$repositories)
    {
        $duplicates = [];

        foreach ($repositories as $repository) {
            foreach ($repository->catalog() as $skill) {
                $this->catalog[] = $skill;

                if (isset($this->owners[$skill->name])) {
                    $duplicates[$skill->name] = true;
                    continue;
                }

                $this->owners[$skill->name] = $repository;
            }

            foreach ($repository->diagnostics() as $diagnostic) {
                $this->diagnostics[] = $diagnostic;
            }
        }

        if ($duplicates !== []) {
            $names = array_keys($duplicates);
            sort($names);
            $quotedNames = array_map(
                fn (string $name): string => sprintf('"%s"', $name),
                $names,
            );

            throw new RuntimeException(sprintf('Duplicate skill names: %s.', implode(', ', $quotedNames)));
        }

        usort(
            $this->catalog,
            fn (SkillMetadata $left, SkillMetadata $right): int => $left->name <=> $right->name,
        );
    }

    public function catalog(): array
    {
        return $this->catalog;
    }

    public function load(string $name): string
    {
        return $this->owner($name)->load($name);
    }

    public function loadResource(string $name, string $path): string
    {
        return $this->owner($name)->loadResource($name, $path);
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    protected function owner(string $name): SkillRepositoryInterface
    {
        if (!isset($this->owners[$name])) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $name));
        }

        return $this->owners[$name];
    }
}
