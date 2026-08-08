<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationType: string
{
    case TaskAssigned = 'task_assigned';
    case TaskStatusChanged = 'task_status_changed';
    case ProjectMemberAdded = 'project_member_added';
    case RemarkCreated = 'remark_created';
    case RemarkMentioned = 'remark_mentioned';
}
