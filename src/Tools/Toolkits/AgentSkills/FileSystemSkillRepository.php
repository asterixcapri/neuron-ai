<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AgentSkills;

use DirectoryIterator;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

use function array_key_exists;
use function array_keys;
use function fclose;
use function fgets;
use function file_get_contents;
use function fopen;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function in_array;
use function mb_strlen;
use function preg_match;
use function preg_split;
use function realpath;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function rtrim;
use function sort;
use function sprintf;
use function trim;

use const DIRECTORY_SEPARATOR;

class FileSystemSkillRepository implements SkillRepositoryInterface
{
    /** @var SkillMetadata[]|null */
    protected ?array $catalog = null;

    /** @var string[] */
    protected array $diagnostics = [];

    /** @var array<string, string> */
    protected array $skillDirectories = [];

    public function __construct(protected string $skillsRoot)
    {
    }

    public function catalog(): array
    {
        $this->bootstrap();
        return $this->catalog ?? [];
    }

    protected function bootstrap(): void
    {
        if ($this->catalog !== null) {
            return;
        }

        $this->catalog = [];

        if (!is_dir($this->skillsRoot)) {
            $this->diagnostics[] = sprintf('Skills root "%s" is not a directory.', $this->skillsRoot);
            return;
        }

        $skillsRoot = realpath($this->skillsRoot);
        if ($skillsRoot === false) {
            $this->diagnostics[] = sprintf('Skills root "%s" could not be resolved.', $this->skillsRoot);
            return;
        }

        $directories = [];
        foreach (new DirectoryIterator($this->skillsRoot) as $entry) {
            if (!$entry->isDot() && $entry->isDir() && is_file($entry->getPathname().'/SKILL.md')) {
                $directory = realpath($entry->getPathname());
                if ($directory === false || !$this->isWithin($directory, $skillsRoot)) {
                    $this->diagnostics[] = sprintf('%s: Skill directory is outside the configured skills root.', $entry->getFilename());
                    continue;
                }

                $directories[$entry->getFilename()] = $directory;
            }
        }
        $names = array_keys($directories);
        sort($names);

        foreach ($names as $directoryName) {
            try {
                $metadata = $this->parseMetadata($directoryName, $this->manifestPath($directories[$directoryName]));
                $this->catalog[] = $metadata;
                $this->skillDirectories[$metadata->name] = $directories[$directoryName];
            } catch (RuntimeException $exception) {
                $this->diagnostics[] = sprintf('%s: %s', $directoryName, $exception->getMessage());
            }
        }

    }

    public function load(string $name): string
    {
        $contents = file_get_contents($this->manifestPath($this->skillDirectory($name)));
        if ($contents === false || preg_match('/\A---\R.*?\R---(?:\R|$)(.*)\z/s', $contents, $matches) !== 1) {
            throw new RuntimeException(sprintf('Skill "%s" has invalid frontmatter.', $name));
        }

        return trim($matches[1]);
    }

