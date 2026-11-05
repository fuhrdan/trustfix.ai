<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_member_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('partner_api_client_id')
                ->constrained('partner_api_clients')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_member_id', 191);
            $table->string('membership_status', 32)->default('active');
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['partner_api_client_id', 'external_member_id'],
                'partner_member_external_unique'
            );
            $table->index('user_id');
            $table->index('membership_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_member_accounts');
    }
};
