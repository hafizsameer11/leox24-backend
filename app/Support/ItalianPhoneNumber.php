<?php

namespace App\Support;

/**
 * Italian telephone vs mobile detection for CRM storage and messaging.
 *
 * National numbers (after stripping +39 / 0039):
 * - Mobile (cellulare): starts with 3
 * - Landline (fisso / telephone): starts with 0 (02, 06, 08, 09, 081, …)
 */
class ItalianPhoneNumber
{
    public const KIND_MOBILE = 'mobile';

    public const KIND_LANDLINE = 'landline';

    public const KIND_UNKNOWN = 'unknown';

    public static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Italian national subscriber number (no country code).
     */
    public static function nationalDigits(string $value): string
    {
        $digits = self::digits($value);
        if ($digits === '') {
            return '';
        }

        // 0039… international prefix
        if (str_starts_with($digits, '0039')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '39') && strlen($digits) >= 11) {
            // +39 + national (mobile 10 digits or landline with leading 0)
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    public static function kind(string $value): string
    {
        $national = self::nationalDigits($value);
        if ($national === '') {
            return self::KIND_UNKNOWN;
        }

        if (str_starts_with($national, '3')) {
            return self::KIND_MOBILE;
        }

        if (str_starts_with($national, '0')) {
            return self::KIND_LANDLINE;
        }

        return self::KIND_UNKNOWN;
    }

    public static function isMobile(string $value): bool
    {
        return self::kind($value) === self::KIND_MOBILE;
    }

    public static function isLandline(string $value): bool
    {
        return self::kind($value) === self::KIND_LANDLINE;
    }

    /**
     * Split/reassign phone + mobile fields by number type.
     *
     * @return array{phone: ?string, mobile: ?string}
     */
    public static function separate(?string $phone, ?string $mobile): array
    {
        $candidates = [];
        foreach ([$phone, $mobile] as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }
            $key = self::digits($trimmed);
            if ($key === '' || isset($candidates[$key])) {
                continue;
            }
            $candidates[$key] = $trimmed;
        }

        $landlines = [];
        $mobiles = [];
        $unknown = [];

        foreach ($candidates as $value) {
            match (self::kind($value)) {
                self::KIND_MOBILE => $mobiles[] = $value,
                self::KIND_LANDLINE => $landlines[] = $value,
                default => $unknown[] = $value,
            };
        }

        // Prefer typed buckets; keep a single unknown in phone if nothing else fits.
        $phoneOut = $landlines[0] ?? null;
        $mobileOut = $mobiles[0] ?? null;

        if ($phoneOut === null && $unknown !== []) {
            $phoneOut = $unknown[0];
        } elseif ($mobileOut === null && count($unknown) > 1) {
            $mobileOut = $unknown[1];
        } elseif ($mobileOut === null && $phoneOut !== null && count($unknown) === 1 && $unknown[0] !== $phoneOut) {
            $mobileOut = $unknown[0];
        }

        return [
            'phone' => $phoneOut,
            'mobile' => $mobileOut,
        ];
    }

    /**
     * Reject landlines (and unknowns that are clearly not mobile) for SMS/WhatsApp.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertMobileForMessaging(string $value, string $channel = 'SMS'): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException("{$channel} requires a mobile number.");
        }

        if (self::isMobile($trimmed)) {
            return $trimmed;
        }

        if (self::isLandline($trimmed)) {
            throw new \InvalidArgumentException(
                "{$channel} can only be sent to Italian mobile numbers (starting with 3). Landline numbers are not allowed."
            );
        }

        throw new \InvalidArgumentException(
            "{$channel} can only be sent to Italian mobile numbers (national number starting with 3)."
        );
    }
}
