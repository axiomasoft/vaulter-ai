<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
            });
        }

        if (! Schema::hasTable('stand_owners')) {
            Schema::create('stand_owners', static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', static function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', static function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('stand_tokens')) {
            Schema::create('stand_tokens', static function (Blueprint $table): void {
                $table->string('token_hash', 64)->primary();
                $table->string('tenant_id', 64);
                $table->string('project_scope', 64);
                $table->unsignedBigInteger('user_id')->index();
                $table->string('drive_id', 64);
                $table->string('profile', 64);
                $table->string('policy_revision', 64);
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('stand_run_contexts')) {
            Schema::create('stand_run_contexts', static function (Blueprint $table): void {
                $table->string('run_id', 64)->primary();
                $table->string('tenant_id', 64);
                $table->string('project_scope', 64);
                $table->unsignedBigInteger('user_id')->index();
                $table->string('drive_id', 64);
                $table->string('resource_id', 64);
                $table->string('profile', 64);
                $table->string('policy_revision', 64);
                $table->string('fault_mode', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('stand_channel_sessions')) {
            Schema::create('stand_channel_sessions', static function (Blueprint $table): void {
                $table->string('tenant_id', 64);
                $table->string('project_scope', 64);
                $table->string('session_id', 128);
                $table->string('effective_subject', 191);
                $table->unsignedBigInteger('user_id')->index();
                $table->string('drive_id', 64);
                $table->timestamp('created_at')->useCurrent();
                $table->primary(['tenant_id', 'project_scope', 'session_id']);
            });
        }

        if (! Schema::hasTable('stand_channel_inbox')) {
            Schema::create('stand_channel_inbox', static function (Blueprint $table): void {
                $table->string('tenant_id', 64);
                $table->string('project_scope', 64);
                $table->string('effective_subject', 191);
                $table->string('channel', 64);
                $table->string('session_id', 128);
                $table->string('event_id', 128);
                $table->string('phase', 16);
                $table->string('resource_id', 64);
                $table->string('run_id', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(
                    ['tenant_id', 'project_scope', 'effective_subject', 'channel', 'event_id'],
                    'stand_channel_inbox_event',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stand_channel_inbox');
        Schema::dropIfExists('stand_channel_sessions');
        Schema::dropIfExists('stand_run_contexts');
        Schema::dropIfExists('stand_tokens');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('stand_owners');
        Schema::dropIfExists('users');
    }
};
