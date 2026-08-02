<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Queries\Projects\ProjectQuery;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectListQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $adminRole = Role::query()->where('name', RoleName::Administrator)->firstOrFail();
        $this->actor = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Actor',
            'last_name' => 'User',
            'email' => 'actor@opsflow.test',
        ]);
        $this->otherOwner = User::factory()->create([
            'role_id' => $adminRole->id,
            'first_name' => 'Other',
            'last_name' => 'Owner',
            'email' => 'other.owner@opsflow.test',
        ]);
    }

    public function test_list_uses_default_pagination_and_sort(): void
    {
        Project::factory()->count(2)->create([
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', ProjectQuery::DEFAULT_PER_PAGE)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'description',
                        'status',
                        'owner',
                        'created_at',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                ],
            ]);

        $this->assertGreaterThanOrEqual(2, $response->json('meta.total'));
        $this->assertNull($response->json('errors'));
    }

    public function test_search_matches_name_and_description(): void
    {
        Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'UniqueAlpha Launch',
            'description' => 'Standard description',
        ]);
        Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Other Project',
            'description' => 'Contains UniqueBeta needle',
        ]);
        Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Unrelated',
            'description' => 'Nothing special',
        ]);

        $byName = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?search=UniqueAlpha');
        $byName->assertOk();
        $this->assertSame(1, $byName->json('meta.total'));
        $this->assertSame('UniqueAlpha Launch', $byName->json('data.0.name'));

        $byDescription = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?search=UniqueBeta');
        $byDescription->assertOk();
        $this->assertSame(1, $byDescription->json('meta.total'));
        $this->assertSame('Other Project', $byDescription->json('data.0.name'));

        $caseInsensitive = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?search=uniquealpha');
        $caseInsensitive->assertOk();
        $this->assertSame(1, $caseInsensitive->json('meta.total'));
    }

    public function test_filters_are_composable(): void
    {
        Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Match Target',
            'status' => ProjectStatus::Active,
        ]);
        Project::factory()->create([
            'created_by' => $this->otherOwner->id,
            'name' => 'Match Wrong Owner',
            'status' => ProjectStatus::Active,
        ]);
        Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Match Wrong Status',
            'status' => ProjectStatus::Planning,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?'.http_build_query([
                'search' => 'Match',
                'status' => ProjectStatus::Active->value,
                'created_by' => $this->actor->id,
            ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Match Target')
            ->assertJsonPath('data.0.status', ProjectStatus::Active->value)
            ->assertJsonPath('data.0.owner.id', $this->actor->id);
    }

    public function test_individual_filters_work(): void
    {
        Project::factory()->create([
            'created_by' => $this->actor->id,
            'status' => ProjectStatus::OnHold,
            'name' => 'On Hold Project',
        ]);
        Project::factory()->create([
            'created_by' => $this->otherOwner->id,
            'status' => ProjectStatus::Active,
            'name' => 'Other Active',
        ]);

        $byStatus = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?status='.ProjectStatus::OnHold->value);
        $byStatus->assertOk();
        $this->assertTrue(collect($byStatus->json('data'))->every(
            fn (array $project): bool => $project['status'] === ProjectStatus::OnHold->value
        ));
        $this->assertGreaterThanOrEqual(1, $byStatus->json('meta.total'));

        $byOwner = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?created_by='.$this->otherOwner->id);
        $byOwner->assertOk();
        $this->assertTrue(collect($byOwner->json('data'))->every(
            fn (array $project): bool => $project['owner']['id'] === $this->otherOwner->id
        ));
        $this->assertGreaterThanOrEqual(1, $byOwner->json('meta.total'));
    }

    public function test_sorting_by_allowed_fields(): void
    {
        Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Alpha Project',
            'created_at' => now()->subDays(2),
        ]);
        Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Zulu Project',
            'created_at' => now()->subDay(),
        ]);

        $asc = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?sort=name&direction=asc&per_page=100');
        $asc->assertOk();
        $names = collect($asc->json('data'))->pluck('name')->all();
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);

        $desc = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?sort=name&direction=desc&per_page=100');
        $desc->assertOk();
        $namesDesc = collect($desc->json('data'))->pluck('name')->all();
        $sortedDesc = $namesDesc;
        rsort($sortedDesc);
        $this->assertSame($sortedDesc, $namesDesc);
    }

    public function test_default_sort_is_created_at_desc(): void
    {
        $older = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Older Project',
            'created_at' => now()->subDays(5),
        ]);
        $newer = Project::factory()->create([
            'created_by' => $this->actor->id,
            'name' => 'Newer Project',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?per_page=100');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertTrue(
            array_search($newer->id, $ids, true) < array_search($older->id, $ids, true)
        );
    }

    public function test_pagination_respects_page_and_per_page(): void
    {
        Project::factory()->count(5)->create([
            'created_by' => $this->actor->id,
        ]);

        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?page=2&per_page=2');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.from', 3);

        $this->assertCount(2, $response->json('data'));
        $this->assertGreaterThanOrEqual(5, $response->json('meta.total'));
    }

    public function test_per_page_above_max_is_clamped_to_100(): void
    {
        $response = $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?per_page=250');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', ProjectQuery::MAX_PER_PAGE);
    }

    public function test_invalid_sort_and_status_return_validation_errors(): void
    {
        $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?sort=password')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['sort']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?status=unknown')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?direction=sideways')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direction']);

        $this->actingAs($this->actor)
            ->getJson('/api/v1/projects?created_by=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['created_by']);
    }

    public function test_guest_cannot_list_projects(): void
    {
        $this->getJson('/api/v1/projects')->assertUnauthorized();
    }
}
