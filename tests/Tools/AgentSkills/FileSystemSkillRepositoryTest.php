<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\AgentSkills;

use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function array_map;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function symlink;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;

class FileSystemSkillRepositoryTest extends TestCase
{
    protected string $skillsRoot;

    protected string $outsideRoot;

    protected function setUp(): void
    {
        $this->skillsRoot = sys_get_temp_dir().'/neuron-skills-'.bin2hex(random_bytes(8));
        $this->outsideRoot = sys_get_temp_dir().'/neuron-outside-skill-'.bin2hex(random_bytes(8));
        mkdir($this->skillsRoot, 0o777, true);
        mkdir($this->outsideRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->skillsRoot);
        $this->removeDirectory($this->outsideRoot);
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

    public function test_loads_nested_resources_and_reads_scripts_without_executing_them(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Write prose\n---\nInstructions");
        mkdir($this->skillsRoot.'/writing/materials/deep', 0o777, true);
        mkdir($this->skillsRoot.'/writing/scripts', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/materials/deep/style.md', "# Style\n\nBe direct.");
        $script = '<?php file_put_contents("'.$this->outsideRoot.'/executed", "yes");';
        file_put_contents($this->skillsRoot.'/writing/scripts/prepare.php', $script);

        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame("# Style\n\nBe direct.", $repository->loadResource('writing', 'materials/deep/style.md'));
        $this->assertSame($script, $repository->loadResource('writing', 'scripts/prepare.php'));
        $this->assertFileDoesNotExist($this->outsideRoot.'/executed');
    }

    /**
     * @dataProvider invalidResourcePaths
     */
    public function test_rejects_empty_absolute_and_traversing_resource_paths(string $path, string $message): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Write prose\n---\nInstructions");
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->expectExceptionMessage($message);

        $repository->loadResource('writing', $path);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidResourcePaths(): array
    {
        return [
            'empty' => ['', 'Resource path cannot be empty.'],
            'POSIX absolute' => ['/etc/passwd', 'Resource path "/etc/passwd" must be relative.'],
            'Windows absolute' => ['C:\\Windows\\system.ini', 'Resource path "C:\\Windows\\system.ini" must be relative.'],
            'UNC absolute' => ['\\\\server\\share\\file.txt', 'Resource path "\\\\server\\share\\file.txt" must be relative.'],
            'parent traversal' => ['../outside.txt', 'Resource path "../outside.txt" cannot contain ".." segments.'],
            'nested traversal' => ['references/deep/../../outside.txt', 'Resource path "references/deep/../../outside.txt" cannot contain ".." segments.'],
        ];
    }

    public function test_reports_missing_resources_deterministically(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Write prose\n---\nInstructions");
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->expectExceptionMessage('Resource "references/missing.md" was not found in skill "writing".');

        $repository->loadResource('writing', 'references/missing.md');
    }

    public function test_allows_confined_symlinks_and_rejects_symlinks_outside_the_skill(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Write prose\n---\nInstructions");
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/references/inside.md', 'Inside');
        file_put_contents($this->outsideRoot.'/outside.md', 'Outside');
        symlink($this->skillsRoot.'/writing/references/inside.md', $this->skillsRoot.'/writing/inside-link.md');
        symlink($this->outsideRoot.'/outside.md', $this->skillsRoot.'/writing/outside-link.md');

        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame('Inside', $repository->loadResource('writing', 'inside-link.md'));

        $this->expectExceptionMessage('Resource "outside-link.md" is outside skill "writing".');

        $repository->loadResource('writing', 'outside-link.md');
    }

    public function test_rejects_binary_resources_without_returning_their_bytes(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Write prose\n---\nInstructions");
        file_put_contents($this->skillsRoot.'/writing/image.bin', "visible\0\xFFsecret");
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        try {
            $repository->loadResource('writing', 'image.bin');
            $this->fail('Expected unsupported binary content to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Resource "image.bin" in skill "writing" contains unsupported binary content.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    protected function writeSkill(string $directory, string $contents): void
    {
        mkdir($this->skillsRoot.'/'.$directory, 0o777, true);
        file_put_contents($this->skillsRoot.'/'.$directory.'/SKILL.md', $contents);
    }

    protected function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isLink() || !$item->isDir() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }

        rmdir($directory);
    }
}
