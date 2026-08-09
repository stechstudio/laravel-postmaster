<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.attachments_table', 'email_attachments');
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            // Grouping key. One outbound submission writes one email_messages
            // row per envelope recipient, all sharing this id — so a single
            // attachment set serves every one of them.
            $table->string('provider_message_id')->index();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            // Byte length, recorded even when the bytes themselves aren't.
            $table->unsignedBigInteger('size')->default(0);
            // sha256 of the contents: the dedup key on write and the
            // reference-count key on delete.
            $table->string('checksum', 64)->index();
            // Symfony's own vocabulary: 'attachment' or 'inline'.
            $table->string('disposition', 16)->default('attachment');
            // CID for inline parts, so re-embedding on resend is faithful.
            $table->string('content_id')->nullable();
            // Recorded per row so changing the configured disk later doesn't
            // orphan files written under the old one.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('status')->index();
            $table->timestamp('stored_at')->nullable();
            $table->timestamps();
            // Drives both the retention window and eviction ordering.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
