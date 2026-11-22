<?php

declare(strict_types=1);

namespace Vaulter\Ai\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Lightweight Drive owner model fixture for multi-project/multi-tenant Drive isolation tests.
 */
final class TestOwner extends Model
{
    protected $table = 'ai_test_owners';

    protected $guarded = [];

    public $timestamps = false;
}
