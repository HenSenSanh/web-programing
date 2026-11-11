<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';
require_once __DIR__ . '/../src/inventory.php';

requireLogin();

$pdo = getDatabaseConnection();
$userId = (int)$_SESSION['user_id'];
$message = "";
$isError = false;

// 1. Xử lý ghi sổ phiếu (POST)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'post_document') {
    requirePermission('documents.post');
    $docId = (int)($_POST['document_id'] ?? 0);
    $res = InventoryService::postDocument($docId, $userId);
    $message = $res['message'];
    $isError = !$res['success'];
}

// 2. Lấy danh sách phiếu
$stmtDocs = $pdo->query("
    SELECT d.*, u.username as creator_name, p.username as poster_name,
           COUNT(l.id) as total_lines,
           COALESCE(SUM(l.subtotal), 0) as total_amount
    FROM inventory_documents d
    JOIN users u ON d.created_by = u.id
    LEFT JOIN users p ON d.posted_by = p.id
    LEFT JOIN inventory_lines l ON d.id = l.document_id
    GROUP BY d.id
    ORDER BY d.id DESC
");
$documents = $stmtDocs->fetchAll();

// Nếu đang xem/in 1 phiếu cụ thể
$viewDocId = !empty($_GET['view_id']) ? (int)$_GET['view_id'] : 0;
$viewDoc = null;
$viewLines = [];

if ($viewDocId > 0) {
    $stmtV = $pdo->prepare("
        SELECT d.*, u.username as creator_name, p.username as poster_name
        FROM inventory_documents d
        JOIN users u ON d.created_by = u.id
        LEFT JOIN users p ON d.posted_by = p.id
        WHERE d.id = :id
    ");
    $stmtV->execute(["id" => $viewDocId]);
    $viewDoc = $stmtV->fetch();

    if ($viewDoc) {
        $stmtL = $pdo->prepare("SELECT * FROM inventory_lines WHERE document_id = :doc_id");
        $stmtL->execute(["doc_id" => $viewDocId]);
        $viewLines = $stmtL->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý Phiếu Kho & In Chứng Từ</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar">
        <div style="font-weight: bold; font-size: 18px;">HỆ THỐNG QUẢN LÝ KHO</div>
        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="products.php">Sản phẩm & Phân trang</a>
            <a href="documents.php" class="active">Phiếu kho & In</a>
            <a href="logout.php" class="btn btn-danger" style="padding: 4px 10px;">Đăng xuất</a>
        </div>
    </nav>

    <div class="main-content">
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $isError ? 'error-box' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($viewDoc): ?>
            <!-- Chi tiết phiếu & Giao diện in (Bài D: Hỗ trợ @media print) -->
            <div class="card" id="printable-document">
                <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <a href="documents.php" class="btn btn-secondary">&larr; Quay lại danh sách phiếu</a>
                    <div>
                        <?php if ($viewDoc['status'] === 'DRAFT'): ?>
                            <form method="POST" action="documents.php" style="display: inline;">
                                <input type="hidden" name="action" value="post_document">
                                <input type="hidden" name="document_id" value="<?php echo $viewDoc['id']; ?>">
                                <button type="submit" class="btn btn-success" onclick="return confirm('Bạn có chắc chắn muốn ghi sổ phiếu này vào kho?');">
                                    Ghi sổ vào kho (Post Document)
                                </button>
                            </form>
                        <?php endif; ?>
                        <button onclick="window.print();" class="btn">🖨️ In phiếu kho (@media print)</button>
                    </div>
                </div>

                <div class="print-header" style="text-align: center; margin-bottom: 24px;">
                    <h2>PHIẾU <?php echo $viewDoc['doc_type'] === 'IN' ? 'NHẬP KHO' : 'XUẤT KHO'; ?> NỘI BỘ</h2>
                    <p style="font-size: 14px; color: #555;">Số phiếu: <strong><?php echo htmlspecialchars($viewDoc['document_code']); ?></strong> | Ngày: <?php echo htmlspecialchars($viewDoc['doc_date']); ?></p>
                    <p style="font-size: 13px;">Trạng thái: <strong><?php echo htmlspecialchars($viewDoc['status']); ?></strong></p>
                </div>

                <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 16px;">
                    <div>
                        <p>Đối tác / Khách hàng: <strong><?php echo htmlspecialchars($viewDoc['partner_name'] ?: 'Nội bộ'); ?></strong></p>
                        <p>Ghi chú: <?php echo htmlspecialchars($viewDoc['note'] ?: 'Không'); ?></p>
                    </div>
                    <div>
                        <p>Người lập: <strong><?php echo htmlspecialchars($viewDoc['creator_name']); ?></strong></p>
                        <p>Người ghi sổ: <strong><?php echo htmlspecialchars($viewDoc['poster_name'] ?: 'Chưa ghi sổ'); ?></strong></p>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>STT</th>
                            <th>Mã SKU</th>
                            <th>Tên sản phẩm</th>
                            <th>Vị trí kho</th>
                            <th>Lô hàng</th>
                            <th>Số lượng</th>
                            <th>Đơn giá chụp</th>
                            <th>Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stt = 1;
                        $grandTotal = 0;
                        foreach ($viewLines as $line):
                            $grandTotal += (float)$line['subtotal'];
                        ?>
                            <tr>
                                <td><?php echo $stt++; ?></td>
                                <td><?php echo htmlspecialchars($line['snapshot_sku']); ?></td>
                                <td><strong><?php echo htmlspecialchars($line['snapshot_product_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($line['snapshot_location_name']); ?></td>
                                <td><?php echo htmlspecialchars($line['snapshot_lot_code']); ?></td>
                                <td style="font-weight: bold;"><?php echo $line['quantity']; ?> <?php echo htmlspecialchars($line['snapshot_unit_name']); ?></td>
                                <td><?php echo number_format($line['snapshot_unit_price'], 0, ',', '.'); ?> đ</td>
                                <td style="font-weight: bold;"><?php echo number_format($line['subtotal'], 0, ',', '.'); ?> đ</td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="7" style="text-align: right; font-weight: bold;">TỔNG TIỀN:</td>
                            <td style="font-weight: bold; color: #1976d2; font-size: 16px;"><?php echo number_format($grandTotal, 0, ',', '.'); ?> đ</td>
                        </tr>
                    </tbody>
                </table>

                <div style="display: flex; justify-content: space-around; text-align: center; margin-top: 40px; font-size: 14px;">
                    <div>
                        <p><strong>Người lập phiếu</strong></p>
                        <p style="margin-top: 45px;"><?php echo htmlspecialchars($viewDoc['creator_name']); ?></p>
                    </div>
                    <div>
                        <p><strong>Thủ kho</strong></p>
                        <p style="margin-top: 45px;"><?php echo htmlspecialchars($viewDoc['poster_name'] ?: '.....................'); ?></p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Danh sách chứng từ -->
            <div class="card">
                <h2>Quản lý Phiếu Kho (Bài D — Nhập, Xuất & In)</h2>
                <p class="subtitle" style="text-align: left;">
                    Lập phiếu nháp (DRAFT) $\rightarrow$ Ghi sổ nguyên tử trong Transaction (POSTED) cập nhật tồn và biến động.
                </p>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Mã phiếu</th>
                            <th>Loại phiếu</th>
                            <th>Ngày lập</th>
                            <th>Đối tác</th>
                            <th>Số dòng</th>
                            <th>Tổng tiền</th>
                            <th>Trạng thái</th>
                            <th>Người lập</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($documents)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; color: #888; padding: 20px;">Chưa có chứng từ nào.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($documents as $d): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($d['document_code']); ?></strong></td>
                                    <td>
                                        <span style="font-weight: bold; color: <?php echo $d['doc_type'] === 'IN' ? '#2e7d32' : '#d32f2f'; ?>;">
                                            <?php echo $d['doc_type'] === 'IN' ? 'Nhập kho (IN)' : 'Xuất kho (OUT)'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($d['doc_date']); ?></td>
                                    <td><?php echo htmlspecialchars($d['partner_name'] ?: '-'); ?></td>
                                    <td><?php echo $d['total_lines']; ?></td>
                                    <td style="font-weight: bold;"><?php echo number_format($d['total_amount'], 0, ',', '.'); ?> đ</td>
                                    <td>
                                        <span style="padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; background: <?php echo $d['status'] === 'POSTED' ? '#e8f5e9; color: #2e7d32;' : '#fff3e0; color: #ef6c00;'; ?>">
                                            <?php echo htmlspecialchars($d['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($d['creator_name']); ?></td>
                                    <td>
                                        <a href="documents.php?view_id=<?php echo $d['id']; ?>" class="btn" style="padding: 4px 8px; font-size: 12px;">Xem / In</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
