<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
