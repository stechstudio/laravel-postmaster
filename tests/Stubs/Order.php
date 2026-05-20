<?php

namespace STS\EmailEvents\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use STS\EmailEvents\Concerns\HasEmailMessages;

class Order extends Model
{
    use HasEmailMessages;

    protected $guarded = [];

    public $timestamps = false;
}
