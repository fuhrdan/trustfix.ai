<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable()->index();
            $table->string('ip_address', 45)->index();
            $table->text('user_agent')->nullable();
            $table->boolean('successful')->default(false)->index();
            $table->string('outcome', 40)->index();
            $table->string('risk_level', 20)->default('normal')->index();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->unsignedSmallInteger('recent_ip_failures')->default(0);
            $table->unsignedSmallInteger('targeted_accounts')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['ip_address', 'created_at']);
            $table->index(['successful', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
