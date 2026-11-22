<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Vaulter\Ai\Documents\Schema\AiDocumentsSchema;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        Container::getInstance()->make(AiDocumentsSchema::class)->createAll();
    }

    public function down(): void
    {
        Container::getInstance()->make(AiDocumentsSchema::class)->dropAll();
    }
};
