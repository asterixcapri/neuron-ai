<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

interface ProvidesConversationInstructions
{
    public function getConversationInstructionKey(): ?string;

    public function getConversationInstructions(): ?string;

    public function markConversationInstructionsAlreadyActive(): void;
}
