<?php

declare(strict_types=1);

namespace App\Enums;

enum DepartmentCode: string
{
    case Administration = 'ADMIN';
    case Operations = 'OPS';
    case Engineering = 'ENG';
    case HumanResources = 'HR';
    case Finance = 'FIN';

    public function label(): string
    {
        return match ($this) {
            self::Administration => 'Administration',
            self::Operations => 'Operations',
            self::Engineering => 'Engineering',
            self::HumanResources => 'Human Resources',
            self::Finance => 'Finance',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Administration => 'Company administration and leadership',
            self::Operations => 'Day-to-day operations',
            self::Engineering => 'Product and engineering',
            self::HumanResources => 'People and talent',
            self::Finance => 'Finance and accounting',
        };
    }
}
