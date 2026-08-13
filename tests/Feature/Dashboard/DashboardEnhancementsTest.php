<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityAction;
use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $employee;

    private User $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $employeeRole = Role::query()->where('name', RoleName::Employee)->firstOrFail();

        $this->administrator = User::factory()->create([
            'role_id' => $adminRole->id,
            'email' => 'admin.dash.enh@opsflow.test',
        ]);
        $this->employee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'employee.dash.enh@opsflow.test',
        ]);
        $this->otherEmployee = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'other.dash.enh@opsflow.test',
        ]);
    }

    public function test_dashboard_payload_includes_enhancement_keys(): void
    {
        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.projects.average_progress', null)
            ->assertJsonPath('data.tasks.due_soon', 0)
            ->assertJsonPath('data.due_soon', [])
            ->assertJsonPath('data.recent_activity', [])
            ->assertJsonPath('data.notifications.unread_count', 0)
            ->assertJsonPath('data.recent', []);
    }

    public function test_due_soon_includes_upcoming_and_excludes_invalid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));

        $project = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'status' => ProjectStatus::Active,
            'name' => 'Due Soon Project',
        ]);

        $includedToday = Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::High,
            'due_date' => '2026-08-12',
            'title' => 'Due Today',
        ]);
        $includedWeek = Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::Medium,
            'due_date' => '2026-08-19',
            'title' => 'Due In Seven Days',
        ]);

        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'due_date' => '2026-08-11',
            'title' => 'Already Overdue',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Completed,
            'due_date' => '2026-08-13',
            'title' => 'Completed Upcoming',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Cancelled,
            'due_date' => '2026-08-13',
            'title' => 'Cancelled Upcoming',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'due_date' => null,
            'title' => 'No Due Date',
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'due_date' => '2026-08-20',
            'title' => 'Outside Window',
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.tasks.due_soon', 2)
            ->assertJsonPath('data.tasks.overdue', 1);

        $dueSoon = $response->json('data.due_soon');
        $this->assertCount(2, $dueSoon);
        $this->assertSame($includedToday->id, $dueSoon[0]['id']);
        $this->assertSame('Due Today', $dueSoon[0]['title']);
        $this->assertSame($project->id, $dueSoon[0]['project']['id']);
        $this->assertSame('Due Soon Project', $dueSoon[0]['project']['name']);
        $this->assertFalse($dueSoon[0]['is_overdue']);
        $this->assertSame($includedWeek->id, $dueSoon[1]['id']);

        Carbon::setTestNow();
    }

    public function test_due_soon_is_capped_and_ordered_by_due_date_then_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));

        $project = Project::factory()->create([
            'created_by' => $this->administrator->id,
        ]);

        $created = [];
        foreach (range(1, 12) as $index) {
            $created[] = Task::factory()->create([
                'project_id' => $project->id,
                'created_by' => $this->administrator->id,
                'status' => TaskStatus::Todo,
                'due_date' => '2026-08-15',
                'title' => "Cap Task {$index}",
            ]);
        }

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.tasks.due_soon', 12);

        $dueSoon = $response->json('data.due_soon');
        $this->assertCount(DashboardService::DEFAULT_DUE_SOON_LIMIT, $dueSoon);

        $ids = array_column($dueSoon, 'id');
        $expected = collect($created)->take(DashboardService::DEFAULT_DUE_SOON_LIMIT)->pluck('id')->all();
        $this->assertSame($expected, $ids);

        Carbon::setTestNow();
    }

    public function test_employee_due_soon_is_scoped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'UTC'));

        $accessible = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Accessible',
        ]);
        $accessible->members()->attach($this->employee->id, ['joined_at' => now()]);

        $hidden = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Hidden',
        ]);

        Task::factory()->create([
            'project_id' => $accessible->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'due_date' => '2026-08-14',
            'title' => 'Visible Due Soon',
        ]);
        Task::factory()->create([
            'project_id' => $hidden->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
            'due_date' => '2026-08-14',
            'title' => 'Hidden Due Soon',
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.tasks.due_soon', 1);

        $titles = collect($response->json('data.due_soon'))->pluck('title')->all();
        $this->assertSame(['Visible Due Soon'], $titles);

        Carbon::setTestNow();
    }

    public function test_average_progress_ignores_null_and_averages_numeric(): void
    {
        $full = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Full',
        ]);
        Task::factory()->create([
            'project_id' => $full->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Completed,
        ]);

        $half = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Half',
        ]);
        Task::factory()->create([
            'project_id' => $half->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Completed,
        ]);
        Task::factory()->create([
            'project_id' => $half->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
        ]);

        // No eligible tasks → null progress; must not pull average to 50.
        Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Empty',
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.projects.average_progress', 75);
    }

    public function test_average_progress_includes_zero_and_null_when_none(): void
    {
        $zero = Project::factory()->create([
            'created_by' => $this->administrator->id,
        ]);
        Task::factory()->create([
            'project_id' => $zero->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.projects.average_progress', 0);

        Project::query()->delete();
        Task::query()->delete();

        $empty = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $empty->assertOk()
            ->assertJsonPath('data.projects.average_progress', null);
    }

    public function test_employee_average_progress_is_scoped(): void
    {
        $accessible = Project::factory()->create([
            'created_by' => $this->administrator->id,
        ]);
        $accessible->members()->attach($this->employee->id, ['joined_at' => now()]);
        Task::factory()->create([
            'project_id' => $accessible->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Completed,
        ]);

        $hidden = Project::factory()->create([
            'created_by' => $this->administrator->id,
        ]);
        Task::factory()->create([
            'project_id' => $hidden->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
        ]);
        Task::factory()->create([
            'project_id' => $hidden->id,
            'created_by' => $this->administrator->id,
            'status' => TaskStatus::Todo,
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/dashboard');

        // Only accessible project (100%) — hidden 0% must not leak into average.
        $response->assertOk()
            ->assertJsonPath('data.projects.average_progress', 100);
    }

    public function test_recent_activity_is_capped_and_activity_limit_clamped(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->administrator->id,
        ]);

        foreach (range(1, 12) as $index) {
            ActivityLog::factory()->create([
                'actor_id' => $this->administrator->id,
                'action' => ActivityAction::ProjectUpdated,
                'subject_type' => $project->getMorphClass(),
                'subject_id' => $project->id,
                'description' => "Update {$index}",
                'created_at' => now()->subMinutes(12 - $index),
            ]);
        }

        $default = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');
        $default->assertOk();
        $this->assertCount(DashboardService::DEFAULT_ACTIVITY_LIMIT, $default->json('data.recent_activity'));

        $clamped = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard?activity_limit=100');
        $clamped->assertOk();
        $this->assertCount(12, $clamped->json('data.recent_activity'));
        $this->assertLessThanOrEqual(
            DashboardService::MAX_ACTIVITY_LIMIT,
            count($clamped->json('data.recent_activity')),
        );
    }

    public function test_employee_cannot_see_unrelated_recent_activity(): void
    {
        $accessible = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Accessible Project',
        ]);
        $accessible->members()->attach($this->employee->id, ['joined_at' => now()]);

        $hidden = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Hidden Project',
        ]);

        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => $accessible->getMorphClass(),
            'subject_id' => $accessible->id,
            'description' => 'Created accessible project.',
            'created_at' => now()->subHour(),
        ]);
        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => $hidden->getMorphClass(),
            'subject_id' => $hidden->id,
            'description' => 'Created hidden project.',
            'created_at' => now()->subMinutes(30),
        ]);
        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::UserCreated,
            'subject_type' => $this->otherEmployee->getMorphClass(),
            'subject_id' => $this->otherEmployee->id,
            'description' => 'Created other employee.',
            'created_at' => now()->subMinutes(10),
        ]);
        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::UserUpdated,
            'subject_type' => $this->employee->getMorphClass(),
            'subject_id' => $this->employee->id,
            'description' => 'Updated employee.',
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/dashboard');

        $response->assertOk();

        $descriptions = collect($response->json('data.recent_activity'))->pluck('description')->all();
        $this->assertContains('Created accessible project.', $descriptions);
        $this->assertContains('Updated employee.', $descriptions);
        $this->assertNotContains('Created hidden project.', $descriptions);
        $this->assertNotContains('Created other employee.', $descriptions);
    }

    public function test_unread_notifications_are_self_only(): void
    {
        Notification::factory()->count(3)->create([
            'recipient_id' => $this->employee->id,
        ]);
        Notification::factory()->read()->create([
            'recipient_id' => $this->employee->id,
        ]);
        Notification::factory()->count(5)->create([
            'recipient_id' => $this->otherEmployee->id,
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.notifications.unread_count', 3);

        $admin = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $admin->assertOk()
            ->assertJsonPath('data.notifications.unread_count', 0);
    }

    public function test_existing_recent_work_remains_alongside_recent_activity(): void
    {
        $project = Project::factory()->create([
            'created_by' => $this->administrator->id,
            'name' => 'Recent Project',
            'updated_at' => now(),
        ]);
        $project->forceFill(['updated_at' => now()])->saveQuietly();

        ActivityLog::factory()->create([
            'actor_id' => $this->administrator->id,
            'action' => ActivityAction::ProjectCreated,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
            'description' => 'Created recent project.',
        ]);

        $response = $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.recent'));
        $this->assertNotEmpty($response->json('data.recent_activity'));
        $this->assertSame('project', $response->json('data.recent.0.type'));
        $this->assertSame('project.created', $response->json('data.recent_activity.0.action'));
    }

    public function test_invalid_activity_limit_returns_validation_error(): void
    {
        $this->actingAs($this->administrator)
            ->getJson('/api/v1/dashboard?activity_limit=abc')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['activity_limit']);
    }
}
