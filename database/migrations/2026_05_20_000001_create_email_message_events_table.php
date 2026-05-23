<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.events_table', 'email_message_events');
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            // Links back to the email_messages row. Apps that changed
            // email_messages to a UUID/ULID primary key should change this
            // column type to match.
            $table->unsignedBigInteger('email_message_id')->index();
            $table->string('provider')->nullable();
            $table->string('status')->nullable();
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
