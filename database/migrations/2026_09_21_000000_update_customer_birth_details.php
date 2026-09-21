<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('date_of_birth_from')->nullable()->after('date_of_birth')->index();
            $table->date('date_of_birth_to')->nullable()->after('date_of_birth_from')->index();
            $table->string('city_of_birth', 120)->nullable()->after('place_of_birth');
        });

        // Preserve existing customer information: a previously precise date is
        // represented as a one-day From/To range after this change.
        DB::table('customers')
            ->whereNotNull('date_of_birth')
            ->update([
                'date_of_birth_from' => DB::raw('date_of_birth'),
                'date_of_birth_to' => DB::raw('date_of_birth'),
            ]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['date_of_birth_from']);
            $table->dropIndex(['date_of_birth_to']);
            $table->dropColumn(['date_of_birth_from', 'date_of_birth_to', 'city_of_birth']);
        });
    }
};
