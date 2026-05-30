<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.messages_table', 'email_messages');
    }

    protected function tenantColumn(): string
    {
        return config('postmaster.persistence.tenant_column', 'tenant_id');
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('to_address')->nullable()->index();
            // Which envelope role this row represents: 'to', 'cc', or 'bcc'.
            // One row per envelope recipient — providers fire webhooks per
            // address, so each gets its own delivery state.
            $table->string('recipient_role', 3)->nullable();
            $table->string('subject')->nullable();
            // Full message representation, captured at send time only when
            // persistence.store_content is enabled, and purged again by the
            // postmaster:prune-content command per prune_content_after_days.
            $table->string('from_address')->nullable();
            $table->json('recipients')->nullable();
            $table->longText('html_body')->nullable();
            $table->longText('text_body')->nullable();
            $table->json('attachments')->nullable();
            // Optional polymorphic link to one of the consuming app's models
            // (an Order, User, etc.), set via a Mailable's relatedTo() call.
            // Apps using UUID/ULID primary keys should change related_id to
            // match (e.g. $table->nullableUuidMorphs('related')).
            $table->nullableMorphs('related');
            // Optional polymorphic link to the *person* the email is for —
            // separate from `related` so an email about an Order can still
            // answer "every email this User has received." Set via a
            // Mailable's Tracking(recipient: ...) or a global resolver.
            $table->nullableMorphs('recipient');
            // Optional owning tenant, for multitenant apps. Apps with
            // UUID/ULID tenant keys should change this column type to match.
            $table->unsignedBigInteger($this->tenantColumn())->nullable()->index();
            // Free-form labels set per email via a Mailable's Tracking, for
            // categorising and filtering recorded mail ("billing", etc.).
            $table->json('tags')->nullable();
            $table->string('status')->nullable()->index();
            $table->string('bounce_type')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
            // Sorted by recency in every dashboard view.
            $table->index('created_at');
            // Webhooks correlate to a recorded row by (provider id + address)
            // because one outbound submission produces one row per envelope
            // recipient — all sharing a provider id, distinguished by address.
            $table->index(['provider_message_id', 'to_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
