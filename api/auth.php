<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string) ($_GET['action'] ?? '');

try {
    $pdo = pulseDatabase();
    pulseEnsureBootstrapAdmin($pdo);

    if ($action === 'login' && $method === 'POST') {
        pulseRequireAllowedOrigin();
        $payload = pulseReadJsonBody();
        $username = trim((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $attemptKey = hash(
            'sha256',
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\0" . strtolower($username)
        );
        $lockStatement = $pdo->prepare(
            'SELECT locked_until > UTC_TIMESTAMP()
             FROM pulse_auth_attempts WHERE attempt_key = :attempt_key'
        );
        $lockStatement->execute(['attempt_key' => $attemptKey]);
        if ((int) $lockStatement->fetchColumn() === 1) {
            pulseRespond(['error' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.'], 429);
        }

        $statement = $pdo->prepare(
            'SELECT username, full_name, role, site, password_hash, is_active
             FROM pulse_users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $account = $statement->fetch();
        if (!$account || !(bool) $account['is_active'] || !password_verify($password, $account['password_hash'])) {
            $failedAttempt = $pdo->prepare(
                'INSERT INTO pulse_auth_attempts (attempt_key, fail_count, window_started_at)
                 VALUES (:attempt_key, 1, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                   fail_count = IF(
                     window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),
                     1,
                     fail_count + 1
                   ),
                   window_started_at = IF(
                     window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),
                     UTC_TIMESTAMP(),
                     window_started_at
                   ),
                   locked_until = IF(
                     fail_count >= 9,
                     DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),
                     locked_until
                   )'
            );
            $failedAttempt->execute(['attempt_key' => $attemptKey]);
            pulseRespond(['error' => 'Username atau password salah.'], 401);
        }

        $clearAttempts = $pdo->prepare('DELETE FROM pulse_auth_attempts WHERE attempt_key = :attempt_key');
        $clearAttempts->execute(['attempt_key' => $attemptKey]);
        $pdo->exec('DELETE FROM pulse_sessions WHERE expires_at <= UTC_TIMESTAMP()');
        $token = bin2hex(random_bytes(32));
        $statement = $pdo->prepare(
            'INSERT INTO pulse_sessions (token_hash, username, expires_at)
             VALUES (:token_hash, :username, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 12 HOUR))'
        );
        $statement->execute([
            'token_hash' => hash('sha256', $token),
            'username' => $account['username'],
        ]);
        pulseSetSessionCookie($token, PULSE_SESSION_TTL_SECONDS);
        // Cookie dari setcookie() baru terbaca di request berikutnya, jadi jangan
        // memanggil pulseCurrentUser() di sini — pakai data akun yang sudah diverifikasi.
        pulseRespond(['ok' => true, 'user' => [
            'username' => (string) $account['username'],
            'namaLengkap' => (string) $account['full_name'],
            'role' => (string) $account['role'],
            'site' => (string) $account['site'],
        ]]);
    }

    if ($action === 'logout' && $method === 'POST') {
        pulseRequireAllowedOrigin();
        $token = (string) ($_COOKIE[PULSE_SESSION_COOKIE] ?? '');
        if ($token !== '') {
            $statement = $pdo->prepare('DELETE FROM pulse_sessions WHERE token_hash = :token_hash');
            $statement->execute(['token_hash' => hash('sha256', $token)]);
        }
        pulseSetSessionCookie('', -3600);
        pulseRespond(['ok' => true]);
    }

    if ($action === 'me' && $method === 'GET') {
        pulseRespond(['user' => pulseCurrentUser($pdo)]);
    }

    if ($action === 'users' && $method === 'GET') {
        $currentUser = pulseCurrentUser($pdo);
        pulseRequireAdmin($currentUser);
        $users = $pdo->query(
            'SELECT username, full_name AS namaLengkap, role, site
             FROM pulse_users WHERE is_active = 1 ORDER BY username'
        )->fetchAll();
        pulseRespond(['users' => $users]);
    }

    if ($action === 'users' && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        pulseRequireAllowedOrigin();
        $currentUser = pulseCurrentUser($pdo);
        pulseRequireAdmin($currentUser);

        if ($method === 'DELETE') {
            $username = trim((string) ($_GET['username'] ?? ''));
            if ($username === '' || $username === $currentUser['username']) {
                pulseRespond(['error' => 'Tidak dapat menghapus akun yang sedang digunakan.'], 400);
            }

            $pdo->beginTransaction();
            $targetStatement = $pdo->prepare(
                'SELECT role FROM pulse_users WHERE username = :username AND is_active = 1 FOR UPDATE'
            );
            $targetStatement->execute(['username' => $username]);
            $target = $targetStatement->fetch();
            if (!$target) {
                $pdo->rollBack();
                pulseRespond(['error' => 'User tidak ditemukan.'], 404);
            }
            if (strcasecmp((string) $target['role'], 'Admin') === 0) {
                $adminCount = (int) $pdo->query(
                    "SELECT COUNT(*) FROM pulse_users WHERE role = 'Admin' AND is_active = 1"
                )->fetchColumn();
                if ($adminCount <= 1) {
                    $pdo->rollBack();
                    pulseRespond(['error' => 'Minimal harus ada satu akun Admin aktif.'], 409);
                }
            }
            $statement = $pdo->prepare('DELETE FROM pulse_sessions WHERE username = :username');
            $statement->execute(['username' => $username]);
            $statement = $pdo->prepare('DELETE FROM pulse_users WHERE username = :username');
            $statement->execute(['username' => $username]);
            $pdo->commit();
            pulseRespond(['ok' => true]);
        }

        $payload = pulseReadJsonBody();
        $username = trim((string) ($payload['username'] ?? ''));
        $fullName = trim((string) ($payload['namaLengkap'] ?? ''));
        $role = (string) ($payload['role'] ?? '');
        $site = trim((string) ($payload['site'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{2,64}$/', $username) || $fullName === '' || $site === '') {
            pulseRespond(['error' => 'Username, nama lengkap, atau site tidak valid.'], 400);
        }
        if (!in_array($role, ['Admin', 'User'], true)) {
            pulseRespond(['error' => 'Role harus Admin atau User.'], 400);
        }
        if ($method === 'POST') {
            if (strlen($password) < 12) {
                pulseRespond(['error' => 'Password user baru minimal 12 karakter.'], 400);
            }
            $statement = $pdo->prepare(
                'INSERT INTO pulse_users (username, full_name, role, site, password_hash, is_active)
                 VALUES (:username, :full_name, :role, :site, :password_hash, 1)'
            );
            try {
                $statement->execute([
                    'username' => $username,
                    'full_name' => $fullName,
                    'role' => $role,
                    'site' => $site,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
            } catch (PDOException $error) {
                if ((string) $error->getCode() === '23000') {
                    pulseRespond(['error' => 'Username sudah digunakan.'], 409);
                }
                throw $error;
            }
            pulseRespond(['ok' => true]);
        }

        $targetStatement = $pdo->prepare(
            'SELECT role FROM pulse_users WHERE username = :username AND is_active = 1 LIMIT 1'
        );
        $targetStatement->execute(['username' => $username]);
        $target = $targetStatement->fetch();
        if (!$target) {
            pulseRespond(['error' => 'User tidak ditemukan.'], 404);
        }
        if (strcasecmp((string) $target['role'], 'Admin') === 0 && $role !== 'Admin') {
            $adminCount = (int) $pdo->query(
                "SELECT COUNT(*) FROM pulse_users WHERE role = 'Admin' AND is_active = 1"
            )->fetchColumn();
            if ($adminCount <= 1) {
                pulseRespond(['error' => 'Minimal harus ada satu akun Admin aktif.'], 409);
            }
        }

        if ($password !== '' && strlen($password) < 12) {
            pulseRespond(['error' => 'Password baru minimal 12 karakter.'], 400);
        }
        $sql = 'UPDATE pulse_users SET full_name = :full_name, role = :role, site = :site';
        $params = ['full_name' => $fullName, 'role' => $role, 'site' => $site, 'username' => $username];
        if ($password !== '') {
            $sql .= ', password_hash = :password_hash';
            $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE username = :username';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        if ($password !== '') {
            $revokeSessions = $pdo->prepare('DELETE FROM pulse_sessions WHERE username = :username');
            $revokeSessions->execute(['username' => $username]);
        }
        pulseRespond(['ok' => true]);
    }

    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
    pulseRespond(['error' => 'Rute atau metode tidak didukung.'], 405);
} catch (InvalidArgumentException $error) {
    pulseRespond(['error' => $error->getMessage()], 503);
} catch (Throwable $error) {
    error_log('Pulse auth API: ' . $error->getMessage());
    pulseRespond(['error' => 'Layanan autentikasi gagal. Periksa konfigurasi server.'], 500);
}
