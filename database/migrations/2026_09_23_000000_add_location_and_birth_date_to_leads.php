<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('city', 120)->nullable()->after('country')->index();
            $table->date('date_of_birth')->nullable()->after('city')->index();
        });

        // Imports made before these fields existed retain their original values
        // in raw_attributes. Backfill the safe, recognisable variants so the new
        // filters immediately work for existing normal lead rows as well.
        DB::table('leads')
            ->select(['id', 'raw_attributes'])
            ->whereNotNull('raw_attributes')
            ->orderBy('id')
            ->chunkById(200, function ($leads): void {
                foreach ($leads as $lead) {
                    $attributes = json_decode((string) $lead->raw_attributes, true);
                    if (! is_array($attributes)) {
                        continue;
                    }

                    $city = null;
                    $dateOfBirth = null;
                    foreach ($attributes as $label => $value) {
                        $normalizedLabel = $this->normalizeHeader((string) $label);
                        $stringValue = trim((string) $value);
                        if ($stringValue === '') {
                            continue;
                        }

                        if ($city === null && $this->isCityHeader($normalizedLabel)) {
                            $city = mb_substr($stringValue, 0, 120);
                        }

                        if ($dateOfBirth === null && $this->isBirthDateHeader($normalizedLabel)) {
                            $dateOfBirth = $this->normalizeBirthDate($stringValue);
                        }
                    }

                    if ($city !== null || $dateOfBirth !== null) {
                        DB::table('leads')->where('id', $lead->id)->update([
                            'city' => $city,
                            'date_of_birth' => $dateOfBirth,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropIndex(['date_of_birth']);
            $table->dropColumn(['city', 'date_of_birth']);
        });
    }

    private function normalizeHeader(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = str_replace(['_', '-', '/', '\\', '.'], ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function isCityHeader(string $header): bool
    {
        return in_array($header, ['city', 'citta', 'comune', 'localita', 'municipality'], true)
            || str_contains($header, 'city')
            || str_contains($header, 'citta');
    }

    private function isBirthDateHeader(string $header): bool
    {
        return str_contains($header, 'data nascita')
            || str_contains($header, 'date of birth')
            || str_contains($header, 'birth date')
            || $header === 'dob';
    }

    private function normalizeBirthDate(string $value): ?string
    {
        $raw = trim($value);

        if (is_numeric($raw) && (float) $raw > 1000 && (float) $raw < 100000) {
            $date = (new DateTimeImmutable('@'.(((int) $raw - 25569) * 86400)))->setTimezone(new DateTimeZone('UTC'));
            return $date <= new DateTimeImmutable('today') ? $date->format('Y-m-d') : null;
        }

        foreach (['!d/m/Y', '!d-m-Y', '!Y-m-d', '!Y/m/d', '!m/d/Y', '!d.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            if ($date !== false && $date <= new DateTimeImmutable('today')) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
};
