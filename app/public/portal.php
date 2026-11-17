<?php
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';

requireLogin();
$userId = (int)$_SESSION['user_id'];
$navItems = getStaffPortalNavigation($userId);
if ($navItems === []) {
    requirePermission('reports.view');
}

$defaultTab = getStaffDefaultTab($userId);
$itemsByKey = [];
foreach ($navItems as $item) {
    $itemsByKey[$item['key']] = $item;
}
if (!isset($itemsByKey[$defaultTab])) {
    $defaultTab = $navItems[0]['key'];
}

$requestedTab = trim((string)($_GET['tab'] ?? ''));
$activeTab = isset($itemsByKey[$requestedTab]) ? $requestedTab : $defaultTab;
$username = (string)($_SESSION['username'] ?? 'Nhân viên');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($itemsByKey[$activeTab]['label'], ENT_QUOTES, 'UTF-8'); ?> · DineFlow</title>
    <link rel="stylesheet" href="/css/staff-portal.css">
</head>
<body class="portal-shell-body">
    <header class="staff-portal-nav">
        <button type="button"
                id="portal-brand-btn"
                class="staff-portal-brand"
                data-tab="<?php echo htmlspecialchars($defaultTab, ENT_QUOTES, 'UTF-8'); ?>">
            DINEFLOW · NHÂN VIÊN
        </button>

        <nav class="staff-portal-links" role="tablist" aria-label="Điều hướng nhân viên không tải lại trang">
            <?php foreach ($navItems as $item): ?>
                <?php
                $key = $item['key'];
                $href = '/' . ltrim($item['href'], '/');
                $embedSrc = $href . (strpos($href, '?') !== false ? '&' : '?') . 'embed=1';
                $isActive = ($key === $activeTab);
                ?>
                <button type="button"
                        role="tab"
                        class="staff-portal-link portal-tab-btn"
                        data-tab="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                        data-label="<?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-src="<?php echo htmlspecialchars($embedSrc, ENT_QUOTES, 'UTF-8'); ?>"
                        aria-selected="<?php echo $isActive ? 'true' : 'false'; ?>"
                        <?php echo $isActive ? 'aria-current="page"' : ''; ?>>
                    <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                </button>
            <?php endforeach; ?>
            <button type="button" id="portal-refresh-btn" class="portal-refresh-btn" title="Làm mới tab hiện tại">
                🔄 Làm mới tab
            </button>
            <span class="portal-user-pill">👤 <?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
            <a class="staff-portal-logout" href="/logout.php">Đăng xuất</a>
        </nav>
    </header>

    <main id="portal-stage" class="portal-stage">
        <?php foreach ($navItems as $item): ?>
            <?php
            $key = $item['key'];
            $href = '/' . ltrim($item['href'], '/');
            $embedSrc = $href . (strpos($href, '?') !== false ? '&' : '?') . 'embed=1';
            $isActive = ($key === $activeTab);
            ?>
            <section class="portal-pane<?php echo $isActive ? ' is-active' : ''; ?>"
                     data-pane="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                     role="tabpanel">
                <div class="portal-loader">
                    <span class="portal-spinner" aria-hidden="true"></span>
                    <span>Đang tải <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>…</span>
                </div>
                <?php if ($isActive): ?>
                    <iframe class="portal-iframe"
                            src="<?php echo htmlspecialchars($embedSrc, ENT_QUOTES, 'UTF-8'); ?>"
                            title="<?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>

    <script>
        (function () {
            const tabButtons = Array.from(document.querySelectorAll('.portal-tab-btn'));
            const panes = new Map(
                Array.from(document.querySelectorAll('.portal-pane')).map((pane) => [pane.dataset.pane, pane])
            );
            const brandBtn = document.getElementById('portal-brand-btn');
            const refreshBtn = document.getElementById('portal-refresh-btn');
            let currentTab = <?php echo json_encode($activeTab, JSON_UNESCAPED_UNICODE); ?>;

            function mountIframe(pane, button) {
                let iframe = pane.querySelector('iframe.portal-iframe');
                if (iframe) {
                    return { iframe, created: false };
                }
                iframe = document.createElement('iframe');
                iframe.className = 'portal-iframe';
                iframe.title = button.dataset.label || button.textContent.trim();
                iframe.addEventListener('load', () => {
                    pane.classList.add('is-loaded');
                });
                iframe.src = button.dataset.src;
                pane.appendChild(iframe);
                return { iframe, created: true };
            }

            const initialPane = panes.get(currentTab);
            const initialIframe = initialPane ? initialPane.querySelector('iframe.portal-iframe') : null;
            if (initialIframe) {
                initialIframe.addEventListener('load', () => {
                    initialPane.classList.add('is-loaded');
                });
            }

            function activateTab(tabKey, pushHistory = true) {
                const button = tabButtons.find((btn) => btn.dataset.tab === tabKey);
                const pane = panes.get(tabKey);
                if (!button || !pane) {
                    return;
                }

                tabButtons.forEach((btn) => {
                    const active = btn.dataset.tab === tabKey;
                    btn.setAttribute('aria-selected', active ? 'true' : 'false');
                    if (active) {
                        btn.setAttribute('aria-current', 'page');
                    } else {
                        btn.removeAttribute('aria-current');
                    }
                });

                panes.forEach((p, key) => {
                    p.classList.toggle('is-active', key === tabKey);
                });

                const { iframe, created } = mountIframe(pane, button);
                currentTab = tabKey;
                const label = button.dataset.label || button.textContent.trim();
                document.title = `${label} · DineFlow`;

                if (pushHistory) {
                    const nextUrl = `/portal.php?tab=${encodeURIComponent(tabKey)}`;
                    if (window.location.pathname + window.location.search !== nextUrl) {
                        history.pushState({ tab: tabKey }, '', nextUrl);
                    }
                }

                if (!created && iframe.contentWindow) {
                    try {
                        iframe.contentWindow.postMessage(
                            { type: 'dineflow:tab-activated', tab: tabKey },
                            window.location.origin
                        );
                    } catch (err) {
                        // Ignore cross-frame hiccup while still loading
                    }
                }
            }

            tabButtons.forEach((btn) => {
                btn.addEventListener('click', () => activateTab(btn.dataset.tab, true));
            });

            if (brandBtn) {
                brandBtn.addEventListener('click', () => activateTab(brandBtn.dataset.tab, true));
            }

            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => {
                    const pane = panes.get(currentTab);
                    const button = tabButtons.find((btn) => btn.dataset.tab === currentTab);
                    if (!pane || !button) return;
                    const iframe = pane.querySelector('iframe.portal-iframe');
                    if (iframe) {
                        pane.classList.remove('is-loaded');
                        iframe.contentWindow.location.reload();
                    } else {
                        mountIframe(pane, button);
                    }
                });
            }

            window.addEventListener('popstate', (event) => {
                const stateTab = event.state && event.state.tab
                    ? event.state.tab
                    : new URLSearchParams(window.location.search).get('tab');
                if (stateTab && panes.has(stateTab)) {
                    activateTab(stateTab, false);
                }
            });

            window.addEventListener('message', (event) => {
                if (event.origin !== window.location.origin || !event.data || typeof event.data !== 'object') {
                    return;
                }
                if (event.data.type === 'dineflow:navigate-tab' && typeof event.data.tab === 'string') {
                    activateTab(event.data.tab, true);
                }
            });

            history.replaceState({ tab: currentTab }, '', `/portal.php?tab=${encodeURIComponent(currentTab)}`);
        })();
    </script>
</body>
</html>
