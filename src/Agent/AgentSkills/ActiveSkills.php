<?php

declare(strict_types=1);

namespace NeuronAI\Agent\AgentSkills;

use NeuronAI\Agent\AgentState;
use NeuronAI\Tools\Toolkits\AgentSkills\ActiveSkill;

class ActiveSkills
{
    protected const STATE_KEY = '__active_skills';

    public static function activate(AgentState $state, ActiveSkill $skill): bool
    {
        $active = self::all($state);
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
    public static function all(AgentState $state): array
    {
        return $state->get(self::STATE_KEY, []);
    }
}
