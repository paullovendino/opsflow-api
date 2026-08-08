<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('action', 64);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('description', 255);
            $table->jsonb('properties')->default('{}');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_logs_subject_created_index');
            $table->index(['actor_id', 'created_at'], 'activity_logs_actor_created_index');
            $table->index(['action', 'created_at'], 'activity_logs_action_created_index');
            $table->index('created_at', 'activity_logs_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
