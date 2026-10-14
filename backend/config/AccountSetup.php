<?php

/**
 * Shared by saveStaff.php and saveCashier.php: issues an account-setup
 * token for a newly-created user and emails the link, in place of the old
 * generate-and-return-a-temp-password flow. See migration 037 for the
 * `account_setup_tokens` table this reads and writes.
 */
final class AccountSetup
{
    public static function issueAndEmail(PDO $pdo, int $userId, string $email, string $name, string $role): void
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

        $pdo->prepare('
            INSERT INTO account_setup_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at)
        ')->execute([$userId, $tokenHash, $expiresAt]);

        $setupUrl = getProjectFullBaseUrl() . '/pages/login/set-password.html?token=' . $token;
        sendAccountSetupEmail($email, $name, $role, $setupUrl);
    }
}
