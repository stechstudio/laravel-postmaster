<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.activity_table', 'email_activity');
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            // The row this activity is about. Either FK can be present:
            //   - email_message_id only  → a message lifecycle event
            //     (sent, delivered, bounced, opened, …) with no separately
            //     addressed audit value.
            //   - email_address_id only → an address-level action
            //     (manually suppressed, unsuppressed, added by sync, …)
            //     with no specific message attached.
            //   - both                  → an event that happened *to* a
            //     specific message *and* is an event in the life of the
            //     recipient address (the most common shape — a bounce or
            //     complaint).
            // Apps that changed email_messages or email_addresses to a
            // UUID/ULID primary key should change these column types to
            // match.
            $table->unsignedBigInteger('email_message_id')->nullable()->index();
            $table->unsignedBigInteger('email_address_id')->nullable()->index();
            $table->string('provider')->nullable();
            $table->string('status')->nullable()->index();
            $table->string('bounce_type')->nullable();
            $table->text('response')->nullable();
            $table->text('reason')->nullable();
            $table->string('code')->nullable();
            // Clicked URL (click events only); the adapter pulls this from
            // the provider's webhook payload when present.
            $table->text('url')->nullable();
            // When the event happened, per the provider (falls back to the
            // time the event was recorded).
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
