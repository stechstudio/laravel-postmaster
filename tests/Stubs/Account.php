<?php

namespace STS\Postmaster\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in tenant model whose class name is not "Tenant" — used to prove
 * the dashboard derives its tenant term from the model name.
 */
class Account extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
