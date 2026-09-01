<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\AgentSkills;

use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_map;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;

class FileSystemSkillRepositoryTest extends TestCase
{
    protected string $skillsRoot;

    protected function setUp(): void
    {
        $this->skillsRoot = sys_get_temp_dir().'/neuron-skills-'.bin2hex(random_bytes(8));
        mkdir($this->skillsRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->skillsRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->skillsRoot);
    }

    public function test_discovers_valid_direct_children_and_loads_instructions_lazily(): void
    {
        $this->writeSkill('writing', <<<'SKILL'
            ---
            name: writing
            description: "Write clear, concise prose: including YAML punctuation."
            license: MIT
            compatibility: Requires PHP 8.1+
            metadata:
              author: Neuron
            allowed-tools: "search read"
            ---
            # Writing

            Prefer short sentences.
            SKILL);
        $this->writeSkill('analysis', <<<'SKILL'
            ---
            name: analysis
            description: Analyse a problem
            ---
            # Analysis

            Check the evidence.
            SKILL);
        mkdir($this->skillsRoot.'/nested/ignored', 0o777, true);
        file_put_contents($this->skillsRoot.'/nested/ignored/SKILL.md', "---\nname: ignored\ndescription: Ignore me\n---\nBody");

        $repository = new FileSystemSkillRepository($this->skillsRoot);
        $catalog = $repository->catalog();

        $this->assertSame(['analysis', 'writing'], array_map(fn ($skill): string => $skill->name, $catalog));
        $this->assertSame('Write clear, concise prose: including YAML punctuation.', $catalog[1]->description);
        $this->assertSame('MIT', $catalog[1]->license);
        $this->assertSame('Requires PHP 8.1+', $catalog[1]->compatibility);
        $this->assertSame(['author' => 'Neuron'], $catalog[1]->metadata);
        $this->assertSame('search read', $catalog[1]->allowedTools);

        file_put_contents(
            $this->skillsRoot.'/writing/SKILL.md',
            str_replace('Prefer short sentences.', 'Prefer direct sentences.', file_get_contents($this->skillsRoot.'/writing/SKILL.md')),
        );

        $this->assertSame("# Writing\n\nPrefer direct sentences.", $repository->load('writing'));
    }

    public function test_excludes_invalid_skills_and_reports_diagnostics(): void
    {
        $this->writeSkill('valid', "---\nname: valid\ndescription: Valid skill\n---\nValid instructions");
        $this->writeSkill('missing-description', "---\nname: missing-description\n---\nInvalid");
        $this->writeSkill('wrong-directory', "---\nname: another-name\ndescription: Invalid name\n---\nInvalid");
        $this->writeSkill('malformed', "---\nname: malformed\ndescription: [broken\n---\nInvalid");

        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame(['valid'], array_map(fn ($skill): string => $skill->name, $repository->catalog()));
        $this->assertCount(3, $repository->diagnostics());
        $this->assertStringContainsString('missing-description', implode("\n", $repository->diagnostics()));
        $this->assertStringContainsString('wrong-directory', implode("\n", $repository->diagnostics()));
        $this->assertStringContainsString('malformed', implode("\n", $repository->diagnostics()));
    }

    protected function writeSkill(string $directory, string $contents): void
    {
        mkdir($this->skillsRoot.'/'.$directory, 0o777, true);
        file_put_contents($this->skillsRoot.'/'.$directory.'/SKILL.md', $contents);
    }
}
