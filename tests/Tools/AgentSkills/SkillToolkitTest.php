<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\AgentSkills;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tests\Stubs\StructuredOutput\User;
use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillToolkit;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function substr_count;
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
    }

    protected function tearDown(): void
    {
        unlink($this->skillsRoot.'/writing/SKILL.md');
        rmdir($this->skillsRoot.'/writing');
        rmdir($this->skillsRoot);
    }

    public function test_agent_discloses_catalog_and_loads_skill_through_tool_loop(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
        $skillLoad = $toolkit->tools()[0];
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillLoad)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            new AssistantMessage('I will follow the writing skill.'),
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);

        $response = $agent->chat(new UserMessage('Help me write.'))->getMessage();

        $this->assertSame('I will follow the writing skill.', $response->getContent());
        $provider->assertToolsConfigured(['skill_load']);
        $systemPrompt = $provider->getRecorded()[0]->systemPrompt ?? '';
        $this->assertStringContainsString('writing: Write clear prose', $systemPrompt);
        $this->assertStringNotContainsString('Prefer direct sentences.', $systemPrompt);
        $provider->assertSent(function (RequestRecord $record): bool {
            foreach ($record->messages as $message) {
                if ($message instanceof ToolResultMessage
                    && $message->getTools()[0]->getResult() === "# Writing instructions\n\nPrefer direct sentences.") {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_unknown_skill_returns_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot)))->tools()[0];
        $tool->setInputs(['name' => 'unknown']);
        $tool->execute();

        $this->assertSame('Skill "unknown" is not available.', $tool->getResult());
    }

    public function test_loaded_skill_is_active_without_duplicate_instructions(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
        $skillLoad = $toolkit->tools()[0];
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillLoad)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            new AssistantMessage('Loaded.'),
            new ToolCallMessage(null, [
                (clone $skillLoad)->setCallId('call_2')->setInputs(['name' => 'writing']),
            ]),
            new AssistantMessage('Still loaded.'),
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);

        $agent->chat(new UserMessage('Load writing.'))->getMessage();
        $agent->chat(new UserMessage('Load writing again.'))->getMessage();

        $lastRequest = $provider->getRecorded()[3];
        $this->assertSame(0, substr_count($lastRequest->systemPrompt ?? '', 'Prefer direct sentences.'));
        $this->assertSame(1, $this->countToolResults($lastRequest, "# Writing instructions\n\nPrefer direct sentences."));
        $this->assertSame(1, $this->countToolResults($lastRequest, 'Skill "writing" is already active.'));
    }

    public function test_loaded_skill_is_reinjected_after_history_trimming(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
        $skillLoad = $toolkit->tools()[0];
        $loaded = new AssistantMessage('Loaded.');
        $loaded->setUsage(new Usage(20, 10));
        $largeResponse = new AssistantMessage('A large response.');
        $largeResponse->setUsage(new Usage(200, 10));
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillLoad)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            $loaded,
            $largeResponse,
            new AssistantMessage('Continued.'),
        );
        $history = new InMemoryChatHistory(100);
        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history)
            ->addTool($toolkit);

        $agent->chat(new UserMessage('Load writing.'))->getMessage();
        $agent->chat(new UserMessage('Create a long answer.'))->getMessage();
        $agent->chat(new UserMessage('Continue.'))->getMessage();

        $lastRequest = $provider->getRecorded()[3];
        $this->assertSame(1, substr_count($lastRequest->systemPrompt ?? '', 'Prefer direct sentences.'));
        $this->assertSame(0, $this->countToolResults($lastRequest, "# Writing instructions\n\nPrefer direct sentences."));
    }

    public function test_loaded_skill_is_reinjected_after_summarization(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
        $skillLoad = $toolkit->tools()[0];
        $loaded = new AssistantMessage('Loaded.');
        $loaded->setUsage(new Usage(80, 20));
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillLoad)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            $loaded,
            new AssistantMessage('Continued.'),
        );
        $summarizer = new FakeAIProvider(new AssistantMessage('The writing skill was loaded.'));
        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addTool($toolkit);
        $agent->addMiddleware(InferenceNode::class, new Summarization($summarizer, 50, 1));

        $agent->chat(new UserMessage('Load writing.'))->getMessage();
        $agent->chat(new UserMessage('Continue.'))->getMessage();

        $lastRequest = $provider->getRecorded()[2];
        $this->assertSame(1, substr_count($lastRequest->systemPrompt ?? '', 'Prefer direct sentences.'));
        $this->assertSame(0, $this->countToolResults($lastRequest, "# Writing instructions\n\nPrefer direct sentences."));
        $summarizer->assertCallCount(1);
    }

    public function test_active_skills_do_not_leak_between_agents(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
        $skillLoad = $toolkit->tools()[0];
        $firstProvider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillLoad)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            new AssistantMessage('Loaded.'),
        );
        Agent::make()
            ->setAiProvider($firstProvider)
            ->addTool($toolkit)
            ->chat(new UserMessage('Load writing.'))
            ->getMessage();

        $secondProvider = new FakeAIProvider(new AssistantMessage('Hello.'));
        Agent::make()
            ->setAiProvider($secondProvider)
            ->addTool($toolkit)
            ->chat(new UserMessage('Hello.'))
            ->getMessage();

        $this->assertStringNotContainsString(
            'Prefer direct sentences.',
            $secondProvider->getRecorded()[0]->systemPrompt ?? '',
        );
    }

    public function test_loaded_skill_is_preserved_in_streaming_mode(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
        $skillLoad = $toolkit->tools()[0];
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillLoad)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            new AssistantMessage('Loaded.'),
            new AssistantMessage('Continued.'),
        );
        $history = new InMemoryChatHistory();
        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history)
            ->addTool($toolkit);

        foreach ($agent->stream(new UserMessage('Load writing.'))->events() as $event) {
        }
        $history->flushAll();
        foreach ($agent->stream(new UserMessage('Continue.'))->events() as $event) {
        }

        $this->assertSame('stream', $provider->getRecorded()[2]->method);
        $this->assertSame(1, substr_count($provider->getRecorded()[2]->systemPrompt ?? '', 'Prefer direct sentences.'));
    }

    public function test_loaded_skill_is_preserved_in_structured_output_mode(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillRepository($this->skillsRoot));
        $skillLoad = $toolkit->tools()[0];
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillLoad)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            new AssistantMessage('{"name": "Alice"}'),
            new AssistantMessage('{"name": "Bob"}'),
        );
        $history = new InMemoryChatHistory();
        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setChatHistory($history)
            ->addTool($toolkit);

        $agent->structured(new UserMessage('Load writing and create a user.'), User::class);
        $history->flushAll();
        $result = $agent->structured(new UserMessage('Create another user.'), User::class);

        $this->assertSame('Bob', $result->name);
        $this->assertSame('structured', $provider->getRecorded()[2]->method);
        $this->assertSame(1, substr_count($provider->getRecorded()[2]->systemPrompt ?? '', 'Prefer direct sentences.'));
    }

    protected function countToolResults(RequestRecord $record, string $result): int
    {
        $count = 0;

        foreach ($record->messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getTools() as $tool) {
                if ($tool->getResult() === $result) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
