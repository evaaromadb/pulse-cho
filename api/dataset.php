<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const PULSE_MAX_UPLOAD_BYTES = 250 * 1024 * 1024;
const PULSE_DB_CHUNK_BYTES = 1024 * 1024;
const PULSE_CLIENT_CHUNK_ROWS = 2000;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'PUT', 'DELETE'], true)) {
    header('Allow: GET, PUT, DELETE');
    pulseRespond(['error' => 'Metode request tidak didukung.'], 405);
}

/**
 * Kirim potongan JSON mentah ke browser tanpa membungkusnya di memori.
 * Dipakai untuk respons besar (dataset penuh / satu chunk).
 */
function pulseStreamStart(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @set_time_limit(900);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Accel-Buffering: no');
}

function pulseActiveDatasetMeta(PDO $pdo): ?array
{
    // Query di-cache sebentar: dipanggil di awal setiap request (termasuk tiap
    // chunk download paralel), jadi menghindari round-trip TiDB berulang.
    static $cachedAt = null;
    static $cached = null;
    if ($cachedAt !== null && (microtime(true) - $cachedAt) < 1.0) {
        return $cached;
    }
    $statement = $pdo->prepare(
        "SELECT row_count, saved_by, active_version,
                DATE_FORMAT(saved_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS saved_at
         FROM pulse_dataset
         WHERE dataset_key = :dataset_key
         LIMIT 1"
    );
    $statement->execute(['dataset_key' => 'active_dataset_v1']);
    $record = $statement->fetch();
    $cachedAt = microtime(true);
    $cached = $record ?: null;
    return $cached;
}

function pulseChunkIndexRows(PDO $pdo, string $versionId): array
{
    if ($versionId === '') {
        return [];
    }
    static $cache = [];
    if (isset($cache[$versionId])) {
        return $cache[$versionId];
    }
    $statement = $pdo->prepare(
        'SELECT chunk_index, row_count, byte_length
         FROM pulse_dataset_chunk_index
         WHERE dataset_key = :dataset_key AND version_id = :version_id
         ORDER BY chunk_index'
    );
    $statement->execute(['dataset_key' => 'active_dataset_v1', 'version_id' => $versionId]);
    $chunks = [];
    foreach ($statement->fetchAll() as $row) {
        $chunks[] = [
            'index' => (int) $row['chunk_index'],
            'row_count' => (int) $row['row_count'],
            'byte_length' => (int) $row['byte_length'],
        ];
    }
    $cache[$versionId] = $chunks;
    return $chunks;
}

/**
 * Cadangan bila tabel index chunk kosong (dataset lama): susun daftar chunk dari
 * tabel chunks tanpa membaca isi JSON-nya, supaya browser tetap paralel.
 */
function pulseDescribeChunksFromTable(PDO $pdo, string $versionId, int $expectedRows): array
{
    if ($versionId === '') {
        return [];
    }
    $statement = $pdo->prepare(
        'SELECT chunk_index, row_count FROM pulse_dataset_chunks
         WHERE dataset_key = :dataset_key AND version_id = :version_id
         ORDER BY chunk_index'
    );
    $statement->execute(['dataset_key' => 'active_dataset_v1', 'version_id' => $versionId]);
    $chunks = [];
    foreach ($statement->fetchAll() as $row) {
        $chunks[] = [
            'index' => (int) $row['chunk_index'],
            'row_count' => (int) $row['row_count'],
            'byte_length' => 0,
        ];
    }
    return $chunks;
}

/**
 * Tulis sekumpulan baris ke tabel chunk (dipecah per <= PULSE_DB_CHUNK_BYTES)
 * untuk satu versi dataset. Dipakai unggahan bertahap (chunked) maupun
 * unggahan satu-request (legacy), sehingga RAM server tetap kecil karena tiap
 * request hanya memuat sebagian baris.
 * Mengembalikan jumlah baris yang ditulis.
 */
function pulseAppendDatasetRows(PDO $pdo, string $datasetKey, string $versionId, array $rows): int
{
    if ($rows === []) {
        return 0;
    }

    $startStatement = $pdo->prepare(
        'SELECT COALESCE(MAX(chunk_index), -1) + 1
         FROM pulse_dataset_chunk_index
         WHERE dataset_key = :dataset_key AND version_id = :version_id'
    );
    $startStatement->execute(['dataset_key' => $datasetKey, 'version_id' => $versionId]);
    $chunkIndex = (int) $startStatement->fetchColumn();

    $chunkStatement = $pdo->prepare(
        'INSERT INTO pulse_dataset_chunks
           (dataset_key, version_id, chunk_index, `rows`, row_count)
         VALUES (:dataset_key, :version_id, :chunk_index, :rows, :row_count)
         ON DUPLICATE KEY UPDATE `rows` = VALUES(`rows`), row_count = VALUES(row_count)'
    );
    $chunkIndexStatement = $pdo->prepare(
        'INSERT INTO pulse_dataset_chunk_index
           (dataset_key, version_id, chunk_index, row_count, byte_length)
         VALUES (:dataset_key, :version_id, :chunk_index, :row_count, :byte_length)
         ON DUPLICATE KEY UPDATE row_count = VALUES(row_count), byte_length = VALUES(byte_length)'
    );

    $chunkRows = [];
    $chunkBytes = 2;
    $flushChunk = static function () use (
        &$chunkRows,
        &$chunkBytes,
        &$chunkIndex,
        $chunkStatement,
        $chunkIndexStatement,
        $datasetKey,
        $versionId
    ): void {
        if ($chunkRows === []) {
            return;
        }
        $chunkJson = json_encode(
            $chunkRows,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $chunkStatement->execute([
            'dataset_key' => $datasetKey,
            'version_id' => $versionId,
            'chunk_index' => $chunkIndex,
            'rows' => $chunkJson,
            'row_count' => count($chunkRows),
        ]);
        $chunkIndexStatement->execute([
            'dataset_key' => $datasetKey,
            'version_id' => $versionId,
            'chunk_index' => $chunkIndex,
            'row_count' => count($chunkRows),
            'byte_length' => strlen($chunkJson),
        ]);
        $chunkIndex++;
        $chunkRows = [];
        $chunkBytes = 2;
    };

    foreach ($rows as $row) {
        $rowJson = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $rowBytes = strlen($rowJson) + 1;
        if ($rowBytes > PULSE_DB_CHUNK_BYTES) {
            throw new LengthException('Satu baris data melebihi batas chunk TiDB 1 MB.');
        }
        if ($chunkRows !== [] && $chunkBytes + $rowBytes > PULSE_DB_CHUNK_BYTES) {
            $flushChunk();
        }
        $chunkRows[] = $row;
        $chunkBytes += $rowBytes;
    }
    $flushChunk();

    return count($rows);
}

/**
 * Aktifkan versi dataset: tulis metadata (row_count diambil dari index chunk,
 * tanpa membaca isi JSON) lalu hapus versi lama. Dataset lama tetap dipakai
 * sampai fungsi ini dijalankan, sehingga kegagalan di tengah unggahan tidak
 * merusak data aktif.
 */
function pulseActivateDatasetVersion(PDO $pdo, string $datasetKey, string $versionId, string $savedBy): int
{
    $countStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(row_count), 0) FROM pulse_dataset_chunk_index
         WHERE dataset_key = :dataset_key AND version_id = :version_id'
    );
    $countStatement->execute(['dataset_key' => $datasetKey, 'version_id' => $versionId]);
    $totalRows = (int) $countStatement->fetchColumn();

    $currentStatement = $pdo->prepare('SELECT active_version FROM pulse_dataset WHERE dataset_key = :dataset_key');
    $currentStatement->execute(['dataset_key' => $datasetKey]);
    $previousVersion = $currentStatement->fetchColumn();

    $metadataStatement = $pdo->prepare(
        'INSERT INTO pulse_dataset
           (dataset_key, `rows`, row_count, saved_by, saved_at, active_version)
         VALUES (:dataset_key, :rows, :row_count, :saved_by, UTC_TIMESTAMP(3), :active_version)
         ON DUPLICATE KEY UPDATE
           `rows` = VALUES(`rows`),
           row_count = VALUES(row_count),
           saved_by = VALUES(saved_by),
           saved_at = UTC_TIMESTAMP(3),
           active_version = VALUES(active_version)'
    );
    $metadataStatement->execute([
        'dataset_key' => $datasetKey,
        'rows' => '[]',
        'row_count' => $totalRows,
        'saved_by' => $savedBy,
        'active_version' => $versionId,
    ]);

    if ($previousVersion !== false && $previousVersion !== null && $previousVersion !== '' && $previousVersion !== $versionId) {
        pulseDeleteDatasetVersion($pdo, $datasetKey, (string) $previousVersion);
    }

    return $totalRows;
}

