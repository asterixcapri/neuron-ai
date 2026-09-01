<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\AgentSkills;

use NeuronAI\Tools\Toolkits\AgentSkills\CompositeSkillRepository;
use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillMetadata;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillRepositoryInterface;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillToolkit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

class CompositeSkillRepositoryTest extends TestCase
{
    protected string $skillsRoot;

    protected function setUp(): void
    {
        $this->skillsRoot = sys_get_temp_dir().'/neuron-composite-skills-'.bin2hex(random_bytes(8));
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', <<<'SKILL'
            ---
            name: writing
            description: Write clear prose
            ---
            Filesystem instructions.
            SKILL);
        file_put_contents($this->skillsRoot.'/writing/references/style.md', 'Filesystem resource.');
    }

    protected function tearDown(): void
    {
        unlink($this->skillsRoot.'/writing/references/style.md');
        unlink($this->skillsRoot.'/writing/SKILL.md');
        rmdir($this->skillsRoot.'/writing/references');
        rmdir($this->skillsRoot.'/writing');
        rmdir($this->skillsRoot);
    }

    public function test_combines_filesystem_and_application_repositories_through_public_seams(): void
    {
        $application = new InMemorySkillRepository(
            [new SkillMetadata('analysis', 'Analyse evidence')],
            ['analysis' => 'Application instructions.'],
            ['analysis' => ['references/checklist.md' => 'Application resource.']],
        );
        $repository = new CompositeSkillRepository(
            new FileSystemSkillRepository($this->skillsRoot),
            $application,
        );
        $toolkit = new SkillToolkit($repository);

        $this->assertSame(
            ['analysis', 'writing'],
            array_map(fn (SkillMetadata $skill): string => $skill->name, $repository->catalog()),
        );
        $this->assertSame(
            "Available skills:\n- analysis: Analyse evidence\n- writing: Write clear prose\n"
            .'Load a relevant skill before following its instructions.',
            $toolkit->guidelines(),
        );

        [$skillLoad, $resourceLoad] = $toolkit->tools();
        $skillLoad->setInputs(['name' => 'analysis'])->execute();
        $this->assertSame('Application instructions.', $skillLoad->getResult());
        $skillLoad->setInputs(['name' => 'writing'])->execute();
        $this->assertSame('Filesystem instructions.', $skillLoad->getResult());

        $resourceLoad->setInputs(['name' => 'analysis', 'path' => 'references/checklist.md'])->execute();
        $this->assertSame('Application resource.', $resourceLoad->getResult());
        $resourceLoad->setInputs(['name' => 'writing', 'path' => 'references/style.md'])->execute();
        $this->assertSame('Filesystem resource.', $resourceLoad->getResult());
    }

    public function test_loads_only_from_the_repository_that_owned_the_skill_at_bootstrap(): void
    {
        $owner = new InMemorySkillRepository(
            [new SkillMetadata('analysis', 'Analyse evidence')],
            ['analysis' => 'Owner instructions.'],
            [],
        );
        $other = new InMemorySkillRepository(
            [new SkillMetadata('writing', 'Write prose')],
            ['writing' => 'Other instructions.'],
            ['analysis' => ['shared.md' => 'Wrong repository resource.']],
        );
        $repository = new CompositeSkillRepository($owner, $other);

        $this->assertSame('Owner instructions.', $repository->load('analysis'));
        $this->assertSame(1, $owner->loadCalls);
        $this->assertSame(0, $other->loadCalls);

        try {
            $repository->loadResource('analysis', 'shared.md');
            $this->fail('Expected the owning repository error to be preserved.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Resource "shared.md" is not available for skill "analysis".', $exception->getMessage());
        }

        $this->assertSame(1, $owner->resourceLoadCalls);
        $this->assertSame(0, $other->resourceLoadCalls);
    }

    public function test_duplicate_names_fail_deterministically_during_bootstrap(): void
    {
        $first = new InMemorySkillRepository([
            new SkillMetadata('writing', 'First writing skill'),
            new SkillMetadata('analysis', 'First analysis skill'),
        ]);
        $second = new InMemorySkillRepository([
            new SkillMetadata('analysis', 'Second analysis skill'),
            new SkillMetadata('writing', 'Second writing skill'),
        ]);

        $messages = [];
        foreach ([[$first, $second], [$second, $first]] as $repositories) {
            try {
                new CompositeSkillRepository(...$repositories);
                $this->fail('Expected duplicate skill names to fail during bootstrap.');
            } catch (RuntimeException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        $this->assertSame([
            'Duplicate skill names: "analysis", "writing".',
            'Duplicate skill names: "analysis", "writing".',
        ], $messages);
    }
}

class InMemorySkillRepository implements SkillRepositoryInterface
{
    public int $loadCalls = 0;

    public int $resourceLoadCalls = 0;

    /**
     * @param SkillMetadata[] $skills
     * @param array<string, string> $instructions
     * @param array<string, array<string, string>> $resources
     * @param string[] $diagnostics
     */
    public function __construct(
        protected array $skills,
        protected array $instructions = [],
        protected array $resources = [],
        protected array $diagnostics = [],
    ) {
    }

    public function catalog(): array
    {
        return $this->skills;
    }

    public function load(string $name): string
    {
        $this->loadCalls++;

        return $this->instructions[$name] ?? throw new RuntimeException("Skill \"{$name}\" is not available.");
    }

    public function loadResource(string $name, string $path): string
    {
        $this->resourceLoadCalls++;

        return $this->resources[$name][$path]
            ?? throw new RuntimeException("Resource \"{$path}\" is not available for skill \"{$name}\".");
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
