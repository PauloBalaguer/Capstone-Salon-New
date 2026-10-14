<?php

/**
 * This is our RateLimiter class, a key component for preventing brute-force attacks.
 * It works by tracking events (like failed logins) against an identifier (like an IP address)
 * in our `rate_limit` database table.
 *
 * We can define a limit and a time window (e.g., 5 failed logins in 15 minutes).
 * Before processing a request, we check if the limit has been exceeded. If it has,
 * we block the request. This is a simple but effective way to secure our endpoints.
 */
class RateLimiter
{
    private PDO $pdo;
    private string $identifier;
    private string $type;
    private int $limit;
    private int $window; // In seconds

    public function __construct(string $identifier, string $type, int $limit, int $window)
    {
        $this->pdo = Database::getInstance();
        $this->identifier = $identifier;
        $this->type = $type;
        $this->limit = $limit;
        $this->window = $window;
    }

    /**
     * Checks if the rate limit has been exceeded.
     * @return bool True if the number of records is greater than or equal to the limit.
     */
    public function isExceeded(): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM rate_limit
            WHERE identifier = ? AND type = ? AND created_at > (NOW() - INTERVAL ? SECOND)
        ");
        $stmt->execute([$this->identifier, $this->type, $this->window]);
        $result = $stmt->fetch();

        return ($result['count'] ?? 0) >= $this->limit;
    }

    public function record(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO rate_limit (identifier, type) VALUES (?, ?)");
        $stmt->execute([$this->identifier, $this->type]);
    }

    public function clear(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM rate_limit WHERE identifier = ? AND type = ?");
        $stmt->execute([$this->identifier, $this->type]);
    }

    /** A simple utility to get the user's IP address. */
    public static function getIpAddress(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}