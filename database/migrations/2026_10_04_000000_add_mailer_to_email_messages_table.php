<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the `mailer` column: the name of the Laravel mailer that sent the
 * message, such as "smtp" or "bulk". The dashboard's configuration page reads
 * it to show which mailers are in use and whether their providers report
 * back. Nullable, because rows written before this migration don't know.
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
            $table->string('mailer')->nullable()->after('provider');
            $table->index(['mailer', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropIndex(['mailer', 'created_at']);
            $table->dropColumn('mailer');
        });
    }
};
