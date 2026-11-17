<?php

require_once __DIR__ . '/db.php';

function hasPermission(int $userId, string $permissionCode): bool
{
    $pdo = getDatabaseConnection();

    // 1. Kiểm tra quyền ngoại lệ riêng của tài khoản (user_permissions)
    $stmtUserPerm = $pdo->prepare("
        SELECT up.effect
        FROM user_permissions up
        JOIN permissions p ON up.permission_id = p.id
        WHERE up.user_id = :user_id AND p.permission_code = :perm_code
        LIMIT 1
    ");
    $stmtUserPerm->execute([
        "user_id" => $userId,
        "perm_code" => $permissionCode
    ]);
    $override = $stmtUserPerm->fetch();

    if ($override) {
        // Quy tắc: deny riêng chặn lập tức, allow riêng cấp quyền lập tức
        if ($override['effect'] === 'deny') {
            return false;
        }
        if ($override['effect'] === 'allow') {
            return true;
        }
    }

    // 2. Kiểm tra quyền kế thừa từ các nhóm (roles) mà user tham gia
    $stmtRolePerm = $pdo->prepare("
        SELECT 1
        FROM user_roles ur
        JOIN role_permissions rp ON ur.role_id = rp.role_id
        JOIN permissions p ON rp.permission_id = p.id
        WHERE ur.user_id = :user_id AND p.permission_code = :perm_code
        LIMIT 1
    ");
    $stmtRolePerm->execute([
        "user_id" => $userId,
        "perm_code" => $permissionCode
    ]);
    $roleHasPerm = $stmtRolePerm->fetch();

    if ($roleHasPerm) {
        return true;
    }

    // 3. Từ chối mặc định
    return false;
}

function apiPermissionDecision(?int $userId, string $permissionCode): array
{
    if ($userId === null || $userId <= 0) {
        return [
            'allowed' => false,
            'http_status' => 401,
            'error' => 'Authentication required',
        ];
    }

    if (!hasPermission($userId, $permissionCode)) {
        return [
            'allowed' => false,
            'http_status' => 403,
            'error' => 'Permission denied',
        ];
    }

    return [
        'allowed' => true,
        'http_status' => 200,
        'error' => null,
    ];
}

function getStaffLandingPath(int $userId): string
{
    if (hasPermission($userId, 'tables.manage') && hasPermission($userId, 'reports.view')) {
        return 'dashboard.php';
    }

    if (hasPermission($userId, 'pos.view')) {
        return 'frontend/pos/index.php';
    }

    if (hasPermission($userId, 'tables.open')) {
        return 'frontend/admin/table-management.php';
    }

    if (hasPermission($userId, 'kds.view')) {
        return 'frontend/kds/index.php';
    }

    return 'dashboard.php';
}

function getStaffDefaultTab(int $userId): string
{
    $landing = getStaffLandingPath($userId);
    $map = [
        'dashboard.php' => 'dashboard',
        'frontend/pos/index.php' => 'pos',
        'frontend/admin/table-management.php' => 'tables',
        'frontend/kds/index.php' => 'kds',
    ];
    if (isset($map[$landing])) {
        return $map[$landing];
    }

    $nav = getStaffPortalNavigation($userId);
    return $nav[0]['key'] ?? 'dashboard';
}

function getStaffPortalEntryPath(int $userId): string
{
    return 'portal.php?tab=' . urlencode(getStaffDefaultTab($userId));
}

function getStaffPortalNavigation(int $userId): array
{
    $items = [
        ['key' => 'tables', 'label' => 'Bàn & QR', 'href' => 'frontend/admin/table-management.php', 'permission' => 'tables.view'],
        ['key' => 'products', 'label' => 'Quản lý món', 'href' => 'products.php', 'permission' => 'products.view'],
        ['key' => 'kds', 'label' => 'Bếp KDS', 'href' => 'frontend/kds/index.php', 'permission' => 'kds.view'],
        ['key' => 'pos', 'label' => 'Thu ngân POS', 'href' => 'frontend/pos/index.php', 'permission' => 'pos.view'],
        ['key' => 'documents', 'label' => 'Hóa đơn', 'href' => 'documents.php', 'permission' => 'documents.print'],
        ['key' => 'live-ops', 'label' => 'Live Ops', 'href' => 'frontend/live-ops/index.php', 'permission' => 'data.manage'],
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'permission' => 'reports.view'],
    ];

    return array_values(array_filter(
        $items,
        static function (array $item) use ($userId): bool {
            return hasPermission($userId, $item['permission']);
        }
    ));
}

function requireApiPermission(string $permissionCode): void
{
    $sessionUserId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $userId = is_int($sessionUserId) && $sessionUserId > 0 ? $sessionUserId : null;
    $decision = apiPermissionDecision($userId, $permissionCode);

    if ($decision['allowed']) {
        return;
    }

    http_response_code($decision['http_status']);
    echo json_encode([
        'error' => $decision['error'],
        'permission' => $permissionCode,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function requirePermission(string $permissionCode): void
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: /login.php");
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    if (!hasPermission($userId, $permissionCode)) {
        $landingHref = '/' . ltrim(getStaffLandingPath($userId), '/');
        http_response_code(403);
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>403 Forbidden</title></head>";
        echo "<body style='font-family: Arial; text-align: center; padding-top: 50px;'>";
        echo "<h1 style='color: #d32f2f;'>403 - Quyền truy cập bị từ chối</h1>";
        echo "<p>Bạn không có quyền thực hiện hành động: <strong>" . htmlspecialchars($permissionCode) . "</strong></p>";
        echo "<a href='" . htmlspecialchars($landingHref, ENT_QUOTES, 'UTF-8') . "'>Quay lại trang chính</a>";
        echo "</body></html>";
        exit;
    }
}
