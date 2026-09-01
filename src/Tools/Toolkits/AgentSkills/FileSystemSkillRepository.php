<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

use DirectoryIterator;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function fclose;
use function fgets;
use function file_get_contents;
use function fopen;
use function is_dir;
use function is_file;
use function preg_match;
use function realpath;
use function rtrim;
use function sort;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;
use const SORT_STRING;

class FileSystemSkillRepository implements SkillRepositoryInterface
{
    /** @var SkillCatalogEntry[] */
    protected array $catalog = [];

    /** @var array<string, string> */
    protected array $skillDirectories = [];

    public function __construct(protected string $skillsRoot)
    {
        $this->discover();
    }

    public function catalog(): array
    {
        return $this->catalog;
    }

    public function read(string $name): string
    {
        if (!array_key_exists($name, $this->skillDirectories)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $name));
        }

        $manifest = $this->manifestPath($this->skillDirectories[$name]);
        if ($manifest === null) {
            throw new RuntimeException(sprintf('Skill "%s" has an unavailable SKILL.md.', $name));
        }

        $contents = file_get_contents($manifest);
        if ($contents === false
            || preg_match('/\A---\r?\n.*?\r?\n---(?:\r?\n|\z)(.*)\z/s', $contents, $matches) !== 1) {
            throw new RuntimeException(sprintf('Skill "%s" has invalid frontmatter.', $name));
        }

        return trim($matches[1]);
    }

    protected function discover(): void
    {
        if (!is_dir($this->skillsRoot)) {
            return;
        }

        $directories = [];
        foreach (new DirectoryIterator($this->skillsRoot) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $directory = realpath($entry->getPathname());
            if ($directory !== false) {
                $directories[$entry->getFilename()] = $directory;
            }
        }

        $names = array_keys($directories);
        sort($names, SORT_STRING);

        foreach ($names as $directoryName) {
            $manifest = $this->manifestPath($directories[$directoryName]);
            if ($manifest === null) {
                continue;
            }

            $entry = $this->catalogEntry($directoryName, $manifest);
            if ($entry === null) {
                continue;
            }

            $this->catalog[] = $entry;
            $this->skillDirectories[$entry->name] = $directories[$directoryName];
        }
    }

    protected function manifestPath(string $skillDirectory): ?string
    {
        $manifest = realpath($skillDirectory.'/SKILL.md');
        if ($manifest === false || !is_file($manifest) || !$this->isWithin($manifest, $skillDirectory)) {
            return null;
        }

        return $manifest;
    }

    protected function isWithin(string $path, string $directory): bool
    {
        return str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    protected function catalogEntry(string $directoryName, string $manifest): ?SkillCatalogEntry
    {
        $frontmatter = $this->frontmatter($manifest);
        if ($frontmatter === null) {
            return null;
        }

        $name = $frontmatter['name'] ?? '';
        $description = $frontmatter['description'] ?? '';
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $name) !== 1
            || strlen($name) > 64
            || $name !== $directoryName
            || preg_match('/\A.{1,1024}\z/usD', $description) !== 1) {
            return null;
        }

        return new SkillCatalogEntry($name, $description);
    }

    /** @return array{name?: string, description?: string}|null */
    protected function frontmatter(string $manifest): ?array
    {
        $stream = fopen($manifest, 'r');
        if ($stream === false) {
            return null;
        }

        try {
            $firstLine = fgets($stream);
            if ($firstLine === false || rtrim($firstLine, "\r\n") !== '---') {
                return null;
            }

            $frontmatter = [];
            while (($line = fgets($stream)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '---') {
                    return $frontmatter;
                }

                if (str_starts_with($line, 'name:')) {
                    $frontmatter['name'] = trim(substr($line, strlen('name:')));
                } elseif (str_starts_with($line, 'description:')) {
                    $frontmatter['description'] = trim(substr($line, strlen('description:')));
                }
            }
        } finally {
            fclose($stream);
        }

        return null;
    }
}
