<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DemoOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
