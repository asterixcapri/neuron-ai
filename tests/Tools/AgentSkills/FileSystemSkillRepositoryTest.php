<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\AgentSkills;

use FilesystemIterator;
use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillCatalogEntry;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function array_map;
use function bin2hex;
use function file_put_contents;
use function is_dir;
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "missing" is not available.');

        $repository->read('missing');
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
