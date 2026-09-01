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
use NeuronAI\Tools\Toolkits\AgentSkills\FileSystemSkillRepository;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillToolkit;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
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
}
