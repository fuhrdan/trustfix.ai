<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractor_profiles', function (Blueprint $table) {
            $table->string('business_address')->nullable()->after('business_name');
            $table->string('business_phone')->nullable()->after('business_address');
            $table->unsignedSmallInteger('year_established')->nullable()->after('website');
            $table->enum('business_type', ['individual', 'company'])->nullable()->after('year_established');
            $table->boolean('emergency_availability')->default(false)->after('service_area');
            $table->string('state_license')->nullable()->after('license_number');
            $table->string('local_license')->nullable()->after('state_license');
            $table->string('sales_tax_license')->nullable()->after('local_license');
            $table->date('license_expiration_date')->nullable()->after('sales_tax_license');
            $table->string('coi_path')->nullable()->after('license_expiration_date');
            $table->date('insurance_expiration_date')->nullable()->after('coi_path');
            $table->string('surety_bond_path')->nullable()->after('insurance_expiration_date');
            $table->text('service_agreement')->nullable()->after('surety_bond_path');
            $table->enum('background_check_status', ['not_started', 'pending', 'passed', 'failed'])->default('not_started')->after('status');
            $table->boolean('is_verified')->default(false)->after('background_check_status');
        });
    }

    public function down(): void
    {
        Schema::table('contractor_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'business_address',
                'business_phone',
                'year_established',
                'business_type',
                'emergency_availability',
                'state_license',
                'local_license',
                'sales_tax_license',
                'license_expiration_date',
                'coi_path',
                'insurance_expiration_date',
                'surety_bond_path',
                'service_agreement',
                'background_check_status',
                'is_verified',
            ]);
        });
    }
};