/**
 * Hapus seluruh chunk satu versi (dipakai saat abort atau pembersihan versi lama).
 */
function pulseDeleteDatasetVersion(PDO $pdo, string $datasetKey, string $versionId): void
{
    if ($versionId === '') {
        return;
    }
    foreach (['pulse_dataset_chunks', 'pulse_dataset_chunk_index'] as $table) {
        $statement = $pdo->prepare(
            "DELETE FROM {$table} WHERE dataset_key = :dataset_key AND version_id = :version_id"
        );
        $statement->execute(['dataset_key' => $datasetKey, 'version_id' => $versionId]);
    }
}

try {
    $pdo = pulseDatabase();
    $currentUser = pulseCurrentUser($pdo);
    if ($method !== 'GET') {
        pulseRequireAllowedOrigin();
        pulseRequireAdmin($currentUser);
    }

    if ($method === 'GET') {
        $record = pulseActiveDatasetMeta($pdo);
        if (!$record) {
            pulseRespond([
                'exists' => false,
                'rows' => null,
                'row_count' => 0,
                'saved_by' => null,
                'saved_at' => null,
                'chunks' => [],
            ]);
        }

        $activeVersion = (string) ($record['active_version'] ?? '');
        $chunks = pulseChunkIndexRows($pdo, $activeVersion);
        $metaOnly = ($_GET['metaOnly'] ?? '') === '1';
        $wantsIndex = ($_GET['index'] ?? '') === '1';

        // Mode per-chunk: browser mengunduh beberapa chunk paralel.
        $chunkParam = $_GET['chunk'] ?? null;
        if ($chunkParam !== null && !$metaOnly) {
            $chunkIndex = (int) $chunkParam;
            $versionParam = (string) ($_GET['version'] ?? $activeVersion);
            if ($versionParam === '' || $chunkIndex < 0) {
                pulseRespond(['error' => 'Parameter chunk tidak valid.'], 400);
            }
            $statement = $pdo->prepare(
                'SELECT `rows` FROM pulse_dataset_chunks
                 WHERE dataset_key = :dataset_key AND version_id = :version_id AND chunk_index = :chunk_index'
            );
            $statement->execute([
                'dataset_key' => 'active_dataset_v1',
                'version_id' => $versionParam,
                'chunk_index' => $chunkIndex,
            ]);
            $raw = $statement->fetchColumn();
            if ($raw === false) {
                pulseRespond(['error' => 'Chunk dataset tidak ditemukan.'], 404);
            }
            // Versi ini dipakai ulang; browser boleh menyimpannya sebentar supaya
            // reload berikutnya tidak perlu mengunduh ulang data yang sama.
            // Chunk adalah payload terbesar aplikasi, jadi dikirim ter-Gzip.
            pulseStreamStart();
            header('Cache-Control: private, max-age=600');
            header('ETag: "' . $versionParam . '-' . $chunkIndex . '"');
            pulseEchoCompressed(trim((string) $raw));
            exit;
        }

        $payload = [
            'exists' => true,
            'row_count' => (int) $record['row_count'],
            'saved_by' => $record['saved_by'],
            'saved_at' => $record['saved_at'],
        ];

        if ($metaOnly) {
            pulseRespond($payload + ['rows' => null]);
        }

        // Mode index: cukup metadata + daftar chunk (dataset besar TIDAK di-stream).
        // Jika tabel index chunk belum terisi (dataset lama), daftar chunk dibentuk
        // dari jumlah baris per chunk supaya browser tetap memakai unduhan paralel.
        if ($chunks === []) {
            $chunks = pulseDescribeChunksFromTable($pdo, $activeVersion, $payload['row_count']);
        }
        pulseRespond($payload + [
            'rows' => null,
            'version' => $activeVersion,
            'chunk_rows' => PULSE_CLIENT_CHUNK_ROWS,
            'chunks' => $chunks,
        ]);
    }

    if ($method === 'PUT') {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > PULSE_MAX_UPLOAD_BYTES) {
            pulseRespond(['error' => 'Ukuran request melebihi batas 250 MB.'], 413);
        }

        $datasetKey = 'active_dataset_v1';
        $uploadMode = (string) ($_GET['upload'] ?? '');
        $normalizeSavedBy = static function (string $value): string {
            $value = trim($value);
            return function_exists('mb_substr') ? mb_substr($value, 0, 255, 'UTF-8') : substr($value, 0, 255);
        };

        // Unggahan bertahap: init -> chunk (berulang) -> finalize.
        // Tiap request hanya membawa sebagian baris sehingga RAM server kecil,
        // dan dataset lama tetap aktif sampai finalize berhasil.
        if ($uploadMode === 'init') {
            pulseRespond(['ok' => true, 'version' => bin2hex(random_bytes(16))]);
        }

        if ($uploadMode !== '') {
            $versionId = (string) ($_GET['version'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $versionId)) {
                pulseRespond(['error' => 'Version upload tidak valid.'], 400);
            }

            if ($uploadMode === 'abort') {
                pulseDeleteDatasetVersion($pdo, $datasetKey, $versionId);
                pulseRespond(['ok' => true]);
            }

            if ($uploadMode === 'chunk') {
                $decoded = json_decode((string) file_get_contents('php://input'), true);
                if (is_array($decoded) && isset($decoded['rows']) && is_array($decoded['rows'])) {
                    $decoded = $decoded['rows'];
                }
                if (!is_array($decoded)) {
                    pulseRespond(['error' => 'Batch chunk harus berupa array rows.'], 400);
                }
                $written = pulseAppendDatasetRows($pdo, $datasetKey, $versionId, array_values($decoded));
                pulseRespond(['ok' => true, 'row_count' => $written]);
            }

            if ($uploadMode === 'finalize') {
                $decoded = json_decode((string) file_get_contents('php://input'), true);
                $savedBy = $normalizeSavedBy((string) (is_array($decoded) ? ($decoded['saved_by'] ?? '') : ''));
                if ($savedBy === '') {
                    $savedBy = $normalizeSavedBy((string) $currentUser['username']);
                }
                if ($savedBy === '') {
                    $savedBy = '-';
                }
                $totalRows = pulseActivateDatasetVersion($pdo, $datasetKey, $versionId, $savedBy);
                $savedAt = $pdo->query(
                    "SELECT DATE_FORMAT(saved_at, '%Y-%m-%dT%H:%i:%s.%fZ')
                     FROM pulse_dataset
                     WHERE dataset_key = 'active_dataset_v1'"
                )->fetchColumn();
                pulseRespond([
                    'ok' => true,
                    'row_count' => $totalRows,
                    'saved_by' => $savedBy,
                    'saved_at' => $savedAt,
                    'version' => $versionId,
                ]);
            }

            pulseRespond(['error' => 'Mode upload tidak didukung.'], 400);
        }

        // Legacy: unggah satu request (kompatibilitas klien lama).
        $body = file_get_contents('php://input');
        $payload = json_decode($body === false ? '' : $body, true);
        if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
            pulseRespond(['error' => 'Request harus berisi array rows yang valid.'], 400);
        }

        $savedBy = $normalizeSavedBy((string) $currentUser['username']);
        if ($savedBy === '') {
            $savedBy = '-';
        }

        $versionId = bin2hex(random_bytes(16));
        try {
            pulseAppendDatasetRows($pdo, $datasetKey, $versionId, array_values($payload['rows']));
        } catch (Throwable $error) {
            pulseDeleteDatasetVersion($pdo, $datasetKey, $versionId);
            throw $error;
        }
        $totalRows = pulseActivateDatasetVersion($pdo, $datasetKey, $versionId, $savedBy);

        $savedAt = $pdo->query(
            "SELECT DATE_FORMAT(saved_at, '%Y-%m-%dT%H:%i:%s.%fZ')
             FROM pulse_dataset
             WHERE dataset_key = 'active_dataset_v1'"
        )->fetchColumn();
        pulseRespond([
            'ok' => true,
            'row_count' => $totalRows,
            'saved_by' => $savedBy,
            'saved_at' => $savedAt,
            'version' => $versionId,
        ]);
    }

    // DELETE: hapus dataset aktif beserta semua chunk-nya.
    $oldVersion = $pdo->query(
        "SELECT active_version FROM pulse_dataset WHERE dataset_key = 'active_dataset_v1'"
    )->fetchColumn();
    if ($oldVersion !== false && $oldVersion !== null && $oldVersion !== '') {
        $pdo->prepare('DELETE FROM pulse_dataset_chunks WHERE dataset_key = :dataset_key AND version_id = :version_id')
            ->execute(['dataset_key' => 'active_dataset_v1', 'version_id' => $oldVersion]);
        $pdo->prepare('DELETE FROM pulse_dataset_chunk_index WHERE dataset_key = :dataset_key AND version_id = :version_id')
            ->execute(['dataset_key' => 'active_dataset_v1', 'version_id' => $oldVersion]);
    }
    $statement = $pdo->prepare('DELETE FROM pulse_dataset WHERE dataset_key = :dataset_key');
    $statement->execute(['dataset_key' => 'active_dataset_v1']);
    $statement = $pdo->prepare('DELETE FROM pulse_dataset_chunks WHERE dataset_key = :dataset_key');
    $statement->execute(['dataset_key' => 'active_dataset_v1']);
    $statement = $pdo->prepare('DELETE FROM pulse_dataset_chunk_index WHERE dataset_key = :dataset_key');
    $statement->execute(['dataset_key' => 'active_dataset_v1']);
    pulseRespond(['ok' => true]);
} catch (JsonException $error) {
    pulseRespond(['error' => 'Data dataset tidak dapat diproses sebagai JSON yang valid.'], 400);
} catch (LengthException $error) {
    pulseRespond(['error' => $error->getMessage()], 413);
} catch (InvalidArgumentException $error) {
    pulseRespond(['error' => $error->getMessage()], 503);
} catch (Throwable $error) {
    error_log('Pulse TiDB API: ' . $error->getMessage());
    pulseRespond(['error' => 'TiDB tidak dapat dihubungi atau operasi database gagal. Periksa konfigurasi server.'], 500);
}
