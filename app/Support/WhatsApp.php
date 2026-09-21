<?php

namespace App\Support;

/**
 * A WhatsApp chat link for a number on these books, or none.
 *
 * The server's copy of whatsappNumber() in resources/js/whatsapp.js, for the
 * links drawn before any script runs: the statement's header and the WhatsApp
 * column of the ledger lists. Two copies of one rule is one too many, so both
 * are held to the same list of cases, tests/whatsapp-numbers.json — change
 * one and the other's tests say so.
 *
 * Those links used to be "https://wa.me/91" and the number, whatever it was. A
 * mobile saved the way the form insists on, ten digits, came out right. A
 * landline, which the form also accepts as ten digits, came out as a chat link
 * WhatsApp answers with "this number is not on WhatsApp" — to be found out only
 * after clicking it.
 */
class WhatsApp
{
    /**
     * Country code and ten digits, no plus or spaces — or null.
     *
     * Indian mobiles only: ten digits starting 6 to 9, however it was written.
     * Anything else is null rather than guessed at; a chat opened on the wrong
     * number is worse than none.
     */
    public static function number(?string $mobile): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $mobile);

        $local = match (true) {
            strlen($digits) === 10 => $digits,
            strlen($digits) === 11 && str_starts_with($digits, '0') => substr($digits, 1),
            strlen($digits) === 12 && str_starts_with($digits, '91') => substr($digits, 2),
            default => null,
        };

        return $local !== null && preg_match('/^[6-9]\d{9}$/', $local) ? '91'.$local : null;
    }

    /** The chat, or null when the number cannot have one. */
    public static function url(?string $mobile): ?string
    {
        $number = self::number($mobile);

        return $number ? 'https://wa.me/'.$number : null;
    }
}
