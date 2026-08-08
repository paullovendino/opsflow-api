<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Project;
use App\Models\Remark;
use App\Models\RemarkMention;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemarkModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    public function test_remark_belongs_to_author_and_morphs_to_project(): void
    {
        $author = User::factory()->create();
        $project = Project::factory()->create(['created_by' => $author->id]);

        $remark = Remark::factory()->create([
            'author_id' => $author->id,
            'remarkable_type' => $project->getMorphClass(),
            'remarkable_id' => $project->id,
            'body' => 'Kickoff notes',
        ]);

        $this->assertTrue($remark->author->is($author));
        $this->assertSame('project', $remark->remarkable_type);
        $this->assertTrue($remark->remarkable->is($project));
        $this->assertSame('Kickoff notes', $remark->body);
    }

    public function test_remark_morphs_to_task(): void
    {
        $author = User::factory()->create();
        $project = Project::factory()->create(['created_by' => $author->id]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'created_by' => $author->id,
        ]);

        $remark = Remark::factory()->create([
            'author_id' => $author->id,
            'remarkable_type' => $task->getMorphClass(),
            'remarkable_id' => $task->id,
        ]);

        $this->assertTrue($remark->remarkable->is($task));
        $this->assertSame('task', $remark->remarkable_type);
    }

    public function test_remark_has_many_mentions(): void
    {
        $author = User::factory()->create();
        $mentioned = User::factory()->create();
        $project = Project::factory()->create(['created_by' => $author->id]);

        $remark = Remark::factory()->create([
            'author_id' => $author->id,
            'remarkable_type' => $project->getMorphClass(),
            'remarkable_id' => $project->id,
        ]);

        $mention = RemarkMention::factory()->create([
            'remark_id' => $remark->id,
            'user_id' => $mentioned->id,
        ]);

        $this->assertTrue($remark->mentions->contains($mention));
        $this->assertTrue($mention->remark->is($remark));
        $this->assertTrue($mention->user->is($mentioned));
        $this->assertNull($mention->updated_at);
        $this->assertNotNull($mention->created_at);
    }

    public function test_remark_uses_soft_deletes(): void
    {
        $author = User::factory()->create();
        $project = Project::factory()->create(['created_by' => $author->id]);

        $remark = Remark::factory()->create([
            'author_id' => $author->id,
            'remarkable_type' => $project->getMorphClass(),
            'remarkable_id' => $project->id,
        ]);

        $remark->delete();

        $this->assertSoftDeleted('remarks', ['id' => $remark->id]);
        $this->assertNull(Remark::query()->find($remark->id));
        $this->assertNotNull(Remark::withTrashed()->find($remark->id));
    }
}
