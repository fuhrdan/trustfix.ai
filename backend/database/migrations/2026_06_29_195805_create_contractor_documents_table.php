<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contractor_documents')) {
            Schema::create('contractor_documents', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('document_type', 100);
                $table->string('original_filename');
                $table->string('stored_filename');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->date('expires_at')->nullable();

                $table->tinyInteger('verification_status')->default(0);

                $table->foreignId('verified_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('verified_at')->nullable();
                $table->text('notes')->nullable();

                $table->timestamps();

                $table->index('user_id');
                $table->index('document_type');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_documents');
    }
};