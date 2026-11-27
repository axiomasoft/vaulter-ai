<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return app(OfficeDatabase::class)->connection()->getNameWithReadWriteType();
    }

    public function up(): void
    {
        $db = app(OfficeDatabase::class);
        // Existing rejected runs keep their state unless a chat cancellation was requested.
        $db->connection()->getSchemaBuilder()->table($db->table('ai_runs'), static function (Blueprint $table): void {
            $table->boolean('cancel_requested')->default(false);
        });
    }

    public function down(): void
    {
        $db = app(OfficeDatabase::class);
        $schema = $db->connection()->getSchemaBuilder();
        $runs = $db->table('ai_runs');
        if (! $schema->hasTable($runs) || ! $schema->hasColumn($runs, 'cancel_requested')) {
            return;
        }

        $schema->table($runs, static function (Blueprint $table): void {
            $table->dropColumn('cancel_requested');
        });
    }
};
