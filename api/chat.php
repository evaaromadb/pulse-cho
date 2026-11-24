<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const PULSE_CHAT_MAX_LEN = 500;
const PULSE_CHAT_EDIT_WINDOW = 120;          // detik: batas edit/hapus pesan sendiri (2 menit)
const PULSE_CHAT_ACTIVE_WINDOW = 180;        // detik: user dianggap online bila heartbeat terakhir <= 3 menit
const PULSE_CHAT_RETENTION_HOURS = 48;       // auto-cleanup pesan lebih tua dari 2 hari
const PULSE_CHAT_CLEANUP_INTERVAL = 600;     // throttle: cleanup maksimal sekali per 10 menit
const PULSE_CHAT_MAX_TAGS = 10;              // batas jumlah @tag per pesan
const PULSE_CHAT_GENERAL_GROUP = 0;          // id grup bawaan "Grup Umum" (semua user)
const PULSE_CHAT_GROUP_NAME_MAX = 40;
const PULSE_CHAT_MAX_GROUP_MEMBERS = 200;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/** Potong teks agar aman terhadap panjang kolom TiDB. */
function pulseChatClip(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $length, 'UTF-8');
    }
    return substr($value, 0, $length);
}

/** Jalankan DDL best-effort: kegagalan skema tidak boleh menggagalkan pemuatan pesan. */
function pulseChatSafeExec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $error) {
        error_log('Pulse chat schema: ' . $error->getMessage());
    }
}

/**
 * Auto-create tabel chat + migrasi aman kolom/index bila belum ada.
 * Semua DDL dijalankan best-effort supaya request GET tidak pernah 500 hanya karena skema.
 */
function pulseEnsureChatTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    pulseChatSafeExec($pdo, 'CREATE TABLE IF NOT EXISTS pulse_chat (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL,
        full_name VARCHAR(128) NOT NULL DEFAULT \'\',
        site VARCHAR(64) NOT NULL DEFAULT \'\',
        role VARCHAR(24) NOT NULL DEFAULT \'User\',
        message VARCHAR(500) NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        peer_a VARCHAR(64) NOT NULL DEFAULT \'\',
        peer_b VARCHAR(64) NOT NULL DEFAULT \'\',
        tagged VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        edited_at TIMESTAMP NULL DEFAULT NULL,
        deleted_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_pulse_chat_room (peer_a, peer_b, id),
        INDEX idx_pulse_chat_group (group_id, id),
        INDEX idx_pulse_chat_created (created_at)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');

    $columns = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM pulse_chat')->fetchAll() as $column) {
            $columns[strtolower((string) $column['Field'])] = true;
        }
    } catch (Throwable $error) {
        error_log('Pulse chat columns: ' . $error->getMessage());
        return;
    }
    if (!isset($columns['edited_at'])) {
        pulseChatSafeExec($pdo, 'ALTER TABLE pulse_chat ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL');
    }
    if (!isset($columns['deleted_at'])) {
        pulseChatSafeExec($pdo, 'ALTER TABLE pulse_chat ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL');
    }
    if (!isset($columns['group_id'])) {
        pulseChatSafeExec($pdo, 'ALTER TABLE pulse_chat ADD COLUMN group_id BIGINT UNSIGNED NOT NULL DEFAULT 0');
    }
    if (!isset($columns['peer_a'])) {
        pulseChatSafeExec($pdo, 'ALTER TABLE pulse_chat ADD COLUMN peer_a VARCHAR(64) NOT NULL DEFAULT \'\'');
    }
    if (!isset($columns['peer_b'])) {
        pulseChatSafeExec($pdo, 'ALTER TABLE pulse_chat ADD COLUMN peer_b VARCHAR(64) NOT NULL DEFAULT \'\'');
    }
    if (!isset($columns['tagged'])) {
        pulseChatSafeExec($pdo, 'ALTER TABLE pulse_chat ADD COLUMN tagged VARCHAR(255) NOT NULL DEFAULT \'\'');
    }
    pulseChatSafeExec($pdo, 'CREATE INDEX idx_pulse_chat_room ON pulse_chat (peer_a, peer_b, id)');
    pulseChatSafeExec($pdo, 'CREATE INDEX idx_pulse_chat_group ON pulse_chat (group_id, id)');
    pulseChatSafeExec($pdo, 'CREATE INDEX idx_pulse_chat_created ON pulse_chat (created_at)');
}

