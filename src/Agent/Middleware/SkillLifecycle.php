<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\HandleContent;
use NeuronAI\Tools\Toolkits\AgentSkills\ActiveSkill;
use NeuronAI\Tools\Toolkits\AgentSkills\SkillLoadTool;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

use function implode;

use const PHP_EOL;

class SkillLifecycle implements WorkflowMiddleware
{
    use HandleContent;

    protected const OPEN_TAG = '<ACTIVE-SKILLS>';
    protected const CLOSE_TAG = '</ACTIVE-SKILLS>';
    protected const STATE_KEY = '__active_skills';

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$node instanceof InferenceNode
            || !$event instanceof AIInferenceEvent
            || !$state instanceof AgentState) {
            return;
        }

        $event->instructions = $this->removeDelimitedContent(
            $event->instructions,
            self::OPEN_TAG,
            self::CLOSE_TAG,
        );

        $messages = [
            ...$state->getChatHistory()->getMessages(),
            ...$event->getMessages(),
        ];
        $instructions = [];

        foreach ($this->activeSkills($state) as $skill) {
            if (!$this->conversationContainsSkill($messages, $skill)) {
                $instructions[] = "## Skill: {$skill->name}".PHP_EOL.PHP_EOL.$skill->instructions;
            }
        }

        if ($instructions !== []) {
            $event->instructions .= PHP_EOL.PHP_EOL.self::OPEN_TAG.PHP_EOL
                .implode(PHP_EOL.PHP_EOL, $instructions).PHP_EOL
                .self::CLOSE_TAG;
        }
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if (!$node instanceof ToolNode
            || !$result instanceof AIInferenceEvent
            || !$state instanceof AgentState) {
            return;
        }

        foreach ($result->getMessages() as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            $this->recordActiveSkills($message, $state);
        }
    }

    protected function recordActiveSkills(ToolResultMessage $message, AgentState $state): void
    {
        foreach ($message->getTools() as $tool) {
            if (!$tool instanceof SkillLoadTool) {
                continue;
            }

            $skill = $tool->getActiveSkill();
            if ($skill === null) {
                continue;
            }

            if (!$this->activate($state, $skill)) {
                $tool->markSkillAlreadyActive();
            }
        }
    }

    protected function activate(AgentState $state, ActiveSkill $skill): bool
    {
        $active = $this->activeSkills($state);
        if (isset($active[$skill->name])) {
            return false;
        }

        $active[$skill->name] = $skill;
        $state->set(self::STATE_KEY, $active);

        return true;
    }

    /**
     * @return array<string, ActiveSkill>
     */
    protected function activeSkills(AgentState $state): array
    {
        return $state->get(self::STATE_KEY, []);
    }

    /**
     * @param Message[] $messages
     */
    protected function conversationContainsSkill(array $messages, ActiveSkill $skill): bool
    {
        foreach ($messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getTools() as $tool) {
                if ($tool->getName() === $skill->tool
                    && $tool->getInputs() === $skill->inputs
                    && $tool->getResult() === $skill->instructions) {
                    return true;
                }
            }
        }

        return false;
    }
}
