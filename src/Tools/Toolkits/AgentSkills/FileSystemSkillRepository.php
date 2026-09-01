<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

use DirectoryIterator;

use function array_key_exists;
use function array_keys;
use function fclose;
use function fgets;
use function file_get_contents;
use function fopen;
use function is_dir;
use function is_file;
use function is_readable;
use function preg_match;
use function realpath;
use function rtrim;
use function sort;
use function sprintf;
use function str_contains;
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

    public function read(string $name, ?string $path = null): string
    {
        if (!array_key_exists($name, $this->skillDirectories)) {
            return sprintf('Skill "%s" is not available.', $name);
        }

        if ($path !== null) {
            return $this->readResource($name, $path);
        }

        $manifest = $this->manifestPath($this->skillDirectories[$name]);
        if ($manifest === null) {
            return sprintf('Skill "%s" has an unavailable SKILL.md.', $name);
        }

        $contents = $this->readText($manifest);
        if ($contents === null) {
            return sprintf('Skill "%s" contains unsupported binary content.', $name);
        }
        if ($contents === false) {
            return sprintf('Skill "%s" could not be read.', $name);
        }

        if (preg_match('/\A---\r?\n.*?\r?\n---(?:\r?\n|\z)(.*)\z/s', $contents, $matches) !== 1) {
            return sprintf('Skill "%s" has invalid frontmatter.', $name);
        }

        return trim($matches[1]);
    }

    protected function readResource(string $name, string $path): string
    {
        if (!$this->validResourcePath($path)) {
            return sprintf('Resource path "%s" is invalid.', $path);
        }

        $skillDirectory = $this->skillDirectories[$name];
        $resource = realpath($skillDirectory.'/'.$path);
        if ($resource === false) {
            return sprintf('Resource "%s" was not found in skill "%s".', $path, $name);
        }
        if ($resource !== $skillDirectory && !$this->isWithin($resource, $skillDirectory)) {
            return sprintf('Resource "%s" escapes skill "%s".', $path, $name);
        }
        if (!is_file($resource)) {
            return sprintf('Resource "%s" in skill "%s" is not a file.', $path, $name);
        }

        $contents = $this->readText($resource);
        if ($contents === null) {
            return sprintf('Resource "%s" in skill "%s" contains unsupported binary content.', $path, $name);
        }
        if ($contents === false) {
            return sprintf('Resource "%s" in skill "%s" could not be read.', $path, $name);
        }

        return $contents;
    }

    protected function validResourcePath(string $path): bool
    {
        return $path !== ''
            && preg_match('/\A(?:[\\\\\/]|[A-Za-z]:[\\\\\/])/', $path) !== 1
            && preg_match('/(?:\A|[\\\\\/])\.\.(?:[\\\\\/]|\z)/D', $path) !== 1;
    }

    /** @return string|false|null Null indicates unsupported binary content. */
    protected function readText(string $path): string|false|null
    {
        if (!is_readable($path)) {
            return false;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            return null;
        }

        return $contents;
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
