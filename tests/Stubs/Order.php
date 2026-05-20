<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Concerns\HasEmailMessages;

class Order extends Model
{
    use HasEmailMessages;

    protected $guarded = [];

    public $timestamps = false;
}
