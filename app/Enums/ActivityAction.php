<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityAction: string
{
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserActivated = 'user.activated';
    case UserDeactivated = 'user.deactivated';

    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectDeleted = 'project.deleted';
    case ProjectStatusChanged = 'project.status_changed';
    case ProjectMemberAdded = 'project.member_added';
    case ProjectMemberRemoved = 'project.member_removed';

    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskDeleted = 'task.deleted';
    case TaskAssigned = 'task.assigned';
    case TaskUnassigned = 'task.unassigned';
    case TaskStatusChanged = 'task.status_changed';
    case TaskPriorityChanged = 'task.priority_changed';
    case TaskDueDateChanged = 'task.due_date_changed';

    case RemarkCreated = 'remark.created';
    case RemarkUpdated = 'remark.updated';
    case RemarkDeleted = 'remark.deleted';
}
