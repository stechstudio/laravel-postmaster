<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in tenant model for the dashboard preview. Its "name" is what the
 * dashboard surfaces in place of the raw tenant key.
 */
class Tenant extends Model
{
    protected $guarded = [];
}
