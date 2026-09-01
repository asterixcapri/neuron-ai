<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\AgentSkills;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillCatalogEntry;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillRepositoryInterface;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillToolkit;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillTool;
use PHPUnit\Framework\TestCase;

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
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
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
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
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
        $tool = (new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot)))->tools()[0];
        $tool->setInputs(['name' => 'unknown']);
        $tool->execute();

        $this->assertSame('Skill "unknown" is not available.', $tool->getResult());
    }

    public function test_invalid_resource_path_is_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot)))->tools()[0];
        $tool->setInputs(['name' => 'writing', 'path' => '../secret.md']);
        $tool->execute();

        $this->assertSame('Resource path "../secret.md" is invalid.', $tool->getResult());
    }

    public function test_tool_delegates_reads_to_a_storage_neutral_repository(): void
    {
        $repository = new class () implements SkillRepositoryInterface {
            public ?string $requestedName = null;
            public ?string $requestedPath = null;

            public function catalog(): array
            {
                return [new SkillCatalogEntry('remote', 'Remote skill')];
            }

            public function read(string $name, ?string $path = null): string
            {
                $this->requestedName = $name;
                $this->requestedPath = $path;

                return $path === null ? 'Remote instructions.' : 'Remote resource.';
            }
        };
        $tool = (new SkillToolkit($repository))->tools()[0];
        $tool->setInputs(['name' => 'remote', 'path' => 'references/api.md']);
        $tool->execute();

        $this->assertSame('Remote resource.', $tool->getResult());
        $this->assertSame('remote', $repository->requestedName);
        $this->assertSame('references/api.md', $repository->requestedPath);
    }

    public function test_unexpected_repository_failures_remain_exceptions(): void
    {
        $repository = new class () implements SkillRepositoryInterface {
            public function catalog(): array
            {
                return [new SkillCatalogEntry('broken', 'Broken skill')];
            }

            public function read(string $name, ?string $path = null): string
            {
                throw new LogicException('Storage failed unexpectedly.');
            }
        };
        $tool = (new SkillToolkit($repository))->tools()[0];
        $tool->setInputs(['name' => 'broken']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Storage failed unexpectedly.');

        $tool->execute();
    }

    public function test_toolkit_snapshots_custom_repository_catalog_and_tracks_names_and_paths_separately(): void
    {
        $repository = new class () implements SkillRepositoryInterface {
            /** @var SkillCatalogEntry[] */
            public array $entries;

            public function __construct()
            {
                $this->entries = [
                    new SkillCatalogEntry('first', 'First skill'),
                    new SkillCatalogEntry('second', 'Second skill'),
                ];
            }

            public function catalog(): array
            {
                return $this->entries;
            }

            public function read(string $name, ?string $path = null): string
            {
                return $name;
            }
        };
        $toolkit = new SkillToolkit($repository);
        $repository->entries = [new SkillCatalogEntry('third', 'Third skill')];

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
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));

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
