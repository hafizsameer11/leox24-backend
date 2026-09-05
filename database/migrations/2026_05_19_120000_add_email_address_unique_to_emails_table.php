<?php

use App\Models\Email;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('email_address')->nullable()->after('category');
        });

        Email::query()->orderBy('id')->chunk(200, function ($emails) {
            foreach ($emails as $email) {
                $address = $email->email_address;
                if (!$address) {
                    $data = $email->row_data_json;
                    if (is_array($data) && !empty($data['email'])) {
                        $address = strtolower(trim((string) $data['email']));
                    }
                }
                if ($address) {
                    DB::table('emails')->where('id', $email->id)->update([
                        'email_address' => $address,
                    ]);
                }
            }
        });

        $keepIds = DB::table('emails')
            ->whereNotNull('email_address')
            ->where('email_address', '!=', '')
            ->select('email_address', DB::raw('MAX(id) as keep_id'))
            ->groupBy('email_address')
            ->pluck('keep_id');

        DB::table('emails')
            ->whereNotNull('email_address')
            ->where('email_address', '!=', '')
            ->whereNotIn('id', $keepIds)
            ->delete();

        Schema::table('emails', function (Blueprint $table) {
            $table->unique('email_address');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropUnique(['email_address']);
            $table->dropColumn('email_address');
        });
    }
};
