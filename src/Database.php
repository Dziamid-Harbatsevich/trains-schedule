<?php
/**
 * PDO connection wrapper (singleton).
 */

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $configFile = dirname(__DIR__) . '/config.php';
        $config = require $configFile;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // MySQL 8 serves TLS with a self-signed certificate. PDO refuses it
        // unless it is told how to verify it, so the mode is configurable:
        //   disabled             - no TLS (default, suitable inside docker)
        //   required-no-verify   - encrypt, do not verify the certificate
        //   verify-ca            - verify against the CA in DB_SSL_CA
        $sslMode = $config['ssl_mode'] ?? 'disabled';
        switch ($sslMode) {
            case 'required-no-verify':
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                break;

            case 'verify-ca':
                if (!empty($config['ssl_ca'])) {
                    // Requires PHP >= 8.2 / mysqlnd.
                    if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                        $options[PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'];
                    }
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
                }
                break;

            case 'disabled':
            default:
                // mysqlnd has no "disable" switch; dropping the CA/verify
                // options is enough for the server's optional TLS to be skipped
                // when the server allows plain connections.
                break;
        }

        try {
            self::$pdo = new PDO($dsn, $config['user'], $config['password'], $options);
        } catch (PDOException $e) {
            // Friendly message instead of a raw stack trace.
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit(
                "Не удалось подключиться к базе данных.\n" .
                "Проверьте параметры в config.php\n" .
                "DSN: {$dsn}\nОшибка: " . $e->getMessage() . "\n"
            );
        }

        return self::$pdo;
    }
}
