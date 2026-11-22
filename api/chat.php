<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const PULSE_CHAT_MAX_LEN = 500;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/**
 * Auto-create tabel chat (idempotent) agar fitur langsung jalan tanpa migrasi manual.
 */
function pulseEnsureChatTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS pulse_chat (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            full_name VARCHAR(128) NOT NULL DEFAULT \'\',
            site VARCHAR(64) NOT NULL DEFAULT \'\',
            role VARCHAR(24) NOT NULL DEFAULT \'User\',
            message VARCHAR(500) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

function pulseChatRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'full_name' => (string) $row['full_name'],
        'site' => (string) $row['site'],
        'role' => (string) $row['role'],
        'message' => (string) $row['message'],
        'created_ts' => (int) $row['created_ts'],
    ];
}

function pulseChatCharCount(string $text): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($text, 'UTF-8');
    }
    $count = preg_match_all('/./us', $text);
    return $count === false ? strlen($text) : (int) $count;
}

try {
    $pdo = pulseDatabase();
    pulseEnsureChatTable($pdo);

    // GET: 50 pesan terakhir (atau hanya pesan baru bila after_id diisi). Wajib login.
    if ($method === 'GET') {
        pulseCurrentUser($pdo);
        $afterId = (int) ($_GET['after_id'] ?? 0);
        if ($afterId > 0) {
            $statement = $pdo->prepare(
                'SELECT id, username, full_name, site, role, message, UNIX_TIMESTAMP(created_at) AS created_ts
                 FROM pulse_chat WHERE id > :after_id ORDER BY id ASC LIMIT 50'
            );
            $statement->execute(['after_id' => $afterId]);
            $rows = $statement->fetchAll();
        } else {
            $rows = array_reverse($pdo->query(
                'SELECT id, username, full_name, site, role, message, UNIX_TIMESTAMP(created_at) AS created_ts
                 FROM pulse_chat ORDER BY id DESC LIMIT 50'
            )->fetchAll());
        }
        $messages = array_map('pulseChatRow', $rows);
        $lastId = 0;
        foreach ($messages as $message) {
            if ($message['id'] > $lastId) {
                $lastId = $message['id'];
            }
        }
        pulseRespond([
            'messages' => $messages,
            'last_id' => $lastId,
            'server_time' => time(),
        ]);
    }

    // POST: kirim pesan baru memakai sesi login aktif. Maksimal 500 karakter.
    if ($method === 'POST') {
        pulseRequireAllowedOrigin();
        $user = pulseCurrentUser($pdo);
        $payload = pulseReadJsonBody();
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            pulseRespond(['error' => 'Pesan tidak boleh kosong.'], 400);
        }
        if (pulseChatCharCount($message) > PULSE_CHAT_MAX_LEN) {
            pulseRespond(['error' => 'Pesan maksimal 500 karakter.'], 400);
        }
        $insert = $pdo->prepare(
            'INSERT INTO pulse_chat (username, full_name, site, role, message)
             VALUES (:username, :full_name, :site, :role, :message)'
        );
        $insert->execute([
            'username' => $user['username'],
            'full_name' => $user['namaLengkap'],
            'site' => $user['site'],
            'role' => $user['role'],
            'message' => $message,
        ]);
        $newId = (int) $pdo->lastInsertId();
        $statement = $pdo->prepare(
            'SELECT id, username, full_name, site, role, message, UNIX_TIMESTAMP(created_at) AS created_ts
             FROM pulse_chat WHERE id = :id'
        );
        $statement->execute(['id' => $newId]);
        pulseRespond(['ok' => true, 'message' => pulseChatRow($statement->fetch())]);
    }

    header('Allow: GET, POST, OPTIONS');
    pulseRespond(['error' => 'Rute atau metode tidak didukung.'], 405);
} catch (InvalidArgumentException $error) {
    pulseRespond(['error' => $error->getMessage()], 503);
} catch (Throwable $error) {
    error_log('Pulse chat API: ' . $error->getMessage());
    pulseRespond(['error' => 'Layanan chat gagal. Periksa konfigurasi server.'], 500);
}
