<?php
declare(strict_types=1);

// Jalankan di mesin API (Render Shell):
//   php bootstrap-admin.php --username=19233208 --password="Rahasia123456" --name="Nama" --site="Site 1 Semarang" [--force|--reset]
// Kredensial database diambil dari environment PULSE_TIDB_*.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/common.php';

$args = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $args[$key] = $value;
    }
}

$username = trim((string) (is_string($args['username'] ?? null) ? $args['username'] : ''));
$password = (string) (is_string($args['password'] ?? null) ? $args['password'] : '');
$name = trim((string) (is_string($args['name'] ?? null) ? $args['name'] : ''));
$site = trim((string) (is_string($args['site'] ?? null) ? $args['site'] : ''));
$reset = isset($args['reset']);
$force = isset($args['force']);

$fail = static function (string $message): void {
    fwrite(STDERR, "GAGAL: {$message}\n");
    exit(1);
};

if (!preg_match('/^[A-Za-z0-9._-]{2,64}$/', $username)) {
    $fail('--username wajib (2-64 karakter: huruf, angka, titik, garis bawah, strip).');
}
if (strlen($password) < 12) {
    $fail('--password wajib minimal 12 karakter (aturan yang sama dengan API).');
}
if ($name === '' || $site === '') {
    $fail('--name dan --site wajib diisi.');
}

try {
    $pdo = pulseDatabase();

    if ($reset) {
        $pdo->exec('DELETE FROM pulse_sessions');
        $pdo->exec('DELETE FROM pulse_auth_attempts');
        $pdo->exec('DELETE FROM pulse_users');
        fwrite(STDOUT, "Semua akun & sesi lama dihapus.\n");
    }

    $existing = $pdo->prepare('SELECT username FROM pulse_users WHERE username = :u LIMIT 1');
    $existing->execute(['u' => $username]);
    $account = $existing->fetch();

    if ($account && !$force) {
        $fail("Username '{$username}' sudah ada. Tambahkan --force untuk mengganti password & role-nya.");
    }

    if ($account) {
        $update = $pdo->prepare(
            "UPDATE pulse_users
                SET full_name = :full_name, role = 'Admin', site = :site,
                    password_hash = :password_hash, is_active = 1
              WHERE username = :username"
        );
        $update->execute([
            'full_name' => $name,
            'site' => $site,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'username' => $username,
        ]);
        $pdo->prepare('DELETE FROM pulse_sessions WHERE username = :username')
            ->execute(['username' => $username]);
        fwrite(STDOUT, "Akun '{$username}' diperbarui (role Admin, password baru, sesi lama dicabut).\n");
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO pulse_users (username, full_name, role, site, password_hash, is_active)
             VALUES (:username, :full_name, 'Admin', :site, :password_hash, 1)"
        );
        $insert->execute([
            'username' => $username,
            'full_name' => $name,
            'site' => $site,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        fwrite(STDOUT, "Akun Admin '{$username}' dibuat.\n");
    }

    $count = (int) $pdo->query("SELECT COUNT(*) FROM pulse_users WHERE role = 'Admin' AND is_active = 1")->fetchColumn();
    fwrite(STDOUT, "Total Admin aktif: {$count}\n");
} catch (InvalidArgumentException $error) {
    $fail($error->getMessage() . ' (cek environment PULSE_TIDB_*)');
} catch (Throwable $error) {
    $fail($error->getMessage());
}
