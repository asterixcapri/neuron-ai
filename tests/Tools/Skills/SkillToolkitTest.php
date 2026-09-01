<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Skills;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Toolkits\Skills\FileSystemSkillStorage;
use NeuronAI\Tools\Toolkits\Skills\SkillRepository;
use NeuronAI\Tools\Toolkits\Skills\SkillStorageInterface;
use NeuronAI\Tools\Toolkits\Skills\SkillToolkit;
use NeuronAI\Tools\Toolkits\Skills\SkillTool;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_unique;
use function bin2hex;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

class SkillToolkitTest extends TestCase
{
    protected string $skillsRoot;

    protected function setUp(): void
    {
        $this->skillsRoot = sys_get_temp_dir().'/neuron-toolkit-skills-'.bin2hex(random_bytes(8));
        mkdir($this->skillsRoot.'/writing', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', <<<'SKILL'
            ---
            name: writing
            description: Write clear prose
            ---
            # Writing instructions

            Prefer direct sentences.
            SKILL);
        mkdir($this->skillsRoot.'/writing/references');
        file_put_contents($this->skillsRoot.'/writing/references/style.md', "# Style guide\n\nUse concrete words.\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->skillsRoot.'/writing/references/style.md')) {
            unlink($this->skillsRoot.'/writing/references/style.md');
        }
        if (is_dir($this->skillsRoot.'/writing/references')) {
            rmdir($this->skillsRoot.'/writing/references');
        }
        if (file_exists($this->skillsRoot.'/writing/SKILL.md')) {
            unlink($this->skillsRoot.'/writing/SKILL.md');
        }
        if (is_dir($this->skillsRoot.'/writing')) {
            rmdir($this->skillsRoot.'/writing');
        }
        rmdir($this->skillsRoot);
    }

