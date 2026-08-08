<?php

declare(strict_types=1);

namespace Tests\Feature\Task;

use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaskPriorityDueDateTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-08', 'UTC'));

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->actor = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'priority.due@opsflow.test',
        ]);
        $this->project = Project::factory()->create([
            'created_by' => $this->actor->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_create_accepts_valid_priority_and_due_date(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', [
                'project_id' => $this->project->id,
                'title' => 'Prioritized work',
                'priority' => TaskPriority::Urgent->value,
                'due_date' => '2026-08-15',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.priority', TaskPriority::Urgent->value)
            ->assertJsonPath('data.due_date', '2026-08-15')
            ->assertJsonPath('data.is_overdue', false);
    }

    public function test_create_rejects_invalid_priority(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', [
                'project_id' => $this->project->id,
                'title' => 'Bad priority',
                'priority' => 'critical',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['priority']);
    }

    public function test_create_rejects_invalid_due_date(): void
    {
        $response = $this->actingAs($this->actor)
            ->postJson('/api/v1/tasks', [
                'project_id' => $this->project->id,
                'title' => 'Bad date',
                'due_date' => 'not-a-date',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['due_date']);
    }

    public function test_update_can_change_and_clear_due_date(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'priority' => TaskPriority::Low,
            'due_date' => '2026-08-01',
            'status' => TaskStatus::Todo,
        ]);

        $updated = $this->actingAs($this->actor)
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => $task->title,
                'priority' => TaskPriority::High->value,
                'due_date' => '2026-09-01',
            ]);

        $updated->assertOk()
            ->assertJsonPath('data.priority', TaskPriority::High->value)
            ->assertJsonPath('data.due_date', '2026-09-01')
            ->assertJsonPath('data.is_overdue', false);

        $cleared = $this->actingAs($this->actor)
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => $task->title,
                'priority' => TaskPriority::High->value,
                'due_date' => null,
            ]);

        $cleared->assertOk()
            ->assertJsonPath('data.due_date', null)
            ->assertJsonPath('data.is_overdue', false);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'due_date' => null,
            'priority' => TaskPriority::High->value,
        ]);
    }

    public function test_update_rejects_invalid_priority_and_due_date(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
        ]);

        $this->actingAs($this->actor)
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => 'Still valid title',
                'priority' => 'critical',
                'due_date' => '2026-09-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);

        $this->actingAs($this->actor)
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => 'Still valid title',
                'priority' => TaskPriority::Medium->value,
                'due_date' => '32/13/2026',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_date']);
    }

    public function test_is_overdue_is_true_for_past_due_active_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::InProgress,
            'due_date' => '2026-08-07',
        ]);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', true)
            ->assertJsonPath('data.due_date', '2026-08-07')
            ->assertJsonPath('data.status', TaskStatus::InProgress->value);
    }

    public function test_is_overdue_is_false_for_completed_or_cancelled_past_due_tasks(): void
    {
        $completed = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Completed,
            'due_date' => '2026-08-01',
        ]);
        $cancelled = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Cancelled,
            'due_date' => '2026-08-01',
        ]);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/tasks/{$completed->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.status', TaskStatus::Completed->value);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/tasks/{$cancelled->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.status', TaskStatus::Cancelled->value);
    }

    public function test_is_overdue_is_false_for_today_future_and_null_due_dates(): void
    {
        $today = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Todo,
            'due_date' => '2026-08-08',
        ]);
        $future = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Todo,
            'due_date' => '2026-08-20',
        ]);
        $none = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Todo,
            'due_date' => null,
        ]);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/tasks/{$today->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/tasks/{$future->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/tasks/{$none->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.due_date', null);
    }

    public function test_overdue_does_not_change_task_status(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->actor->id,
            'status' => TaskStatus::Blocked,
            'due_date' => '2026-07-01',
        ]);

        $this->actingAs($this->actor)
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.is_overdue', true)
            ->assertJsonPath('data.status', TaskStatus::Blocked->value);

        $this->assertSame(TaskStatus::Blocked, $task->fresh()->status);
    }
}