/** Tabel presence: mencatat heartbeat terakhir tiap user (untuk indikator online). */
function pulseEnsureChatPresence(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    pulseChatSafeExec($pdo, 'CREATE TABLE IF NOT EXISTS pulse_chat_presence (
        username VARCHAR(64) NOT NULL PRIMARY KEY,
        full_name VARCHAR(128) NOT NULL DEFAULT \'\',
        site VARCHAR(64) NOT NULL DEFAULT \'\',
        role VARCHAR(24) NOT NULL DEFAULT \'User\',
        last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pulse_chat_presence_seen (last_seen)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
}

/** Tabel penanda baca: id pesan terakhir yang sudah dibaca per user & per room. */
function pulseEnsureChatReads(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    pulseChatSafeExec($pdo, 'CREATE TABLE IF NOT EXISTS pulse_chat_reads (
        username VARCHAR(64) NOT NULL,
        room VARCHAR(140) NOT NULL,
        last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (username, room)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
}

/** Tabel kecil untuk throttle auto-cleanup agar tidak dijalankan tiap polling. */
function pulseEnsureChatMeta(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    pulseChatSafeExec($pdo, 'CREATE TABLE IF NOT EXISTS pulse_chat_meta (
        meta_key VARCHAR(32) NOT NULL PRIMARY KEY,
        meta_value BIGINT NOT NULL DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
}

/** Daftar grup obrolan + keanggotaan. */
function pulseEnsureChatGroups(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    pulseChatSafeExec($pdo, 'CREATE TABLE IF NOT EXISTS pulse_chat_groups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(64) NOT NULL,
        created_by VARCHAR(64) NOT NULL DEFAULT \'\',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    pulseChatSafeExec($pdo, 'CREATE TABLE IF NOT EXISTS pulse_chat_group_members (
        group_id BIGINT UNSIGNED NOT NULL,
        username VARCHAR(64) NOT NULL,
        added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (group_id, username)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
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
        'group_id' => (int) ($row['group_id'] ?? 0),
        'created_ts' => (int) $row['created_ts'],
        'edited' => !empty($row['is_edited']),
        'tagged' => pulseChatTagList((string) ($row['tagged'] ?? '')),
    ];
}

/** Kolom pesan standar (dipakai GET & pengambilan satu pesan). */
function pulseChatSelectColumns(): string
{
    return 'SELECT id, username, full_name, site, role, message, group_id, tagged,
                UNIX_TIMESTAMP(created_at) AS created_ts,
                CASE WHEN edited_at IS NULL THEN 0 ELSE 1 END AS is_edited
            FROM pulse_chat';
}

/** Ambil satu pesan siap-render berdasarkan ID. */
function pulseChatFetchById(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(pulseChatSelectColumns() . ' WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    return $row ? pulseChatRow($row) : [];
}

/**
 * Ambil pesan milik user yang sedang login (belum dihapus).
 * Null bila tidak ada / bukan miliknya / sudah dihapus.
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
 * Pasangan partisipan room DM terkanonikalisasi (dipakai agar kedua pihak berbagi room sama).
 * Pesan grup selalu memakai pasangan ['', ''] dan dibedakan lewat kolom group_id.
 */
function pulseChatRoomPair(string $me, string $peer): array
{
    if ($peer === '' || strcasecmp($peer, $me) === 0) {
        return ['', ''];
    }
    return strcmp($me, $peer) <= 0 ? [$me, $peer] : [$peer, $me];
}

/** Kunci room versi stabil untuk tabel penanda baca (maks 140 karakter). */
function pulseChatRoomKey(int $groupId, string $peerA, string $peerB): string
{
    if ($peerA === '' && $peerB === '') {
        return 'group:' . $groupId;
    }
    return 'dm:' . $peerA . '|' . $peerB;
}

/** Catat heartbeat user agar status online-nya akurat. */
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
            'username' => pulseChatClip((string) $user['username'], 64),
            'full_name' => pulseChatClip((string) $user['namaLengkap'], 128),
            'site' => pulseChatClip((string) $user['site'], 64),
            'role' => pulseChatClip((string) $user['role'], 24),
            'full_name2' => pulseChatClip((string) $user['namaLengkap'], 128),
            'site2' => pulseChatClip((string) $user['site'], 64),
            'role2' => pulseChatClip((string) $user['role'], 24),
        ]);
    } catch (Throwable $error) {
        error_log('Pulse chat presence: ' . $error->getMessage());
    }
}

/**
 * Seluruh user aplikasi (dari tabel pulse_users), bukan hanya yang online.
 * Indikator online dihitung dari heartbeat presence <= PULSE_CHAT_ACTIVE_WINDOW detik.
 * Urutan: user online lebih dulu, lalu nama A-Z.
 */
function pulseChatAllUsers(PDO $pdo, string $me): array
{
    $statement = $pdo->prepare(
        'SELECT u.username, u.full_name, u.role, u.site, UNIX_TIMESTAMP(p.last_seen) AS last_seen_ts
         FROM pulse_users u
         LEFT JOIN pulse_chat_presence p ON p.username = u.username
         WHERE u.is_active = 1 AND u.username <> :me'
    );
    $statement->execute(['me' => $me]);
    $now = time();
    $users = [];
    foreach ($statement->fetchAll() as $row) {
        $lastSeen = (int) ($row['last_seen_ts'] ?? 0);
        $users[] = [
            'username' => (string) $row['username'],
            'full_name' => (string) $row['full_name'],
            'role' => (string) $row['role'],
            'site' => (string) $row['site'],
            'last_seen_ts' => $lastSeen,
            'online' => $lastSeen > 0 && ($now - $lastSeen) <= PULSE_CHAT_ACTIVE_WINDOW,
        ];
    }
    usort($users, static function (array $a, array $b): int {
        if ($a['online'] !== $b['online']) {
            return $a['online'] ? -1 : 1;
        }
        return strcasecmp((string) ($a['full_name'] ?: $a['username']), (string) ($b['full_name'] ?: $b['username']));
    });
    return $users;
}

function pulseChatGroupExists(PDO $pdo, int $groupId): bool
{
    if ($groupId <= 0) {
        return false;
    }
    $statement = $pdo->prepare('SELECT 1 FROM pulse_chat_groups WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $groupId]);
    return (bool) $statement->fetchColumn();
}

function pulseChatIsGroupMember(PDO $pdo, int $groupId, string $username): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM pulse_chat_group_members WHERE group_id = :gid AND username = :username LIMIT 1');
    $statement->execute(['gid' => $groupId, 'username' => $username]);
    return (bool) $statement->fetchColumn();
}

/** Grup Umum (id 0) selalu terbuka; Admin dapat mengakses semua grup; user lain sesuai keanggotaan. */
function pulseChatCanAccessGroup(PDO $pdo, int $groupId, array $user): bool
{
    if ($groupId === PULSE_CHAT_GENERAL_GROUP) {
        return true;
    }
    if (strcasecmp((string) $user['role'], 'Admin') === 0) {
        return pulseChatGroupExists($pdo, $groupId);
    }
    return pulseChatIsGroupMember($pdo, $groupId, (string) $user['username']);
}

/** Daftar grup yang boleh dilihat user: Grup Umum + (Admin: semua grup / user: grup yang diikuti). */
function pulseChatUserGroups(PDO $pdo, array $user): array
{
    $groups = [[
        'id' => PULSE_CHAT_GENERAL_GROUP,
        'name' => 'Grup Umum',
        'member_count' => 0,
        'is_general' => true,
    ]];

    try {
        if (strcasecmp((string) $user['role'], 'Admin') === 0) {
            $statement = $pdo->query(
                'SELECT g.id, g.name, (SELECT COUNT(*) FROM pulse_chat_group_members m WHERE m.group_id = g.id) AS member_count
                 FROM pulse_chat_groups g ORDER BY g.name ASC, g.id ASC'
            );
            $rows = $statement->fetchAll();
        } else {
            $statement = $pdo->prepare(
                'SELECT g.id, g.name, (SELECT COUNT(*) FROM pulse_chat_group_members m WHERE m.group_id = g.id) AS member_count
                 FROM pulse_chat_groups g
                 JOIN pulse_chat_group_members m2 ON m2.group_id = g.id AND m2.username = :me
                 ORDER BY g.name ASC, g.id ASC'
            );
            $statement->execute(['me' => (string) $user['username']]);
            $rows = $statement->fetchAll();
        }
    } catch (Throwable $error) {
        error_log('Pulse chat groups: ' . $error->getMessage());
        return $groups;
    }

    // Daftar anggota tiap grup (dipakai form pengaturan untuk mengubah keanggotaan).
    $memberMap = [];
    try {
        foreach ($pdo->query('SELECT group_id, username FROM pulse_chat_group_members')->fetchAll() as $memberRow) {
            $memberMap[(int) $memberRow['group_id']][] = (string) $memberRow['username'];
        }
    } catch (Throwable $error) {
        error_log('Pulse chat group members: ' . $error->getMessage());
    }

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $groups[] = [
            'id' => $id,
            'name' => (string) $row['name'],
            'member_count' => (int) $row['member_count'],
            'is_general' => false,
            'members' => $memberMap[$id] ?? [],
        ];
    }
    return $groups;
}

