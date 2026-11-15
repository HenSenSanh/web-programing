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

function requirePermission(string $permissionCode): void
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    if (!hasPermission($userId, $permissionCode)) {
        http_response_code(403);
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>403 Forbidden</title></head>";
        echo "<body style='font-family: Arial; text-align: center; padding-top: 50px;'>";
        echo "<h1 style='color: #d32f2f;'>403 - Quyền truy cập bị từ chối</h1>";
        echo "<p>Bạn không có quyền thực hiện hành động: <strong>" . htmlspecialchars($permissionCode) . "</strong></p>";
        echo "<a href='dashboard.php'>Quay lại Dashboard</a>";
        echo "</body></html>";
        exit;
    }
}
