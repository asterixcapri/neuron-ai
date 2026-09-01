<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Skills;

use LogicException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Toolkits\Skills\SkillRepository;
use NeuronAI\Tools\Toolkits\Skills\Storage\SkillStorageInterface;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function str_repeat;
use function array_keys;

class SkillRepositoryTest extends TestCase
{
    public function test_builds_a_deterministic_catalog_and_removes_frontmatter_from_instructions(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Write clearly: for humans\nlicense: MIT\n---\n\nWrite directly.\n",
            ],
            'analysis' => [
                'SKILL.md' => "---\r\nname: analysis\r\ndescription: Analyse evidence\r\n---\r\nAnalyse carefully.\r\n",
            ],
        ]);
        $repository = new SkillRepository($storage);

        $this->assertSame([
            ['name' => 'analysis', 'description' => 'Analyse evidence'],
            ['name' => 'writing', 'description' => 'Write clearly: for humans'],
        ], $repository->catalog());
        $this->assertSame('Write directly.', $repository->readInstructions('writing'));
    }

    /** @dataProvider invalidSkills */
    public function test_silently_omits_invalid_skills(string $package, string $contents): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([$package => ['SKILL.md' => $contents]]));

        $this->assertSame([], $repository->catalog());
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
            'package mismatch' => ['expected-name', "---\nname: another-name\ndescription: Mismatch\n---\nBody"],
        ];
    }

    public function test_catalog_is_snapshotted_while_instruction_and_resource_reads_are_lazy(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Original description\n---\nOriginal body.",
                'guide.md' => 'Original guide.',
            ],
        ]);
        $repository = new SkillRepository($storage);
        $storage->files['writing']['SKILL.md'] = "---\nname: writing\ndescription: Changed description\n---\nChanged body.";
        $storage->files['writing']['guide.md'] = 'Changed guide.';
        $storage->files['added']['SKILL.md'] = "---\nname: added\ndescription: Added later\n---\nAdded.";

        $this->assertSame([
            ['name' => 'writing', 'description' => 'Original description'],
        ], $repository->catalog());
        $this->assertSame('Changed body.', $repository->readInstructions('writing'));
        $this->assertSame('Changed guide.', $repository->readResource('writing', 'guide.md'));
        $this->assertSame('Skill "added" is not available.', $repository->readInstructions('added'));
    }

    public function test_rejects_an_empty_resource_path_before_calling_storage(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions.",
                '' => 'Instructions exposed as a resource.',
            ],
        ]);
        $repository = new SkillRepository($storage);

        $this->assertSame('Resource path "" is invalid.', $repository->readResource('writing', ''));
    }

    public function test_returns_expected_storage_failure_messages(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $repository = new SkillRepository($storage);

        $storage->failures['writing']['missing.md'] = 'Resource "missing.md" was not found in skill "writing".';
        $this->assertSame(
            'Resource "missing.md" was not found in skill "writing".',
            $repository->readResource('writing', 'missing.md'),
        );
        $storage->failures['writing']['../secret.md'] = 'Resource path "../secret.md" is invalid.';
        $this->assertSame(
            'Resource path "../secret.md" is invalid.',
            $repository->readResource('writing', '../secret.md'),
        );
        $storage->failures['writing']['binary.bin'] =
            'Resource "binary.bin" in skill "writing" contains unsupported binary content.';
        $this->assertSame(
            'Resource "binary.bin" in skill "writing" contains unsupported binary content.',
            $repository->readResource('writing', 'binary.bin'),
        );
        $storage->failures['writing']['escaped.md'] = 'Resource "escaped.md" escapes skill "writing".';
        $this->assertSame(
            'Resource "escaped.md" escapes skill "writing".',
            $repository->readResource('writing', 'escaped.md'),
        );
        $storage->failures['writing']['references'] = 'Resource "references" in skill "writing" is not a file.';
        $this->assertSame(
            'Resource "references" in skill "writing" is not a file.',
            $repository->readResource('writing', 'references'),
        );
        $storage->failures['writing']['locked.md'] = 'Resource "locked.md" in skill "writing" could not be read.';
        $this->assertSame(
            'Resource "locked.md" in skill "writing" could not be read.',
            $repository->readResource('writing', 'locked.md'),
        );
        $storage->failures['writing']['SKILL.md'] = 'Skill "writing" could not be read.';
        $this->assertSame('Skill "writing" could not be read.', $repository->readInstructions('writing'));
    }

    public function test_expected_manifest_storage_failures_exclude_a_package_from_the_catalog(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $storage->failures['writing']['SKILL.md'] = 'Skill "writing" has an unavailable SKILL.md.';

        $this->assertSame([], (new SkillRepository($storage))->catalog());
    }

    public function test_unexpected_storage_failures_remain_exceptions(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public function packages(): array
            {
                return ['broken'];
            }

            public function read(string $package, string $path): string
            {
                throw new LogicException('Storage failed unexpectedly.');
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Storage failed unexpectedly.');

        new SkillRepository($storage);
    }
}

class InMemorySkillStorage implements SkillStorageInterface
{
    /** @param array<string, array<string, string>> $files */
    public function __construct(public array $files)
    {
    }

    /** @var array<string, array<string, string>> */
    public array $failures = [];

    public function packages(): array
    {
        return array_keys($this->files);
    }

    public function read(string $package, string $path): string
    {
        if (isset($this->failures[$package][$path])) {
            throw new ToolException($this->failures[$package][$path]);
        }
        if (!array_key_exists($package, $this->files)) {
            throw new ToolException("Skill \"{$package}\" is not available.");
        }
        if (!array_key_exists($path, $this->files[$package])) {
            throw new ToolException("Resource \"{$path}\" was not found in skill \"{$package}\".");
        }

        return $this->files[$package][$path];
    }
}
