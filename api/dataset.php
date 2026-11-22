<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const PULSE_MAX_UPLOAD_BYTES = 250 * 1024 * 1024;
const PULSE_DB_CHUNK_BYTES = 1024 * 1024;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'PUT', 'DELETE'], true)) {
    header('Allow: GET, PUT, DELETE');
    pulseRespond(['error' => 'Metode request tidak didukung.'], 405);
}

try {
    $pdo = pulseDatabase();
    $currentUser = pulseCurrentUser($pdo);
    if ($method !== 'GET') {
        pulseRequireAllowedOrigin();
        pulseRequireAdmin($currentUser);
    }

    if ($method === 'GET') {
        $statement = $pdo->prepare(
            "SELECT `rows`, row_count, saved_by, active_version,
                    DATE_FORMAT(saved_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS saved_at
             FROM pulse_dataset
             WHERE dataset_key = :dataset_key
             LIMIT 1"
        );
        $statement->execute(['dataset_key' => 'active_dataset_v1']);
        $record = $statement->fetch();
        if (!$record) {
            pulseRespond([
                'exists' => false,
                'rows' => null,
                'row_count' => 0,
                'saved_by' => null,
                'saved_at' => null,
            ]);
        }

        $metaOnly = ($_GET['metaOnly'] ?? '') === '1';
        $rows = null;
        if (!$metaOnly && !empty($record['active_version'])) {
            $chunkStatement = $pdo->prepare(
                'SELECT `rows` FROM pulse_dataset_chunks
                 WHERE dataset_key = :dataset_key AND version_id = :version_id
                 ORDER BY chunk_index'
            );
            $chunkStatement->execute([
                'dataset_key' => 'active_dataset_v1',
                'version_id' => $record['active_version'],
            ]);
            $rows = [];
            while ($chunk = $chunkStatement->fetch()) {
                $chunkRows = json_decode((string) $chunk['rows'], true);
                if (!is_array($chunkRows)) {
                    throw new RuntimeException('Format chunk dataset di TiDB tidak valid.');
                }
                array_push($rows, ...$chunkRows);
            }
        } elseif (!$metaOnly) {
            $rows = json_decode((string) $record['rows'], true);
            if (!is_array($rows)) {
                throw new RuntimeException('Format dataset di TiDB tidak valid.');
            }
        }

        pulseRespond([
            'exists' => true,
            'rows' => $rows,
            'row_count' => (int) $record['row_count'],
            'saved_by' => $record['saved_by'],
            'saved_at' => $record['saved_at'],
        ]);
    }

    if ($method === 'PUT') {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > PULSE_MAX_UPLOAD_BYTES) {
            pulseRespond(['error' => 'Ukuran request melebihi batas 250 MB.'], 413);
        }
        $body = file_get_contents('php://input');
        $payload = json_decode($body === false ? '' : $body, true);
        if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
            pulseRespond(['error' => 'Request harus berisi array rows yang valid.'], 400);
        }

        $savedBy = trim((string) $currentUser['username']);
        $savedBy = $savedBy !== '' ? $savedBy : '-';
        $savedBy = function_exists('mb_substr')
            ? mb_substr($savedBy, 0, 255, 'UTF-8')
            : substr($savedBy, 0, 255);

        $datasetKey = 'active_dataset_v1';
        $versionId = bin2hex(random_bytes(16));
        $currentVersionStatement = $pdo->prepare(
            'SELECT active_version FROM pulse_dataset WHERE dataset_key = :dataset_key'
        );
        $currentVersionStatement->execute(['dataset_key' => $datasetKey]);
        $previousVersion = $currentVersionStatement->fetchColumn();
        $chunkStatement = $pdo->prepare(
            'INSERT INTO pulse_dataset_chunks
               (dataset_key, version_id, chunk_index, `rows`, row_count)
             VALUES (:dataset_key, :version_id, :chunk_index, :rows, :row_count)'
        );
        $chunkRows = [];
        $chunkBytes = 2;
        $chunkIndex = 0;

        $flushChunk = static function () use (
            &$chunkRows,
            &$chunkBytes,
            &$chunkIndex,
            $chunkStatement,
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
                'chunk_index' => $chunkIndex++,
                'rows' => $chunkJson,
                'row_count' => count($chunkRows),
            ]);
            $chunkRows = [];
            $chunkBytes = 2;
        };

        try {
            foreach ($payload['rows'] as $row) {
                $rowJson = json_encode(
                    $row,
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
                );
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
                'row_count' => count($payload['rows']),
                'saved_by' => $savedBy,
                'active_version' => $versionId,
            ]);
        } catch (Throwable $error) {
            $cleanup = $pdo->prepare(
                'DELETE FROM pulse_dataset_chunks
                 WHERE dataset_key = :dataset_key AND version_id = :version_id'
            );
            $cleanup->execute(['dataset_key' => $datasetKey, 'version_id' => $versionId]);
            throw $error;
        }

        try {
            if ($previousVersion !== false && $previousVersion !== null) {
                $cleanup = $pdo->prepare(
                    'DELETE FROM pulse_dataset_chunks
                     WHERE dataset_key = :dataset_key AND version_id = :version_id'
                );
                $cleanup->execute(['dataset_key' => $datasetKey, 'version_id' => $previousVersion]);
            }
        } catch (Throwable $error) {
            error_log('Pulse TiDB cleanup: ' . $error->getMessage());
        }

        $savedAt = $pdo->query(
            "SELECT DATE_FORMAT(saved_at, '%Y-%m-%dT%H:%i:%s.%fZ')
             FROM pulse_dataset
             WHERE dataset_key = 'active_dataset_v1'"
        )->fetchColumn();
        pulseRespond([
            'ok' => true,
            'row_count' => count($payload['rows']),
            'saved_by' => $savedBy,
            'saved_at' => $savedAt,
        ]);
    }

    $statement = $pdo->prepare('DELETE FROM pulse_dataset WHERE dataset_key = :dataset_key');
    $statement->execute(['dataset_key' => 'active_dataset_v1']);
    $statement = $pdo->prepare('DELETE FROM pulse_dataset_chunks WHERE dataset_key = :dataset_key');
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
