<?php
declare(strict_types=1);

const PULSE_SESSION_COOKIE = 'pulse_cho_session';
const PULSE_SESSION_TTL_SECONDS = 43200;

// Key menu yang dapat dikonfigurasi hak aksesnya per role (dipakai juga oleh UI).
const PULSE_MENU_KEYS = [
    'nav-dashboard',
    'nav-grapari',
    'action-export',
    'action-upload',
    'action-activity',
    'action-users',
];

// Hak akses menu default bila admin belum mengubah konfigurasi.
const PULSE_DEFAULT_ROLE_MENUS = [
    'Admin' => ['nav-dashboard', 'nav-grapari', 'action-export', 'action-upload', 'action-activity', 'action-users'],
    'User' => ['nav-dashboard', 'nav-grapari', 'action-export'],
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function pulseRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function pulseAllowedOrigins(): array
{
    $raw = trim((string) (getenv('PULSE_ALLOWED_ORIGIN') ?: ''));
    if ($raw === '') {
        return [];
    }
    // Boleh lebih dari satu origin, dipisah koma atau titik koma.
    $origins = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_map(static fn (string $o): string => rtrim($o, '/'), $origins));
}

function pulseIsOriginAllowed(string $origin, array $allowedOrigins): bool
{
    if ($origin === '') {
        return false;
    }
    if ($allowedOrigins === []) {
        return preg_match('#^https?://(localhost|127\.0\.0\.1|pulse-cho\.test)(:\d+)?$#i', $origin) === 1;
    }
    foreach ($allowedOrigins as $allowed) {
        if (hash_equals($allowed, rtrim($origin, '/'))) {
            return true;
        }
    }
    return false;
}

