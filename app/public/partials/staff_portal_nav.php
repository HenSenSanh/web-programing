<?php
if (($_GET['embed'] ?? '') === '1') {
    return;
}
$staffPortalUserId = (int)$_SESSION['user_id'];
$staffPortalItems = getStaffPortalNavigation($staffPortalUserId);
$staffPortalHome = getStaffLandingPath($staffPortalUserId);
$staffPortalActive = $staffPortalActive ?? '';
$staffPortalShellHref = $staffPortalActive !== ''
    ? '/portal.php?tab=' . rawurlencode((string)$staffPortalActive)
    : '/' . ltrim(getStaffPortalEntryPath($staffPortalUserId), '/');
$staffCanSeeServiceAlerts = hasAnyPermission($staffPortalUserId, ['tables.view', 'pos.view', 'data.manage']);
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
        <?php if ($staffCanSeeServiceAlerts): ?>
            <span id="staff-service-bell-root"></span>
        <?php endif; ?>
        <a class="staff-portal-link"
           href="<?php echo htmlspecialchars($staffPortalShellHref, ENT_QUOTES, 'UTF-8'); ?>"
           style="border-color: #c7d2fe; color: #4338ca;">
            Tab mượt
        </a>
        <a class="staff-portal-logout" href="/logout.php">Đăng xuất</a>
    </nav>
</header>
<?php if ($staffCanSeeServiceAlerts): ?>
    <script src="/js/staff-service-alerts.js" defer></script>
<?php endif; ?>
