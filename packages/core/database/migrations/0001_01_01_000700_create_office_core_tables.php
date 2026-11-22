<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Schema\OfficeCoreSchema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Container::getInstance()->make(OfficeDatabase::class)->connection()->getName();
    }

    public function up(): void
    {
        Container::getInstance()->make(OfficeCoreSchema::class)->createAll();
    }

    public function down(): void
    {
        Container::getInstance()->make(OfficeCoreSchema::class)->dropAll();
    }
};
