<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments live in email_attachments now, and only there.
 *
 * Before that table existed this one carried a JSON array of filenames — names
 * only, never the files. Keeping it meant every message had two places to look
 * for what it carried, and every fresh install created a column that could
 * never hold anything.
 *
 * Guarded rather than unconditional because the column reaches this point
 * under either name: `legacy_attachment_names` for an install that ran the
 * rename, `attachments` for one that never had it renamed. A fresh install has
 * neither, and this does nothing.
 *
 * Irreversible by design — down() would have to invent the filenames back.
 */
return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.messages_table', 'email_messages');
    }

    public function up(): void
    {
        foreach (['legacy_attachment_names', 'attachments'] as $column) {
            if (! Schema::hasColumn($this->table(), $column)) {
                continue;
            }

            Schema::table($this->table(), function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        //
    }
};
