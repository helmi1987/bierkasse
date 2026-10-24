<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Single-use form tokens against double submits (double tap on "Bier abstreichen").
 * Every rendered form gets a fresh token stored in the session; a booking is only
 * made when the posted token is still unused. Up to KEEP tokens per form are kept so
 * several open tabs (and the live refresh of background tabs) keep working.
 *
 * The session alone is not atomic (two parallel requests read the same token list),
 * so a used token is additionally claimed with an atomic Cache::add (file and
 * database cache stores implement add atomically).
 */
class FormToken
{
    public const FIELD = 'form_token';

    private const SESSION = 'form_tokens';

    private const KEEP = 50;

    /** How long a used token stays claimed in the cache (seconds). */
    private const CLAIM_TTL = 86400;

    public static function issue(string $form): string
    {
        $token = Str::random(40);
        $tokens = (array) session()->get(self::SESSION.'.'.$form, []);
        $tokens[] = $token;
        session()->put(self::SESSION.'.'.$form, array_slice($tokens, -self::KEEP));

        return $token;
    }

    /**
     * Consumes the posted token; false when it is missing, unknown or already used.
     */
    public static function consume(Request $request, string $form): bool
    {
        $posted = (string) $request->input(self::FIELD, '');
        $tokens = (array) $request->session()->get(self::SESSION.'.'.$form, []);

        foreach ($tokens as $i => $token) {
            if ($posted !== '' && hash_equals((string) $token, $posted)) {
                // Only the first request may claim the token.
                if (! Cache::add('form-token:'.hash('sha256', $form.'|'.$posted), 1, self::CLAIM_TTL)) {
                    return false;
                }
                unset($tokens[$i]);
                $request->session()->put(self::SESSION.'.'.$form, array_values($tokens));

                return true;
            }
        }

        return false;
    }

    public static function field(string $form): string
    {
        return '<input type="hidden" name="'.self::FIELD.'" value="'.e(self::issue($form)).'">';
    }
}
