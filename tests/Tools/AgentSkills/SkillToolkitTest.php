<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\AgentSkills;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillLoadResourceTool;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillLoadTool;
use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillMetadata;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillRepositoryInterface;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillToolkit;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_exists;
use function file_put_contents;
use function in_array;
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
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/references/style.md', 'Use the active voice.');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->skillsRoot.'/writing/image.bin')) {
            unlink($this->skillsRoot.'/writing/image.bin');
        }
        unlink($this->skillsRoot.'/writing/references/style.md');
        rmdir($this->skillsRoot.'/writing/references');
        unlink($this->skillsRoot.'/writing/SKILL.md');
        rmdir($this->skillsRoot.'/writing');
        rmdir($this->skillsRoot);
    }

    public function test_agent_discloses_catalog_and_loads_skill_through_tool_loop(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
        [$skillLoad, $resourceLoad] = $toolkit->tools();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillLoad)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            new ToolCallMessage(null, [
                (clone $resourceLoad)->setCallId('call_2')->setInputs([
                    'name' => 'writing',
                    'path' => 'references/style.md',
                ]),
            ]),
            new AssistantMessage('I will follow the writing skill.'),
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);

        $response = $agent->chat(new UserMessage('Help me write.'))->getMessage();

        $this->assertSame('I will follow the writing skill.', $response->getContent());
        $provider->assertToolsConfigured(['skill_load', 'skill_load_resource']);
        $systemPrompt = $provider->getRecorded()[0]->systemPrompt ?? '';
        $this->assertStringContainsString('writing: Write clear prose', $systemPrompt);
        $this->assertStringNotContainsString('Prefer direct sentences.', $systemPrompt);
        $provider->assertSent(function (RequestRecord $record): bool {
            $results = [];
            foreach ($record->messages as $message) {
                if ($message instanceof ToolResultMessage
                    && $message->getTools() !== []) {
                    $results[] = $message->getTools()[0]->getResult();
                }
            }

            return in_array("# Writing instructions\n\nPrefer direct sentences.", $results, true)
                && in_array('Use the active voice.', $results, true);
        });
    }

    public function test_unknown_skill_returns_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot)))->tools()[0];
        $tool->setInputs(['name' => 'unknown']);
        $tool->execute();

        $this->assertSame('Skill "unknown" is not available.', $tool->getResult());
    }

    public function test_resource_errors_and_binary_content_are_model_readable_tool_results(): void
    {
        file_put_contents($this->skillsRoot.'/writing/image.bin', "raw\0secret");
        $tool = (new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot)))->tools()[1];

        $tool->setInputs(['name' => 'writing', 'path' => '../outside.txt']);
        $tool->execute();
        $this->assertSame(
            'Resource path "../outside.txt" cannot contain ".." segments.',
            $tool->getResult(),
        );

        $tool->setInputs(['name' => 'unknown', 'path' => 'file.txt']);
        $tool->execute();
        $this->assertSame('Skill "unknown" is not available.', $tool->getResult());

        $tool->setInputs(['name' => 'writing', 'path' => 'image.bin']);
        $tool->execute();
        $this->assertSame(
            'Resource "image.bin" in skill "writing" contains unsupported binary content.',
            $tool->getResult(),
        );
        $this->assertStringNotContainsString('secret', $tool->getResult());

        unlink($this->skillsRoot.'/writing/image.bin');
    }

    public function test_resource_tool_delegates_to_the_repository_contract(): void
    {
        $repository = new class () implements SkillRepositoryInterface {
            /** @var array{name: string, path: string}|null */
            public ?array $requestedResource = null;

            public function catalog(): array
            {
                return [new SkillMetadata('remote', 'Remote skill')];
            }

            public function load(string $name): string
            {
                return 'Instructions';
            }

            public function loadResource(string $name, string $path): string
            {
                $this->requestedResource = ['name' => $name, 'path' => $path];

                return 'Repository content';
            }

            public function diagnostics(): array
            {
                return [];
            }
        };
        $tool = (new SkillToolkit($repository))->tools()[1];

        $tool->setInputs(['name' => 'remote', 'path' => 'any/category/file.txt']);
        $tool->execute();

        $this->assertSame('Repository content', $tool->getResult());
        $this->assertSame(
            ['name' => 'remote', 'path' => 'any/category/file.txt'],
            $repository->requestedResource,
        );
    }

    public function test_skill_tools_track_distinct_inputs_with_distinct_run_keys(): void
    {
        [$skillLoad, $resourceLoad] = (new SkillToolkit(
            new FileSystemSkillRepository($this->skillsRoot),
        ))->tools();
        $this->assertInstanceOf(SkillLoadTool::class, $skillLoad);
        $this->assertInstanceOf(SkillLoadResourceTool::class, $resourceLoad);

        $skillLoad->setInputs(['name' => 'writing']);
        $writingSkillKey = $skillLoad->getRunKey();
        $skillLoad->setInputs(['name' => 'another']);
        $anotherSkillKey = $skillLoad->getRunKey();

        $resourceLoad->setInputs(['name' => 'writing', 'path' => 'references/style.md']);
        $styleResourceKey = $resourceLoad->getRunKey();
        $resourceLoad->setInputs(['name' => 'writing', 'path' => 'scripts/prepare.php']);
        $scriptResourceKey = $resourceLoad->getRunKey();
        $resourceLoad->setInputs(['name' => 'another', 'path' => 'references/style.md']);
        $otherSkillResourceKey = $resourceLoad->getRunKey();

        $this->assertNotSame($writingSkillKey, $anotherSkillKey);
        $this->assertNotSame($styleResourceKey, $scriptResourceKey);
        $this->assertNotSame($styleResourceKey, $otherSkillResourceKey);
    }
}
