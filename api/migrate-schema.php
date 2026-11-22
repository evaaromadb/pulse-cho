<?php
declare(strict_types=1);

// Jalankan di mesin API (Render Shell):
//   php migrate-schema.php
// Menggunakan kredensial dari environment PULSE_TIDB_* dan CA di PULSE_TIDB_CA_PATH.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/common.php';

$files = [
    dirname(__DIR__) . '/tidb-setup.sql',
    dirname(__DIR__) . '/tidb-migration-chunks.sql',
];

try {
    $pdo = pulseDatabase();
    foreach ($files as $file) {
        if (!is_file($file)) {
            fwrite(STDOUT, "Lewati (tidak ada): {$file}\n");
            continue;
        }
        $sql = file_get_contents($file);
        $lines = array_filter(
            array_map('trim', preg_split('/\R/', (string) $sql) ?: []),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '--')
        );
        foreach (array_filter(array_map('trim', explode(';', implode("\n", $lines)))) as $statement) {
            try {
                $pdo->exec($statement);
                fwrite(STDOUT, 'OK   ' . substr(preg_replace('/\s+/', ' ', $statement), 0, 80) . "\n");
            } catch (Throwable $error) {
                fwrite(STDOUT, 'SKIP ' . substr(preg_replace('/\s+/', ' ', $statement), 0, 60) . ' -> ' . $error->getMessage() . "\n");
            }
        }
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM pulse_users')->fetchColumn();
    fwrite(STDOUT, "Selesai. Jumlah akun terdaftar: {$count}\n");
    if ($count === 0) {
        fwrite(STDOUT, "Belum ada akun. Buat Admin dengan: php bootstrap-admin.php --username=... --password=... --name=... --site=...\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migrasi gagal: ' . $error->getMessage() . "\n");
    exit(1);
}
