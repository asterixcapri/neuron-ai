<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\AgentSkills;

use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillMetadata;
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
use function str_repeat;
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

        $this->assertSame(['analysis', 'writing'], array_map(fn (SkillMetadata $skill): string => $skill->name, $catalog));
        $this->assertSame('Write clear, concise prose: including YAML punctuation.', $catalog[1]->description);
        $this->assertSame('MIT', $catalog[1]->license);
        $this->assertSame('Requires PHP 8.1+', $catalog[1]->compatibility);
        $this->assertSame(['author' => 'Neuron'], $catalog[1]->metadata);
        $this->assertSame('search read', $catalog[1]->allowedTools);

        $skillContents = file_get_contents($this->skillsRoot.'/writing/SKILL.md');
        $this->assertIsString($skillContents);
        file_put_contents(
            $this->skillsRoot.'/writing/SKILL.md',
            str_replace('Prefer short sentences.', 'Prefer direct sentences.', $skillContents),
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

        $this->assertSame(['valid'], array_map(fn (SkillMetadata $skill): string => $skill->name, $repository->catalog()));
        $this->assertCount(3, $repository->diagnostics());
        $this->assertStringContainsString('missing-description', implode("\n", $repository->diagnostics()));
        $this->assertStringContainsString('wrong-directory', implode("\n", $repository->diagnostics()));
        $this->assertStringContainsString('malformed', implode("\n", $repository->diagnostics()));
    }

    /**
     * @dataProvider invalidMetadata
     */
    public function test_excludes_metadata_that_violates_the_agent_skills_spec(
        string $directory,
        string $frontmatter,
        string $message,
    ): void {
        $this->writeSkill($directory, "---\n{$frontmatter}\n---\nInstructions");
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame([], $repository->catalog());
        $this->assertStringContainsString($message, implode("\n", $repository->diagnostics()));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidMetadata(): array
    {
        return [
            'uppercase name' => ['Bad-name', "name: Bad-name\ndescription: Invalid", 'name" must be 1-64'],
            'leading hyphen' => ['-bad-name', "name: -bad-name\ndescription: Invalid", 'name" must be 1-64'],
            'trailing hyphen' => ['bad-name-', "name: bad-name-\ndescription: Invalid", 'name" must be 1-64'],
            'consecutive hyphens' => ['bad--name', "name: bad--name\ndescription: Invalid", 'name" must be 1-64'],
            'long name' => [str_repeat('a', 65), 'name: '.str_repeat('a', 65)."\ndescription: Invalid", 'name" must be 1-64'],
            'empty description' => ['empty-description', "name: empty-description\ndescription: ''", 'description" must be a string between 1 and 1024'],
            'long description' => ['long-description', "name: long-description\ndescription: '".str_repeat('a', 1025)."'", 'description" must be a string between 1 and 1024'],
            'empty compatibility' => ['empty-compatibility', "name: empty-compatibility\ndescription: Invalid\ncompatibility: ''", 'compatibility" must be between 1 and 500'],
            'long compatibility' => ['long-compatibility', "name: long-compatibility\ndescription: Invalid\ncompatibility: '".str_repeat('a', 501)."'", 'compatibility" must be between 1 and 500'],
            'metadata value is not a string' => ['number-metadata', "name: number-metadata\ndescription: Invalid\nmetadata:\n  version: 1", 'metadata" must map strings to strings'],
            'metadata key is not a string' => ['number-key', "name: number-key\ndescription: Invalid\nmetadata:\n  1: value", 'metadata" must map strings to strings'],
            'license is not a string' => ['list-license', "name: list-license\ndescription: Invalid\nlicense: [MIT]", 'license" must be a string'],
            'allowed-tools is not a string' => ['list-tools', "name: list-tools\ndescription: Invalid\nallowed-tools: [read]", 'allowed-tools" must be a string'],
        ];
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

    public function test_excludes_skill_directories_and_manifests_that_resolve_outside_their_boundaries(): void
    {
        mkdir($this->outsideRoot.'/external-skill', 0o777, true);
        file_put_contents(
            $this->outsideRoot.'/external-skill/SKILL.md',
            "---\nname: external-skill\ndescription: External\n---\nInstructions",
        );
        symlink($this->outsideRoot.'/external-skill', $this->skillsRoot.'/external-skill');

        mkdir($this->skillsRoot.'/linked-manifest', 0o777, true);
        file_put_contents(
            $this->outsideRoot.'/SKILL.md',
            "---\nname: linked-manifest\ndescription: External manifest\n---\nInstructions",
        );
        symlink($this->outsideRoot.'/SKILL.md', $this->skillsRoot.'/linked-manifest/SKILL.md');

        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame([], $repository->catalog());
        $diagnostics = implode("\n", $repository->diagnostics());
        $this->assertStringContainsString('external-skill: Skill directory is outside', $diagnostics);
        $this->assertStringContainsString('linked-manifest: SKILL.md is outside', $diagnostics);
    }

    public function test_load_rejects_a_manifest_replaced_by_an_external_symlink_after_discovery(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Write prose\n---\nInstructions");
        $repository = new FileSystemSkillRepository($this->skillsRoot);
        $this->assertCount(1, $repository->catalog());

        file_put_contents(
            $this->outsideRoot.'/replacement.md',
            "---\nname: writing\ndescription: Replaced\n---\nExternal instructions",
        );
        unlink($this->skillsRoot.'/writing/SKILL.md');
        symlink($this->outsideRoot.'/replacement.md', $this->skillsRoot.'/writing/SKILL.md');

        $this->expectExceptionMessage('SKILL.md is outside its skill directory.');

        $repository->load('writing');
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
