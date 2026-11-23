<?php

namespace App\Controllers;

use App\Core\Database;
use PDO;
use Throwable;

class ServiceRequestController
{
    private const COOLDOWN_SECONDS = 30;
    private const MAX_PENDING_PER_SESSION = 5;

    public const REQUEST_LABELS = [
        'CALL_STAFF' => 'Gọi nhân viên đến bàn',
        'WATER_ICE' => 'Xin thêm nước / đá',
        'UTENSILS' => 'Xin thêm chén / đũa / khăn',
        'PAYMENT' => 'Yêu cầu thanh toán',
        'OTHER' => 'Yêu cầu hỗ trợ khác',
    ];

    public static function ensureTable(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS service_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    session_id VARCHAR(64) NOT NULL,
                    table_id INT NOT NULL,
                    request_type VARCHAR(32) NOT NULL DEFAULT 'CALL_STAFF',
                    message VARCHAR(255) NOT NULL DEFAULT '',
                    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    resolved_at TIMESTAMP NULL,
                    resolved_by INT NULL,
                    INDEX idx_sr_status_created (status, created_at),
                    INDEX idx_sr_session (session_id),
                    FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON DELETE CASCADE,
                    FOREIGN KEY (table_id) REFERENCES dining_tables(id) ON DELETE CASCADE,
                    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS service_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id VARCHAR(64) NOT NULL,
                table_id INTEGER NOT NULL,
                request_type VARCHAR(32) NOT NULL DEFAULT 'CALL_STAFF',
                message VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                resolved_by INTEGER NULL,
                FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (table_id) REFERENCES dining_tables(id) ON DELETE CASCADE,
                FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
    }

