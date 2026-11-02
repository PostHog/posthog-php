<?php

namespace PostHog\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class CollectedRecord extends Model
{
    protected $table = 'collected_records';
    protected $guarded = [];
    public $timestamps = false;
}
