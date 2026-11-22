<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const PULSE_CHAT_MAX_LEN = 500;
const PULSE_CHAT_EDIT_WINDOW = 120; // detik: batas edit/hapus pesan sendiri (2 menit)

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/**
 * Auto-create tabel chat (idempotent) + migrasi aman kolom baru bila belum ada.
 * Agar fitur langsung jalan tanpa migrasi manual.
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
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            edited_at TIMESTAMP NULL DEFAULT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

    // Migrasi aman untuk tabel versi lama: tambahkan kolom hanya bila belum ada.
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM pulse_chat')->fetchAll() as $column) {
        $columns[strtolower((string) $column['Field'])] = true;
    }
    if (!isset($columns['edited_at'])) {
        $pdo->exec('ALTER TABLE pulse_chat ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL');
    }
    if (!isset($columns['deleted_at'])) {
        $pdo->exec('ALTER TABLE pulse_chat ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL');
    }
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
        'edited' => !empty($row['is_edited']),
    ];
}

/** Ambil satu pesan siap-render berdasarkan ID. */
function pulseChatFetchById(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(
        'SELECT id, username, full_name, site, role, message,
                UNIX_TIMESTAMP(created_at) AS created_ts,
                CASE WHEN edited_at IS NULL THEN 0 ELSE 1 END AS is_edited
         FROM pulse_chat WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    return $row ? pulseChatRow($row) : [];
}

/**
 * Ambil pesan milik user yang sedang login (belum dihapus).
 * Mengembalikan null bila tidak ada / bukan miliknya / sudah dihapus.
 * Nilai age_seconds dipakai untuk menegakkan batas waktu 2 menit di server.
 */
function pulseChatFetchOwned(PDO $pdo, int $id, string $username): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, username, deleted_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds
         FROM pulse_chat WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    if (!$row || $row['deleted_at'] !== null) {
        return null;
    }
    if ((string) $row['username'] !== $username) {
        return null;
    }
    return $row;
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
    // Menyertakan daftar edit & hapus terkini agar klien lain ikut sinkron saat polling.
    if ($method === 'GET') {
        pulseCurrentUser($pdo);
        $afterId = (int) ($_GET['after_id'] ?? 0);
        $selectColumns = 'SELECT id, username, full_name, site, role, message,
                UNIX_TIMESTAMP(created_at) AS created_ts,
                CASE WHEN edited_at IS NULL THEN 0 ELSE 1 END AS is_edited
            FROM pulse_chat';
        $edited = [];
        $deletedIds = [];

        if ($afterId > 0) {
            $statement = $pdo->prepare($selectColumns . ' WHERE id > :after_id AND deleted_at IS NULL ORDER BY id ASC LIMIT 50');
            $statement->execute(['after_id' => $afterId]);
            $rows = $statement->fetchAll();

            // Pesan yang diedit dalam jendela sinkron (agar klien lain memperbarui isi).
            $editedStatement = $pdo->prepare($selectColumns .
                ' WHERE deleted_at IS NULL AND edited_at IS NOT NULL AND edited_at >= (NOW() - INTERVAL 900 SECOND) ORDER BY id ASC LIMIT 50');
            $editedStatement->execute();
            $edited = array_map('pulseChatRow', $editedStatement->fetchAll());

            // ID pesan yang dihapus dalam jendela sinkron (agar klien lain ikut menghapus).
            $deletedStatement = $pdo->prepare(
                'SELECT id FROM pulse_chat WHERE deleted_at IS NOT NULL AND deleted_at >= (NOW() - INTERVAL 900 SECOND) ORDER BY id ASC LIMIT 100'
            );
            $deletedStatement->execute();
            foreach ($deletedStatement->fetchAll() as $deletedRow) {
                $deletedIds[] = (int) $deletedRow['id'];
            }
        } else {
            $rows = array_reverse($pdo->query(
                $selectColumns . ' WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 50'
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
            'edited' => $edited,
            'deleted_ids' => $deletedIds,
            'last_id' => $lastId,
            'server_time' => time(),
        ]);
    }

    // POST: satu endpoint dengan field "action" (send / edit / delete) memakai sesi login aktif.
    if ($method === 'POST') {
        pulseRequireAllowedOrigin();
        $user = pulseCurrentUser($pdo);
        $payload = pulseReadJsonBody();
        $action = strtolower(trim((string) ($payload['action'] ?? 'send')));
        if ($action === '') {
            $action = 'send';
        }

        // --- Kirim pesan baru (maksimal 500 karakter) ---
        if ($action === 'send') {
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
            pulseRespond(['ok' => true, 'message' => pulseChatFetchById($pdo, $newId), 'server_time' => time()]);
        }

        // --- Edit / Hapus pesan sendiri, wajib ≤ 2 menit sejak created_at ---
        if ($action === 'edit' || $action === 'delete') {
            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                pulseRespond(['error' => 'ID pesan tidak valid.'], 400);
            }
            // Validasi kepemilikan + keberadaan pesan (ditegakkan di server, bukan hanya UI).
            $target = pulseChatFetchOwned($pdo, $id, (string) $user['username']);
            if ($target === null) {
                pulseRespond(['error' => 'Pesan tidak ditemukan atau bukan milik akun Anda.'], 403);
            }
            if ((int) $target['age_seconds'] > PULSE_CHAT_EDIT_WINDOW) {
                pulseRespond(['error' => 'Batas 2 menit untuk mengubah atau menghapus pesan sudah lewat.'], 403);
            }

            if ($action === 'delete') {
                $pdo->prepare('UPDATE pulse_chat SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute(['id' => $id]);
                pulseRespond(['ok' => true, 'deleted_id' => $id, 'server_time' => time()]);
            }

            $message = trim((string) ($payload['message'] ?? ''));
            if ($message === '') {
                pulseRespond(['error' => 'Pesan tidak boleh kosong.'], 400);
            }
            if (pulseChatCharCount($message) > PULSE_CHAT_MAX_LEN) {
                pulseRespond(['error' => 'Pesan maksimal 500 karakter.'], 400);
            }
            $pdo->prepare('UPDATE pulse_chat SET message = :message, edited_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['message' => $message, 'id' => $id]);
            pulseRespond(['ok' => true, 'message' => pulseChatFetchById($pdo, $id), 'server_time' => time()]);
        }

        pulseRespond(['error' => 'Aksi chat tidak didukung.'], 400);
    }

    header('Allow: GET, POST, OPTIONS');
    pulseRespond(['error' => 'Rute atau metode tidak didukung.'], 405);
} catch (InvalidArgumentException $error) {
    pulseRespond(['error' => $error->getMessage()], 503);
} catch (Throwable $error) {
    error_log('Pulse chat API: ' . $error->getMessage());
    pulseRespond(['error' => 'Layanan chat gagal. Periksa konfigurasi server.'], 500);
}
