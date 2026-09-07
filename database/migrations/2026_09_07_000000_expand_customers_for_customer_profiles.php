<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Identity and registration
            $table->string('title', 30)->nullable();
            $table->string('second_last_name')->nullable();
            $table->string('customer_group')->nullable()->index();
            $table->string('customer_code', 80)->nullable()->unique();
            $table->string('gender', 30)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('branch')->nullable();
            $table->date('date_added')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Main address and fiscal data
            $table->string('city')->nullable()->index();
            $table->string('zip_code', 30)->nullable();
            $table->string('state_province')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('tax_code')->nullable()->index();
            $table->string('pec_email')->nullable();
            $table->string('tax_code_fe')->nullable();

            // Communication and consent preferences
            $table->string('phone_secondary')->nullable();
            $table->string('mobile')->nullable();
            $table->string('fax')->nullable();
            $table->date('privacy_date')->nullable();
            $table->boolean('privacy_consent_processing')->default(false);
            $table->boolean('marketing_consent')->default(false);
            $table->boolean('profiling_consent')->default(false);
            $table->boolean('send_sms')->default(false);
            $table->boolean('send_mail')->default(false);
            $table->boolean('send_newsletter')->default(false);

            // Billing and CRM profile data
            $table->text('billing_address')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_zip_code', 30)->nullable();
            $table->string('billing_state')->nullable();
            $table->string('billing_country', 100)->nullable();
            $table->text('family_members')->nullable();
            $table->string('language', 10)->nullable()->default('en');
            $table->text('private_notes')->nullable();
            $table->string('occupation')->nullable();
            $table->string('vision_problem')->nullable();
            $table->string('hobbies')->nullable();
            $table->string('acquired_by')->nullable();
            $table->string('promotion')->nullable();
            $table->string('referred_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropUnique(['customer_code']);
            $table->dropIndex(['customer_group']);
            $table->dropIndex(['date_added']);
            $table->dropIndex(['city']);
            $table->dropIndex(['tax_code']);
            $table->dropColumn([
                'title', 'second_last_name', 'customer_group', 'customer_code', 'gender', 'date_of_birth',
                'place_of_birth', 'branch', 'date_added', 'created_by', 'city', 'zip_code', 'state_province',
                'country', 'tax_code', 'pec_email', 'tax_code_fe', 'phone_secondary', 'mobile', 'fax',
                'privacy_date', 'privacy_consent_processing', 'marketing_consent', 'profiling_consent',
                'send_sms', 'send_mail', 'send_newsletter', 'billing_address', 'billing_city',
                'billing_zip_code', 'billing_state', 'billing_country', 'family_members', 'language',
                'private_notes', 'occupation', 'vision_problem', 'hobbies', 'acquired_by', 'promotion',
                'referred_by',
            ]);
        });
    }
};
