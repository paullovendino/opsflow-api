<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    public function test_notification_belongs_to_recipient_and_actor(): void
    {
        $recipient = User::factory()->create(['first_name' => 'Eli', 'last_name' => 'Employee']);
        $actor = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Admin']);
        $task = Task::factory()->create(['created_by' => $actor->id]);

        $notification = Notification::factory()->create([
            'recipient_id' => $recipient->id,
            'actor_id' => $actor->id,
            'type' => NotificationType::TaskAssigned,
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->id,
        ]);

        $this->assertTrue($notification->recipient->is($recipient));
        $this->assertTrue($notification->actor->is($actor));
        $this->assertTrue($notification->subject->is($task));
        $this->assertTrue($recipient->receivedNotifications->contains($notification));
        $this->assertNull($notification->updated_at);
        $this->assertTrue($notification->isUnread());
        $this->assertFalse($notification->isRead());
    }

    public function test_unread_and_read_scopes(): void
    {
        $recipient = User::factory()->create();

        $unread = Notification::factory()->create([
            'recipient_id' => $recipient->id,
            'read_at' => null,
        ]);
        $read = Notification::factory()->read()->create([
            'recipient_id' => $recipient->id,
        ]);

        $this->assertTrue(Notification::query()->unread()->get()->contains($unread));
        $this->assertFalse(Notification::query()->unread()->get()->contains($read));
        $this->assertTrue(Notification::query()->read()->get()->contains($read));
        $this->assertTrue(Notification::query()->forUser($recipient)->get()->contains($unread));
    }
}
