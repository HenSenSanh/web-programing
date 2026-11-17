<?php
$staffPortalUserId = (int)$_SESSION['user_id'];
$staffPortalItems = getStaffPortalNavigation($staffPortalUserId);
$staffPortalHome = getStaffLandingPath($staffPortalUserId);
$staffPortalActive = $staffPortalActive ?? '';
?>
<header class="staff-portal-nav">
    <a class="staff-portal-brand"
       href="/<?php echo htmlspecialchars($staffPortalHome, ENT_QUOTES, 'UTF-8'); ?>">
        DINEFLOW · NHÂN VIÊN
    </a>
    <nav class="staff-portal-links" aria-label="Điều hướng nhân viên">
        <?php foreach ($staffPortalItems as $item): ?>
            <a class="staff-portal-link"
               href="/<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
               <?php echo $staffPortalActive === $item['key'] ? 'aria-current="page"' : ''; ?>>
                <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
        <?php endforeach; ?>
        <a class="staff-portal-logout" href="/logout.php">Đăng xuất</a>
    </nav>
</header>
