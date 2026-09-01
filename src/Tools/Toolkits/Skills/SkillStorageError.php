<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Skills;

enum SkillStorageError: string
{
    case PACKAGE_NOT_FOUND = 'package_not_found';
    case INVALID_PATH = 'invalid_path';
    case FILE_NOT_FOUND = 'file_not_found';
    case ESCAPES_PACKAGE = 'escapes_package';
    case NOT_A_FILE = 'not_a_file';
    case UNREADABLE = 'unreadable';
    case BINARY_CONTENT = 'binary_content';
}
