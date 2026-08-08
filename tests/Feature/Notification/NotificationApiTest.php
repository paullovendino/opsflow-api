<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Projects\ProjectService;
use App\Services\Remarks\RemarkService;
use App\Services\Tasks\TaskService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $employee;

    private User $outsider;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'admin@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'first_name' => 'Eli',
            'last_name' => 'Employee',
            'email' => 'employee@opsflow.test',
        ]);
        $this->outsider = User::factory()->create([
            'role_id' => $employeeRole->id,
            'first_name' => 'Out',
            'last_name' => 'Sider',
            'email' => 'outsider@opsflow.test',
        ]);

        $this->project = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'OpsFlow Alpha',
        ]);
        $this->project->members()->attach($this->employee->id, ['joined_at' => now()]);

        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'assigned_to' => $this->employee->id,
            'title' => 'Ship notifications',
        ]);
    }

    public function test_user_lists_only_own_notifications_newest_first(): void
    {
        $older = Notification::factory()->create([
            'recipient_id' => $this->employee->id,
            'type' => NotificationType::TaskAssigned,
            'created_at' => now()->subHour(),
            'data' => ['title' => 'Older', 'message' => 'Older', 'target_type' => 'task', 'target_id' => $this->task->id],
        ]);
        $newer = Notification::factory()->create([
            'recipient_id' => $this->employee->id,
            'type' => NotificationType::RemarkMentioned,
            'created_at' => now(),
            'data' => ['title' => 'Newer', 'message' => 'Newer', 'target_type' => 'task', 'target_id' => $this->task->id],
        ]);
        Notification::factory()->create([
            'recipient_id' => $this->outsider->id,
            'type' => NotificationType::TaskAssigned,
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/notifications?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id);

        $this->actingAs($this->employee)
            ->getJson('/api/v1/notifications?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id);
    }

    public function test_unread_filter_and_empty_state(): void
    {
        Notification::factory()->read()->create([
            'recipient_id' => $this->employee->id,
        ]);

        $this->actingAs($this->employee)
            ->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_unread_count_is_self_only(): void
    {
        Notification::factory()->count(2)->create(['recipient_id' => $this->employee->id]);
        Notification::factory()->read()->create(['recipient_id' => $this->employee->id]);
        Notification::factory()->create(['recipient_id' => $this->outsider->id]);

        $this->actingAs($this->employee)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);

        $this->actingAs($this->outsider)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_mark_read_is_idempotent_and_forbidden_for_others(): void
    {
        $notification = Notification::factory()->create([
            'recipient_id' => $this->employee->id,
        ]);

        $this->actingAs($this->outsider)
            ->patchJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertNotFound();

        $this->actingAs($this->employee)
            ->patchJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($this->employee)
            ->patchJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk();
    }

    public function test_mark_all_read_only_updates_authenticated_user(): void
    {
        Notification::factory()->count(2)->create(['recipient_id' => $this->employee->id]);
        $other = Notification::factory()->create(['recipient_id' => $this->outsider->id]);

        $this->actingAs($this->employee)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2);

        $this->assertSame(0, Notification::query()->forUser($this->employee)->unread()->count());
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_task_assignment_creates_notification_and_skips_self(): void
    {
        $taskService = app(TaskService::class);

        $taskService->changeAssignment($this->task->fresh(), $this->administrator->id, $this->administrator);
        $this->assertSame(0, Notification::query()->forUser($this->administrator)->count());

        $taskService->changeAssignment($this->task->fresh(), $this->employee->id, $this->administrator);

        $notification = Notification::query()->forUser($this->employee)->where('type', NotificationType::TaskAssigned)->latest('id')->first();
        $this->assertNotNull($notification);
        $this->assertSame('task', $notification->data['target_type']);
        $this->assertSame($this->task->id, $notification->data['target_id']);
    }

    public function test_task_assignment_respects_preference(): void
    {
        $this->employee->forceFill(['notify_task_assigned' => false])->save();

        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
        ]);

        app(TaskService::class)->changeAssignment($task, $this->employee->id, $this->administrator);

        $this->assertSame(0, Notification::query()->forUser($this->employee)->where('type', NotificationType::TaskAssigned)->count());
    }

    public function test_task_status_notification_skips_actor_assignee(): void
    {
        $taskService = app(TaskService::class);

        $taskService->changeStatus($this->task->fresh(), TaskStatus::InProgress, $this->administrator);
        $this->assertSame(1, Notification::query()->forUser($this->employee)->where('type', NotificationType::TaskStatusChanged)->count());

        $this->employee->forceFill(['notify_task_status' => false])->save();
        $taskService->changeStatus($this->task->fresh(), TaskStatus::Blocked, $this->administrator);
        $this->assertSame(1, Notification::query()->forUser($this->employee)->where('type', NotificationType::TaskStatusChanged)->count());

        $this->task->update(['assigned_to' => $this->administrator->id]);
        $taskService->changeStatus($this->task->fresh(), TaskStatus::Completed, $this->administrator);
        $this->assertSame(0, Notification::query()->forUser($this->administrator)->where('type', NotificationType::TaskStatusChanged)->count());
    }

    public function test_project_member_added_notifies_new_member(): void
    {
        app(ProjectService::class)->addMember($this->project, $this->outsider->id, $this->administrator);

        $notification = Notification::query()->forUser($this->outsider)->where('type', NotificationType::ProjectMemberAdded)->first();
        $this->assertNotNull($notification);
        $this->assertSame($this->project->id, $notification->data['target_id']);

        $this->assertSame(
            0,
            Notification::query()->forUser($this->administrator)->where('type', NotificationType::ProjectMemberAdded)->count(),
        );
    }

    public function test_remark_notifications_dedupe_mentions_and_watchers(): void
    {
        $remarkService = app(RemarkService::class);

        $remarkService->create($this->task, [
            'body' => 'Please review @Eli Employee',
            'mentioned_user_ids' => [$this->employee->id, $this->administrator->id],
        ], $this->administrator);

        $this->assertSame(1, Notification::query()->forUser($this->employee)->count());
        $this->assertSame(
            NotificationType::RemarkMentioned,
            Notification::query()->forUser($this->employee)->first()?->type,
        );
        $this->assertSame(0, Notification::query()->forUser($this->administrator)->count());
        $this->assertSame(0, Notification::query()->where('type', NotificationType::RemarkCreated)->count());
    }

    public function test_remark_created_notifies_owner_when_not_mentioned(): void
    {
        app(RemarkService::class)->create($this->project, [
            'body' => 'Status update',
        ], $this->employee);

        $notification = Notification::query()->forUser($this->administrator)->where('type', NotificationType::RemarkCreated)->first();
        $this->assertNotNull($notification);
        $this->assertSame('project', $notification->data['target_type']);
    }

    public function test_remark_preferences_are_honored(): void
    {
        $this->employee->forceFill([
            'notify_mentions' => false,
            'notify_remarks' => false,
        ])->save();
        $this->administrator->forceFill(['notify_remarks' => false])->save();

        app(RemarkService::class)->create($this->task, [
            'body' => 'Mention Eli',
            'mentioned_user_ids' => [$this->employee->id],
        ], $this->administrator);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_service_skips_disabled_task_status_preference(): void
    {
        $this->employee->forceFill(['notify_task_status' => false])->save();

        app(NotificationService::class)->notify(
            recipient: $this->employee->fresh(),
            type: NotificationType::TaskStatusChanged,
            actor: $this->administrator,
            subject: $this->task,
            data: ['title' => 'x', 'message' => 'y'],
        );

        $this->assertSame(0, Notification::query()->count());
    }
}
