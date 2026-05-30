<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the `resent_from_id` foreign key column so a resent message points
 * back to its original. Enables the dashboard's chain card and the
 * EmailMessage::resentFrom() / resends() relationships.
 *
 * Nullable — existing rows pre-dating this migration carry null, and a
 * fresh send (never a resend) carries null too. Foreign key uses
 * ON DELETE SET NULL so pruning an old original doesn't cascade through
 * the chain.
 */
return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.messages_table', 'email_messages');
    }

    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->foreignId('resent_from_id')
                ->nullable()
                ->after('provider_message_id')
                ->constrained($this->table())
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropConstrainedForeignId('resent_from_id');
        });
    }
};
