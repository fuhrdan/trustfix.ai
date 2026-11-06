<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('issuer', 191);
            $table->char('issuer_hash', 64);
            $table->string('subject', 255);
            $table->char('subject_hash', 64);
            $table->string('last_seen_email', 191)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            // Hashes keep the uniqueness index compatible with older MySQL
            // limits while the original issuer and subject remain available
            // for an exact value comparison in application code.
            $table->unique(['issuer_hash', 'subject_hash'], 'oidc_identity_subject_unique');
            $table->unique(['user_id', 'provider'], 'oidc_identity_user_provider_unique');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_identities');
    }
};
