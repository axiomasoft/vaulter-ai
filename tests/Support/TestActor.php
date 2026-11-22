<?php

declare(strict_types=1);

namespace Vaulter\Ai\Tests\Support;

use Illuminate\Foundation\Auth\User;

/**
 * Lightweight Authenticatable/Authorizable actor fixture for Drive and Office SDK tests.
 */
final class TestActor extends User
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
