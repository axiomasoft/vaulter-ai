<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
        });
        Schema::create('ai_test_owners', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_test_owners');
        Schema::dropIfExists('users');
    }
};
