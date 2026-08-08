<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme_preference', 16)->default('system');
            $table->boolean('notify_task_assigned')->default(true);
            $table->boolean('notify_task_status')->default(true);
            $table->boolean('notify_remarks')->default(true);
            $table->boolean('notify_mentions')->default(true);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('type', 64);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipient_id', 'read_at', 'created_at'], 'notifications_recipient_read_created_index');
            $table->index(['recipient_id', 'created_at'], 'notifications_recipient_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'theme_preference',
                'notify_task_assigned',
                'notify_task_status',
                'notify_remarks',
                'notify_mentions',
            ]);
        });
    }
};
