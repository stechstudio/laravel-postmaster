<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function table(): string
    {
        return config('email-events.persistence.table', 'email_messages');
    }

    protected function tenantColumn(): string
    {
        return config('email-events.persistence.tenant_column', 'tenant_id');
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('message_id')->nullable()->index();
            $table->string('recipient')->nullable()->index();
            $table->string('subject')->nullable();
            // Optional polymorphic link to one of the consuming app's models
            // (an Order, User, etc.), set via a Mailable's relatedTo() call.
            // Apps using UUID/ULID primary keys should change related_id to
            // match (e.g. $table->nullableUuidMorphs('related')).
            $table->nullableMorphs('related');
            // Optional owning tenant, for multitenant apps. Apps with
            // UUID/ULID tenant keys should change this column type to match.
            $table->unsignedBigInteger($this->tenantColumn())->nullable()->index();
            $table->string('status')->nullable();
            $table->string('bounce_type')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
