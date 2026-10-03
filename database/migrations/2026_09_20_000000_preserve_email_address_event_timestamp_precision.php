<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('postmaster.persistence.connection'))
            ->table(config('postmaster.persistence.addresses_table', 'email_addresses'), function (Blueprint $table) {
                $table->timestamp('last_event_at', 6)->nullable()->change();
                $table->timestamp('suppressed_at', 6)->nullable()->change();
            });
    }

    public function down(): void
    {
        // Keep the precision of observed events when rolling back code.
    }
};
