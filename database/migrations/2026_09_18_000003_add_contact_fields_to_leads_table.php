<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('mobile')->nullable()->after('phone');
            $table->string('age', 20)->nullable()->after('mobile');
            $table->string('gender', 100)->nullable()->after('age');
            $table->string('country', 100)->nullable()->after('gender');
            $table->string('intention', 255)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn(['mobile', 'age', 'gender', 'country', 'intention']);
        });
    }
};
