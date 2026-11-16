<?php

namespace App\Controllers;

use App\Core\Database;
use PDO;
use Throwable;

class ClientCancellationController
{
    private bool $sqliteWriteTransaction = false;

    public function cancelItem(string $token, int $orderItemId): void
    {
        $db = Database::getConnection();

        try {
            $this->beginWriteTransaction($db);
            $lockSuffix = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';

            $stmtSession = $db->prepare("
                SELECT ts.id
                FROM table_sessions ts
                JOIN dining_tables dt
                  ON dt.id = ts.table_id
                 AND dt.current_session_id = ts.id
                WHERE ts.session_token = ? AND ts.status = 'ACTIVE'
                {$lockSuffix}
            ");
            $stmtSession->execute([$token]);
            $session = $stmtSession->fetch();
            if (!$session) {
                $this->rollbackWriteTransaction($db);
                $this->respond(404, ['error' => 'Phiên bàn không tồn tại hoặc đã đóng']);
                return;
            }

            $stmtItem = $db->prepare("
                SELECT oi.id, oi.order_id, oi.subtotal, oi.status
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE oi.id = ? AND o.session_id = ?
                {$lockSuffix}
            ");
            $stmtItem->execute([$orderItemId, $session['id']]);
            $item = $stmtItem->fetch();
            if (!$item) {
                $this->rollbackWriteTransaction($db);
                $this->respond(404, ['error' => 'Không tìm thấy món trong phiên bàn này']);
                return;
            }

            if ($item['status'] !== 'PENDING') {
                $this->rollbackWriteTransaction($db);
                $this->respond(409, [
                    'error' => 'Chỉ có thể hủy món đang chờ bếp',
                    'current_status' => $item['status'],
                ]);
                return;
            }

            $stmtCancel = $db->prepare("
                UPDATE order_items
                SET status = 'CANCELLED'
                WHERE id = ? AND status = 'PENDING'
            ");
            $stmtCancel->execute([$orderItemId]);
            if ($stmtCancel->rowCount() !== 1) {
                $stmtCurrent = $db->prepare("SELECT status FROM order_items WHERE id = ?{$lockSuffix}");
                $stmtCurrent->execute([$orderItemId]);
                $currentStatus = $stmtCurrent->fetchColumn();
                $this->rollbackWriteTransaction($db);
                $this->respond(409, [
                    'error' => 'Trạng thái món đã thay đổi; không thể hủy',
                    'current_status' => $currentStatus ?: null,
                ]);
                return;
            }

            $stmtOrderTotal = $db->prepare("
                SELECT COALESCE(SUM(subtotal), 0)
                FROM order_items
                WHERE order_id = ? AND status <> 'CANCELLED'
            ");
            $stmtOrderTotal->execute([$item['order_id']]);
            $orderTotal = (float)$stmtOrderTotal->fetchColumn();
            $db->prepare("UPDATE orders SET total_amount = ? WHERE id = ?")
                ->execute([$orderTotal, $item['order_id']]);

            $stmtSessionTotal = $db->prepare("
                SELECT COALESCE(SUM(oi.subtotal), 0)
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE o.session_id = ? AND oi.status <> 'CANCELLED'
            ");
            $stmtSessionTotal->execute([$session['id']]);
            $sessionTotal = (float)$stmtSessionTotal->fetchColumn();
            $stmtUpdateSession = $db->prepare("
                UPDATE table_sessions
                SET total_amount = ?
                WHERE id = ? AND status = 'ACTIVE'
            ");
            $stmtUpdateSession->execute([$sessionTotal, $session['id']]);

            $stmtSessionStatus = $db->prepare("SELECT status FROM table_sessions WHERE id = ?");
            $stmtSessionStatus->execute([$session['id']]);
            if ($stmtSessionStatus->fetchColumn() !== 'ACTIVE') {
                $this->rollbackWriteTransaction($db);
                $this->respond(409, ['error' => 'Phiên bàn đã đóng; không thể hủy món']);
                return;
            }

            $this->commitWriteTransaction($db);
            $this->respond(200, [
                'status' => 'success',
                'order_item_id' => $orderItemId,
                'new_status' => 'CANCELLED',
                'order_total' => $orderTotal,
                'session_total' => $sessionTotal,
            ]);
        } catch (Throwable $e) {
            $this->rollbackWriteTransaction($db);
            http_response_code(500);
            echo json_encode(['error' => 'Không thể hủy món lúc này']);
            error_log('Client item cancellation failed: ' . $e->getMessage());
        }
    }

    private function beginWriteTransaction(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec('BEGIN IMMEDIATE');
            $this->sqliteWriteTransaction = true;
            return;
        }

        $db->beginTransaction();
    }

    private function commitWriteTransaction(PDO $db): void
    {
        if ($this->sqliteWriteTransaction) {
            $db->exec('COMMIT');
            $this->sqliteWriteTransaction = false;
            return;
        }

        $db->commit();
    }

    private function rollbackWriteTransaction(PDO $db): void
    {
        if ($this->sqliteWriteTransaction) {
            $db->exec('ROLLBACK');
            $this->sqliteWriteTransaction = false;
            return;
        }

        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    private function respond(int $statusCode, array $body): void
    {
        http_response_code($statusCode);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
