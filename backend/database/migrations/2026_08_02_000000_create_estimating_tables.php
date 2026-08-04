<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_pricing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 255)->default('TrustFix pricing');
            $table->decimal('hourly_wage', 10, 2)->default(35);
            $table->decimal('payroll_burden_percent', 6, 2)->default(20);
            $table->decimal('insurance_percent', 6, 2)->default(5);
            $table->decimal('tools_percent', 6, 2)->default(5);
            $table->decimal('material_markup_percent', 6, 2)->default(10);
            $table->decimal('travel_flat_fee', 10, 2)->default(35);
            $table->decimal('overhead_percent', 6, 2)->default(15);
            $table->decimal('profit_percent', 6, 2)->default(15);
            $table->decimal('uncertainty_percent', 6, 2)->default(10);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('material_prices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('normalized_name', 191)->index();
            $table->string('category', 100)->nullable()->index();
            $table->string('zip_code', 10)->nullable()->index();
            $table->string('unit', 40)->default('each');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('low_unit_price', 12, 2)->nullable();
            $table->decimal('high_unit_price', 12, 2)->nullable();
            $table->string('source_name', 255)->nullable();
            $table->text('source_url')->nullable();
            $table->date('observed_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['normalized_name', 'zip_code', 'active'], 'material_price_lookup');
        });

        Schema::create('job_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->unique()->constrained('jobs')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 40)->default('preliminary')->index();
            $table->string('analysis_provider', 40)->default('rules');
            $table->string('analysis_model', 255)->nullable();
            $table->text('analysis_error')->nullable();
            $table->string('project_type', 80)->default('general_repair')->index();
            $table->string('zip_code', 10)->nullable()->index();
            $table->text('scope_summary')->nullable();
            $table->string('confidence', 20)->default('low');
            $table->json('follow_up_questions')->nullable();
            $table->json('intake_answers')->nullable();
            $table->json('assumptions')->nullable();
            $table->json('risk_flags')->nullable();
            $table->json('steps')->nullable();
            $table->json('materials')->nullable();
            $table->unsignedInteger('photo_count')->default(0);
            $table->decimal('estimated_hours_low', 10, 2)->default(0);
            $table->decimal('estimated_hours_high', 10, 2)->default(0);
            $table->decimal('labor_cost_low', 12, 2)->default(0);
            $table->decimal('labor_cost_high', 12, 2)->default(0);
            $table->decimal('material_cost_low', 12, 2)->default(0);
            $table->decimal('material_cost_high', 12, 2)->default(0);
            $table->decimal('travel_cost', 12, 2)->default(0);
            $table->decimal('insurance_cost_low', 12, 2)->default(0);
            $table->decimal('insurance_cost_high', 12, 2)->default(0);
            $table->decimal('tools_cost_low', 12, 2)->default(0);
            $table->decimal('tools_cost_high', 12, 2)->default(0);
            $table->decimal('overhead_cost_low', 12, 2)->default(0);
            $table->decimal('overhead_cost_high', 12, 2)->default(0);
            $table->decimal('profit_low', 12, 2)->default(0);
            $table->decimal('profit_high', 12, 2)->default(0);
            $table->decimal('estimate_low', 12, 2)->default(0);
            $table->decimal('estimate_high', 12, 2)->default(0);
            $table->json('pricing_snapshot')->nullable();
            $table->decimal('contractor_quote', 12, 2)->nullable();
            $table->decimal('accepted_price', 12, 2)->nullable();
            $table->decimal('actual_hours', 10, 2)->nullable();
            $table->decimal('actual_material_cost', 12, 2)->nullable();
            $table->decimal('final_invoice', 12, 2)->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('job_estimate_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_estimate_id')->constrained('job_estimates')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->json('snapshot');
            $table->timestamps();

            $table->index(['job_estimate_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_estimate_revisions');
        Schema::dropIfExists('job_estimates');
        Schema::dropIfExists('material_prices');
        Schema::dropIfExists('estimate_pricing_profiles');
    }
};
