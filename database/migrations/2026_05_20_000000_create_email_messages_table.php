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

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            $table->string('provider')->nullable();
            $table->string('message_id')->nullable()->index();
            $table->string('recipient')->nullable()->index();
            $table->string('subject')->nullable();
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
