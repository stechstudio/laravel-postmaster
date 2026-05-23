<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function table(): string
    {
        return config('postmaster.persistence.addresses_table', 'email_addresses');
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            // The recipient address, normalized to lower case. Suppression is
            // tracked globally — one row per address, never per tenant — to
            // mirror how providers maintain their own suppression lists.
            $table->string('address')->unique();
            $table->string('status')->default('active')->index();
            // Why the address is suppressed: bounced, complained, dropped, or
            // manual. Null while the address is active.
            $table->string('reason')->nullable();
            // Provider names that have touched this address — typically
            // either via a delivery webhook (bounce / complaint / drop)
            // or via a suppression-list sync. Used by the dashboard to
            // decide whether an API-driven Unsuppress is even possible.
            $table->json('providers')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
