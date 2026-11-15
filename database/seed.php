<?php

require_once __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

function runSeeder(): void
{
    echo "🚀 [1/3] Khởi tạo Database Schema (OLTP & OLAP)...\n";
    Database::initSchema();
    $db = Database::getConnection();

    // Kiểm tra xem đã seed chưa
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM dining_tables");
    $count = (int)($stmt->fetch()['cnt'] ?? 0);
    if ($count > 0) {
        echo "✅ Dữ liệu mẫu đã tồn tại trong Database. Bỏ qua bước tạo mới.\n";
        return;
    }

    echo "🚀 [2/3] Bơm dữ liệu nền OLTP (Nhân viên, 15 Bàn ăn, Menu, Topping)...\n";
    $db->beginTransaction();

    try {
        // 1. Users
        $users = [
            ['username' => 'admin', 'email' => 'admin@dineflow.local', 'password' => 'admin123', 'full_name' => 'Quản Lý Quán (Admin)', 'role' => 'ADMIN'],
            ['username' => 'cashier1', 'email' => 'cashier1@dineflow.local', 'password' => 'cashier123', 'full_name' => 'Thu Ngân Ca Sáng', 'role' => 'CASHIER'],
            ['username' => 'chef_hung', 'email' => 'chef_hung@dineflow.local', 'password' => 'chef123', 'full_name' => 'Bếp Trưởng Hùng', 'role' => 'CHEF'],
            ['username' => 'chef_lan', 'email' => 'chef_lan@dineflow.local', 'password' => 'chef123', 'full_name' => 'Đầu Bếp Lan', 'role' => 'CHEF'],
        ];
        $stmtUser = $db->prepare("INSERT OR IGNORE INTO users (username, email, password_hash, full_name, role, email_verified) VALUES (?, ?, ?, ?, ?, 1)");
        foreach ($users as $u) {
            $hash = password_hash($u['password'], PASSWORD_BCRYPT);
            $stmtUser->execute([$u['username'], $u['email'], $hash, $u['full_name'], $u['role']]);
        }

        // 2. 15 Dining Tables
        $stmtTable = $db->prepare("INSERT INTO dining_tables (table_number, status) VALUES (?, 'AVAILABLE')");
        for ($i = 1; $i <= 15; $i++) {
            $stmtTable->execute([sprintf("Bàn %02d", $i)]);
        }

        // 3. Categories
        $categories = [
            ['name' => 'Món Chính', 'display_order' => 1],
            ['name' => 'Món Ăn Kèm', 'display_order' => 2],
            ['name' => 'Đồ Uống', 'display_order' => 3],
        ];
        $stmtCat = $db->prepare("INSERT INTO categories (name, display_order) VALUES (?, ?)");
        $catIds = [];
        foreach ($categories as $c) {
            $stmtCat->execute([$c['name'], $c['display_order']]);
            $catIds[$c['name']] = (int)$db->lastInsertId();
        }

        // 4. Menu Items
        $menuItems = [
            // Món chính
            ['cat' => 'Món Chính', 'name' => 'Mì Cay Hải Sản Cấp Độ 7', 'desc' => 'Tôm mực tươi rói, nước dùng chua cay đậm đà chuẩn vị Hàn', 'price' => 65000, 'hot' => 1],
            ['cat' => 'Món Chính', 'name' => 'Lẩu Thái Tomyum Bò Mỹ', 'desc' => 'Thịt bò Mỹ mềm thơm cùng nấm kim châm và rau tươi', 'price' => 189000, 'hot' => 1],
            ['cat' => 'Món Chính', 'name' => 'Cơm Trộn Hàn Quốc Bibimbap', 'desc' => 'Cơm dẻo kèm thịt băm, trứng ốp la lòng đào và sốt gochujang', 'price' => 55000, 'hot' => 0],
            ['cat' => 'Món Chính', 'name' => 'Mì Ý Sốt Bò Bằm Bolognaise', 'desc' => 'Sợi mì Ý dai ngon ngập sốt cà chua thịt bò bằm phủ phô mai', 'price' => 59000, 'hot' => 0],
            // Món ăn kèm
            ['cat' => 'Món Ăn Kèm', 'name' => 'Khoai Tây Chiên Lắc Phô Mai', 'desc' => 'Khoai giòn rụm áo bột phô mai béo ngậy ngọt mặn', 'price' => 35000, 'hot' => 0],
            ['cat' => 'Món Ăn Kèm', 'name' => 'Gà Rán Sốt Cay Hàn Quốc (4 miếng)', 'desc' => 'Gà giòn tan đẫm sốt cay ngọt mè rang', 'price' => 69000, 'hot' => 1],
            ['cat' => 'Món Ăn Kèm', 'name' => 'Kim Chi Cải Thảo Truyền Thống', 'desc' => 'Lên men tự nhiên, giòn cay giải ngấy tuyệt hảo', 'price' => 15000, 'hot' => 0],
            // Đồ uống
            ['cat' => 'Đồ Uống', 'name' => 'Trà Đào Cam Sả Tươi', 'desc' => 'Trà thanh mát, đào miếng giòn ngọt kết hợp hương sả thơm lừng', 'price' => 32000, 'hot' => 1],
            ['cat' => 'Đồ Uống', 'name' => 'Trà Sữa Trân Châu Hoàng Kim', 'desc' => 'Trà sữa đậm vị béo thơm kèm trân châu hoàng kim dai dẻo', 'price' => 35000, 'hot' => 1],
            ['cat' => 'Đồ Uống', 'name' => 'Coca-Cola Lon Lạnh', 'desc' => 'Nước ngọt có gas ướp lạnh sảng khoái', 'price' => 18000, 'hot' => 0],
        ];

        $stmtItem = $db->prepare("INSERT INTO menu_items (category_id, name, description, price, is_available, is_hot) VALUES (?, ?, ?, ?, 1, ?)");
        $itemMap = [];
        foreach ($menuItems as $item) {
            $catId = $catIds[$item['cat']];
            $stmtItem->execute([$catId, $item['name'], $item['desc'], $item['price'], $item['hot']]);
            $itemMap[$item['name']] = [
                'id' => (int)$db->lastInsertId(),
                'cat' => $item['cat'],
                'price' => $item['price']
            ];
        }

        // 5. Toppings
        $toppings = [
            ['name' => 'Thêm Bò Mỹ Thượng Hạng', 'price' => 25000],
            ['name' => 'Thêm Phô Mai Mozzarella Kéo Sợi', 'price' => 15000],
            ['name' => 'Thêm Trân Châu Hoàng Kim', 'price' => 8000],
            ['name' => 'Thêm Xúc Xích Đức', 'price' => 12000],
        ];
        $stmtTop = $db->prepare("INSERT INTO toppings (name, price, is_available) VALUES (?, ?, 1)");
        foreach ($toppings as $top) {
            $stmtTop->execute([$top['name'], $top['price']]);
        }

        // 6. Combo rules (Gợi ý kèm Apriori)
        $miCayId = $itemMap['Mì Cay Hải Sản Cấp Độ 7']['id'];
        $traDaoId = $itemMap['Trà Đào Cam Sả Tươi']['id'];
        $khoaiTayId = $itemMap['Khoai Tây Chiên Lắc Phô Mai']['id'];

        $stmtCombo = $db->prepare("INSERT INTO combo_rules (source_item_id, target_item_id, confidence, discount_percent) VALUES (?, ?, ?, ?)");
        $stmtCombo->execute([$miCayId, $traDaoId, 0.85, 10]);
        $stmtCombo->execute([$miCayId, $khoaiTayId, 0.70, 5]);

        echo "🚀 [3/3] Bơm dữ liệu phân tích OLAP Star Schema (Dimensions & Facts)...\n";
        // Dim Chef
        $stmtDimChef = $db->prepare("INSERT INTO dim_chef (chef_username, chef_full_name) VALUES (?, ?)");
        $stmtDimChef->execute(['chef_hung', 'Bếp Trưởng Hùng']);
        $chefHungKey = (int)$db->lastInsertId();
        $stmtDimChef->execute(['chef_lan', 'Đầu Bếp Lan']);
        $chefLanKey = (int)$db->lastInsertId();

        // Dim Menu Items
        $stmtDimItem = $db->prepare("INSERT INTO dim_menu_item (oltp_item_id, item_name, category_name, current_price) VALUES (?, ?, ?, ?)");
        $dimItemKeys = [];
        foreach ($itemMap as $name => $meta) {
            $stmtDimItem->execute([$meta['id'], $name, $meta['cat'], $meta['price']]);
            $dimItemKeys[$meta['id']] = (int)$db->lastInsertId();
        }

        // Dim Time & Fact Order Items (Seed lịch sử 5 ngày gần đây)
        $stmtDimTime = $db->prepare("INSERT OR IGNORE INTO dim_time (time_key, full_date, hour, day_of_week, is_weekend) VALUES (?, ?, ?, ?, ?)");
        $stmtFactOrder = $db->prepare("INSERT INTO fact_order_items (time_key, item_key, chef_key, session_id, quantity, revenue, prep_time_seconds, is_sla_violated) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtFactClick = $db->prepare("INSERT INTO fact_clickstream (time_key, session_id, oltp_item_id, event_type) VALUES (?, ?, ?, ?)");

        $now = time();
        for ($i = 0; $i < 60; $i++) {
            $offset = rand(0, 5 * 86400);
            $ts = $now - $offset;
            $dtStr = date('Y-m-d H:i:s', $ts);
            $datePart = date('Y-m-d', $ts);
            $hour = (int)date('H', $ts);
            $dayOfWeek = (int)date('w', $ts);
            $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6) ? 1 : 0;
            $timeKey = (int)date('YmdH', $ts);

            $stmtDimTime->execute([$timeKey, $datePart, $hour, $dayOfWeek, $isWeekend]);

            $sessId = 'hist_sess_' . uniqid();
            $randChef = rand(0, 1) ? $chefHungKey : $chefLanKey;
            
            // Chọn 2-3 món
            $chosenNames = array_rand($itemMap, rand(2, 3));
            foreach ($chosenNames as $cName) {
                $m = $itemMap[$cName];
                $iKey = $dimItemKeys[$m['id']];
                $qty = rand(1, 3);
                $rev = $qty * $m['price'];
                $prepSec = rand(300, 1100);
                $isViolated = ($prepSec > 900) ? 1 : 0;

                $stmtFactOrder->execute([$timeKey, $iKey, $randChef, $sessId, $qty, $rev, $prepSec, $isViolated]);
                $stmtFactClick->execute([$timeKey, $sessId, $m['id'], 'VIEW_ITEM']);
            }
        }

        $db->commit();
        echo "🎉 Hoàn tất bơm dữ liệu mẫu! Hệ thống DineFlow PHP đã sẵn sàng.\n";
    } catch (\Throwable $e) {
        $db->rollBack();
        echo "❌ Lỗi khi seed dữ liệu: " . $e->getMessage() . "\n";
    }
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    runSeeder();
}
