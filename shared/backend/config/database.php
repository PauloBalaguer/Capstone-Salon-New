<?php

// Every endpoint loads this file, so set the app timezone here. Without it,
// files that write expiries (e.g. OTP codes) use php.ini's timezone while
// files that call date_default_timezone_set('Asia/Manila') compare against
// a different clock, making codes look expired the moment they're issued.
date_default_timezone_set('Asia/Manila');

/**
 * Database Connection Class
 *
 * Utilizes the Singleton pattern to ensure a single, shared PDO connection
 * instance throughout the application. This prevents multiple connections
...
 *
 * Usage:
 * $pdo = Database::getInstance();
 * $stmt = $pdo->query("SELECT * FROM users");
 * $users = $stmt->fetchAll();
 */
class Database
{
    private static string $host = '127.0.0.1';
    private static string $port = '3307'; // Matches the [mysqld] port in this XAMPP install.
    private static string $db_name = 'new capstone_salon'; // Existing schema name includes a space.
    private static string $username = 'root';
    private static string $password = ''; // Default for XAMPP
    private static string $charset = 'utf8mb4';

    private static ?PDO $instance = null;

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Gets the single instance of the PDO database connection.
     *
     * @return PDO The PDO instance.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . self::$host . ";port=" . self::$port . ";dbname=" . self::$db_name . ";charset=" . self::$charset;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, self::$username, self::$password, $options);
            } catch (PDOException $e) {
                // In a production environment, you should log this error, not display it.
                // For this project, we'll stop execution with a generic message.
                die('Database connection failed. Please check your configuration.');
            }
        }

        return self::$instance;
    }

    /**
     * Prevent cloning of the instance.
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserialization of the instance.
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }
}