    public function createClientRequest(string $token, ?array $customPayload = null): void
    {
        $db = Database::getConnection();
        self::ensureTable($db);

        $data = $customPayload ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
        if (!is_array($data)) {
            $this->respond(422, ['error' => 'Dữ liệu yêu cầu không hợp lệ', 'code' => 'INVALID_PAYLOAD']);
            return;
        }

        $requestType = strtoupper(trim((string)($data['request_type'] ?? 'CALL_STAFF')));
        if (!array_key_exists($requestType, self::REQUEST_LABELS)) {
            $this->respond(422, ['error' => 'Loại yêu cầu hỗ trợ không hợp lệ', 'code' => 'INVALID_REQUEST_TYPE']);
            return;
        }

        $rawMessage = trim((string)($data['message'] ?? ''));
        if (mb_strlen($rawMessage, 'UTF-8') > 255) {
            $rawMessage = mb_substr($rawMessage, 0, 255, 'UTF-8');
        }
        if ($requestType === 'OTHER' && $rawMessage === '') {
            $this->respond(422, ['error' => 'Vui lòng nhập nội dung cần hỗ trợ', 'code' => 'EMPTY_MESSAGE']);
            return;
        }
        $message = $rawMessage !== '' ? $rawMessage : self::REQUEST_LABELS[$requestType];

        $session = $this->findActiveSession($db, $token);
        if (!$session) {
            $this->respond(404, ['error' => 'Phiên bàn không tồn tại hoặc đã đóng', 'code' => 'INVALID_SESSION']);
            return;
        }

        try {
            $stmtPending = $db->prepare("
                SELECT id, request_type, created_at
                FROM service_requests
                WHERE session_id = ? AND status = 'PENDING'
                ORDER BY id DESC
            ");
            $stmtPending->execute([$session['id']]);
            $pendingRows = $stmtPending->fetchAll();

            if (count($pendingRows) >= self::MAX_PENDING_PER_SESSION) {
                $this->respond(429, [
                    'error' => 'Bàn của bạn đang có nhiều yêu cầu chờ xử lý. Nhân viên sẽ đến hỗ trợ ngay.',
                    'code' => 'TOO_MANY_REQUESTS',
                ]);
                return;
            }

            $now = time();
            foreach ($pendingRows as $row) {
                if (($row['request_type'] ?? '') === $requestType) {
                    $createdTs = strtotime((string)($row['created_at'] ?? ''));
                    if ($createdTs !== false && ($now - $createdTs) < self::COOLDOWN_SECONDS) {
                        $this->respond(429, [
                            'error' => 'Yêu cầu này đã được gửi, nhân viên đang tới hỗ trợ bạn.',
                            'code' => 'TOO_MANY_REQUESTS',
                        ]);
                        return;
                    }
                }
            }

            $nowStr = date('Y-m-d H:i:s', $now);
            $stmtInsert = $db->prepare("
                INSERT INTO service_requests (session_id, table_id, request_type, message, status, created_at)
                VALUES (?, ?, ?, ?, 'PENDING', ?)
            ");
            $stmtInsert->execute([
                $session['id'],
                (int)$session['table_id'],
                $requestType,
                $message,
                $nowStr,
            ]);
            $requestId = (int)$db->lastInsertId();

            $this->respond(201, [
                'status' => 'success',
                'request' => [
                    'id' => $requestId,
                    'session_id' => $session['id'],
                    'table_id' => (int)$session['table_id'],
                    'table_number' => $session['table_number'],
                    'request_type' => $requestType,
                    'request_label' => self::REQUEST_LABELS[$requestType],
                    'message' => $message,
                    'status' => 'PENDING',
                    'created_at' => $nowStr,
                ],
                'message' => 'Đã gửi yêu cầu hỗ trợ tới nhân viên!',
            ]);
        } catch (Throwable $e) {
            error_log('Create service request failed: ' . $e->getMessage());
            $this->respond(500, ['error' => 'Không thể gửi yêu cầu hỗ trợ lúc này']);
        }
    }

    public function listClientRequests(string $token): void
    {
        $db = Database::getConnection();
        self::ensureTable($db);

        $session = $this->findActiveSession($db, $token);
        if (!$session) {
            $this->respond(404, ['error' => 'Phiên bàn không tồn tại hoặc đã đóng', 'code' => 'INVALID_SESSION']);
            return;
        }

        $stmt = $db->prepare("
            SELECT id, request_type, message, status, created_at, resolved_at
            FROM service_requests
            WHERE session_id = ?
            ORDER BY id DESC
            LIMIT 10
        ");
        $stmt->execute([$session['id']]);
        $rows = [];
        $pendingCount = 0;
        foreach ($stmt->fetchAll() as $row) {
            $type = (string)($row['request_type'] ?? 'CALL_STAFF');
            if (($row['status'] ?? '') === 'PENDING') {
                $pendingCount++;
            }
            $rows[] = [
                'id' => (int)$row['id'],
                'request_type' => $type,
                'request_label' => self::REQUEST_LABELS[$type] ?? $type,
                'message' => (string)$row['message'],
                'status' => (string)$row['status'],
                'created_at' => (string)$row['created_at'],
                'resolved_at' => $row['resolved_at'] ?? null,
            ];
        }

        $this->respond(200, [
            'pending_count' => $pendingCount,
            'requests' => $rows,
        ]);
    }

    public function listStaffRequests(?string $statusFilter = null): void
    {
        $db = Database::getConnection();
        self::ensureTable($db);

        $status = strtoupper(trim($statusFilter ?? (string)($_GET['status'] ?? 'PENDING')));
        $whereSql = $status === 'ALL' ? '1 = 1' : "sr.status = 'PENDING'";
        $orderSql = $status === 'ALL' ? 'sr.id DESC' : 'sr.created_at ASC, sr.id ASC';
        $limit = $status === 'ALL' ? 20 : 50;

        $stmt = $db->query("
            SELECT sr.id, sr.session_id, sr.table_id, dt.table_number,
                   sr.request_type, sr.message, sr.status, sr.created_at, sr.resolved_at,
                   u.full_name AS resolved_by_name, u.username AS resolved_by_username
            FROM service_requests sr
            JOIN dining_tables dt ON dt.id = sr.table_id
            LEFT JOIN users u ON u.id = sr.resolved_by
            WHERE {$whereSql}
            ORDER BY {$orderSql}
            LIMIT {$limit}
        ");

        $pendingCount = (int)$db->query("SELECT COUNT(*) FROM service_requests WHERE status = 'PENDING'")->fetchColumn();
        $rows = [];
        foreach (($stmt ? $stmt->fetchAll() : []) as $row) {
            $type = (string)($row['request_type'] ?? 'CALL_STAFF');
            $rows[] = [
                'id' => (int)$row['id'],
                'session_id' => (string)$row['session_id'],
                'table_id' => (int)$row['table_id'],
                'table_number' => (string)$row['table_number'],
                'request_type' => $type,
                'request_label' => self::REQUEST_LABELS[$type] ?? $type,
                'message' => (string)$row['message'],
                'status' => (string)$row['status'],
                'created_at' => (string)$row['created_at'],
                'resolved_at' => $row['resolved_at'] ?? null,
                'resolved_by_name' => $row['resolved_by_name'] ?: ($row['resolved_by_username'] ?: null),
            ];
        }

        $this->respond(200, [
            'pending_count' => $pendingCount,
            'requests' => $rows,
        ]);
    }

    public function resolveStaffRequest(int $requestId): void
    {
        if ($requestId < 1) {
            $this->respond(422, ['error' => 'Mã yêu cầu không hợp lệ']);
            return;
        }

        $db = Database::getConnection();
        self::ensureTable($db);

        $actorId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        $resolvedBy = (is_int($actorId) && $actorId > 0) ? $actorId : null;

        try {
            $db->beginTransaction();
            $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $db->prepare("SELECT id, status, resolved_at FROM service_requests WHERE id = ?{$lock}");
            $stmt->execute([$requestId]);
            $row = $stmt->fetch();

            if (!$row) {
                $db->rollBack();
                $this->respond(404, ['error' => 'Không tìm thấy yêu cầu hỗ trợ']);
                return;
            }

            if (($row['status'] ?? '') === 'RESOLVED') {
                $db->commit();
                $this->respond(200, [
                    'status' => 'success',
                    'request_id' => $requestId,
                    'already_resolved' => true,
                    'resolved_at' => $row['resolved_at'],
                ]);
                return;
            }

            $nowStr = date('Y-m-d H:i:s');
            $stmtUpdate = $db->prepare("
                UPDATE service_requests
                SET status = 'RESOLVED', resolved_at = ?, resolved_by = ?
                WHERE id = ?
            ");
            $stmtUpdate->execute([$nowStr, $resolvedBy, $requestId]);
            $db->commit();

            $this->respond(200, [
                'status' => 'success',
                'request_id' => $requestId,
                'already_resolved' => false,
                'resolved_at' => $nowStr,
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Resolve service request failed: ' . $e->getMessage());
            $this->respond(500, ['error' => 'Không thể cập nhật yêu cầu hỗ trợ']);
        }
    }

    private function findActiveSession(PDO $db, string $token): ?array
    {
        $cleanToken = trim($token);
        if ($cleanToken === '') {
            return null;
        }
        $stmt = $db->prepare("
            SELECT ts.id, ts.table_id, dt.table_number
            FROM table_sessions ts
            JOIN dining_tables dt
              ON dt.id = ts.table_id
             AND dt.current_session_id = ts.id
            WHERE ts.session_token = ? AND ts.status = 'ACTIVE'
            LIMIT 1
        ");
        $stmt->execute([$cleanToken]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function respond(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
