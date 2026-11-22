<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const PULSE_CHAT_MAX_LEN = 500;
const PULSE_CHAT_EDIT_WINDOW = 120;       // detik: batas edit/hapus pesan sendiri (2 menit)
const PULSE_CHAT_ACTIVE_WINDOW = 180;     // detik: user dianggap aktif bila polling terakhir <= 3 menit
const PULSE_CHAT_RETENTION_HOURS = 48;    // auto-cleanup pesan lebih tua dari 2 hari
const PULSE_CHAT_CLEANUP_INTERVAL = 600;  // throttle: cleanup maksimal sekali per 10 menit
const PULSE_CHAT_MAX_TAGS = 10;           // batas jumlah @tag per pesan

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
            peer_a VARCHAR(64) NOT NULL DEFAULT \'\',
            peer_b VARCHAR(64) NOT NULL DEFAULT \'\',
            tagged VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            edited_at TIMESTAMP NULL DEFAULT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_pulse_chat_room (peer_a, peer_b, id),
            INDEX idx_pulse_chat_created (created_at)
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
    if (!isset($columns['peer_a'])) {
        $pdo->exec('ALTER TABLE pulse_chat ADD COLUMN peer_a VARCHAR(64) NOT NULL DEFAULT \'\'');
    }
    if (!isset($columns['peer_b'])) {
        $pdo->exec('ALTER TABLE pulse_chat ADD COLUMN peer_b VARCHAR(64) NOT NULL DEFAULT \'\'');
    }
    if (!isset($columns['tagged'])) {
        $pdo->exec('ALTER TABLE pulse_chat ADD COLUMN tagged VARCHAR(255) NOT NULL DEFAULT \'\'');
    }
    try {
        $pdo->exec('CREATE INDEX idx_pulse_chat_room ON pulse_chat (peer_a, peer_b, id)');
    } catch (Throwable $ignored) {
        // Index sudah ada / tidak didukung: aman diabaikan.
    }
}

