<?php
declare(strict_types=1);

namespace Saa\Support;

/** Normalização de e-mails (a unicidade usa a forma normalizada: sem espaços nas pontas e em minúsculas). */
final class Email
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function isValid(string $email): bool
    {
        $email = trim($email);
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