/**
 * Hitung pesan belum dibaca per room.
 * Catatan penting: setiap named parameter dipakai sekali saja. Mengulang nama parameter
 * yang sama (mis. ":me" dua kali) membuat PDO native prepare TiDB gagal dengan HY093.
 */
function pulseChatUnread(PDO $pdo, string $me, array $groupIds): array
{
    $reads = [];
    $readStatement = $pdo->prepare('SELECT room, last_read_id FROM pulse_chat_reads WHERE username = :me');
    $readStatement->execute(['me' => $me]);
    foreach ($readStatement->fetchAll() as $readRow) {
        $reads[(string) $readRow['room']] = (int) $readRow['last_read_id'];
    }

    $result = ['groups' => [], 'dms' => []];

    // --- Unread grup (agregat satu kueri untuk semua grup yang boleh diakses) ---
    $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
    if ($groupIds === []) {
        $groupIds = [PULSE_CHAT_GENERAL_GROUP];
    }
    $params = ['gme' => $me, 'gme2' => $me];
    $placeholders = [];
    foreach ($groupIds as $index => $gid) {
        $key = 'gid' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $gid;
    }
    $groupStatement = $pdo->prepare(
        'SELECT c.group_id AS gid, COUNT(*) AS total
         FROM pulse_chat c
         LEFT JOIN pulse_chat_reads r ON r.username = :gme AND r.room = CONCAT(\'group:\', c.group_id)
         WHERE c.peer_a = \'\' AND c.peer_b = \'\' AND c.deleted_at IS NULL
           AND c.username <> :gme2
           AND c.group_id IN (' . implode(',', $placeholders) . ')
           AND c.id > COALESCE(r.last_read_id, 0)
         GROUP BY c.group_id'
    );
    $groupStatement->execute($params);
    foreach ($groupStatement->fetchAll() as $row) {
        $gid = (int) $row['gid'];
        $total = (int) $row['total'];
        if ($total > 0) {
            $result['groups'][$gid] = $total;
        }
    }

    // --- Unread DM (agregat satu kueri; tiap named parameter unik) ---
    $dmStatement = $pdo->prepare(
        'SELECT c.peer_a, c.peer_b, COUNT(*) AS total
         FROM pulse_chat c
         LEFT JOIN pulse_chat_reads r ON r.username = :dme AND r.room = CONCAT(\'dm:\', c.peer_a, \'|\', c.peer_b)
         WHERE c.group_id = 0 AND c.deleted_at IS NULL
           AND (c.peer_a = :dme1 OR c.peer_b = :dme2)
           AND c.username <> :dme3
           AND c.id > COALESCE(r.last_read_id, 0)
         GROUP BY c.peer_a, c.peer_b'
    );
    $dmStatement->execute(['dme' => $me, 'dme1' => $me, 'dme2' => $me, 'dme3' => $me]);
    foreach ($dmStatement->fetchAll() as $row) {
        $a = (string) $row['peer_a'];
        $b = (string) $row['peer_b'];
        if ($a === '' && $b === '') {
            continue;
        }
        $peer = $a === $me ? $b : $a;
        $total = (int) $row['total'];
        if ($total > 0) {
            $result['dms'][$peer] = $total;
        }
    }

    return $result;
}

/** Tandai room sebagai sudah dibaca sampai pesan terakhir (reset unread otomatis). */
function pulseChatMarkRead(PDO $pdo, string $username, int $groupId, string $peerA, string $peerB): void
{
    if ($peerA === '' && $peerB === '') {
        $maxStatement = $pdo->prepare(
            'SELECT MAX(id) FROM pulse_chat WHERE group_id = :gid AND peer_a = \'\' AND peer_b = \'\' AND deleted_at IS NULL'
        );
        $maxStatement->execute(['gid' => $groupId]);
    } else {
        $maxStatement = $pdo->prepare(
            'SELECT MAX(id) FROM pulse_chat WHERE group_id = 0 AND peer_a = :ra AND peer_b = :rb AND deleted_at IS NULL'
        );
        $maxStatement->execute(['ra' => $peerA, 'rb' => $peerB]);
    }
    $maxId = (int) $maxStatement->fetchColumn();
    if ($maxId <= 0) {
        return;
    }
    $room = pulseChatRoomKey($groupId, $peerA, $peerB);
    $pdo->prepare(
        'INSERT INTO pulse_chat_reads (username, room, last_read_id) VALUES (:username, :room, :last_read)
         ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, :last_read2)'
    )->execute(['username' => $username, 'room' => $room, 'last_read' => $maxId, 'last_read2' => $maxId]);
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
    $params = [];
    $placeholders = [];
    foreach ($candidates as $index => $candidate) {
        $key = 'c' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $candidate;
    }
    $statement = $pdo->prepare('SELECT username FROM pulse_users WHERE LOWER(username) IN (' . implode(',', $placeholders) . ')');
    $statement->execute($params);
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

/** Semua username aktif (dipakai opsi "semua user" saat membuat grup). */
function pulseChatActiveUsernames(PDO $pdo): array
{
    $names = [];
    foreach ($pdo->query('SELECT username FROM pulse_users WHERE is_active = 1')->fetchAll() as $row) {
        $names[] = (string) $row['username'];
    }
    return $names;
}

/** Validasi daftar anggota: hanya username yang benar-benar terdaftar & aktif. */
function pulseChatSanitizeMembers(PDO $pdo, array $raw, string $creator): array
{
    $names = [];
    $names[$creator] = true;
    foreach ($raw as $value) {
        $name = trim((string) $value);
        if ($name !== '') {
            $names[$name] = true;
        }
    }
    $list = array_slice(array_keys($names), 0, PULSE_CHAT_MAX_GROUP_MEMBERS);
    if ($list === []) {
        return [];
    }
    $params = [];
    $placeholders = [];
    foreach ($list as $index => $name) {
        $key = 'm' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $name;
    }
    $statement = $pdo->prepare('SELECT username FROM pulse_users WHERE is_active = 1 AND username IN (' . implode(',', $placeholders) . ')');
    $statement->execute($params);
    $valid = [];
    foreach ($statement->fetchAll() as $row) {
        $valid[(string) $row['username']] = true;
    }
    $result = [];
    foreach ($list as $name) {
        if (isset($valid[$name])) {
            $result[] = $name;
        }
    }
    return $result;
}

/** Ganti seluruh anggota grup (delete-lalu-insert, aman untuk jumlah kecil). */
function pulseChatReplaceMembers(PDO $pdo, int $groupId, array $members): void
{
    $pdo->prepare('DELETE FROM pulse_chat_group_members WHERE group_id = :gid')->execute(['gid' => $groupId]);
    if ($members === []) {
        return;
    }
    $params = [];
    $values = [];
    foreach (array_values($members) as $index => $member) {
        $gKey = 'g' . $index;
        $uKey = 'u' . $index;
        $values[] = '(:' . $gKey . ', :' . $uKey . ')';
        $params[$gKey] = $groupId;
        $params[$uKey] = $member;
    }
    $pdo->prepare('INSERT INTO pulse_chat_group_members (group_id, username) VALUES ' . implode(',', $values))
        ->execute($params);
}

function pulseChatGroupPayload(PDO $pdo, int $groupId): array
{
    $statement = $pdo->prepare(
        'SELECT g.id, g.name, (SELECT COUNT(*) FROM pulse_chat_group_members m WHERE m.group_id = g.id) AS member_count
         FROM pulse_chat_groups g WHERE g.id = :id'
    );
    $statement->execute(['id' => $groupId]);
    $row = $statement->fetch();
    if (!$row) {
        return ['id' => $groupId, 'name' => '', 'member_count' => 0, 'is_general' => false, 'members' => []];
    }
    $members = [];
    try {
        $memberStatement = $pdo->prepare('SELECT username FROM pulse_chat_group_members WHERE group_id = :gid');
        $memberStatement->execute(['gid' => $groupId]);
        foreach ($memberStatement->fetchAll() as $memberRow) {
            $members[] = (string) $memberRow['username'];
        }
    } catch (Throwable $error) {
        error_log('Pulse chat group payload members: ' . $error->getMessage());
    }
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'member_count' => (int) $row['member_count'],
        'is_general' => false,
        'members' => $members,
    ];
}

try {
    $pdo = pulseDatabase();
    pulseEnsureChatTable($pdo);
    pulseEnsureChatPresence($pdo);
    pulseEnsureChatReads($pdo);
    pulseEnsureChatMeta($pdo);
    pulseEnsureChatGroups($pdo);

    // GET: pesan room aktif (grup/DM) + daftar seluruh user + daftar grup + unread. Wajib login.
    if ($method === 'GET') {
        $user = pulseCurrentUser($pdo);
        $me = (string) $user['username'];
        pulseChatTouchPresence($pdo, $user);
        pulseChatCleanup($pdo);

        $isAdmin = strcasecmp((string) $user['role'], 'Admin') === 0;

        // Mode meta saja: heartbeat ringan (dipakai saat daftar user/grup sedang tampil).
        $metaOnly = !empty($_GET['meta']) || !empty($_GET['presence']);

        $peer = trim((string) ($_GET['peer'] ?? ''));
        if (strcasecmp($peer, $me) === 0) {
            $peer = '';
        }
        $isDm = $peer !== '';
        $groupId = PULSE_CHAT_GENERAL_GROUP;
        if (!$isDm) {
            $requestedGroup = (int) ($_GET['group'] ?? 0);
            if ($requestedGroup > 0 && pulseChatCanAccessGroup($pdo, $requestedGroup, $user)) {
                $groupId = $requestedGroup;
            }
        }

        [$peerA, $peerB] = $isDm ? pulseChatRoomPair($me, $peer) : ['', ''];
        if ($isDm) {
            $roomFilter = 'group_id = 0 AND peer_a = :ra AND peer_b = :rb';
            $roomParams = ['ra' => $peerA, 'rb' => $peerB];
        } else {
            $roomFilter = 'group_id = :rgid AND peer_a = \'\' AND peer_b = \'\'';
            $roomParams = ['rgid' => $groupId];
        }

        // Reset unread bila klien sedang menampilkan room ini.
        if (!$metaOnly && !empty($_GET['read'])) {
            pulseChatMarkRead($pdo, $me, $groupId, $peerA, $peerB);
        }

        $messages = [];
        $edited = [];
        $deletedIds = [];
        $lastId = 0;
        $afterId = 0;

        if (!$metaOnly) {
            // after_id / last_id / since: kirim hanya pesan baru (bukan seluruh riwayat).
            $afterId = (int) ($_GET['after_id'] ?? $_GET['last_id'] ?? $_GET['since'] ?? 0);
            if ($afterId > 0) {
                $statement = $pdo->prepare(pulseChatSelectColumns() . ' WHERE ' . $roomFilter . ' AND id > :after_id AND deleted_at IS NULL ORDER BY id ASC LIMIT 50');
                $statement->execute(array_merge($roomParams, ['after_id' => $afterId]));
                $rows = $statement->fetchAll();

                // Pesan yang diedit dalam jendela sinkron (agar klien lain memperbarui isi).
                $editedStatement = $pdo->prepare(pulseChatSelectColumns() . ' WHERE ' . $roomFilter . ' AND deleted_at IS NULL AND edited_at IS NOT NULL AND edited_at >= (UTC_TIMESTAMP() - INTERVAL 900 SECOND) ORDER BY id ASC LIMIT 50');
                $editedStatement->execute($roomParams);
                $edited = array_map('pulseChatRow', $editedStatement->fetchAll());

                // ID pesan yang dihapus dalam jendela sinkron (agar klien lain ikut menghapus).
                $deletedStatement = $pdo->prepare('SELECT id FROM pulse_chat WHERE ' . $roomFilter . ' AND deleted_at IS NOT NULL AND deleted_at >= (UTC_TIMESTAMP() - INTERVAL 900 SECOND) ORDER BY id ASC LIMIT 100');
                $deletedStatement->execute($roomParams);
                foreach ($deletedStatement->fetchAll() as $deletedRow) {
                    $deletedIds[] = (int) $deletedRow['id'];
                }
            } else {
                $statement = $pdo->prepare(pulseChatSelectColumns() . ' WHERE ' . $roomFilter . ' AND deleted_at IS NULL ORDER BY id DESC LIMIT 50');
                $statement->execute($roomParams);
                $rows = array_reverse($statement->fetchAll());
            }

            $messages = array_map('pulseChatRow', $rows);
            foreach ($messages as $message) {
                if ($message['id'] > $lastId) {
                    $lastId = $message['id'];
                }
            }
        }

        $payload = [
            'room' => $isDm ? 'dm' : 'group',
            'group_id' => $groupId,
            'peer' => $peer,
            'meta' => $metaOnly,
            'messages' => $messages,
            'edited' => $edited,
            'deleted_ids' => $deletedIds,
            'last_id' => $lastId,
            'server_time' => time(),
        ];

        // Daftar user/grup/unread hanya dikirim saat memang dibutuhkan klien
        // (muat awal atau heartbeat), bukan pada setiap polling pesan baru.
        // Ini memangkas payload polling secara signifikan tanpa mengubah fitur.
        if ($metaOnly || $afterId <= 0) {
            $groups = pulseChatUserGroups($pdo, $user);
            $groupIds = array_map(static fn (array $group): int => (int) $group['id'], $groups);
            $payload['users'] = pulseChatAllUsers($pdo, $me);
            $payload['groups'] = $groups;
            $payload['unread'] = pulseChatUnread($pdo, $me, $groupIds);
            $payload['can_manage_groups'] = $isAdmin;
        }

        pulseRespond($payload);
    }

    // POST: satu endpoint dengan field "action" memakai sesi login aktif.
    if ($method === 'POST') {
        pulseRequireAllowedOrigin();
        $user = pulseCurrentUser($pdo);
        $me = (string) $user['username'];
        pulseChatTouchPresence($pdo, $user);
        $payload = pulseReadJsonBody();
        $action = strtolower(trim((string) ($payload['action'] ?? 'send')));
        if ($action === '') {
            $action = 'send';
        }
        $isAdmin = strcasecmp((string) $user['role'], 'Admin') === 0;

        $peer = trim((string) ($payload['peer'] ?? ''));
        if (strcasecmp($peer, $me) === 0) {
            $peer = '';
        }
        $isDm = $peer !== '';
        $groupId = (int) ($payload['group'] ?? 0);
        if (!$isDm) {
            if ($groupId > 0 && !pulseChatCanAccessGroup($pdo, $groupId, $user)) {
                pulseRespond(['error' => 'Anda tidak memiliki akses ke grup ini.'], 403);
            }
            if ($groupId < 0) {
                $groupId = PULSE_CHAT_GENERAL_GROUP;
            }
        } else {
            $groupId = PULSE_CHAT_GENERAL_GROUP;
        }
        [$peerA, $peerB] = $isDm ? pulseChatRoomPair($me, $peer) : ['', ''];

        // --- Kirim pesan baru (maksimal 500 karakter) ---
        if ($action === 'send') {
            $message = trim((string) ($payload['message'] ?? ''));
            if ($message === '') {
                pulseRespond(['error' => 'Pesan tidak boleh kosong.'], 400);
            }
            $message = pulseChatClip($message, PULSE_CHAT_MAX_LEN);
            $tags = pulseChatExtractTags($pdo, $message);
            pulseChatCleanup($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO pulse_chat (username, full_name, site, role, message, group_id, peer_a, peer_b, tagged)
                 VALUES (:username, :full_name, :site, :role, :message, :group_id, :peer_a, :peer_b, :tagged)'
            );
            $insert->execute([
                'username' => pulseChatClip($me, 64),
                'full_name' => pulseChatClip((string) $user['namaLengkap'], 128),
                'site' => pulseChatClip((string) $user['site'], 64),
                'role' => pulseChatClip((string) $user['role'], 24),
                'message' => $message,
                'group_id' => $isDm ? 0 : $groupId,
                'peer_a' => $peerA,
                'peer_b' => $peerB,
                'tagged' => implode(',', $tags),
            ]);
            $newId = (int) $pdo->lastInsertId();
            // Pesan sendiri otomatis dianggap sudah dibaca pada room tujuan.
            pulseChatMarkRead($pdo, $me, $isDm ? 0 : $groupId, $peerA, $peerB);
            pulseRespond([
                'ok' => true,
                'message' => pulseChatFetchById($pdo, $newId),
                'group_id' => $isDm ? 0 : $groupId,
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
            $message = pulseChatClip($message, PULSE_CHAT_MAX_LEN);
            $tags = pulseChatExtractTags($pdo, $message);
            $pdo->prepare('UPDATE pulse_chat SET message = :message, tagged = :tagged, edited_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['message' => $message, 'tagged' => implode(',', $tags), 'id' => $id]);
            pulseRespond(['ok' => true, 'message' => pulseChatFetchById($pdo, $id), 'server_time' => time()]);
        }

        // --- Manajemen grup (khusus Admin) ---
        if ($action === 'group_create' || $action === 'group_update' || $action === 'group_delete') {
            if (!$isAdmin) {
                pulseRespond(['error' => 'Hanya Admin yang dapat mengelola grup chat.'], 403);
            }

            if ($action === 'group_delete') {
                $targetGroup = (int) ($payload['group_id'] ?? 0);
                if ($targetGroup <= 0 || !pulseChatGroupExists($pdo, $targetGroup)) {
                    pulseRespond(['error' => 'Grup tidak ditemukan.'], 404);
                }
                // Pesan grup disembunyikan (soft delete) agar riwayat tetap konsisten saat sinkron polling.
                $pdo->prepare('UPDATE pulse_chat SET deleted_at = CURRENT_TIMESTAMP WHERE group_id = :gid AND deleted_at IS NULL')
                    ->execute(['gid' => $targetGroup]);
                $pdo->prepare('DELETE FROM pulse_chat_group_members WHERE group_id = :gid')->execute(['gid' => $targetGroup]);
                $pdo->prepare('DELETE FROM pulse_chat_groups WHERE id = :gid')->execute(['gid' => $targetGroup]);
                $pdo->prepare('DELETE FROM pulse_chat_reads WHERE room = :room')->execute(['room' => 'group:' . $targetGroup]);
                pulseRespond(['ok' => true, 'group_id' => $targetGroup, 'server_time' => time()]);
            }

            $name = pulseChatClip(trim((string) ($payload['name'] ?? '')), PULSE_CHAT_GROUP_NAME_MAX);
            $hasMembers = array_key_exists('members', $payload) || !empty($payload['all']);
            $members = [];
            if ($hasMembers) {
                if (!empty($payload['all'])) {
                    $members = pulseChatActiveUsernames($pdo);
                } else {
                    $rawMembers = is_array($payload['members'] ?? null) ? $payload['members'] : [];
                    $members = pulseChatSanitizeMembers($pdo, $rawMembers, $me);
                }
            }

            if ($action === 'group_create') {
                if ($name === '') {
                    pulseRespond(['error' => 'Nama grup wajib diisi.'], 400);
                }
                if (!$hasMembers) {
                    $members = pulseChatSanitizeMembers($pdo, [], $me);
                }
                if ($members === []) {
                    pulseRespond(['error' => 'Pilih minimal satu anggota grup.'], 400);
                }
                $pdo->prepare('INSERT INTO pulse_chat_groups (name, created_by) VALUES (:name, :created_by)')
                    ->execute(['name' => $name, 'created_by' => pulseChatClip($me, 64)]);
                $newGroupId = (int) $pdo->lastInsertId();
                pulseChatReplaceMembers($pdo, $newGroupId, $members);
                pulseRespond(['ok' => true, 'group' => pulseChatGroupPayload($pdo, $newGroupId), 'server_time' => time()]);
            }

            // group_update
            $targetGroup = (int) ($payload['group_id'] ?? 0);
            if ($targetGroup <= 0 || !pulseChatGroupExists($pdo, $targetGroup)) {
                pulseRespond(['error' => 'Grup tidak ditemukan.'], 404);
            }
            if ($name !== '') {
                $pdo->prepare('UPDATE pulse_chat_groups SET name = :name WHERE id = :gid')
                    ->execute(['name' => $name, 'gid' => $targetGroup]);
            }
            if ($hasMembers && $members !== []) {
                pulseChatReplaceMembers($pdo, $targetGroup, $members);
            }
            pulseRespond(['ok' => true, 'group' => pulseChatGroupPayload($pdo, $targetGroup), 'server_time' => time()]);
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
