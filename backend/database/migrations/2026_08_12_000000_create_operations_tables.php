<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 80);
            $table->string('status', 20)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('summary')->nullable();
            $table->json('details')->nullable();
            $table->string('artifact_disk', 80)->nullable();
            $table->string('artifact_path', 1024)->nullable();
            $table->unsignedBigInteger('artifact_size_bytes')->nullable();
            $table->char('artifact_sha256', 64)->nullable();
            $table->timestamps();

            $table->index(['operation', 'started_at']);
            $table->index(['operation', 'status']);
        });

        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->string('target_key', 64);
            $table->string('target_name', 120);
            $table->string('target_url', 2048);
            $table->string('status', 20);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->boolean('alert_sent')->default(false);
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['target_key', 'checked_at']);
            $table->index(['status', 'checked_at']);
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_uuid');
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 180);
            $table->string('resource_type', 100)->nullable();
            $table->string('resource_id', 100)->nullable();
            $table->string('http_method', 10);
            $table->string('route_path', 512);
            $table->string('route_name', 180)->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('summary', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('request_uuid');
        });

        Schema::create('support_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 30)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->string('category', 40);
            $table->string('severity', 20)->default('normal');
            $table->string('status', 30)->default('open');
            $table->string('subject', 180);
            $table->text('description');
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('escalation_level')->default(1);
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('response_overdue_alerted_at')->nullable();
            $table->timestamp('resolution_overdue_alerted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'severity']);
            $table->index(['escalation_level', 'status']);
            $table->index('first_response_due_at');
            $table->index('resolution_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_cases');
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('uptime_checks');
        Schema::dropIfExists('operation_runs');
    }
};
