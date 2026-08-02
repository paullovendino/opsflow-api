<?php

declare(strict_types=1);

namespace App\Enums;

enum JobTitleCode: string
{
    case Administrator = 'ADMIN';
    case ProjectManager = 'PM';
    case SoftwareEngineer = 'SE';
    case OperationsSpecialist = 'OPS_SPEC';
    case HumanResourcesSpecialist = 'HR_SPEC';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::ProjectManager => 'Project Manager',
            self::SoftwareEngineer => 'Software Engineer',
            self::OperationsSpecialist => 'Operations Specialist',
            self::HumanResourcesSpecialist => 'Human Resources Specialist',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Administrator => 'Company / system administrator position',
            self::ProjectManager => 'Delivers projects and coordinates teams',
            self::SoftwareEngineer => 'Builds and maintains software',
            self::OperationsSpecialist => 'Supports operational processes',
            self::HumanResourcesSpecialist => 'Supports HR processes',
        };
    }
}
