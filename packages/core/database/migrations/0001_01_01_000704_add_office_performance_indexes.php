<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Vaulter\Ai\Core\Schema\OfficeCoreSchema;

return new class extends Migration
{
    public function up(): void
    {
        app(OfficeCoreSchema::class)->ensurePerformanceIndexes();
    }

    public function down(): void
    {
        app(OfficeCoreSchema::class)->dropPerformanceIndexes();
    }
};
