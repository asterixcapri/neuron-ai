<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Skills;

use function array_key_exists;
use function preg_match;
use function preg_split;
use function sort;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const SORT_STRING;

class SkillRepository
{
    protected const MANIFEST = 'SKILL.md';

    /** @var SkillCatalogEntry[] */
    protected array $catalog = [];

    /** @var array<string, true> */
    protected array $availableSkills = [];

    public function __construct(protected SkillStorageInterface $storage)
    {
        $this->buildCatalog();
    }

    /** @return SkillCatalogEntry[] */
    public function catalog(): array
    {
        return $this->catalog;
    }

    public function readInstructions(string $name): string
    {
        if (!array_key_exists($name, $this->availableSkills)) {
            return sprintf('Skill "%s" is not available.', $name);
        }

        try {
            $contents = $this->storage->read($name, self::MANIFEST);
        } catch (SkillStorageException $exception) {
            return $this->instructionStorageError($name, $exception->error);
        }

        $document = $this->parseManifest($contents);
        if ($document === null) {
            return sprintf('Skill "%s" has invalid frontmatter.', $name);
        }

        return trim($document['body']);
    }

    public function readResource(string $name, string $path): string
    {
        if (!array_key_exists($name, $this->availableSkills)) {
            return sprintf('Skill "%s" is not available.', $name);
        }

        try {
            return $this->storage->read($name, $path);
        } catch (SkillStorageException $exception) {
            return $this->resourceStorageError($name, $path, $exception->error);
        }
    }

    protected function buildCatalog(): void
    {
        $packages = $this->storage->packages();
        sort($packages, SORT_STRING);

        foreach ($packages as $package) {
            try {
                $contents = $this->storage->read($package, self::MANIFEST);
            } catch (SkillStorageException) {
                continue;
            }

            $document = $this->parseManifest($contents);
            if ($document === null) {
                continue;
            }

            $name = $document['name'];
            $description = $document['description'];
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $name) !== 1
                || strlen($name) > 64
                || $name !== $package
                || preg_match('/\A.{1,1024}\z/usD', $description) !== 1) {
                continue;
            }

            $this->catalog[] = new SkillCatalogEntry($name, $description);
            $this->availableSkills[$name] = true;
        }
    }

    /** @return array{name: string, description: string, body: string}|null */
    protected function parseManifest(string $contents): ?array
    {
        if (preg_match('/\A---\r?\n(.*?)\r?\n---(?:\r?\n|\z)(.*)\z/s', $contents, $matches) !== 1) {
            return null;
        }

        $metadata = [];
        foreach (preg_split('/\r?\n/', $matches[1]) ?: [] as $line) {
            if (str_starts_with($line, 'name:')) {
                $metadata['name'] = trim(substr($line, strlen('name:')));
            } elseif (str_starts_with($line, 'description:')) {
                $metadata['description'] = trim(substr($line, strlen('description:')));
            }
        }
        if (!isset($metadata['name'], $metadata['description'])) {
            return null;
        }

        return [
            'name' => $metadata['name'],
            'description' => $metadata['description'],
            'body' => $matches[2],
        ];
    }

    protected function instructionStorageError(string $name, SkillStorageError $error): string
    {
        return match ($error) {
            SkillStorageError::UNREADABLE => sprintf('Skill "%s" could not be read.', $name),
            SkillStorageError::BINARY_CONTENT => sprintf(
                'Skill "%s" contains unsupported binary content.',
                $name,
            ),
            default => sprintf('Skill "%s" has an unavailable SKILL.md.', $name),
        };
    }

    protected function resourceStorageError(string $name, string $path, SkillStorageError $error): string
    {
        return match ($error) {
            SkillStorageError::PACKAGE_NOT_FOUND => sprintf('Skill "%s" is not available.', $name),
            SkillStorageError::INVALID_PATH => sprintf('Resource path "%s" is invalid.', $path),
            SkillStorageError::FILE_NOT_FOUND => sprintf(
                'Resource "%s" was not found in skill "%s".',
                $path,
                $name,
            ),
            SkillStorageError::ESCAPES_PACKAGE => sprintf(
                'Resource "%s" escapes skill "%s".',
                $path,
                $name,
            ),
            SkillStorageError::NOT_A_FILE => sprintf(
                'Resource "%s" in skill "%s" is not a file.',
                $path,
                $name,
            ),
            SkillStorageError::UNREADABLE => sprintf(
                'Resource "%s" in skill "%s" could not be read.',
                $path,
                $name,
            ),
            SkillStorageError::BINARY_CONTENT => sprintf(
                'Resource "%s" in skill "%s" contains unsupported binary content.',
                $path,
                $name,
            ),
        };
    }
}
