<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_api_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 191);
            $table->string('slug', 64)->unique();
            $table->string('token_hint', 32);
            $table->string('api_token_hash', 255);
            $table->json('scopes');
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('token_hint');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_api_clients');
    }
};
