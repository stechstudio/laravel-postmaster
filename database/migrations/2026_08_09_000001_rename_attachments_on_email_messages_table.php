<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical. Attachments used to be a JSON array of filenames on this table;
 * they now live in email_attachments, and the column that held them is dropped
 * by the migration that follows this one.
 *
 * This step survives only so an install that already ran it can still roll
 * back, and so an install upgrading from before the attachments table lands on
 * the same column name the drop looks for. Fresh installs never create the
 * column, so both directions are guarded and do nothing.
 */
return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.messages_table', 'email_messages');
    }

    public function up(): void
    {
        if (! Schema::hasColumn($this->table(), 'attachments')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table) {
            $table->renameColumn('attachments', 'legacy_attachment_names');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn($this->table(), 'legacy_attachment_names')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table) {
            $table->renameColumn('legacy_attachment_names', 'attachments');
        });
    }
};
