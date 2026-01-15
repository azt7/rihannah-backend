<?php

namespace App\Helpers;

class PhoneHelper
{
    /**
     * Normalize a phone number to +966 format (Saudi Arabia).
     *
     * Handles various input formats:
     * - 0501234567 -> +966501234567
     * - 501234567 -> +966501234567
     * - 966501234567 -> +966501234567
     * - +966501234567 -> +966501234567
     * - 00966501234567 -> +966501234567
     *
     * @param string|null $phone
     * @return string|null
     */
    public static function normalize(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Remove all non-digit characters except the leading +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Remove leading + for processing
        $hasPlus = str_starts_with($phone, '+');
        if ($hasPlus) {
            $phone = substr($phone, 1);
        }

        // Remove leading 00 (international prefix)
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }

        // If starts with 0, assume local Saudi number and remove the 0
        if (str_starts_with($phone, '0')) {
            $phone = substr($phone, 1);
        }

        // If it doesn't start with 966, add it
        if (!str_starts_with($phone, '966')) {
            $phone = '966' . $phone;
        }

        return '+' . $phone;
    }

    /**
     * Strip the phone to just digits for searching.
     * Useful for partial matching.
     *
     * @param string|null $phone
     * @return string
     */
    public static function stripToDigits(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Format phone for display (with spaces).
     *
     * @param string|null $phone
     * @return string
     */
    public static function format(?string $phone): string
    {
        $normalized = self::normalize($phone);
        if (empty($normalized)) {
            return '';
        }

        // Format: +966 5X XXX XXXX
        if (preg_match('/^\+966(\d{2})(\d{3})(\d{4})$/', $normalized, $matches)) {
            return "+966 {$matches[1]} {$matches[2]} {$matches[3]}";
        }

        return $normalized;
    }

    /**
     * Get the last N digits of a phone number for quick search.
     *
     * @param string|null $phone
     * @param int $digits
     * @return string
     */
    public static function lastDigits(?string $phone, int $digits = 4): string
    {
        $stripped = self::stripToDigits($phone);
        return substr($stripped, -$digits);
    }

    /**
     * Check if two phone numbers are the same (after normalization).
     *
     * @param string|null $phone1
     * @param string|null $phone2
     * @return bool
     */
    public static function isSame(?string $phone1, ?string $phone2): bool
    {
        return self::normalize($phone1) === self::normalize($phone2);
    }
}
