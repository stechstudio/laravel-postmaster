<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the sender's display name and the Reply-To addresses, so a record says
 * who the email claimed to be from and where a reply would go.
 *
 * Both nullable — rows pre-dating this migration carry null, and most email
 * sets no Reply-To at all.
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
            $table->string('from_name')->nullable()->after('from_address');
            $table->json('reply_to')->nullable()->after('from_name');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['from_name', 'reply_to']);
        });
    }
};