    public function loadResource(string $name, string $path): string
    {
        $skillDirectory = $this->skillDirectory($name);
        if ($path === '') {
            throw new RuntimeException('Resource path cannot be empty.');
        }
        if (preg_match('/^(?:[\\\\\/]|[A-Za-z]:[\\\\\/])/', $path) === 1) {
            throw new RuntimeException(sprintf('Resource path "%s" must be relative.', $path));
        }

        $segments = preg_split('/[\\\\\/]/', $path);
        if ($segments !== false && in_array('..', $segments, true)) {
            throw new RuntimeException(sprintf('Resource path "%s" cannot contain ".." segments.', $path));
        }

        $resource = realpath($skillDirectory.'/'.str_replace('\\', '/', $path));
        if ($resource === false || !is_file($resource)) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $path, $name));
        }
        if (!$this->isWithin($resource, $skillDirectory)) {
            throw new RuntimeException(sprintf('Resource "%s" is outside skill "%s".', $path, $name));
        }

        $contents = file_get_contents($resource);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" could not be read.', $path, $name));
        }
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" contains unsupported binary content.', $path, $name));
        }

        return $contents;
    }

    public function diagnostics(): array
    {
        $this->bootstrap();
        return $this->diagnostics;
    }

    protected function skillDirectory(string $name): string
    {
        $this->bootstrap();
        if (!array_key_exists($name, $this->skillDirectories)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $name));
        }

        return $this->skillDirectories[$name];
    }

    protected function manifestPath(string $skillDirectory): string
    {
        $manifest = realpath($skillDirectory.'/SKILL.md');
        if ($manifest === false || !is_file($manifest)) {
            throw new RuntimeException('SKILL.md could not be resolved.');
        }
        if (!$this->isWithin($manifest, $skillDirectory)) {
            throw new RuntimeException('SKILL.md is outside its skill directory.');
        }

        return $manifest;
    }

    protected function isWithin(string $path, string $directory): bool
    {
        return str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    protected function parseMetadata(string $directoryName, string $path): SkillMetadata
    {
        $frontmatter = Yaml::parse($this->readFrontmatter($path));
        if (!is_array($frontmatter)) {
            throw new RuntimeException('Frontmatter must be a YAML mapping.');
        }

        $name = $frontmatter['name'] ?? null;
        $description = $frontmatter['description'] ?? null;
        if (!is_string($name) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $name) !== 1 || mb_strlen($name) > 64) {
            throw new RuntimeException('Required metadata "name" must be 1-64 characters using lowercase letters, numbers, and single hyphens only.');
        }
        if ($name !== $directoryName) {
            throw new RuntimeException(sprintf('Skill name "%s" must match its directory "%s".', $name, $directoryName));
        }
        if (!is_string($description) || trim($description) === '' || mb_strlen($description) > 1024) {
            throw new RuntimeException('Required metadata "description" must be a string between 1 and 1024 characters.');
        }

        $compatibility = $this->optionalString($frontmatter, 'compatibility');
        if ($compatibility !== null && (trim($compatibility) === '' || mb_strlen($compatibility) > 500)) {
            throw new RuntimeException('Optional metadata "compatibility" must be between 1 and 500 characters.');
        }

        return new SkillMetadata(
            name: $name,
            description: $description,
            license: $this->optionalString($frontmatter, 'license'),
            compatibility: $compatibility,
            metadata: $this->optionalStringMapping($frontmatter, 'metadata'),
            allowedTools: $this->optionalString($frontmatter, 'allowed-tools'),
        );
    }

    protected function readFrontmatter(string $path): string
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            throw new RuntimeException('SKILL.md could not be read.');
        }

        try {
            $firstLine = fgets($stream);
            if ($firstLine === false || rtrim($firstLine, "\r\n") !== '---') {
                throw new RuntimeException('SKILL.md must begin with YAML frontmatter.');
            }

            $frontmatter = '';
            while (($line = fgets($stream)) !== false) {
                if (rtrim($line, "\r\n") === '---') {
                    return $frontmatter;
                }
                $frontmatter .= $line;
            }
        } finally {
            fclose($stream);
        }

        throw new RuntimeException('SKILL.md frontmatter is not closed.');
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    protected function optionalString(array $frontmatter, string $key): ?string
    {
        if (!array_key_exists($key, $frontmatter)) {
            return null;
        }
        if (!is_string($frontmatter[$key])) {
            throw new RuntimeException(sprintf('Optional metadata "%s" must be a string.', $key));
        }

        return $frontmatter[$key];
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @return array<string, string>
     */
    protected function optionalStringMapping(array $frontmatter, string $key): array
    {
        if (!array_key_exists($key, $frontmatter)) {
            return [];
        }
        if (!is_array($frontmatter[$key])) {
            throw new RuntimeException(sprintf('Optional metadata "%s" must be a mapping.', $key));
        }

        $mapping = [];
        foreach ($frontmatter[$key] as $metadataKey => $metadataValue) {
            if (!is_string($metadataKey) || !is_string($metadataValue)) {
                throw new RuntimeException(sprintf('Optional metadata "%s" must map strings to strings.', $key));
            }

            $mapping[$metadataKey] = $metadataValue;
        }

        return $mapping;
    }
}
