<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns the dashboard filters and sorts on most. The table
 * grows roughly one row per send (messages) and one per webhook (events), so
 * these keep status filtering and date-range queries fast at volume.
 */
return new class extends Migration
{
    protected function messages(): string
    {
        return config('postmaster.persistence.table', 'email_messages');
    }

    protected function events(): string
    {
        return config('postmaster.persistence.events_table', 'email_message_events');
    }

    public function up(): void
    {
        Schema::table($this->messages(), function (Blueprint $table) {
            $table->index('status');
            $table->index('created_at');
        });

        Schema::table($this->events(), function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table($this->messages(), function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
        });

        Schema::table($this->events(), function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
