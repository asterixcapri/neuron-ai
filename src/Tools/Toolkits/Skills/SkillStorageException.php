<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Skills;

use RuntimeException;

class SkillStorageException extends RuntimeException
{
    public function __construct(public readonly SkillStorageError $error)
    {
        parent::__construct($error->value);
    }
}
