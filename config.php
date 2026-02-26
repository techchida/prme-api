<?php
declare(strict_types=1);

function prime_load_env(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $vars = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, "\"'");
        $vars[$key] = $value;
    }

    return $vars;
}

function prime_env(string $key, ?string $default = null): ?string
{
    static $fileVars = null;
    if ($fileVars === null) {
        $fileVars = prime_load_env(__DIR__ . '/.env');
    }

    $value = getenv($key);
    if ($value !== false) {
        return (string)$value;
    }

    return $fileVars[$key] ?? $default;
}

function prime_db_config(): array
{
    return [
        'host' => prime_env('DB_HOST', '127.0.0.1'),
        'port' => (int)(prime_env('DB_PORT', '3306') ?? '3306'),
        'name' => prime_env('DB_NAME', 'prime'),
        'user' => prime_env('DB_USER', 'root'),
        'pass' => prime_env('DB_PASS', ''),
        'charset' => prime_env('DB_CHARSET', 'utf8mb4'),
        'auto_migrate' => (prime_env('PRIME_AUTO_MIGRATE', '1') ?? '1') === '1',
        'seed_defaults' => (prime_env('PRIME_SEED_DEFAULTS', '1') ?? '1') === '1',
    ];
}

function prime_mail_config(): array
{
    return [
        'enabled' => (prime_env('SMTP_ENABLED', '0') ?? '0') === '1',
        'host' => trim((string)(prime_env('SMTP_HOST', '') ?? '')),
        'port' => (int)(prime_env('SMTP_PORT', '587') ?? '587'),
        'username' => (string)(prime_env('SMTP_USER', '') ?? ''),
        'password' => (string)(prime_env('SMTP_PASS', '') ?? ''),
        'auth' => (prime_env('SMTP_AUTH', '1') ?? '1') === '1',
        'secure' => strtolower((string)(prime_env('SMTP_SECURE', 'tls') ?? 'tls')), // tls|ssl|none
        'timeout' => (int)(prime_env('SMTP_TIMEOUT', '15') ?? '15'),
        'from_email' => trim((string)(prime_env('SMTP_FROM_EMAIL', '') ?? '')),
        'from_name' => trim((string)(prime_env('SMTP_FROM_NAME', 'PRIME Conference Team') ?? 'PRIME Conference Team')),
        'reply_to' => trim((string)(prime_env('SMTP_REPLY_TO', '') ?? '')),
        'frontend_url' => rtrim((string)(prime_env('FRONTEND_URL', '') ?? ''), '/'),
        'training_url' => rtrim((string)(prime_env('TRAINING_URL', '') ?? ''), '/'),
        'resources_url' => rtrim((string)(prime_env('RESOURCES_URL', '') ?? ''), '/'),
    ];
}

function prime_stripe_config(): array
{
    return [
        'enabled' => (prime_env('STRIPE_ENABLED', '0') ?? '0') === '1',
        'secret_key' => trim((string)(prime_env('STRIPE_SECRET_KEY', '') ?? '')),
        'webhook_secret' => trim((string)(prime_env('STRIPE_WEBHOOK_SECRET', '') ?? '')),
        'currency' => strtolower(trim((string)(prime_env('STRIPE_CURRENCY', 'usd') ?? 'usd'))),
        'frontend_url' => rtrim((string)(prime_env('FRONTEND_URL', '') ?? ''), '/'),
        'success_url' => trim((string)(prime_env('STRIPE_SUCCESS_URL', '') ?? '')),
        'cancel_url' => trim((string)(prime_env('STRIPE_CANCEL_URL', '') ?? '')),
        'statement_descriptor' => trim((string)(prime_env('STRIPE_STATEMENT_DESCRIPTOR', 'PRIME Giving') ?? 'PRIME Giving')),
    ];
}

function prime_admin_auth_config(): array
{
    return [
        'username' => trim((string)(prime_env('ADMIN_USERNAME', 'admin') ?? 'admin')),
        'password' => (string)(prime_env('ADMIN_PASSWORD', '') ?? ''),
        'password_hash' => (string)(prime_env('ADMIN_PASSWORD_HASH', '') ?? ''),
        'session_name' => trim((string)(prime_env('ADMIN_SESSION_NAME', 'PRIMEADMINSESSID') ?? 'PRIMEADMINSESSID')),
        'session_secure' => (prime_env('ADMIN_SESSION_SECURE', '0') ?? '0') === '1',
    ];
}
