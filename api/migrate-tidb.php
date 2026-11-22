<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = __DIR__ . '/tidb-config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing api/tidb-config.php\n");
    exit(1);
}

try {
    $config = require $configPath;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_CA => $config['ssl_ca'],
    ];
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        (int) ($config['port'] ?? 4000),
        $config['database']
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
    $setupPath = dirname(__DIR__) . '/tidb-setup.sql';
    $statements = array_filter(array_map('trim', explode(';', file_get_contents($setupPath))));
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    fwrite(STDOUT, "TiDB schema migration completed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'TiDB schema migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
