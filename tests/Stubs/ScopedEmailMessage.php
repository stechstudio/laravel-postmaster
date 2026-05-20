<?php

namespace STS\EmailEvents\Tests\Stubs;

use Illuminate\Database\Eloquent\Builder;
use STS\EmailEvents\Models\EmailMessage;

/**
 * Stands in for a swapped-in model that carries a tenant global scope
 * (as stancl/tenancy and similar packages add). The scope hides every
 * row, so any query that fails to drop global scopes returns nothing.
 */
class ScopedEmailMessage extends EmailMessage
{
    protected $table = 'email_messages';

    protected static function booted(): void
    {
        static::addGlobalScope('hidden', function (Builder $query) {
            $query->whereRaw('1 = 0');
        });
    }
}
