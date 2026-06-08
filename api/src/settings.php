<?php

if (!function_exists('ots_load_env')) {
    function ots_load_env($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);

            if ($name === '') {
                continue;
            }

            if (
                (substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")
            ) {
                $value = substr($value, 1, -1);
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    function ots_env($name, $default = null)
    {
        if (array_key_exists($name, $_ENV)) {
            return $_ENV[$name];
        }

        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    function ots_env_bool($name, $default = false)
    {
        $value = ots_env($name, null);
        if ($value === null) {
            return (bool) $default;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

ots_load_env(__DIR__ . '/../.env');

$settings = [
    'settings' => [
        'displayErrorDetails' => ots_env_bool('APP_DEBUG', true),
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header

        // Renderer settings
        'renderer' => [
            'template_path' => __DIR__ . '/../templates/',
        ],

        // Monolog settings
        'logger' => [
            'name' => ots_env('APP_NAME', 'slim-app'),
            'path' => isset($_ENV['docker']) ? 'php://stdout' : ots_env('LOG_PATH', __DIR__ . '/../logs/app.log'),
            'level' => \Monolog\Logger::DEBUG,
        ],

        // Database Settings (DEFAULT / PRODUCTION)
        'db' => [
            'host' => ots_env('DB_HOST', 'localhost'),
            'user' => ots_env('DB_USER', 'root'),
            'pass' => ots_env('DB_PASS', ''),
            'dbname' => ots_env('DB_NAME', 'ots_db'),
            'driver' => ots_env('DB_DRIVER', 'mysql'),
            'port' => ots_env('DB_PORT', '3306')
        ]
    ],
];

//  Cek apakah ada file settingan khusus laptop ini?
// Jika ada, timpa settingan di atas dengan settingan lokal.
if (file_exists(__DIR__ . '/settings-local.php')) {
    $localSettings = require __DIR__ . '/settings-local.php';
    // Gabungkan array (settingan lokal menang/menimpa yang lama)
    $settings['settings'] = array_replace_recursive($settings['settings'], $localSettings['settings']);
}

return $settings;
