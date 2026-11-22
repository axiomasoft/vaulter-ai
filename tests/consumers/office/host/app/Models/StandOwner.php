<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StandOwner extends Model
{
    protected $table = 'stand_owners';

    protected $guarded = [];

    public $timestamps = false;
}
