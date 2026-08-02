<?php

declare(strict_types=1);

namespace App\Enums;

enum RoleName: string
{
    case Administrator = 'administrator';
    case ProjectManager = 'project_manager';
    case Employee = 'employee';

    public function description(): string
    {
        return match ($this) {
            self::Administrator => 'Full system access',
            self::ProjectManager => 'Manage projects and tasks',
            self::Employee => 'Assigned work and updates',
        };
    }
}
