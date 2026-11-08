<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('bulk_email_status', 20)->nullable()->after('status');
            $table->timestamp('bulk_email_sent_at')->nullable()->after('bulk_email_status');
            $table->text('bulk_email_error')->nullable()->after('bulk_email_sent_at');
            $table->index(['bulk_email_status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['bulk_email_status', 'category']);
            $table->dropColumn(['bulk_email_status', 'bulk_email_sent_at', 'bulk_email_error']);
        });
    }
};
