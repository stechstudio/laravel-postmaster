<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frees the `attachments` name for the email_attachments relation. Eloquent
 * resolves attributes before relations, so a column of that name would make
 * $message->attachments permanently unreachable as a HasMany.
 *
 * The old column keeps its data for pre-upgrade rows, read through
 * EmailMessage::legacyAttachmentNames().
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
            $table->renameColumn('attachments', 'legacy_attachment_names');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->renameColumn('legacy_attachment_names', 'attachments');
        });
    }
};
