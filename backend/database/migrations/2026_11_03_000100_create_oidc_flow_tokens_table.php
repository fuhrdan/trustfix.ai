<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_flow_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('purpose', 32);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 64)->nullable();
            $table->string('issuer', 191)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('name', 191)->nullable();
            $table->boolean('email_verified')->default(false);
            $table->text('claims')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'expires_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_flow_tokens');
    }
};
