<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add actor attribution to the activity ledger so operator-initiated entries
 * (manual suppress / unsuppress from a dashboard, an artisan command, etc.)
 * can answer "who did this, and where did it come from?"
 *
 *   - causer_type / causer_id: a nullable polymorphic pointer, resolved
 *     through Laravel's morph map so it stores 'user' rather than the
 *     consumer's FQCN. Apps that key users by ULID/UUID should change
 *     causer_id's column type to match.
 *   - source: a free-form label for actors that aren't models — the sync
 *     command, a webhook, an artisan call — and a fallback when causer_*
 *     lives on a different DB connection than the consumer's users table
 *     and the morph relation can't be hydrated.
 *
 * Existing rows pre-dating this migration carry null for all three; old
 * automatic entries written by the webhook listener never had a "who" to
 * attribute anyway.
 */
return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.activity_table', 'email_activity');
    }

    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->string('causer_type')->nullable()->after('email_address_id');
            $table->unsignedBigInteger('causer_id')->nullable()->after('causer_type');
            $table->index(['causer_type', 'causer_id']);

            $table->string('source')->nullable()->after('causer_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropIndex(['causer_type', 'causer_id']);
            $table->dropColumn(['causer_type', 'causer_id', 'source']);
        });
    }
};
