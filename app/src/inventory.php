<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rbac.php';

class InventoryService
{
    /**
     * Lấy giá hợp lệ của sản phẩm theo thời điểm và loại giá (Bài C)
     */
    public static function getValidPrice(int $productId, string $priceType, string $targetDateTime): ?array
    {
        $pdo = getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT *
            FROM product_prices
            WHERE product_id = :product_id
              AND price_type = :price_type
              AND valid_from <= :target_dt
              AND (valid_to IS NULL OR valid_to > :target_dt)
            ORDER BY valid_from DESC
            LIMIT 1
        ");
        $stmt->execute([
            "product_id" => $productId,
            "price_type" => $priceType,
            "target_dt" => $targetDateTime
        ]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Kiểm tra khoảng giá không giao nhau khi thêm mới mức giá (Bài C)
     */
    public static function addPrice(int $productId, string $priceType, float $amount, string $validFrom, ?string $validTo): array
    {
        if ($validTo !== null && strtotime($validTo) <= strtotime($validFrom)) {
            return ["success" => false, "message" => "Thời điểm kết thúc (valid_to) phải lớn hơn thời điểm bắt đầu (valid_from)."];
        }

        $pdo = getDatabaseConnection();
        $pdo->beginTransaction();
        try {
            // Kiểm tra giao khoảng với các mức giá cùng loại
            $stmt = $pdo->prepare("
                SELECT * FROM product_prices
                WHERE product_id = :product_id AND price_type = :price_type
            ");
            $stmt->execute(["product_id" => $productId, "price_type" => $priceType]);
            $existing = $stmt->fetchAll();

            $fromTs = strtotime($validFrom);
            $toTs = $validTo ? strtotime($validTo) : PHP_INT_MAX;

            foreach ($existing as $p) {
                $eFrom = strtotime($p['valid_from']);
                $eTo = $p['valid_to'] ? strtotime($p['valid_to']) : PHP_INT_MAX;

                // Hai khoảng [fromTs, toTs) và [eFrom, eTo) giao nhau khi max(fromTs, eFrom) < min(toTs, eTo)
                if (max($fromTs, $eFrom) < min($toTs, $eTo)) {
                    $pdo->rollBack();
                    return [
                        "success" => false,
                        "message" => "Khoảng giá bị giao nhau với mức giá đã có (từ {$p['valid_from']} đến " . ($p['valid_to'] ?: 'vô hạn') . ")."
                    ];
                }
            }

            $stmtInsert = $pdo->prepare("
                INSERT INTO product_prices (product_id, price_type, amount, valid_from, valid_to)
                VALUES (:product_id, :price_type, :amount, :valid_from, :valid_to)
            ");
            $stmtInsert->execute([
                "product_id" => $productId,
                "price_type" => $priceType,
                "amount" => $amount,
                "valid_from" => $validFrom,
                "valid_to" => $validTo
            ]);

            $pdo->commit();
            return ["success" => true, "id" => (int)$pdo->lastInsertId()];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        }
    }

    /**
     * Tạo phiếu nháp (DRAFT) có chụp đơn giá và thông tin sản phẩm (Bài D)
     */
    public static function createDraftDocument(
        string $docType, // 'IN' hoặc 'OUT'
        string $docDate,
        ?string $partnerName,
        ?string $note,
        int $createdByUserId,
        array $lines // array of [product_id, location_id, lot_id, quantity]
    ): array {
        if (!in_array($docType, ['IN', 'OUT'])) {
            return ["success" => false, "message" => "Loại phiếu không hợp lệ."];
        }
        if (empty($lines)) {
            return ["success" => false, "message" => "Phiếu phải có ít nhất một dòng hàng."];
        }

        $pdo = getDatabaseConnection();
        $pdo->beginTransaction();
        try {
            $docCode = ($docType === 'IN' ? 'PN-' : 'PX-') . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            $stmtDoc = $pdo->prepare("
                INSERT INTO inventory_documents (document_code, doc_type, doc_date, partner_name, note, status, created_by)
                VALUES (:code, :type, :date, :partner, :note, 'DRAFT', :created_by)
            ");
            $stmtDoc->execute([
                "code" => $docCode,
                "type" => $docType,
                "date" => $docDate,
                "partner" => $partnerName,
                "note" => $note,
                "created_by" => $createdByUserId
            ]);
            $documentId = (int)$pdo->lastInsertId();

            $priceType = ($docType === 'IN') ? 'purchase' : 'sale';
            $stmtLine = $pdo->prepare("
                INSERT INTO inventory_lines (
                    document_id, product_id, location_id, lot_id, unit_id,
                    price_id, snapshot_unit_price, quantity, subtotal,
                    snapshot_product_name, snapshot_sku, snapshot_unit_name,
                    snapshot_location_name, snapshot_lot_code
                ) VALUES (
                    :doc_id, :prod_id, :loc_id, :lot_id, :unit_id,
                    :price_id, :unit_price, :qty, :subtotal,
                    :prod_name, :sku, :unit_name, :loc_name, :lot_code
                )
            ");

            foreach ($lines as $line) {
                $prodId = (int)$line['product_id'];
                $locId = (int)$line['location_id'];
                $lotId = (int)$line['lot_id'];
                $qty = (int)$line['quantity'];

                if ($qty <= 0) {
                    throw new \Exception("Số lượng phải là số nguyên dương.");
                }

                // Lấy thông tin sản phẩm
                $stmtP = $pdo->prepare("
                    SELECT p.*, u.name as unit_name
                    FROM products p
                    JOIN units u ON p.unit_id = u.id
                    WHERE p.id = :id
                ");
                $stmtP->execute(["id" => $prodId]);
                $prod = $stmtP->fetch();
                if (!$prod) throw new \Exception("Sản phẩm ID #{$prodId} không tồn tại.");

                // Lấy thông tin vị trí
                $stmtL = $pdo->prepare("SELECT location_code FROM warehouse_locations WHERE id = :id");
                $stmtL->execute(["id" => $locId]);
                $loc = $stmtL->fetch();
                if (!$loc) throw new \Exception("Vị trí kho ID #{$locId} không tồn tại.");

                // Lấy thông tin lô
                $stmtLot = $pdo->prepare("SELECT * FROM product_lots WHERE id = :id AND product_id = :p_id");
                $stmtLot->execute(["id" => $lotId, "p_id" => $prodId]);
                $lot = $stmtLot->fetch();
                if (!$lot) throw new \Exception("Lô ID #{$lotId} không hợp lệ với sản phẩm.");

                // Kiểm tra hạn sử dụng nếu là phiếu xuất
                if ($docType === 'OUT' && !empty($lot['expired_date'])) {
                    if (strtotime($lot['expired_date']) < time()) {
                        throw new \Exception("Lô {$lot['lot_code']} đã hết hạn sử dụng ({$lot['expired_date']}), không thể xuất.");
                    }
                }

                // Tìm giá hiệu lực tại ngày lập phiếu
                $validPrice = self::getValidPrice($prodId, $priceType, $docDate . ' ' . date('H:i:s'));
                $unitPrice = $validPrice ? (float)$validPrice['amount'] : 0.00;
                $priceId = $validPrice ? (int)$validPrice['id'] : null;
                $subtotal = $unitPrice * $qty;

                $stmtLine->execute([
                    "doc_id" => $documentId,
                    "prod_id" => $prodId,
                    "loc_id" => $locId,
                    "lot_id" => $lotId,
                    "unit_id" => $prod['unit_id'],
                    "price_id" => $priceId,
                    "unit_price" => $unitPrice,
                    "qty" => $qty,
                    "subtotal" => $subtotal,
                    "prod_name" => $prod['name'],
                    "sku" => $prod['sku'],
                    "unit_name" => $prod['unit_name'],
                    "loc_name" => $loc['location_code'],
                    "lot_code" => $lot['lot_code']
                ]);
            }

            $pdo->commit();
            return ["success" => true, "document_id" => $documentId, "document_code" => $docCode];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        }
    }

    /**
     * Ghi sổ chứng từ nguyên tử trong Transaction, khóa dòng chống âm tồn (Bài D)
     */
    public static function postDocument(int $documentId, int $postedByUserId): array
    {
        $pdo = getDatabaseConnection();
        $pdo->beginTransaction();
        try {
            // Khóa phiếu để kiểm tra trạng thái
            $stmtDoc = $pdo->prepare("SELECT * FROM inventory_documents WHERE id = :id");
            $stmtDoc->execute(["id" => $documentId]);
            $doc = $stmtDoc->fetch();

            if (!$doc) {
                throw new \Exception("Không tìm thấy chứng từ.");
            }
            if ($doc['status'] === 'POSTED') {
                throw new \Exception("Chứng từ đã được ghi sổ trước đó. Không được ghi sổ lặp.");
            }
            if ($doc['status'] === 'CANCELLED') {
                throw new \Exception("Chứng từ đã bị hủy. Không thể ghi sổ.");
            }

            // Lấy toàn bộ dòng hàng
            $stmtLines = $pdo->prepare("SELECT * FROM inventory_lines WHERE document_id = :doc_id");
            $stmtLines->execute(["doc_id" => $documentId]);
            $lines = $stmtLines->fetchAll();

            if (empty($lines)) {
                throw new \Exception("Chứng từ không có dòng hàng.");
            }

            // Khóa các bản ghi tồn kho tương ứng
            foreach ($lines as $line) {
                $prodId = (int)$line['product_id'];
                $locId = (int)$line['location_id'];
                $lotId = (int)$line['lot_id'];
                $qty = (int)$line['quantity'];

                // Tìm hoặc tạo stock_balance
                $stmtBal = $pdo->prepare("
                    SELECT quantity FROM stock_balances
                    WHERE product_id = :p_id AND location_id = :loc_id AND lot_id = :lot_id
                ");
                $stmtBal->execute(["p_id" => $prodId, "loc_id" => $locId, "lot_id" => $lotId]);
                $bal = $stmtBal->fetch();

                $currentQty = $bal ? (int)$bal['quantity'] : 0;

                if ($doc['doc_type'] === 'OUT') {
                    // Chặn âm tồn
                    if ($currentQty < $qty) {
                        throw new \Exception("Sản phẩm ID #{$prodId} tại vị trí ID #{$locId} chỉ còn tồn {$currentQty}, không đủ để xuất {$qty}.");
                    }
                    $newQty = $currentQty - $qty;
                    $stmtUp = $pdo->prepare("
                        UPDATE stock_balances SET quantity = :qty
                        WHERE product_id = :p_id AND location_id = :loc_id AND lot_id = :lot_id
                    ");
                    $stmtUp->execute(["qty" => $newQty, "p_id" => $prodId, "loc_id" => $locId, "lot_id" => $lotId]);

                    // Ghi sổ biến động (movement)
                    $stmtMov = $pdo->prepare("
                        INSERT INTO stock_movements (line_id, document_id, product_id, location_id, lot_id, movement_type, quantity)
                        VALUES (:line_id, :doc_id, :p_id, :loc_id, :lot_id, 'OUT', :qty)
                    ");
                    $stmtMov->execute([
                        "line_id" => $line['id'],
                        "doc_id" => $documentId,
                        "p_id" => $prodId,
                        "loc_id" => $locId,
                        "lot_id" => $lotId,
                        "qty" => -$qty
                    ]);
                } else {
                    // Phiếu nhập IN
                    if ($bal) {
                        $newQty = $currentQty + $qty;
                        $stmtUp = $pdo->prepare("
                            UPDATE stock_balances SET quantity = :qty
                            WHERE product_id = :p_id AND location_id = :loc_id AND lot_id = :lot_id
                        ");
                        $stmtUp->execute(["qty" => $newQty, "p_id" => $prodId, "loc_id" => $locId, "lot_id" => $lotId]);
                    } else {
                        $stmtIns = $pdo->prepare("
                            INSERT INTO stock_balances (product_id, location_id, lot_id, quantity)
                            VALUES (:p_id, :loc_id, :lot_id, :qty)
                        ");
                        $stmtIns->execute(["p_id" => $prodId, "loc_id" => $locId, "lot_id" => $lotId, "qty" => $qty]);
                    }

                    $stmtMov = $pdo->prepare("
                        INSERT INTO stock_movements (line_id, document_id, product_id, location_id, lot_id, movement_type, quantity)
                        VALUES (:line_id, :doc_id, :p_id, :loc_id, :lot_id, 'IN', :qty)
                    ");
                    $stmtMov->execute([
                        "line_id" => $line['id'],
                        "doc_id" => $documentId,
                        "p_id" => $prodId,
                        "loc_id" => $locId,
                        "lot_id" => $lotId,
                        "qty" => $qty
                    ]);
                }
            }

            // Cập nhật trạng thái phiếu sang POSTED
            $stmtPosted = $pdo->prepare("
                UPDATE inventory_documents
                SET status = 'POSTED', posted_by = :user_id, posted_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmtPosted->execute(["user_id" => $postedByUserId, "id" => $documentId]);

            $pdo->commit();
            return ["success" => true, "message" => "Ghi sổ chứng từ thành công."];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        }
    }
}
