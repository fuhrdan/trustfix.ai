<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('partner_api_client_id')
                ->constrained('partner_api_clients')
                ->cascadeOnDelete();
            $table->foreignId('partner_member_account_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('job_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('external_request_id', 191);
            $table->timestamp('scheduled_for')->nullable();
            $table->string('scheduling_reference', 191)->nullable();
            $table->timestamp('last_status_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['partner_api_client_id', 'external_request_id'],
                'partner_service_request_external_unique'
            );
            $table->index('partner_member_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_service_requests');
    }
};
