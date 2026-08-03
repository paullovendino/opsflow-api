<?php

declare(strict_types=1);

namespace Tests\Feature\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('tasks'));
        $this->assertTrue(Schema::hasColumns('tasks', [
            'id',
            'project_id',
            'title',
            'description',
            'status',
            'priority',
            'due_date',
            'assigned_to',
            'created_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_task_belongs_to_project_creator_and_optional_assignee(): void
    {
        $creator = User::factory()->create();
        $assignee = User::factory()->create();
        $project = Project::factory()->create([
            'created_by' => $creator->id,
        ]);

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $creator->id,
            'assigned_to' => $assignee->id,
        ]);

        $task->load(['project', 'creator', 'createdBy', 'assignee']);

        $this->assertTrue($task->project->is($project));
        $this->assertTrue($task->creator->is($creator));
        $this->assertTrue($task->createdBy->is($creator));
        $this->assertTrue($task->assignee->is($assignee));
        $this->assertTrue($project->tasks()->first()->is($task));
        $this->assertTrue($creator->createdTasks()->first()->is($task));
        $this->assertTrue($assignee->assignedTasks()->first()->is($task));
    }

    public function test_task_assignee_may_be_null(): void
    {
        $task = Task::factory()->create([
            'assigned_to' => null,
        ]);

        $this->assertNull($task->assigned_to);
        $this->assertNull($task->assignee);
    }

    public function test_task_status_and_priority_are_cast_to_enums(): void
    {
        $task = Task::factory()->create([
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
        ]);

        $this->assertInstanceOf(TaskStatus::class, $task->status);
        $this->assertInstanceOf(TaskPriority::class, $task->priority);
        $this->assertSame(TaskStatus::Todo, $task->status);
        $this->assertSame(TaskPriority::Medium, $task->priority);
        $this->assertSame('To Do', $task->status->label());
        $this->assertSame('Medium', $task->priority->label());

        $task->update([
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::High,
        ]);
        $task->refresh();

        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertSame(TaskPriority::High, $task->priority);
    }

    public function test_task_factory_defaults_to_todo_and_medium(): void
    {
        $task = Task::factory()->create();

        $this->assertSame(TaskStatus::Todo, $task->status);
        $this->assertSame(TaskPriority::Medium, $task->priority);
        $this->assertNotNull($task->project);
        $this->assertNotNull($task->creator);
        $this->assertNull($task->assigned_to);
    }

    public function test_tasks_support_soft_deletes(): void
    {
        $task = Task::factory()->create();

        $task->delete();

        $this->assertSoftDeleted($task);
        $this->assertNull(Task::query()->find($task->id));
        $this->assertNotNull(Task::withTrashed()->find($task->id));
    }

    public function test_soft_deleted_project_keeps_task_rows(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $project->created_by,
        ]);

        $project->delete();

        $this->assertSoftDeleted($project);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'project_id' => $project->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_hard_delete_user_who_created_a_task(): void
    {
        $creator = User::factory()->create();
        Task::factory()->create([
            'created_by' => $creator->id,
        ]);

        $this->expectException(QueryException::class);

        $creator->forceDelete();
    }

    public function test_cannot_hard_delete_user_who_is_assigned_a_task(): void
    {
        $assignee = User::factory()->create();
        Task::factory()->create([
            'assigned_to' => $assignee->id,
        ]);

        $this->expectException(QueryException::class);

        $assignee->forceDelete();
    }

    public function test_cannot_hard_delete_project_with_tasks(): void
    {
        $project = Project::factory()->create();
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $project->created_by,
        ]);

        $this->expectException(QueryException::class);

        $project->forceDelete();
    }

    public function test_task_status_and_priority_defaults_in_database(): void
    {
        $creator = User::factory()->create();
        $project = Project::factory()->create([
            'created_by' => $creator->id,
        ]);

        $taskId = DB::table('tasks')->insertGetId([
            'project_id' => $project->id,
            'title' => 'Default Status Task',
            'description' => null,
            'due_date' => null,
            'assigned_to' => null,
            'created_by' => $creator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = Task::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Todo, $task->status);
        $this->assertSame(TaskPriority::Medium, $task->priority);
    }

    public function test_morph_map_includes_task_alias(): void
    {
        $this->assertSame(
            'task',
            (new Task)->getMorphClass()
        );
    }
}
