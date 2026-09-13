<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('postmaster.persistence.connection'));
        $schema->table(config('postmaster.persistence.messages_table', 'email_messages'), function (Blueprint $table) {
            $table->timestamp('last_event_at', 6)->nullable()->change();
        });
        $schema->table(config('postmaster.persistence.activity_table', 'email_activity'), function (Blueprint $table) {
            $table->timestamp('occurred_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve observed precision when rolling back code.
    }
};
