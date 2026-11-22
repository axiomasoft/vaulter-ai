<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User;

final class StandActor extends User
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
