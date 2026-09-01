<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Skills;

interface SkillStorageInterface
{
    /** @return string[] */
    public function packages(): array;

    /** @throws SkillStorageException */
    public function read(string $package, string $path): string;
}