function pulseIsSameOrigin(string $origin): bool
{
    if ($origin === '') {
        return true;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    $scheme = $isHttps ? 'https' : 'http';
    return $origin === ($scheme . '://' . $host);
}

function pulseConfigureCors(): void
{
    $allowedOrigins = pulseAllowedOrigins();
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $isAllowed = pulseIsOriginAllowed($origin, $allowedOrigins) || pulseIsSameOrigin($origin);

    if ($origin !== '' && $isAllowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    } elseif ($origin !== '' && $allowedOrigins !== [] && !pulseIsSameOrigin($origin)) {
        pulseRespond(['error' => 'Origin tidak diizinkan.'], 403);
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
        http_response_code(204);
        exit;
    }
}

function pulseRequireAllowedOrigin(): void
{
    $allowedOrigins = pulseAllowedOrigins();
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

    // Request dari domain yang sama dengan API selalu sah (mode satu domain).
    if (pulseIsSameOrigin($origin)) {
        return;
    }
    if ($allowedOrigins !== []) {
        if (!pulseIsOriginAllowed($origin, $allowedOrigins)) {
            pulseRespond(['error' => 'Origin tidak diizinkan.'], 403);
        }
        return;
    }
    pulseRespond(['error' => 'Origin tidak diizinkan. Tambahkan domain ini ke PULSE_ALLOWED_ORIGIN.'], 403);
}

function pulseDatabase(): PDO
{
    $localConfigPath = __DIR__ . '/tidb-config.php';
    $config = is_file($localConfigPath) ? require $localConfigPath : [];
    if (!is_array($config)) {
        throw new RuntimeException('Format konfigurasi TiDB tidak valid.');
    }

    $config = [
        'host' => getenv('PULSE_TIDB_HOST') ?: ($config['host'] ?? ''),
        'port' => getenv('PULSE_TIDB_PORT') ?: ($config['port'] ?? 4000),
        'database' => getenv('PULSE_TIDB_DATABASE') ?: ($config['database'] ?? 'pulse_cho'),
        'username' => getenv('PULSE_TIDB_USERNAME') ?: ($config['username'] ?? ''),
        'password' => getenv('PULSE_TIDB_PASSWORD') ?: ($config['password'] ?? ''),
        'ssl_ca' => getenv('PULSE_TIDB_CA_PATH') ?: ($config['ssl_ca'] ?? ''),
    ];

    $hasCa = trim((string) $config['ssl_ca']) !== '';
    foreach (['host', 'database', 'username', 'password'] as $key) {
        if (trim((string) $config[$key]) === '') {
            throw new InvalidArgumentException('Konfigurasi TiDB belum lengkap.');
        }
    }
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('Ekstensi PHP pdo_mysql belum aktif.');
    }
    if ($hasCa && !is_file($config['ssl_ca'])) {
        throw new InvalidArgumentException('File CA TLS TiDB tidak ditemukan.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if ($hasCa) {
        // CA khusus TiDB (paling aman: verifikasi penuh)
        $options[PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'];
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
    } else {
        // Tanpa file CA: tetap pakai TLS dengan CA bawaan sistem operasi.
        // Port 4000 TiDB mewajibkan TLS, jadi jangan jatuh ke koneksi plaintext.
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        (int) $config['port'],
        $config['database']
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function pulseReadJsonBody(): array
{
    $body = file_get_contents('php://input');
    $payload = json_decode($body === false ? '' : $body, true);
    if (!is_array($payload)) {
        pulseRespond(['error' => 'Request harus berisi JSON yang valid.'], 400);
    }
    return $payload;
}

function pulseEnsureBootstrapAdmin(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM pulse_users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $username = trim((string) (getenv('PULSE_ADMIN_USERNAME') ?: ''));
    $password = (string) (getenv('PULSE_ADMIN_PASSWORD') ?: '');
    $name = trim((string) (getenv('PULSE_ADMIN_NAME') ?: ''));
    $site = trim((string) (getenv('PULSE_ADMIN_SITE') ?: ''));
    if ($username === '' || strlen($password) < 12 || $name === '' || $site === '') {
        throw new RuntimeException('User belum tersedia. Atur environment Admin awal di layanan API.');
    }

    $statement = $pdo->prepare(
        "INSERT INTO pulse_users (username, full_name, role, site, password_hash, is_active)
         VALUES (:username, :full_name, 'Admin', :site, :password_hash, 1)"
    );
    $statement->execute([
        'username' => $username,
        'full_name' => $name,
        'site' => $site,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
}

function pulseCurrentUser(PDO $pdo, bool $required = true): ?array
{
    $token = (string) ($_COOKIE[PULSE_SESSION_COOKIE] ?? '');
    if ($token === '') {
        if ($required) {
            pulseRespond(['error' => 'Silakan login kembali.'], 401);
        }
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT u.username, u.full_name, u.role, u.site
         FROM pulse_sessions s
         JOIN pulse_users u ON u.username = s.username
         WHERE s.token_hash = :token_hash
           AND s.expires_at > UTC_TIMESTAMP()
           AND u.is_active = 1
         LIMIT 1'
    );
    $statement->execute(['token_hash' => hash('sha256', $token)]);
    $user = $statement->fetch();
    if (!$user) {
        if ($required) {
            pulseRespond(['error' => 'Sesi berakhir. Silakan login kembali.'], 401);
        }
        return null;
    }

    return [
        'username' => (string) $user['username'],
        'namaLengkap' => (string) $user['full_name'],
        'role' => (string) $user['role'],
        'site' => (string) $user['site'],
    ];
}

function pulseRequireAdmin(array $user): void
{
    if (strcasecmp((string) $user['role'], 'Admin') !== 0) {
        pulseRespond(['error' => 'Akses hanya untuk Admin.'], 403);
    }
}

/**
 * Pastikan tabel log aktivitas tersedia (idempotent, aman bila dijalankan berulang).
 * Dibuat otomatis saat runtime agar tidak wajib menjalankan migrasi manual.
 */
function pulseEnsureActivityLog(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pulse_activity_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                activity VARCHAR(32) NOT NULL,
                detail VARCHAR(255) NULL,
                ip_address VARCHAR(64) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pulse_activity_user_time (username, created_at),
                INDEX idx_pulse_activity_time (created_at)
            )'
        );
    } catch (Throwable $error) {
        // Kegagalan di sini tidak boleh menghentikan alur login/logout.
        error_log('Pulse activity log init: ' . $error->getMessage());
    }
}

/**
 * Catat satu baris aktivitas (login/logout). Selalu best-effort:
 * kegagalan penulisan log tidak boleh memblokir autentikasi.
 */
function pulseLogActivity(PDO $pdo, string $username, string $activity, string $detail = ''): void
{
    $username = trim($username);
    if ($username === '') {
        return;
    }
    try {
        pulseEnsureActivityLog($pdo);
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $statement = $pdo->prepare(
            'INSERT INTO pulse_activity_log (username, activity, detail, ip_address)
             VALUES (:username, :activity, :detail, :ip_address)'
        );
        $statement->execute([
            'username' => substr($username, 0, 64),
            'activity' => substr($activity, 0, 32),
            'detail' => $detail === '' ? null : substr($detail, 0, 255),
            'ip_address' => $ip === '' ? null : substr($ip, 0, 64),
        ]);
    } catch (Throwable $error) {
        error_log('Pulse activity log write: ' . $error->getMessage());
    }
}

/**
 * Ubah datetime UTC dari TiDB menjadi ISO-8601 (…Z) untuk dikonsumsi browser.
 */
function pulseIsoTime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime(substr($value, 0, 19) . ' UTC');
    if ($timestamp === false) {
        return null;
    }
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
}

/**
 * Pastikan tabel hak akses menu per role tersedia (idempotent, best-effort).
 * Dibuat otomatis saat runtime agar tidak wajib menjalankan migrasi manual.
 */
function pulseEnsureRoleMenus(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pulse_role_menus (
                role VARCHAR(32) NOT NULL PRIMARY KEY,
                menus TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )'
        );
    } catch (Throwable $error) {
        // Kegagalan di sini tidak boleh menghentikan alur autentikasi.
        error_log('Pulse role menus init: ' . $error->getMessage());
    }
}