/** Tabel presence: mencatat aktivitas polling terakhir tiap user (untuk daftar user aktif). */
function pulseEnsureChatPresence(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS pulse_chat_presence (
            username VARCHAR(64) NOT NULL PRIMARY KEY,
            full_name VARCHAR(128) NOT NULL DEFAULT \'\',
            site VARCHAR(64) NOT NULL DEFAULT \'\',
            role VARCHAR(24) NOT NULL DEFAULT \'User\',
            last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pulse_chat_presence_seen (last_seen)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

/** Tabel penanda baca: menyimpan id pesan terakhir yang sudah dibaca per user & per room. */
function pulseEnsureChatReads(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS pulse_chat_reads (
            username VARCHAR(64) NOT NULL,
            room VARCHAR(140) NOT NULL,
            last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (username, room)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

/** Tabel kecil untuk throttle auto-cleanup agar tidak dijalankan tiap polling. */
function pulseEnsureChatMeta(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS pulse_chat_meta (
            meta_key VARCHAR(32) NOT NULL PRIMARY KEY,
            meta_value BIGINT NOT NULL DEFAULT 0
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

/** Daftar username dari kolom tagged (disimpan sebagai string dipisah koma). */
function pulseChatTagList(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $parts = array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== '');
    return array_values($parts);
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
        'tagged' => pulseChatTagList((string) ($row['tagged'] ?? '')),
    ];
}

/** Ambil satu pesan siap-render berdasarkan ID. */
function pulseChatFetchById(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(
        'SELECT id, username, full_name, site, role, message, tagged,
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

/**
 * Pasangan partisipan room terkanonikalisasi (pesan grup = ['', '']).
 * DM selalu tersimpan dengan urutan nama yang deterministik agar kedua pihak berbagi room yang sama.
 */
function pulseChatRoomPair(string $me, string $peer): array
{
    if ($peer === '' || strcasecmp($peer, $me) === 0) {
        return ['', ''];
    }
    return strcmp($me, $peer) <= 0 ? [$me, $peer] : [$peer, $me];
}

/** Kunci room versi stabil untuk tabel penanda baca. */
function pulseChatRoomKey(string $peerA, string $peerB): string
{
    if ($peerA === '' && $peerB === '') {
        return 'group';
    }
    return 'dm:' . $peerA . '|' . $peerB;
}

/** Catat aktivitas polling user agar muncul di daftar user aktif. */
function pulseChatTouchPresence(PDO $pdo, array $user): void
{
    try {
        $statement = $pdo->prepare(
            'INSERT INTO pulse_chat_presence (username, full_name, site, role, last_seen)
             VALUES (:username, :full_name, :site, :role, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                full_name = :full_name2, site = :site2, role = :role2, last_seen = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'username' => $user['username'],
            'full_name' => $user['namaLengkap'],
            'site' => $user['site'],
            'role' => $user['role'],
            'full_name2' => $user['namaLengkap'],
            'site2' => $user['site'],
            'role2' => $user['role'],
        ]);
    } catch (Throwable $error) {
        error_log('Pulse chat presence: ' . $error->getMessage());
    }
}

/**
 * Daftar user aktif (heartbeat <= 3 menit terakhir), selain user yang sedang login.
 * Sengaja TANPA filter role: semua user (Admin, PIC WhatsApp, User, dst.) yang aktif
 * ikut ditampilkan selama presence-nya masih segar.
 */
function pulseChatActiveUsers(PDO $pdo, string $me): array
{
    $statement = $pdo->prepare(
        'SELECT username, full_name, site, role, UNIX_TIMESTAMP(last_seen) AS last_seen_ts
         FROM pulse_chat_presence
         WHERE username <> :me
           AND last_seen >= (UTC_TIMESTAMP() - INTERVAL ' . PULSE_CHAT_ACTIVE_WINDOW . ' SECOND)
         ORDER BY full_name ASC, username ASC'
    );
    $statement->execute(['me' => $me]);
    $users = [];
    foreach ($statement->fetchAll() as $row) {
        $users[] = [
            'username' => (string) $row['username'],
            'full_name' => (string) $row['full_name'],
            'site' => (string) $row['site'],
            'role' => (string) $row['role'],
            'last_seen_ts' => (int) $row['last_seen_ts'],
        ];
    }
    return $users;
}

/** Hitung pesan belum dibaca per room untuk user yang sedang login. */
function pulseChatUnread(PDO $pdo, string $me): array
{
    $reads = [];
    $readStatement = $pdo->prepare('SELECT room, last_read_id FROM pulse_chat_reads WHERE username = :me');
    $readStatement->execute(['me' => $me]);
    foreach ($readStatement->fetchAll() as $readRow) {
        $reads[(string) $readRow['room']] = (int) $readRow['last_read_id'];
    }

    $result = ['group' => 0, 'dms' => []];

    $groupStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM pulse_chat
         WHERE peer_a = \'\' AND peer_b = \'\' AND deleted_at IS NULL
           AND username <> :me AND id > :last'
    );
    $groupStatement->execute(['me' => $me, 'last' => $reads['group'] ?? 0]);
    $result['group'] = (int) $groupStatement->fetchColumn();

    $roomStatement = $pdo->prepare(
        'SELECT peer_a, peer_b FROM pulse_chat
         WHERE (peer_a = :me OR peer_b = :me)
         GROUP BY peer_a, peer_b'
    );
    $roomStatement->execute(['me' => $me]);
    $countStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM pulse_chat
         WHERE peer_a = :a AND peer_b = :b AND deleted_at IS NULL
           AND username <> :me AND id > :last'
    );
    foreach ($roomStatement->fetchAll() as $roomRow) {
        $a = (string) $roomRow['peer_a'];
        $b = (string) $roomRow['peer_b'];
        if ($a === '' && $b === '') {
            continue;
        }
        $peer = $a === $me ? $b : $a;
        $key = pulseChatRoomKey($a, $b);
        $countStatement->execute(['a' => $a, 'b' => $b, 'me' => $me, 'last' => $reads[$key] ?? 0]);
        $unread = (int) $countStatement->fetchColumn();
        if ($unread > 0) {
            $result['dms'][$peer] = $unread;
        }
    }

    return $result;
}

/** Tandai room sebagai sudah dibaca sampai pesan terakhir (reset unread otomatis). */
function pulseChatMarkRead(PDO $pdo, string $username, string $peerA, string $peerB): void
{
    $maxStatement = $pdo->prepare(
        'SELECT MAX(id) FROM pulse_chat WHERE peer_a = :a AND peer_b = :b AND deleted_at IS NULL'
    );
    $maxStatement->execute(['a' => $peerA, 'b' => $peerB]);
    $maxId = (int) $maxStatement->fetchColumn();
    if ($maxId <= 0) {
        return;
    }
    $room = pulseChatRoomKey($peerA, $peerB);
    $pdo->prepare(
        'INSERT INTO pulse_chat_reads (username, room, last_read_id) VALUES (:username, :room, :last)
         ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, :last2)'
    )->execute(['username' => $username, 'room' => $room, 'last' => $maxId, 'last2' => $maxId]);
}

/** Ambil @username dari isi pesan dan cocokkan dengan user yang benar-benar terdaftar. */
function pulseChatExtractTags(PDO $pdo, string $message): array
{
    if (!preg_match_all('/@([A-Za-z0-9._-]{2,64})/', $message, $matches)) {
        return [];
    }
    $candidates = array_slice(array_values(array_unique(array_map('strtolower', $matches[1]))), 0, PULSE_CHAT_MAX_TAGS * 2);
    if ($candidates === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $statement = $pdo->prepare('SELECT username FROM pulse_users WHERE LOWER(username) IN (' . $placeholders . ')');
    $statement->execute($candidates);
    $canonical = [];
    foreach ($statement->fetchAll() as $row) {
        $canonical[strtolower((string) $row['username'])] = (string) $row['username'];
    }
    $tags = [];
    foreach ($candidates as $candidate) {
        if (isset($canonical[$candidate])) {
            $tags[$canonical[$candidate]] = true;
        }
    }
    return array_slice(array_keys($tags), 0, PULSE_CHAT_MAX_TAGS);
}

/** Auto-cleanup pesan > 2 hari (dan presence kedaluwarsa), di-throttle agar hemat I/O. */
function pulseChatCleanup(PDO $pdo): void
{
    try {
        $now = time();
        $pdo->prepare('INSERT IGNORE INTO pulse_chat_meta (meta_key, meta_value) VALUES (:k, 0)')
            ->execute(['k' => 'chat_cleanup']);
        $claim = $pdo->prepare(
            'UPDATE pulse_chat_meta SET meta_value = :now WHERE meta_key = :k AND meta_value < :threshold'
        );
        $claim->execute(['now' => $now, 'k' => 'chat_cleanup', 'threshold' => $now - PULSE_CHAT_CLEANUP_INTERVAL]);
        if ($claim->rowCount() < 1) {
            return; // User lain baru saja menjalankan cleanup.
        }
        $pdo->exec('DELETE FROM pulse_chat WHERE created_at < (UTC_TIMESTAMP() - INTERVAL ' . PULSE_CHAT_RETENTION_HOURS . ' HOUR)');
        $pdo->exec('DELETE FROM pulse_chat_presence WHERE last_seen < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
    } catch (Throwable $error) {
        error_log('Pulse chat cleanup: ' . $error->getMessage());
    }
}

try {
    $pdo = pulseDatabase();
    pulseEnsureChatTable($pdo);
    pulseEnsureChatPresence($pdo);
    pulseEnsureChatReads($pdo);
    pulseEnsureChatMeta($pdo);

    $selectColumns = 'SELECT id, username, full_name, site, role, message, tagged,
                UNIX_TIMESTAMP(created_at) AS created_ts,
                CASE WHEN edited_at IS NULL THEN 0 ELSE 1 END AS is_edited
            FROM pulse_chat';

    // GET: pesan pada room aktif (grup atau DM) + daftar user aktif + unread. Wajib login.
    if ($method === 'GET') {
        $user = pulseCurrentUser($pdo);
        $me = (string) $user['username'];
        // Setiap request chat memperbarui heartbeat presence user (login awal & setiap polling).
        pulseChatTouchPresence($pdo, $user);
        pulseChatCleanup($pdo);

        // Heartbeat ringan (?presence=1): cukup perbarui presence lalu kirim daftar user aktif
        // + unread, tanpa memuat pesan. Dipakai agar semua user yang login (termasuk Admin &
        // PIC WhatsApp) tetap terdeteksi aktif meski jendela chat sedang tertutup.
        if (!empty($_GET['presence'])) {
            pulseRespond([
                'presence' => pulseChatActiveUsers($pdo, $me),
                'unread' => pulseChatUnread($pdo, $me),
                'server_time' => time(),
            ]);
        }

        $peer = trim((string) ($_GET['peer'] ?? ''));
        if (strcasecmp($peer, $me) === 0) {
            $peer = '';
        }
        [$peerA, $peerB] = pulseChatRoomPair($me, $peer);
        $isDm = $peer !== '';
        $roomFilter = $isDm ? 'peer_a = :ra AND peer_b = :rb' : 'peer_a = \'\' AND peer_b = \'\'';
        $roomParams = $isDm ? ['ra' => $peerA, 'rb' => $peerB] : [];

        // Reset unread bila klien sedang menampilkan room ini.
        if (!empty($_GET['read'])) {
            pulseChatMarkRead($pdo, $me, $peerA, $peerB);
        }

        $afterId = (int) ($_GET['after_id'] ?? 0);
        $edited = [];
        $deletedIds = [];

        if ($afterId > 0) {
            $statement = $pdo->prepare($selectColumns . ' WHERE ' . $roomFilter . ' AND id > :after_id AND deleted_at IS NULL ORDER BY id ASC LIMIT 50');
            $statement->execute(array_merge($roomParams, ['after_id' => $afterId]));
            $rows = $statement->fetchAll();

            // Pesan yang diedit dalam jendela sinkron (agar klien lain memperbarui isi).
            $editedStatement = $pdo->prepare($selectColumns . ' WHERE ' . $roomFilter . ' AND deleted_at IS NULL AND edited_at IS NOT NULL AND edited_at >= (UTC_TIMESTAMP() - INTERVAL 900 SECOND) ORDER BY id ASC LIMIT 50');
            $editedStatement->execute($roomParams);
            $edited = array_map('pulseChatRow', $editedStatement->fetchAll());

            // ID pesan yang dihapus dalam jendela sinkron (agar klien lain ikut menghapus).
            $deletedStatement = $pdo->prepare('SELECT id FROM pulse_chat WHERE ' . $roomFilter . ' AND deleted_at IS NOT NULL AND deleted_at >= (UTC_TIMESTAMP() - INTERVAL 900 SECOND) ORDER BY id ASC LIMIT 100');
            $deletedStatement->execute($roomParams);
            foreach ($deletedStatement->fetchAll() as $deletedRow) {
                $deletedIds[] = (int) $deletedRow['id'];
            }
        } else {
            $statement = $pdo->prepare($selectColumns . ' WHERE ' . $roomFilter . ' AND deleted_at IS NULL ORDER BY id DESC LIMIT 50');
            $statement->execute($roomParams);
            $rows = array_reverse($statement->fetchAll());
        }

        $messages = array_map('pulseChatRow', $rows);
        $lastId = 0;
        foreach ($messages as $message) {
            if ($message['id'] > $lastId) {
                $lastId = $message['id'];
            }
        }
        pulseRespond([
            'room' => $isDm ? 'dm' : 'group',
            'peer' => $peer,
            'messages' => $messages,
            'edited' => $edited,
            'deleted_ids' => $deletedIds,
            'last_id' => $lastId,
            'presence' => pulseChatActiveUsers($pdo, $me),
            'unread' => pulseChatUnread($pdo, $me),
            'server_time' => time(),
        ]);
    }

    // POST: satu endpoint dengan field "action" (send / edit / delete) memakai sesi login aktif.
    if ($method === 'POST') {
        pulseRequireAllowedOrigin();
        $user = pulseCurrentUser($pdo);
        $me = (string) $user['username'];
        // Heartbeat presence juga diperbarui pada setiap aksi POST (kirim/edit/hapus).
        pulseChatTouchPresence($pdo, $user);
        $payload = pulseReadJsonBody();
        $action = strtolower(trim((string) ($payload['action'] ?? 'send')));
        if ($action === '') {
            $action = 'send';
        }

        // Room tujuan: grup (default) atau DM 1-on-1 ke user aktif yang dipilih.
        $peer = trim((string) ($payload['peer'] ?? ''));
        if (strcasecmp($peer, $me) === 0) {
            $peer = '';
        }
        [$peerA, $peerB] = pulseChatRoomPair($me, $peer);

        // --- Kirim pesan baru (maksimal 500 karakter) ---
        if ($action === 'send') {
            $message = trim((string) ($payload['message'] ?? ''));
            if ($message === '') {
                pulseRespond(['error' => 'Pesan tidak boleh kosong.'], 400);
            }
            if (pulseChatCharCount($message) > PULSE_CHAT_MAX_LEN) {
                pulseRespond(['error' => 'Pesan maksimal 500 karakter.'], 400);
            }
            $tags = pulseChatExtractTags($pdo, $message);
            pulseChatCleanup($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO pulse_chat (username, full_name, site, role, message, peer_a, peer_b, tagged)
                 VALUES (:username, :full_name, :site, :role, :message, :peer_a, :peer_b, :tagged)'
            );
            $insert->execute([
                'username' => $me,
                'full_name' => $user['namaLengkap'],
                'site' => $user['site'],
                'role' => $user['role'],
                'message' => $message,
                'peer_a' => $peerA,
                'peer_b' => $peerB,
                'tagged' => implode(',', $tags),
            ]);
            $newId = (int) $pdo->lastInsertId();
            if ($peer === '') {
                // Pesan sendiri di grup otomatis dianggap sudah dibaca.
                pulseChatMarkRead($pdo, $me, '', '');
            }
            pulseRespond([
                'ok' => true,
                'message' => pulseChatFetchById($pdo, $newId),
                'peer' => $peer,
                'server_time' => time(),
            ]);
        }

        // --- Edit / Hapus pesan sendiri, wajib <= 2 menit sejak created_at ---
        if ($action === 'edit' || $action === 'delete') {
            $id = (int) ($payload['id'] ?? 0);
            if ($id <= 0) {
                pulseRespond(['error' => 'ID pesan tidak valid.'], 400);
            }
            // Validasi kepemilikan + keberadaan pesan (ditegakkan di server, bukan hanya UI).
            $target = pulseChatFetchOwned($pdo, $id, $me);
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
            $tags = pulseChatExtractTags($pdo, $message);
            $pdo->prepare('UPDATE pulse_chat SET message = :message, tagged = :tagged, edited_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['message' => $message, 'tagged' => implode(',', $tags), 'id' => $id]);
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
