<?php
declare(strict_types=1);

const PULSE_SESSION_COOKIE = 'pulse_cho_session';
const PULSE_SESSION_TTL_SECONDS = 43200;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function pulseRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function pulseConfigureCors(): void
{
    $allowedOrigin = trim((string) (getenv('PULSE_ALLOWED_ORIGIN') ?: ''));
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $isLocalOrigin = $allowedOrigin === ''
        && preg_match('#^https?://(localhost|127\.0\.0\.1|pulse-cho\.test)(:\d+)?$#i', $origin) === 1;

    if ($origin !== '' && (
        ($allowedOrigin !== '' && hash_equals($allowedOrigin, $origin))
        || $isLocalOrigin
    )) {
        header('Access-Control-Allow-Origin: ' . ($allowedOrigin !== '' ? $allowedOrigin : $origin));
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    } elseif ($origin !== '' && $allowedOrigin !== '') {
        pulseRespond(['error' => 'Origin tidak diizinkan.'], 403);
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        if ($allowedOrigin === '' && $origin !== '' && !$isLocalOrigin) {
            pulseRespond(['error' => 'PULSE_ALLOWED_ORIGIN belum dikonfigurasi.'], 503);
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
        http_response_code(204);
        exit;
    }
}

function pulseRequireAllowedOrigin(): void
{
    $allowedOrigin = trim((string) (getenv('PULSE_ALLOWED_ORIGIN') ?: ''));
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $isLocalOrigin = $allowedOrigin === ''
        && preg_match('#^https?://(localhost|127\.0\.0\.1|pulse-cho\.test)(:\d+)?$#i', $origin) === 1;

    if ($allowedOrigin !== '' && ($origin === '' || !hash_equals($allowedOrigin, $origin))) {
        pulseRespond(['error' => 'Origin tidak diizinkan.'], 403);
    }
    if ($allowedOrigin === '' && $origin !== '' && !$isLocalOrigin) {
        pulseRespond(['error' => 'PULSE_ALLOWED_ORIGIN belum dikonfigurasi.'], 503);
    }
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

    foreach (['host', 'database', 'username', 'password', 'ssl_ca'] as $key) {
        if (trim((string) $config[$key]) === '') {
            throw new InvalidArgumentException('Konfigurasi TiDB belum lengkap.');
        }
    }
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('Ekstensi PHP pdo_mysql belum aktif.');
    }
    if (!is_file($config['ssl_ca'])) {
        throw new InvalidArgumentException('File CA TLS TiDB tidak ditemukan.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_SSL_CA => $config['ssl_ca'],
    ];
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
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

function pulseSetSessionCookie(string $token, int $maxAge): void
{
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    setcookie(PULSE_SESSION_COOKIE, $token, [
        'expires' => time() + $maxAge,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $secure ? 'None' : 'Lax',
    ]);
}

pulseConfigureCors();