/**
 * Ambil konfigurasi hak akses menu per role. Selalu mengembalikan nilai default
 * bila tabel belum tersedia, sehingga login/navigasi tidak pernah gagal.
 */
function pulseGetRoleMenus(PDO $pdo): array
{
    $result = PULSE_DEFAULT_ROLE_MENUS;
    try {
        pulseEnsureRoleMenus($pdo);
        $rows = $pdo->query('SELECT role, menus FROM pulse_role_menus')->fetchAll();
        foreach ($rows as $row) {
            $role = (string) $row['role'];
            $decoded = json_decode((string) $row['menus'], true);
            if (!is_array($decoded)) {
                continue;
            }
            $result[$role] = array_values(array_intersect(
                PULSE_MENU_KEYS,
                array_map('strval', $decoded)
            ));
        }
    } catch (Throwable $error) {
        error_log('Pulse role menus read: ' . $error->getMessage());
        return PULSE_DEFAULT_ROLE_MENUS;
    }
    // Admin selalu memiliki seluruh menu agar tidak terkunci dari pengaturan.
    $result['Admin'] = PULSE_MENU_KEYS;
    return $result;
}

/**
 * Simpan konfigurasi hak akses menu per role (hanya role yang dikenal).
 * Role Admin dipaksa penuh agar admin tidak dapat mengunci dirinya sendiri.
 */
function pulseSaveRoleMenus(PDO $pdo, array $roles): array
{
    pulseEnsureRoleMenus($pdo);
    $cleaned = [];
    foreach (['Admin', 'User'] as $role) {
        $keys = $roles[$role] ?? [];
        if (!is_array($keys)) {
            $keys = [];
        }
        $cleaned[$role] = array_values(array_intersect(
            PULSE_MENU_KEYS,
            array_map('strval', $keys)
        ));
    }
    $cleaned['Admin'] = PULSE_MENU_KEYS;

    $statement = $pdo->prepare(
        'INSERT INTO pulse_role_menus (role, menus) VALUES (:role, :menus)
         ON DUPLICATE KEY UPDATE menus = VALUES(menus)'
    );
    foreach ($cleaned as $role => $keys) {
        $statement->execute([
            'role' => $role,
            'menus' => json_encode($keys),
        ]);
    }
    return $cleaned;
}

function pulseSetSessionCookie(string $token, int $maxAge): void
{
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    // Mode satu domain (UI + API di host yang sama): Lax sudah cukup dan paling
    // kompatibel. SameSite=None hanya diperlukan bila API berada di host lain.
    $isSameOrigin = pulseIsSameOrigin(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')));
    setcookie(PULSE_SESSION_COOKIE, $token, [
        'expires' => time() + $maxAge,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => (!$isSameOrigin && $secure) ? 'None' : 'Lax',
    ]);
}

pulseConfigureCors();
