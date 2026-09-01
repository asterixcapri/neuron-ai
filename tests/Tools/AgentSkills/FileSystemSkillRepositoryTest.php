<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\AgentSkills;

use FilesystemIterator;
use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillCatalogEntry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_map;
use function bin2hex;
use function chmod;
use function file_put_contents;
use function is_dir;
use function is_readable;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

class FileSystemSkillRepositoryTest extends TestCase
{
    protected string $skillsRoot;
    protected string $outsideRoot;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->skillsRoot = sys_get_temp_dir().'/neuron-skills-'.$suffix;
        $this->outsideRoot = sys_get_temp_dir().'/neuron-outside-skills-'.$suffix;
        mkdir($this->skillsRoot, 0o777, true);
        mkdir($this->outsideRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->skillsRoot);
        $this->removeDirectory($this->outsideRoot);
    }

    public function test_discovers_only_valid_direct_children_in_alphabetical_order(): void
    {
        file_put_contents($this->skillsRoot.'/README.md', 'Not a skill.');
        $this->writeSkill('writing', <<<'SKILL'
            ---
            name: writing
            description: Write clearly: for humans
            license: MIT
            metadata: ignored
            ---
            Secret writing instructions.
            SKILL);
        $this->writeSkill('analysis', "---\nname: analysis\ndescription: Analyse evidence\n---\nAnalyse carefully.");
        $this->writeSkill(
            'quoted-description',
            "---\nname: quoted-description\ndescription: \"Quoted text\"\n---\nQuoted.",
        );
        mkdir($this->skillsRoot.'/nested/ignored', 0o777, true);
        file_put_contents(
            $this->skillsRoot.'/nested/ignored/SKILL.md',
            "---\nname: ignored\ndescription: Nested skill\n---\nIgnored.",
        );

        $catalog = (new FileSystemSkillRepository($this->skillsRoot))->catalog();

        $this->assertEquals([
            new SkillCatalogEntry('analysis', 'Analyse evidence'),
            new SkillCatalogEntry('quoted-description', '"Quoted text"'),
            new SkillCatalogEntry('writing', 'Write clearly: for humans'),
        ], $catalog);
        $this->assertSame(
            ['analysis', 'quoted-description', 'writing'],
            array_map(fn (SkillCatalogEntry $entry): string => $entry->name, $catalog),
        );
    }

    /** @dataProvider invalidSkills */
    public function test_silently_omits_invalid_skills(string $directory, string $contents): void
    {
        $this->writeSkill($directory, $contents);

        $this->assertSame([], (new FileSystemSkillRepository($this->skillsRoot))->catalog());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidSkills(): array
    {
        return [
            'missing opening delimiter' => ['missing-open', "name: missing-open\ndescription: Missing delimiter\n---\nBody"],
            'missing closing delimiter' => ['missing-close', "---\nname: missing-close\ndescription: Missing delimiter\nBody"],
            'missing name' => ['missing-name', "---\ndescription: Missing name\n---\nBody"],
            'missing description' => ['missing-description', "---\nname: missing-description\n---\nBody"],
            'empty name' => ['empty-name', "---\nname: \ndescription: Empty name\n---\nBody"],
            'empty description' => ['empty-description', "---\nname: empty-description\ndescription: \n---\nBody"],
            'indented name' => ['indented-name', "---\n name: indented-name\ndescription: Indented\n---\nBody"],
            'indented description' => ['indented-description', "---\nname: indented-description\n description: Indented\n---\nBody"],
            'uppercase name' => ['Bad-name', "---\nname: Bad-name\ndescription: Invalid\n---\nBody"],
            'leading hyphen' => ['-bad', "---\nname: -bad\ndescription: Invalid\n---\nBody"],
            'trailing hyphen' => ['bad-', "---\nname: bad-\ndescription: Invalid\n---\nBody"],
            'consecutive hyphens' => ['bad--name', "---\nname: bad--name\ndescription: Invalid\n---\nBody"],
            'overlong name' => [str_repeat('a', 65), "---\nname: ".str_repeat('a', 65)."\ndescription: Invalid\n---\nBody"],
            'overlong description' => ['long-description', "---\nname: long-description\ndescription: ".str_repeat('a', 1025)."\n---\nBody"],
            'directory mismatch' => ['expected-name', "---\nname: another-name\ndescription: Mismatch\n---\nBody"],
        ];
    }

    public function test_uses_a_linked_skill_package_as_its_canonical_boundary(): void
    {
        mkdir($this->outsideRoot.'/shared-skill', 0o777, true);
        file_put_contents(
            $this->outsideRoot.'/shared-skill/SKILL.md',
            "---\nname: shared-skill\ndescription: Shared package\n---\nOriginal body.",
        );
        symlink($this->outsideRoot.'/shared-skill', $this->skillsRoot.'/shared-skill');

        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertEquals([new SkillCatalogEntry('shared-skill', 'Shared package')], $repository->catalog());
        $this->assertSame('Original body.', $repository->read('shared-skill'));
    }

    public function test_omits_a_skill_whose_manifest_symlink_escapes_its_directory(): void
    {
        mkdir($this->skillsRoot.'/escaped-manifest', 0o777, true);
        file_put_contents(
            $this->outsideRoot.'/SKILL.md',
            "---\nname: escaped-manifest\ndescription: Escaped manifest\n---\nBody.",
        );
        symlink($this->outsideRoot.'/SKILL.md', $this->skillsRoot.'/escaped-manifest/SKILL.md');

        $this->assertSame([], (new FileSystemSkillRepository($this->skillsRoot))->catalog());
    }

    public function test_catalog_is_snapshotted_while_instruction_bodies_are_read_lazily(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Original description\n---\nOriginal body.");
        $this->writeSkill('removable', "---\nname: removable\ndescription: Removed later\n---\nTemporary body.");
        $repository = new FileSystemSkillRepository($this->skillsRoot);
        $catalog = [
            new SkillCatalogEntry('removable', 'Removed later'),
            new SkillCatalogEntry('writing', 'Original description'),
        ];
        $this->assertEquals($catalog, $repository->catalog());

        file_put_contents(
            $this->skillsRoot.'/writing/SKILL.md',
            "---\nname: writing\ndescription: Changed description\n---\nChanged body.",
        );
        unlink($this->skillsRoot.'/removable/SKILL.md');
        $this->writeSkill('new-skill', "---\nname: new-skill\ndescription: Added later\n---\nNew body.");

        $this->assertEquals($catalog, $repository->catalog());
        $this->assertSame('Changed body.', $repository->read('writing'));
    }

    public function test_empty_and_nonexistent_roots_have_empty_catalogs(): void
    {
        $this->assertSame([], (new FileSystemSkillRepository($this->skillsRoot))->catalog());
        $this->assertSame([], (new FileSystemSkillRepository($this->skillsRoot.'/missing'))->catalog());
    }

    public function test_unavailable_skill_cannot_be_read(): void
    {
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame('Skill "missing" is not available.', $repository->read('missing'));
    }

    public function test_reads_nested_resources_and_reflects_lazy_edits_without_truncation(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Writing\n---\nInstructions.");
        mkdir($this->skillsRoot.'/writing/references/nested', 0o777, true);
        $path = $this->skillsRoot.'/writing/references/nested/guide.md';
        file_put_contents($path, 'Original resource.');
        $repository = new FileSystemSkillRepository($this->skillsRoot);
        $contents = str_repeat('Complete UTF-8 text: café. ', 10000);
        file_put_contents($path, $contents);

        $this->assertSame($contents, $repository->read('writing', 'references/nested/guide.md'));
    }

    /** @dataProvider invalidResourcePaths */
    public function test_rejects_invalid_resource_paths(string $path): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Writing\n---\nInstructions.");
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame('Resource path "'.$path.'" is invalid.', $repository->read('writing', $path));
    }

    /** @return array<string, array{string}> */
    public static function invalidResourcePaths(): array
    {
        return [
            'empty' => [''],
            'POSIX absolute' => ['/etc/passwd'],
            'Windows absolute with backslashes' => ['C:\\Windows\\win.ini'],
            'Windows absolute with slashes' => ['C:/Windows/win.ini'],
            'UNC' => ['\\\\server\\share\\file.txt'],
            'parent traversal' => ['references/../../secret.txt'],
            'Windows parent traversal' => ['references\\..\\secret.txt'],
        ];
    }

    public function test_reports_missing_resources_and_directories(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Writing\n---\nInstructions.");
        mkdir($this->skillsRoot.'/writing/references');
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame(
            'Resource "missing.md" was not found in skill "writing".',
            $repository->read('writing', 'missing.md'),
        );
        $this->assertSame(
            'Resource "references" in skill "writing" is not a file.',
            $repository->read('writing', 'references'),
        );
    }

    public function test_allows_confined_resource_symlinks_and_rejects_escaping_ones(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Writing\n---\nInstructions.");
        mkdir($this->skillsRoot.'/writing/references');
        file_put_contents($this->skillsRoot.'/writing/references/guide.md', 'Confined target.');
        file_put_contents($this->outsideRoot.'/secret.md', 'External target.');
        symlink('references/guide.md', $this->skillsRoot.'/writing/guide-link.md');
        symlink($this->outsideRoot.'/secret.md', $this->skillsRoot.'/writing/secret-link.md');
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame('Confined target.', $repository->read('writing', 'guide-link.md'));
        $this->assertSame(
            'Resource "secret-link.md" escapes skill "writing".',
            $repository->read('writing', 'secret-link.md'),
        );
    }

    public function test_reads_scripts_as_text_without_executing_them(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Writing\n---\nInstructions.");
        mkdir($this->skillsRoot.'/writing/scripts');
        $marker = $this->outsideRoot.'/executed';
        $script = "#!/bin/sh\ntouch {$marker}\n";
        file_put_contents($this->skillsRoot.'/writing/scripts/run.sh', $script);
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame($script, $repository->read('writing', 'scripts/run.sh'));
        $this->assertFileDoesNotExist($marker);
    }

    public function test_reports_an_unreadable_resource(): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Writing\n---\nInstructions.");
        $path = $this->skillsRoot.'/writing/locked.txt';
        file_put_contents($path, 'Locked content.');
        chmod($path, 0o000);
        if (is_readable($path)) {
            chmod($path, 0o644);
            $this->markTestSkipped('The current user can read files regardless of their permission bits.');
        }
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        try {
            $this->assertSame(
                'Resource "locked.txt" in skill "writing" could not be read.',
                $repository->read('writing', 'locked.txt'),
            );
        } finally {
            chmod($path, 0o644);
        }
    }

    /** @dataProvider binaryContents */
    public function test_rejects_binary_resources(string $contents): void
    {
        $this->writeSkill('writing', "---\nname: writing\ndescription: Writing\n---\nInstructions.");
        file_put_contents($this->skillsRoot.'/writing/content.bin', $contents);
        $repository = new FileSystemSkillRepository($this->skillsRoot);

        $this->assertSame(
            'Resource "content.bin" in skill "writing" contains unsupported binary content.',
            $repository->read('writing', 'content.bin'),
        );
    }

    /** @return array<string, array{string}> */
    public static function binaryContents(): array
    {
        return [
            'null byte' => ["text\0binary"],
            'invalid UTF-8' => ["invalid \xC3\x28"],
        ];
    }

    protected function writeSkill(string $directory, string $contents): void
    {
        mkdir($this->skillsRoot.'/'.$directory, 0o777, true);
        file_put_contents($this->skillsRoot.'/'.$directory.'/SKILL.md', $contents);
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

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
