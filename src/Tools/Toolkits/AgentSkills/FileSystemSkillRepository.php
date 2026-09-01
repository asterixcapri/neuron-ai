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
use function preg_match;
use function rtrim;
use function sort;
use function sprintf;
use function trim;

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
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $this->catalog = [];

        if (!is_dir($this->skillsRoot)) {
            $this->diagnostics[] = sprintf('Skills root "%s" is not a directory.', $this->skillsRoot);
            return $this->catalog;
        }

        $directories = [];
        foreach (new DirectoryIterator($this->skillsRoot) as $entry) {
            if (!$entry->isDot() && $entry->isDir() && is_file($entry->getPathname().'/SKILL.md')) {
                $directories[$entry->getFilename()] = $entry->getPathname();
            }
        }
        $names = array_keys($directories);
        sort($names);

        foreach ($names as $directoryName) {
            try {
                $metadata = $this->parseMetadata($directoryName, $directories[$directoryName].'/SKILL.md');
                $this->catalog[] = $metadata;
                $this->skillDirectories[$metadata->name] = $directories[$directoryName];
            } catch (RuntimeException $exception) {
                $this->diagnostics[] = sprintf('%s: %s', $directoryName, $exception->getMessage());
            }
        }

        return $this->catalog;
    }

    public function load(string $name): string
    {
        $this->catalog();
        if (!array_key_exists($name, $this->skillDirectories)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $name));
        }

        $contents = file_get_contents($this->skillDirectories[$name].'/SKILL.md');
        if ($contents === false || preg_match('/\A---\R.*?\R---(?:\R|$)(.*)\z/s', $contents, $matches) !== 1) {
            throw new RuntimeException(sprintf('Skill "%s" has invalid frontmatter.', $name));
        }

        return trim($matches[1]);
    }

    public function diagnostics(): array
    {
        $this->catalog();
        return $this->diagnostics;
    }

    protected function parseMetadata(string $directoryName, string $path): SkillMetadata
    {
        $frontmatter = Yaml::parse($this->readFrontmatter($path));
        if (!is_array($frontmatter)) {
            throw new RuntimeException('Frontmatter must be a YAML mapping.');
        }

        $name = $frontmatter['name'] ?? null;
        $description = $frontmatter['description'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new RuntimeException('Required metadata "name" must be a non-empty string.');
        }
        if ($name !== $directoryName) {
            throw new RuntimeException(sprintf('Skill name "%s" must match its directory "%s".', $name, $directoryName));
        }
        if (!is_string($description) || trim($description) === '') {
            throw new RuntimeException('Required metadata "description" must be a non-empty string.');
        }

        return new SkillMetadata(
            name: $name,
            description: $description,
            license: $this->optionalString($frontmatter, 'license'),
            compatibility: $this->optionalString($frontmatter, 'compatibility'),
            metadata: $this->optionalMapping($frontmatter, 'metadata'),
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
     * @return array<string, mixed>
     */
    protected function optionalMapping(array $frontmatter, string $key): array
    {
        if (!array_key_exists($key, $frontmatter)) {
            return [];
        }
        if (!is_array($frontmatter[$key])) {
            throw new RuntimeException(sprintf('Optional metadata "%s" must be a mapping.', $key));
        }

        return $frontmatter[$key];
    }
}
