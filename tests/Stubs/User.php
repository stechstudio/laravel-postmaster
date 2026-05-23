<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use STS\Postmaster\Concerns\IsEmailRecipient;

/**
 * A stand-in user model — the kind of model an app would mark as an email
 * recipient via the recipient_model_* link.
 */
class User extends Model
{
    use IsEmailRecipient;

    protected $guarded = [];

    public $timestamps = false;
}
