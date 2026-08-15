<?php

declare(strict_types=1);

namespace App\Enums;

enum OrgEntityStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
