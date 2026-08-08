<?php

declare(strict_types=1);

namespace Tests\Feature\Remark;

use App\Enums\ActivityAction;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Remark;
use App\Models\RemarkMention;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\Remarks\RemarkService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemarkApiTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $projectManager;

    private User $employee;

    private User $outsider;

    private Project $project;

    private Task $task;

    private RemarkService $remarkService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $pmRole = Role::query()->where('name', RoleName::ProjectManager)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'admin@opsflow.test',
            'status' => UserStatus::Active,
        ]);
        $this->projectManager = User::factory()->create([
            'role_id' => $pmRole->id,
            'first_name' => 'Pat',
            'last_name' => 'Manager',
            'email' => 'pm@opsflow.test',
            'status' => UserStatus::Active,
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'first_name' => 'Eli',
            'last_name' => 'Employee',
            'email' => 'employee@opsflow.test',
            'status' => UserStatus::Active,
        ]);
        $this->outsider = User::factory()->create([
            'role_id' => $employeeRole->id,
            'first_name' => 'Out',
            'last_name' => 'Sider',
            'email' => 'outsider@opsflow.test',
            'status' => UserStatus::Active,
        ]);

        $this->project = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'OpsFlow Alpha',
        ]);
        $this->project->members()->attach($this->employee->id, [
            'joined_at' => now(),
        ]);

        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->administrator->id,
            'title' => 'Ship remarks',
        ]);

        $this->remarkService = app(RemarkService::class);
    }

    public function test_project_remarks_list_is_paginated_and_eager_loads_author_and_mentions(): void
    {
        $older = $this->remarkService->create($this->project, [
            'body' => 'Older remark',
            'mentioned_user_ids' => [$this->employee->id],
        ], $this->administrator);

        $newer = $this->remarkService->create($this->project, [
            'body' => 'Newer remark',
        ], $this->employee);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/projects/'.$this->project->id.'/remarks?per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('message', 'Remarks retrieved successfully.')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data');

        $this->assertSame($older->id, $response->json('data.0.id'));
        $this->assertSame('Older remark', $response->json('data.0.body'));
        $this->assertSame($this->administrator->id, $response->json('data.0.author.id'));
        $this->assertSame($this->employee->id, $response->json('data.0.mentions.0.id'));
        $this->assertFalse($response->json('data.0.can_edit'));
        $this->assertFalse($response->json('data.0.can_delete'));

        $pageTwo = $this->actingAs($this->employee)
            ->getJson('/api/v1/projects/'.$this->project->id.'/remarks?per_page=1&page=2')
            ->assertOk();

        $this->assertSame($newer->id, $pageTwo->json('data.0.id'));
        $this->assertTrue($pageTwo->json('data.0.can_edit'));
        $this->assertTrue($pageTwo->json('data.0.can_delete'));
    }

    public function test_task_remarks_empty_state_and_create_with_mentions(): void
    {
        $this->actingAs($this->employee)
            ->getJson('/api/v1/tasks/'.$this->task->id.'/remarks')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        $response = $this->actingAs($this->employee)
            ->postJson('/api/v1/tasks/'.$this->task->id.'/remarks', [
                'body' => 'Please review @Eli Employee',
                'mentioned_user_ids' => [$this->employee->id, $this->employee->id, $this->administrator->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Please review @Eli Employee')
            ->assertJsonPath('data.author.id', $this->employee->id);

        $mentionIds = collect($response->json('data.mentions'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$this->administrator->id, $this->employee->id], $mentionIds);

        $remarkId = (int) $response->json('data.id');
        $this->assertDatabaseCount('remark_mentions', 2);
        $this->assertDatabaseHas('remark_mentions', [
            'remark_id' => $remarkId,
            'user_id' => $this->employee->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityAction::RemarkCreated->value,
            'subject_type' => 'remark',
            'subject_id' => $remarkId,
        ]);
    }

    public function test_create_rejects_empty_body_and_unauthorized_mentions(): void
    {
        $this->actingAs($this->employee)
            ->postJson('/api/v1/projects/'.$this->project->id.'/remarks', [
                'body' => '   ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);

        $this->actingAs($this->employee)
            ->postJson('/api/v1/projects/'.$this->project->id.'/remarks', [
                'body' => 'Ping outsider',
                'mentioned_user_ids' => [$this->outsider->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mentioned_user_ids']);

        $this->assertDatabaseCount('remarks', 0);
    }

    public function test_author_can_update_and_synchronize_mentions(): void
    {
        $remark = $this->remarkService->create($this->project, [
            'body' => 'Original',
            'mentioned_user_ids' => [$this->employee->id, $this->administrator->id],
        ], $this->employee);

        $response = $this->actingAs($this->employee)
            ->putJson('/api/v1/remarks/'.$remark->id, [
                'body' => 'Updated body',
                'mentioned_user_ids' => [$this->administrator->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.body', 'Updated body')
            ->assertJsonCount(1, 'data.mentions')
            ->assertJsonPath('data.mentions.0.id', $this->administrator->id);

        $this->assertDatabaseMissing('remark_mentions', [
            'remark_id' => $remark->id,
            'user_id' => $this->employee->id,
        ]);
        $this->assertDatabaseHas('remark_mentions', [
            'remark_id' => $remark->id,
            'user_id' => $this->administrator->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityAction::RemarkUpdated->value,
            'subject_id' => $remark->id,
        ]);
    }

    public function test_administrator_can_update_and_delete_others_remarks(): void
    {
        $remark = $this->remarkService->create($this->project, [
            'body' => 'Employee note',
            'mentioned_user_ids' => [$this->employee->id],
        ], $this->employee);

        $this->actingAs($this->administrator)
            ->putJson('/api/v1/remarks/'.$remark->id, [
                'body' => 'Moderated note',
            ])
            ->assertOk()
            ->assertJsonPath('data.body', 'Moderated note');

        $this->actingAs($this->administrator)
            ->deleteJson('/api/v1/remarks/'.$remark->id)
            ->assertOk()
            ->assertJsonPath('message', 'Remark deleted successfully.');

        $this->assertSoftDeleted('remarks', ['id' => $remark->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityAction::RemarkDeleted->value,
            'subject_id' => $remark->id,
        ]);

        $this->actingAs($this->employee)
            ->getJson('/api/v1/projects/'.$this->project->id.'/remarks')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $mention = RemarkMention::query()->where('remark_id', $remark->id)->first();
        $this->assertNotNull($mention);
        $this->assertNull($mention->remark);
    }

    public function test_project_manager_cannot_update_or_delete_others_remarks(): void
    {
        $remark = $this->remarkService->create($this->project, [
            'body' => 'Employee owned',
        ], $this->employee);

        $this->actingAs($this->projectManager)
            ->putJson('/api/v1/remarks/'.$remark->id, [
                'body' => 'PM rewrite',
            ])
            ->assertForbidden();

        $this->actingAs($this->projectManager)
            ->deleteJson('/api/v1/remarks/'.$remark->id)
            ->assertForbidden();

        $this->assertDatabaseHas('remarks', [
            'id' => $remark->id,
            'body' => 'Employee owned',
            'deleted_at' => null,
        ]);
    }

    public function test_unauthorized_user_cannot_list_or_create_project_remarks(): void
    {
        $this->actingAs($this->outsider)
            ->getJson('/api/v1/projects/'.$this->project->id.'/remarks')
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->postJson('/api/v1/projects/'.$this->project->id.'/remarks', [
                'body' => 'Should fail',
            ])
            ->assertForbidden();
    }

    public function test_unrelated_user_cannot_update_or_delete_remark(): void
    {
        $remark = $this->remarkService->create($this->project, [
            'body' => 'Private-ish',
        ], $this->employee);

        $this->actingAs($this->outsider)
            ->putJson('/api/v1/remarks/'.$remark->id, [
                'body' => 'Hacked',
            ])
            ->assertForbidden();

        $this->actingAs($this->outsider)
            ->deleteJson('/api/v1/remarks/'.$remark->id)
            ->assertForbidden();
    }

    public function test_author_can_delete_own_remark(): void
    {
        $remark = $this->remarkService->create($this->task, [
            'body' => 'Temporary note',
        ], $this->employee);

        $this->actingAs($this->employee)
            ->deleteJson('/api/v1/remarks/'.$remark->id)
            ->assertOk();

        $this->assertSoftDeleted('remarks', ['id' => $remark->id]);
    }

    public function test_inactive_mentioned_user_is_rejected(): void
    {
        $inactive = User::factory()->create([
            'role_id' => Role::query()->where('name', RoleName::Employee)->value('id'),
            'status' => UserStatus::Inactive,
        ]);
        $this->project->members()->attach($inactive->id, ['joined_at' => now()]);

        $this->actingAs($this->administrator)
            ->postJson('/api/v1/projects/'.$this->project->id.'/remarks', [
                'body' => 'Mention inactive',
                'mentioned_user_ids' => [$inactive->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mentioned_user_ids']);
    }

    public function test_direction_desc_returns_newest_first(): void
    {
        $first = $this->remarkService->create($this->project, ['body' => 'First'], $this->administrator);
        $second = $this->remarkService->create($this->project, ['body' => 'Second'], $this->administrator);

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/projects/'.$this->project->id.'/remarks?direction=desc')
            ->assertOk();

        $this->assertSame([$second->id, $first->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_service_records_remark_activity_actions(): void
    {
        $remark = $this->remarkService->create($this->project, [
            'body' => 'Logged remark',
            'mentioned_user_ids' => [$this->employee->id],
        ], $this->administrator);

        $created = ActivityLog::query()->where('action', ActivityAction::RemarkCreated)->latest('id')->first();
        $this->assertNotNull($created);
        $this->assertSame($this->administrator->id, $created->actor_id);
        $this->assertSame([$this->employee->id], $created->properties['mentioned_user_ids']);
        $this->assertSame('project', $created->properties['remarkable_type']);

        $this->remarkService->update($remark, [
            'body' => 'Logged update',
            'mentioned_user_ids' => [],
        ], $this->administrator);

        $updated = ActivityLog::query()->where('action', ActivityAction::RemarkUpdated)->latest('id')->first();
        $this->assertNotNull($updated);
        $this->assertSame('Logged remark', $updated->properties['before']['body']);
        $this->assertSame('Logged update', $updated->properties['after']['body']);

        $this->remarkService->delete($remark->fresh(), $this->administrator);

        $deleted = ActivityLog::query()->where('action', ActivityAction::RemarkDeleted)->latest('id')->first();
        $this->assertNotNull($deleted);
        $this->assertSame('Logged update', $deleted->properties['body']);
    }
}