    public function test_agent_discloses_catalog_and_loads_instructions_through_the_tool_loop(): void
    {
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));
        $tools = $toolkit->tools();
        $this->assertCount(1, $tools);
        $skillTool = $tools[0];
        $this->assertInstanceOf(SkillTool::class, $skillTool);
        $this->assertSame(['name'], $skillTool->getRequiredProperties());
        $nameProperty = $skillTool->getProperties()[0];
        $this->assertInstanceOf(ToolProperty::class, $nameProperty);
        $this->assertSame(['writing'], $nameProperty->getEnum());
        $this->assertCount(2, $skillTool->getProperties());

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillTool)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            new AssistantMessage('I will follow the writing skill.'),
        );
        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);

        $response = $agent->chat(new UserMessage('Help me write.'))->getMessage();

        $this->assertSame('I will follow the writing skill.', $response->getContent());
        $provider->assertToolsConfigured(['skill']);
        $systemPrompt = $provider->getRecorded()[0]->systemPrompt ?? '';
        $this->assertStringContainsString('writing: Write clear prose', $systemPrompt);
        $this->assertStringContainsString('skill', $systemPrompt);
        $this->assertStringContainsString('Skill instructions may reference other files in their package.', $systemPrompt);
        $this->assertStringContainsString(
            'Always load every referenced file by calling `skill` with its `path` input.',
            $systemPrompt,
        );
        $this->assertStringContainsString('The `skill` tool only reads text and never executes scripts.', $systemPrompt);
        $this->assertStringContainsString(
            'If a loaded file is a script and an appropriate execution tool is available, '
                .'use that separate tool to execute the loaded contents.',
            $systemPrompt,
        );
        $this->assertStringNotContainsString('Prefer direct sentences.', $systemPrompt);
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult(
            $record,
            "# Writing instructions\n\nPrefer direct sentences.",
        ));
    }

    public function test_agent_reads_a_resource_through_the_same_tool_loop(): void
    {
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));
        $skillTool = $toolkit->tools()[0];
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillTool)->setCallId('call_1')->setInputs([
                    'name' => 'writing',
                    'path' => 'references/style.md',
                ]),
            ]),
            new AssistantMessage('I read the style guide.'),
        );
        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);

        $agent->chat(new UserMessage('Read the style guide.'))->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult(
            $record,
            "# Style guide\n\nUse concrete words.\n",
        ));
    }

    public function test_unknown_skill_is_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot))))->tools()[0];
        $tool->setInputs(['name' => 'unknown']);
        $tool->execute();

        $this->assertSame('Skill "unknown" is not available.', $tool->getResult());
    }

    public function test_invalid_resource_path_is_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot))))->tools()[0];
        $tool->setInputs(['name' => 'writing', 'path' => '../secret.md']);
        $tool->execute();

        $this->assertSame('Resource path "../secret.md" is invalid.', $tool->getResult());
    }

    public function test_tool_delegates_reads_to_a_storage_neutral_repository(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public ?string $requestedPackage = null;
            public ?string $requestedPath = null;

            public function packages(): array
            {
                return ['remote'];
            }

            public function read(string $package, string $path): string
            {
                $this->requestedPackage = $package;
                $this->requestedPath = $path;

                return $path === 'SKILL.md'
                    ? "---\nname: remote\ndescription: Remote skill\n---\nRemote instructions."
                    : 'Remote resource.';
            }
        };
        $repository = new SkillRepository($storage);
        $storage->requestedPackage = null;
        $storage->requestedPath = null;
        $tool = (new SkillToolkit($repository))->tools()[0];
        $tool->setInputs(['name' => 'remote', 'path' => 'references/api.md']);
        $tool->execute();

        $this->assertSame('Remote resource.', $tool->getResult());
        $this->assertSame('remote', $storage->requestedPackage);
        $this->assertSame('references/api.md', $storage->requestedPath);
    }

    public function test_unexpected_repository_failures_remain_exceptions(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public int $reads = 0;

            public function packages(): array
            {
                return ['broken'];
            }

            public function read(string $package, string $path): string
            {
                if ($this->reads++ === 0) {
                    return "---\nname: broken\ndescription: Broken skill\n---\nInstructions.";
                }

                throw new LogicException('Storage failed unexpectedly.');
            }
        };
        $repository = new SkillRepository($storage);
        $tool = (new SkillToolkit($repository))->tools()[0];
        $tool->setInputs(['name' => 'broken']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Storage failed unexpectedly.');

        $tool->execute();
    }

    public function test_toolkit_snapshots_custom_repository_catalog_and_tracks_names_and_paths_separately(): void
    {
        $storage = new class () implements SkillStorageInterface {
            /** @var array<string, string> */
            public array $manifests = [
                'first' => "---\nname: first\ndescription: First skill\n---\nFirst.",
                'second' => "---\nname: second\ndescription: Second skill\n---\nSecond.",
            ];

            public function packages(): array
            {
                return array_keys($this->manifests);
            }

            public function read(string $package, string $path): string
            {
                return $path === 'SKILL.md' ? $this->manifests[$package] : $package;
            }
        };
        $repository = new SkillRepository($storage);
        $toolkit = new SkillToolkit($repository);
        $storage->manifests = [
            'third' => "---\nname: third\ndescription: Third skill\n---\nThird.",
        ];

        $this->assertStringContainsString('first: First skill', $toolkit->guidelines() ?? '');
        $this->assertStringContainsString('second: Second skill', $toolkit->guidelines() ?? '');
        $this->assertStringNotContainsString('third', $toolkit->guidelines() ?? '');
        $tool = $toolkit->tools()[0];
        $this->assertInstanceOf(SkillTool::class, $tool);
        $keys = [];
        foreach (['first', 'second'] as $name) {
            foreach (['references/details.md', 'references/examples.md'] as $path) {
                $tool->setInputs(['name' => $name, 'path' => $path]);
                $keys[] = $tool->getRunKey();
            }
        }

        $this->assertCount(4, array_unique($keys));
    }

    public function test_empty_catalog_contributes_neither_guidelines_nor_tools(): void
    {
        unlink($this->skillsRoot.'/writing/references/style.md');
        rmdir($this->skillsRoot.'/writing/references');
        unlink($this->skillsRoot.'/writing/SKILL.md');
        rmdir($this->skillsRoot.'/writing');
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));

        $this->assertNull($toolkit->guidelines());
        $this->assertSame([], $toolkit->tools());

        $provider = new FakeAIProvider(new AssistantMessage('Hello.'));
        Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit)
            ->chat(new UserMessage('Hello.'))
            ->getMessage();

        $this->assertSame([], $provider->getRecorded()[0]->tools);
        $this->assertStringNotContainsString('SkillToolkit', $provider->getRecorded()[0]->systemPrompt ?? '');
    }

    protected function hasToolResult(RequestRecord $record, string $expected): bool
    {
        foreach ($record->messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getTools() as $tool) {
                if ($tool->getResult() === $expected) {
                    return true;
                }
            }
        }

        return false;
    }
}
