<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Normalizer;

/**
 * Rate-limit key for login attempts (mobile API `api-login` limiter and the
 * website's LoginRequest): "<account>|<ip>".
 *
 * The account part must not be something a client can vary while still
 * reaching the same account, or every variant would get a fresh allowance:
 *
 * - `email` may arrive as any JSON type before validation runs (the
 *   throttle middleware runs first), so non-strings never get cast.
 * - MySQL's utf8mb4_unicode_ci collation ignores case, accents, width and
 *   zero-weight characters (zero-width space, BOM, soft hyphen, ...) and
 *   pads trailing spaces. So when the address belongs to an account, the
 *   key is that account's id, as found by the same query the login uses;
 *   otherwise it is the address with whitespace / invisible characters
 *   removed, NFKC-normalised, transliterated and lower-cased.
 */
final class LoginThrottleKey
{
    public static function for(mixed $email, ?string $ip): string
    {
        return self::account($email).'|'.$ip;
    }

    public static function normalizeEmail(mixed $email): string
    {
        if (! is_string($email)) {
            return '';
        }

        // Format chars (Cf: zero-width, BOM, soft hyphen, bidi marks),
        // separators, controls and whitespace anywhere in the string.
        // preg_replace returns null on invalid UTF-8.
        $email = preg_replace('/[\p{Cf}\p{Z}\p{Cc}\s]+/u', '', $email) ?? '';

        if ($email !== '' && class_exists(Normalizer::class)) {
            $email = Normalizer::normalize($email, Normalizer::FORM_KC) ?: $email;
        }

        return Str::lower(Str::transliterate($email));
    }

    private static function account(mixed $email): string
    {
        $normalized = self::normalizeEmail($email);

        if ($normalized === '') {
            return 'email:';
        }

        // Longer input fails validation (max:255) and can't match a row.
        if (mb_strlen($email) <= 255) {
            foreach (array_unique([$email, $normalized]) as $candidate) {
                $id = User::query()->where('email', $candidate)->value('id');

                if ($id !== null) {
                    return 'user:'.$id;
                }
            }
        }

        return 'email:'.$normalized;
    }
}
