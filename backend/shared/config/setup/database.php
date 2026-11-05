<?php

// Every endpoint loads this file, so set the app timezone here. Without it,
// files that write expiries (e.g. OTP codes) use php.ini's timezone while
// files that call date_default_timezone_set('Asia/Manila') compare against
// a different clock, making codes look expired the moment they're issued.
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/env.php'; // Also hides PHP error details from responses unless APP_DEBUG=true.

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
    // Set by the test harness to point at a throwaway copy; wins over DB_NAME.
    private static ?string $db_name = null;
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
        return self::$instance ??= self::connect();
    }

    /**
     * Opens a new connection. Everything except database sessions should use
     * getInstance(), so one request holds one connection.
     */
    public static function connect(): PDO
    {
        // The defaults are the local XAMPP setup; a live server sets DB_* (see .env.example).
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $dbName = self::$db_name ?? env('DB_NAME', 'new_capstone_salon');
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=" . self::$charset;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Same clock as PHP (Asia/Manila above), whatever timezone the server runs in. On a UTC
            // cloud database, NOW() ran 8 hours behind and a new booking was already past its deadline.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
        ];
        // A database on another network: encrypt the connection and verify the server.
        if (env('DB_SSL_CA') !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = env('DB_SSL_CA');
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        try {
            return new PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD'), $options);
        } catch (PDOException $e) {
            // In a production environment, you should log this error, not display it.
            // For this project, we'll stop execution with a generic message.
            die('Database connection failed. Please check your configuration.');
        }
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
