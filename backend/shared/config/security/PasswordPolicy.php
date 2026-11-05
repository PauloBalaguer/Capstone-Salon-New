<?php

/** The password rules, checked by the server whatever the page shows. */
final class PasswordPolicy
{
    public const MESSAGE = 'Password must have at least 8 characters, with an uppercase letter, a lowercase letter, a number, and a special character.';

    public static function isValid(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/[0-9]/', $password)
            && preg_match('/[^A-Za-z0-9\s]/', $password);
    }
}
