<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Enums\ActivityAction;
use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Role $employeeRole;

    private UserService $userService;

    private ProjectService $projectService;

    private TaskService $taskService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();
        $this->actor = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada.admin@opsflow.test',
        ]);

        $this->userService = app(UserService::class);
        $this->projectService = app(ProjectService::class);
        $this->taskService = app(TaskService::class);
    }

    public function test_user_created_is_recorded_without_password(): void
    {
        $user = $this->userService->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@opsflow.test',
            'password' => 'password123',
            'role_id' => $this->employeeRole->id,
            'status' => UserStatus::Active->value,
        ], $this->actor);

        $log = $this->latestLog(ActivityAction::UserCreated);

        $this->assertSame($this->actor->id, $log->actor_id);
        $this->assertTrue($log->subject->is($user));
        $this->assertSame('user', $log->subject_type);
        $this->assertStringContainsString('Jane Doe', $log->description);
        $this->assertSame('jane.doe@opsflow.test', $log->properties['email']);
        $this->assertArrayNotHasKey('password', $log->properties);
        $this->assertStringNotContainsString('password123', json_encode($log->properties));
    }

    public function test_user_updated_is_recorded_and_no_op_is_skipped(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@opsflow.test',
            'status' => UserStatus::Active,
        ]);

        $this->userService->update($user, [
            'first_name' => 'Janet',
            'last_name' => 'Doe',
            'email' => 'jane.doe@opsflow.test',
            'role_id' => $this->employeeRole->id,
            'status' => UserStatus::Active->value,
        ], $this->actor);

        $log = $this->latestLog(ActivityAction::UserUpdated);
        $this->assertSame('Jane', $log->properties['before']['first_name']);
        $this->assertSame('Janet', $log->properties['after']['first_name']);
        $this->assertArrayNotHasKey('password', $log->properties['before']);
        $this->assertArrayNotHasKey('password', $log->properties['after']);

        $count = ActivityLog::query()->forAction(ActivityAction::UserUpdated)->count();

        $this->userService->update($user->fresh(), [
            'first_name' => 'Janet',
            'last_name' => 'Doe',
            'email' => 'jane.doe@opsflow.test',
            'role_id' => $this->employeeRole->id,
            'status' => UserStatus::Active->value,
        ], $this->actor);

        $this->assertSame($count, ActivityLog::query()->forAction(ActivityAction::UserUpdated)->count());
    }

    public function test_user_status_changed_records_activated_and_deactivated(): void
    {
        $user = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'Sam',
            'last_name' => 'Smith',
            'status' => UserStatus::Active,
        ]);

        $this->userService->changeStatus($user, UserStatus::Inactive, $this->actor);
        $deactivated = $this->latestLog(ActivityAction::UserDeactivated);
        $this->assertSame('active', $deactivated->properties['before']['status']);
        $this->assertSame('inactive', $deactivated->properties['after']['status']);
        $this->assertStringContainsString('Deactivated', $deactivated->description);

        $this->userService->changeStatus($user->fresh(), UserStatus::Active, $this->actor);
        $activated = $this->latestLog(ActivityAction::UserActivated);
        $this->assertSame('inactive', $activated->properties['before']['status']);
        $this->assertSame('active', $activated->properties['after']['status']);

        $count = ActivityLog::query()->forSubject($user->fresh())->count();
        $this->userService->changeStatus($user->fresh(), UserStatus::Active, $this->actor);
        $this->assertSame($count, ActivityLog::query()->forSubject($user->fresh())->count());
    }

    public function test_project_created_updated_and_deleted_are_recorded(): void
    {
        $project = $this->projectService->create([
            'name' => 'Website Redesign',
            'description' => 'Refresh the site',
        ], $this->actor);

        $created = $this->latestLog(ActivityAction::ProjectCreated);
        $this->assertTrue($created->subject->is($project));
        $this->assertStringContainsString('Website Redesign', $created->description);

        $this->projectService->update($project, [
            'name' => 'Website Relaunch',
            'description' => 'Refresh the site',
            'start_date' => null,
            'due_date' => null,
        ], $this->actor);

        $updated = $this->latestLog(ActivityAction::ProjectUpdated);
        $this->assertSame('Website Redesign', $updated->properties['before']['name']);
        $this->assertSame('Website Relaunch', $updated->properties['after']['name']);

        $count = ActivityLog::query()->forAction(ActivityAction::ProjectUpdated)->count();
        $this->projectService->update($project->fresh(), [
            'name' => 'Website Relaunch',
            'description' => 'Refresh the site',
            'start_date' => null,
            'due_date' => null,
        ], $this->actor);
        $this->assertSame($count, ActivityLog::query()->forAction(ActivityAction::ProjectUpdated)->count());

        $this->projectService->delete($project->fresh(), $this->actor);

        $deleted = $this->latestLog(ActivityAction::ProjectDeleted);
        $this->assertSame($project->id, $deleted->subject_id);
        $this->assertSame('project', $deleted->subject_type);
        $this->assertStringContainsString('Website Relaunch', $deleted->description);
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_project_member_added_and_removed_are_recorded(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'OpsFlow Launch',
        ]);
        $member = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'Mem',
            'last_name' => 'Ber',
        ]);

        $this->projectService->addMember($project, $member->id, $this->actor);
        $added = $this->latestLog(ActivityAction::ProjectMemberAdded);
        $this->assertSame($member->id, $added->properties['member_user_id']);
        $this->assertSame('Mem Ber', $added->properties['member_full_name']);
        $this->assertTrue($added->subject->is($project));

        $this->projectService->removeMember($project, $member, $this->actor);
        $removed = $this->latestLog(ActivityAction::ProjectMemberRemoved);
        $this->assertSame($member->id, $removed->properties['member_user_id']);
        $this->assertStringContainsString('Mem Ber', $removed->description);
    }

    public function test_task_created_updated_assigned_and_status_changed_are_recorded(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
        ]);
        $assignee = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'Maria',
            'last_name' => 'Lopez',
        ]);
        $project->members()->attach($assignee->id, ['joined_at' => now()]);

        $task = $this->taskService->create([
            'project_id' => $project->id,
            'title' => 'Draft API spec',
            'description' => 'Write the contract',
            'priority' => TaskPriority::Medium->value,
            'assigned_to' => $assignee->id,
        ], $this->actor);

        $created = $this->latestLog(ActivityAction::TaskCreated);
        $this->assertTrue($created->subject->is($task));
        $this->assertSame($assignee->id, $created->properties['assigned_to']);

        $assignedOnCreate = $this->latestLog(ActivityAction::TaskAssigned);
        $this->assertNull($assignedOnCreate->properties['before']['assigned_to']);
        $this->assertSame($assignee->id, $assignedOnCreate->properties['after']['assigned_to']);
        $this->assertStringContainsString('Maria Lopez', $assignedOnCreate->description);

        $this->taskService->update($task, [
            'title' => 'Draft API specification',
            'description' => 'Write the contract',
            'priority' => TaskPriority::High->value,
            'due_date' => '2026-08-20',
        ], $this->actor);

        $updated = $this->latestLog(ActivityAction::TaskUpdated);
        $this->assertSame('Draft API spec', $updated->properties['before']['title']);
        $this->assertSame('Draft API specification', $updated->properties['after']['title']);

        $priority = $this->latestLog(ActivityAction::TaskPriorityChanged);
        $this->assertSame('medium', $priority->properties['before']['priority']);
        $this->assertSame('high', $priority->properties['after']['priority']);

        $due = $this->latestLog(ActivityAction::TaskDueDateChanged);
        $this->assertNull($due->properties['before']['due_date']);
        $this->assertSame('2026-08-20', $due->properties['after']['due_date']);

        $other = User::factory()->create([
            'role_id' => $this->employeeRole->id,
            'first_name' => 'John',
            'last_name' => 'Reyes',
        ]);
        $project->members()->attach($other->id, ['joined_at' => now()]);

        $this->taskService->changeAssignment($task->fresh(), $other->id, $this->actor);
        $reassigned = $this->latestLog(ActivityAction::TaskAssigned);
        $this->assertSame($assignee->id, $reassigned->properties['before']['assigned_to']);
        $this->assertSame($other->id, $reassigned->properties['after']['assigned_to']);

        $this->taskService->changeStatus($task->fresh(), TaskStatus::InProgress, $this->actor);
        $status = $this->latestLog(ActivityAction::TaskStatusChanged);
        $this->assertSame('todo', $status->properties['before']['status']);
        $this->assertSame('in_progress', $status->properties['after']['status']);
        $this->assertStringContainsString('To Do', $status->description);
        $this->assertStringContainsString('In Progress', $status->description);

        $statusCount = ActivityLog::query()->forAction(ActivityAction::TaskStatusChanged)->count();
        $this->taskService->changeStatus($task->fresh(), TaskStatus::InProgress, $this->actor);
        $this->assertSame($statusCount, ActivityLog::query()->forAction(ActivityAction::TaskStatusChanged)->count());
    }

    public function test_project_status_change_is_recorded(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->actor->id,
            'status' => ProjectStatus::Planning,
        ]);

        $this->projectService->changeStatus($project, ProjectStatus::Active, $this->actor);
        $log = $this->latestLog(ActivityAction::ProjectStatusChanged);

        $this->assertSame('planning', $log->properties['before']['status']);
        $this->assertSame('active', $log->properties['after']['status']);
    }

    private function latestLog(ActivityAction $action): ActivityLog
    {
        return ActivityLog::query()
            ->forAction($action)
            ->latest('id')
            ->firstOrFail();
    }
}
