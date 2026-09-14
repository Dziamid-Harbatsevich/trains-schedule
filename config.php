<?php
/**
 * Database connection settings.
 *
 * Values can be overridden by environment variables, which is handy for
 * running the project on different machines without editing this file.
 */

declare(strict_types=1);

return [
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => (int) (getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_NAME') ?: 'belta_trains',
    'user'     => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
    'charset'  => 'utf8mb4',

    // MySQL 8 enables TLS with a self-signed certificate. Inside a private
    // docker network the certificate cannot be validated, so the app connects
    // without it by default. Set DB_SSL_CA to a CA file path to enforce
    // verification in a real deployment.
    'ssl_mode' => getenv('DB_SSL_MODE') ?: 'disabled',
    'ssl_ca'   => getenv('DB_SSL_CA') ?: '',
];
