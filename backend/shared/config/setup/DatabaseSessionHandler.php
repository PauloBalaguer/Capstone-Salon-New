<?php

require_once __DIR__ . '/database.php';

/**
 * PHP sessions stored in the php_sessions table (SESSION_DRIVER=database), for
 * containers whose files vanish when an instance is recycled and aren't shared
 * between instances. Only a hash of each session ID is stored, so reading the
 * table doesn't give anyone a usable login.
 */
class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?PDO $ownConnection = null;
    private array $lockedIds = [];

    /**
     * Sessions must not join a booking or payment transaction, or a rollback
     * would undo them too. While one is open, use a separate connection.
     */
    private function db(): PDO
    {
        $shared = Database::getInstance();
        return $shared->inTransaction() ? ($this->ownConnection ??= Database::connect()) : $shared;
    }

    private function key(string $id): string
    {
        return hash('sha256', $id);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        foreach (array_keys($this->lockedIds) as $key) {
            Database::getInstance()->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->lockName($key)]);
        }
        $this->lockedIds = [];
        return true;
    }

    private function lockName(string $key): string
    {
        return 'salon-sess-' . substr($key, 0, 40);
    }

    /** Takes the same per-session lock file sessions have, so one login's parallel requests can't overwrite each other. */
    public function read(string $id): string|false
    {
        $key = $this->key($id);
        $lock = Database::getInstance()->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$this->lockName($key)]);
        $lock->closeCursor();
        $this->lockedIds[$key] = true;

        $stmt = $this->db()->prepare('SELECT data FROM php_sessions WHERE id = ?');
        $stmt->execute([$key]);
        $data = $stmt->fetchColumn();
        return $data === false ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        return $this->db()->prepare('INSERT INTO php_sessions (id, data, last_activity) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)')
            ->execute([$this->key($id), $data, time()]);
    }

    public function destroy(string $id): bool
    {
        return $this->db()->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$this->key($id)]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->db()->prepare('DELETE FROM php_sessions WHERE last_activity < ?');
        $stmt->execute([time() - $max_lifetime]);
        return $stmt->rowCount();
    }

    /** Strict mode (session.php): refuses a session ID the server didn't issue. */
    public function validateId(string $id): bool
    {
        $stmt = $this->db()->prepare('SELECT 1 FROM php_sessions WHERE id = ?');
        $stmt->execute([$this->key($id)]);
        return $stmt->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->db()->prepare('UPDATE php_sessions SET last_activity = ? WHERE id = ?')
            ->execute([time(), $this->key($id)]);
    }
}
