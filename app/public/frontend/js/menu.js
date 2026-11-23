// DineFlow Client QR Menu: thanh cuộn món Hot, chọn số lượng topping, sửa món, xác nhận gửi/hủy & gọi nhân viên.
(() => {
  "use strict";

  const MAX_TOPPING_QTY = 10;
  const SUPPORT_PRESETS = [
    { code: "CALL_STAFF", icon: "🔔", label: "Gọi nhân viên đến bàn" },
    { code: "WATER_ICE", icon: "🧊", label: "Xin thêm nước / đá" },
    { code: "UTENSILS", icon: "🥢", label: "Xin chén / đũa / khăn" },
    { code: "PAYMENT", icon: "💳", label: "Yêu cầu thanh toán" },
    { code: "OTHER", icon: "💬", label: "Yêu cầu hỗ trợ khác" },
  ];

  const params = new URLSearchParams(location.search);
  const sessionToken = params.get("token") || "";
  let tableNumber = params.get("table") || "";
  const API_BASE = (params.get("api") || localStorage.getItem("dineflow_api_base") || (location.protocol.startsWith("http") ? location.origin : "http://localhost:8080")).replace(/\/$/, "");
  const FRONTEND_ROOT = new URL("../", document.currentScript.src);

  const $ = (selector) => document.querySelector(selector);
  const els = {
    table: $("#table-label"),
    notice: $("#notice"),
    favoritesSection: $("#favorites"),
    featured: $("#featured-grid"),
    hotPrev: $("#hot-prev"),
    hotNext: $("#hot-next"),
    categories: $("#category-list"),
    grid: $("#menu-grid"),
    search: $("#search-input"),
    bottomDock: $("#bottom-dock"),
    cartBar: $("#cart-bar"),
    cartCount: $("#cart-count"),
    cartTotal: $("#cart-total"),
    overlay: $("#overlay"),
    panel: $("#cart-panel"),
    cartItems: $("#cart-items"),
    panelTotal: $("#panel-total"),
    submit: $("#submit-order"),
    dialog: $("#item-dialog"),
    closeItemDialog: $("#close-item-dialog"),
    dialogContent: $("#dialog-content"),
    confirmDialog: $("#confirm-dialog"),
    confirmTitle: $("#confirm-title"),
    confirmBody: $("#confirm-body"),
    confirmCancel: $("#confirm-cancel"),
    confirmAccept: $("#confirm-accept"),
    supportBtn: $("#support-button"),
    supportPendingDot: $("#support-pending-dot"),
    supportDialog: $("#support-dialog"),
    closeSupportDialog: $("#close-support-dialog"),
    supportPresets: $("#support-presets"),
    supportNote: $("#support-note"),
    supportFeedback: $("#support-feedback"),
    sendSupportBtn: $("#send-support-btn"),
    supportHistory: $("#support-history"),
    toast: $("#toast"),
    orders: $("#sent-orders"),
    orderList: $("#sent-order-list"),
    ordersToggle: $("#orders-toggle"),
    ordersCount: $("#orders-count"),
    ordersTotal: $("#sent-orders-total"),
    closeOrders: $("#close-orders"),
    ordersOverlay: $("#orders-overlay"),
  };

  let categories = [];
  let menuItems = [];
  let toppings = [];
  let cart = [];
  let selectedCategory = "Tất cả";
  let activeItem = null;
  let editingState = null; // { index, originalKey } | null
  let dialogToppingQty = new Map();
  let recAddedCounts = new Map();
  let cartSyncTimer = null;
  let menuPollTimer = null;
  let isSyncingCart = false;
  const idempotencyStorageKey = `pending-order:${sessionToken}`;
  let pendingIdempotencyKey = sessionStorage.getItem(idempotencyStorageKey) || null;
  let toastTimer = null;
  let dialogFeedbackTimer = null;
  let submitting = false;
  let sentOrders = [];
  let ordersPanelOpen = false;
  let ordersPollTimer = null;
  let loadingOrders = false;
  let selectedSupportType = "CALL_STAFF";
  let sendingSupport = false;
  let activeConfirmMeta = null; // { mode: 'submit-order' | 'cancel-item', cartFingerprint?: string, resolve: fn }
  let lastCatalogSignature = "";

  const orderStatusLabels = {
    PENDING: "Đang chờ bếp",
    COOKING: "Đang nấu",
    READY: "Sẵn sàng",
    COMPLETED: "Nấu xong",
    SERVED: "Đã phục vụ",
    CANCELLED: "Đã hủy",
  };

  const money = (amount) => new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND", maximumFractionDigits: 0 }).format(Number(amount) || 0);
  const escapeHTML = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);

  function resolveImageUrl(source) {
    const raw = String(source || "").trim();
    if (!raw) return "";
    if (raw.startsWith("/")) return new URL(raw, API_BASE).href;
    if (/^(https?:|data:image\/|blob:)/i.test(raw)) {
      const url = new URL(raw, FRONTEND_ROOT);
      if (["localhost", "127.0.0.1"].includes(url.hostname) && !["localhost", "127.0.0.1"].includes(location.hostname)) {
        url.hostname = location.hostname;
      }
      return url.href;
    }
    if (/^file:\/\//i.test(raw) || /^[a-z]:[\\/]/i.test(raw) || /^\\\\/.test(raw)) {
      const filename = raw.replace(/^file:\/\//i, "").replace(/\\/g, "/").split("/").pop();
      return new URL(`assets/images/${encodeURIComponent(filename)}`, FRONTEND_ROOT).href;
    }
    return new URL(raw.replace(/\\/g, "/").replace(/^\.\//, "").replace(/^\/+/, ""), FRONTEND_ROOT).href;
  }

  const imageOrEmoji = (item, className = "dish-emoji") => item.image_url
    ? `<img data-dish-image="${escapeHTML(className)}" src="${escapeHTML(resolveImageUrl(item.image_url))}" alt="${escapeHTML(item.name)}" loading="lazy">`
    : `<span class="${className}" aria-hidden="true">${item.emoji || "🍽️"}</span>`;

  function bindImageFallbacks(root) {
    root.querySelectorAll("img[data-dish-image]").forEach((image) => image.addEventListener("error", () => {
      const fallback = document.createElement("span");
      fallback.className = image.dataset.dishImage || "dish-emoji";
      fallback.setAttribute("aria-hidden", "true");
      fallback.textContent = "🍽️";
      image.replaceWith(fallback);
    }, { once: true }));
  }

  document.addEventListener("error", (event) => {
    const image = event.target;
    if (!(image instanceof HTMLImageElement) || !image.matches("img[data-dish-image]")) return;
    const fallback = document.createElement("span");
    fallback.className = image.dataset.dishImage || "dish-emoji";
    fallback.setAttribute("aria-hidden", "true");
    fallback.textContent = "🍽️";
    image.replaceWith(fallback);
  }, true);

  function showNotice(message) {
    els.notice.textContent = message;
    els.notice.hidden = false;
  }

  function toast(message) {
    els.toast.textContent = message;
    els.toast.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.toast.classList.remove("show"), 2600);
  }

  function showDialogFeedback(message, isError = false) {
    const box = $("#dialog-feedback");
    if (!box) return;
    box.textContent = message;
    box.classList.toggle("is-error", Boolean(isError));
    box.hidden = false;
    clearTimeout(dialogFeedbackTimer);
    dialogFeedbackTimer = setTimeout(() => {
      if (box) box.hidden = true;
    }, 2500);
  }

  function formatToppingsText(toppingList, includePrice = false) {
    if (!Array.isArray(toppingList) || !toppingList.length) return "";
    return toppingList.map((top) => {
      const qty = Math.max(1, Number(top.quantity || 1));
      const qtySuffix = qty > 1 ? ` x${qty}` : "";
      if (!includePrice) {
        return `${top.name}${qtySuffix}`;
      }
      const unitPrice = Number(top.unit_price ?? top.price ?? 0);
      return `${top.name}${qtySuffix} (+${money(unitPrice * qty)})`;
    }).join(", ");
  }

  function buildCartKey(menuItemId, selectedToppings, note) {
    const topSig = (selectedToppings || [])
      .map((top) => `${Number(top.id)}x${Math.max(1, Number(top.quantity || 1))}`)
      .sort()
      .join(",");
    return `${Number(menuItemId)}-${topSig}-${String(note || "").trim()}`;
  }

  function updateBottomDockVisibility() {
    const showOrders = sentOrders.length > 0 && !ordersPanelOpen;
    const showCart = cart.length > 0;
    if (els.ordersToggle) {
      els.ordersToggle.hidden = !showOrders;
      els.ordersToggle.setAttribute("aria-expanded", String(ordersPanelOpen));
    }
    if (els.cartBar) {
      els.cartBar.hidden = !showCart;
    }
    if (els.bottomDock) {
      els.bottomDock.hidden = !showOrders && !showCart;
    }
  }

  // ---------- Hộp thoại xác nhận chung ----------
  function closeConfirmDialog(accepted) {
    const meta = activeConfirmMeta;
    activeConfirmMeta = null;
    if (els.confirmDialog && els.confirmDialog.open) {
      els.confirmDialog.close();
    }
    if (meta && typeof meta.resolve === "function") {
      meta.resolve(Boolean(accepted));
    }
  }

  function showConfirmDialog({ title, bodyHtml, confirmText = "Xác nhận", cancelText = "Quay lại", tone = "primary", mode = "generic", cartFingerprint = null }) {
    return new Promise((resolve) => {
      if (!els.confirmDialog) {
        resolve(true);
        return;
      }
      if (activeConfirmMeta) {
        closeConfirmDialog(false);
      }
      els.confirmTitle.textContent = title;
      els.confirmBody.innerHTML = bodyHtml;
      els.confirmCancel.textContent = cancelText;
      els.confirmAccept.textContent = confirmText;
      els.confirmAccept.classList.toggle("danger", tone === "danger");
      activeConfirmMeta = { mode, cartFingerprint, resolve };
      els.confirmDialog.showModal();
    });
  }

  // ---------- Theo dõi đơn đã gửi ----------
  function normalizeOrderHistory(data) {
    if (Array.isArray(data?.orders)) {
      return data.orders.map((order) => ({
        id: order.order_id ?? order.id ?? order.order_code ?? "—",
        time: order.created_at || order.time || "",
        items: (order.items || []).map((item) => ({
          ...item,
          id: Number(item.order_item_id ?? item.id),
          name: item.name || item.item_name || "Món ăn",
          line_total: Number(item.line_total ?? item.subtotal ?? (Number(item.unit_price || 0) * Number(item.quantity || 0))),
          note: item.note || item.notes || "",
          status: item.status || "PENDING",
        })),
      }));
    }

    const rows = Array.isArray(data) ? data : (data?.items || []);
    const groups = new Map();
    for (const row of rows) {
      const orderKey = String(row.order_id ?? row.order_code ?? "Đơn");
      if (!groups.has(orderKey)) {
        groups.set(orderKey, {
          id: row.order_code || row.order_id || orderKey,
          time: row.created_at || row.order_created_at || "",
          items: [],
        });
      }
      groups.get(orderKey).items.push({
        ...row,
        id: Number(row.order_item_id ?? row.id),
        name: row.name || row.item_name || "Món ăn",
        quantity: Number(row.quantity || 0),
        unit_price: Number(row.unit_price || 0),
        line_total: Number(row.line_total ?? row.subtotal ?? (Number(row.unit_price || 0) * Number(row.quantity || 0))),
        note: row.note || row.notes || "",
        status: row.status || "PENDING",
      });
    }
    return [...groups.values()];
  }

  function renderSentOrders() {
    if (!els.orders || !els.orderList) return;
    const itemCount = sentOrders.reduce((count, order) => count + order.items.length, 0);
    const total = sentOrders.reduce((sum, order) => sum + order.items.reduce((itemsSum, item) => {
      return itemsSum + (item.status === "CANCELLED" ? 0 : Number(item.line_total || 0));
    }, 0), 0);

    els.orders.classList.toggle("open", ordersPanelOpen && sentOrders.length > 0);
    els.orders.setAttribute("aria-hidden", String(!ordersPanelOpen));
    if (els.ordersOverlay) els.ordersOverlay.hidden = !ordersPanelOpen || sentOrders.length === 0;
    if (els.ordersCount) els.ordersCount.textContent = String(itemCount);
    if (els.ordersTotal) els.ordersTotal.textContent = money(total);
    updateBottomDockVisibility();

    if (!sentOrders.length) {
      els.orderList.innerHTML = '<p class="cart-empty">Chưa có món nào được gửi đến bếp.</p>';
      return;
    }

    els.orderList.innerHTML = [...sentOrders].reverse().map((order) => `
      <article class="sent-order-card">
        <header>
          <strong>Đơn #${escapeHTML(order.id)}</strong>
          <time>${escapeHTML(order.time ? new Date(String(order.time).replace(" ", "T")).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" }) : "")}</time>
        </header>
        ${order.items.map((item) => {
          const savedToppings = Array.isArray(item.toppings) ? item.toppings : [];
          const toppingInfo = item.topping_details_recorded
            ? (savedToppings.length
              ? `Topping: ${escapeHTML(formatToppingsText(savedToppings, true))}`
              : "Không chọn topping")
            : "Đơn cũ chưa lưu chi tiết topping";
          return `
          <div class="sent-order-item">
            <div>
              <strong>${escapeHTML(item.name)}</strong>
              <small>${Number(item.quantity || 0)} phần · ${money(item.line_total)} · ${toppingInfo}${item.note ? ` · Ghi chú: ${escapeHTML(item.note)}` : ""}</small>
            </div>
            <div class="sent-order-actions">
              <span class="order-status" data-status="${escapeHTML(item.status)}">${escapeHTML(orderStatusLabels[item.status] || item.status)}</span>
              ${item.status === "PENDING" && item.id ? `<button type="button" class="cancel-order-button" data-cancel-order-item="${Number(item.id)}">Hủy món</button>` : ""}
            </div>
          </div>`;
        }).join("")}
      </article>`).join("");
  }

  async function loadSentOrders() {
    if (!sessionToken || loadingOrders) return;
    loadingOrders = true;
    try {
      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/status`, {
        headers: { Accept: "application/json" },
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      sentOrders = normalizeOrderHistory(data);
      renderSentOrders();
    } catch (error) {
      console.info("Chưa tải được lịch sử đơn:", error.message);
    } finally {
      loadingOrders = false;
    }
  }

  function findSentOrderItemById(orderItemId) {
    const numericId = Number(orderItemId);
    for (const order of sentOrders) {
      for (const item of order.items || []) {
        if (Number(item.id) === numericId) return item;
      }
    }
    return null;
  }

  async function confirmAndCancelSentItem(orderItemId) {
    if (!sessionToken || !orderItemId) return;
    const targetItem = findSentOrderItemById(orderItemId);
    const toppingText = targetItem && Array.isArray(targetItem.toppings) && targetItem.toppings.length
      ? `<small>Topping: ${escapeHTML(formatToppingsText(targetItem.toppings, true))}</small>`
      : "";
    const noteText = targetItem && targetItem.note
      ? `<small>Ghi chú: ${escapeHTML(targetItem.note)}</small>`
      : "";
    const summaryHtml = targetItem
      ? `
        <div class="confirm-summary-list">
          <div class="confirm-summary-row">
            <div>
              <strong>${Number(targetItem.quantity || 1)} × ${escapeHTML(targetItem.name)}</strong>
              ${toppingText}
              ${noteText}
            </div>
            <strong>${money(targetItem.line_total)}</strong>
          </div>
        </div>
        <p style="margin:0;">Chỉ có thể hủy khi bếp chưa bắt đầu chế biến món này.</p>
      `
      : `<p style="margin:0;">Bạn có chắc chắn muốn hủy món này không?</p>`;

    const confirmed = await showConfirmDialog({
      title: "Xác nhận hủy món?",
      bodyHtml: summaryHtml,
      confirmText: "Xác nhận hủy món",
      cancelText: "Giữ lại",
      tone: "danger",
      mode: "cancel-item",
    });
    if (!confirmed) return;

    await cancelSentItem(orderItemId);
  }

  async function cancelSentItem(orderItemId) {
    if (!sessionToken || !orderItemId) return;
    try {
      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/order-items/${orderItemId}/cancel`, {
        method: "POST",
        headers: { Accept: "application/json" },
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.error || result.detail || `Không thể hủy món (HTTP ${response.status})`);
      toast("Đã hủy món thành công.");
      await loadSentOrders();
    } catch (error) {
      toast(error.message || "Không thể hủy món lúc này.");
    }
  }

  function connectOrderUpdates() {
    if (!sessionToken) return;
    clearInterval(ordersPollTimer);
    ordersPollTimer = setInterval(loadSentOrders, 5000);
  }

  function openOrdersPanel() {
    ordersPanelOpen = true;
    renderSentOrders();
  }

  function closeOrdersPanel() {
    ordersPanelOpen = false;
    renderSentOrders();
  }

  function updateTableLabel() {
    let label = "Menu DineFlow";
    if (tableNumber) {
      const raw = String(tableNumber).trim();
      label = /^bàn\b/i.test(raw) ? raw : `Bàn ${raw}`;
    }
    els.table.textContent = sessionToken ? label : `${label} · Xem thử`;
  }

  async function loadSessionInfo() {
    if (!sessionToken) return;
    try {
      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}`, { headers: { Accept: "application/json" } });
      if (!response.ok) return;
      const data = await response.json();
      if (data.table_number) {
        tableNumber = String(data.table_number);
        updateTableLabel();
      }
      if (data.status && data.status !== "ACTIVE") {
        showNotice("Phiên bàn này đã đóng hoặc đã thanh toán.");
      }
    } catch (error) {
      console.info("Không lấy được thông tin phiên bàn", error);
    }
  }

  // ---------- Tải thực đơn & đồng bộ trạng thái còn/hết từ Bếp ----------
  async function loadMenu(silent = false) {
    try {
      const response = await fetch(`${API_BASE}/api/v1/admin/menu-catalog`, { headers: { Accept: "application/json" } });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();

      let nextCategories = [];
      let nextToppings = [];
      if (Array.isArray(data)) {
        const grouped = new Map();
        for (const item of data) {
          const catName = item.category_name || "Thực đơn";
          if (!grouped.has(catName)) grouped.set(catName, { id: item.category_id || grouped.size + 1, name: catName, items: [] });
          grouped.get(catName).items.push(item);
        }
        nextCategories = [...grouped.values()];
      } else {
        nextCategories = (data.categories || []).map((category) => ({ ...category, items: category.items || [] }));
        nextToppings = (data.available_toppings || []).filter((topping) => topping.is_available !== false);
      }

      const nextSignature = JSON.stringify({ c: nextCategories, t: nextToppings });
      if (silent && nextSignature === lastCatalogSignature) {
        return;
      }
      lastCatalogSignature = nextSignature;
      categories = nextCategories;
      toppings = nextToppings;
      menuItems = categories.flatMap((category) => category.items.map((item) => ({ ...item, category: category.name })));

      if (!menuItems.length && !silent) {
        showNotice("Menu từ máy chủ hiện chưa có món. Hãy chạy dữ liệu mẫu hoặc kiểm tra danh mục món.");
      }
    } catch (error) {
      if (silent) return;
      categories = [];
      menuItems = [];
      toppings = [];
      showNotice("Chưa kết nối được menu từ máy chủ. Kiểm tra backend và thử lại.");
    }
    renderCategories();
    renderFeatured();
    renderMenu();
  }

  function renderCategories() {
    const list = ["Tất cả", ...categories.map((category) => category.name)];
    els.categories.innerHTML = list
      .map((name) => `<button class="category-button ${name === selectedCategory ? "active" : ""}" type="button" data-category="${escapeHTML(name)}">${escapeHTML(name)}</button>`)
      .join("");
  }

  function renderFeatured() {
    const featured = menuItems.filter((item) => item.is_hot && item.is_available !== false);
    if (els.favoritesSection) {
      els.favoritesSection.hidden = featured.length === 0;
    }
    if (!featured.length) {
      els.featured.innerHTML = "";
      return;
    }
    els.featured.innerHTML = featured
      .map((item) => `
        <article class="dish-card" data-item-id="${item.id}" tabindex="0" aria-label="Chọn ${escapeHTML(item.name)}">
          <div class="dish-image">
            ${imageOrEmoji(item, "dish-emoji")}
            <span class="badge hot">🔥 Hot</span>
          </div>
          <div class="dish-info">
            <p class="dish-category">${escapeHTML(item.category || "Món ngon")}</p>
            <h3 class="dish-title">${escapeHTML(item.name)}</h3>
            <div class="dish-footer">
              <strong class="dish-price">${money(item.price)}</strong>
              <button class="add-button" type="button" data-add="${item.id}" aria-label="Thêm ${escapeHTML(item.name)}">+</button>
            </div>
          </div>
        </article>
      `)
      .join("");
    bindImageFallbacks(els.featured);
  }

  function renderMenu() {
    const query = els.search.value.trim().toLowerCase();
    const filtered = menuItems.filter((item) => {
      const matchCategory = selectedCategory === "Tất cả" || item.category === selectedCategory;
      const matchQuery = !query || item.name.toLowerCase().includes(query) || (item.description || "").toLowerCase().includes(query);
      return matchCategory && matchQuery;
    });

    if (!filtered.length) {
      els.grid.innerHTML = '<div class="notice" style="grid-column: 1 / -1;">Không tìm thấy món phù hợp. Hãy thử tìm từ khóa khác.</div>';
      return;
    }

    els.grid.innerHTML = filtered
      .map((item) => {
        const available = item.is_available !== false;
        return `
          <article class="dish-card ${available ? "" : "unavailable"}" data-item-id="${item.id}" ${available ? 'tabindex="0"' : ""} aria-label="${available ? `Chọn ${escapeHTML(item.name)}` : `${escapeHTML(item.name)} tạm hết`}">
            <div class="dish-image">
              ${imageOrEmoji(item, "dish-emoji")}
              ${available ? (item.is_hot ? '<span class="badge hot">🔥 Hot</span>' : "") : '<span class="badge sold">Tạm hết</span>'}
            </div>
            <div class="dish-info">
              <p class="dish-category">${escapeHTML(item.category || "Món ăn")}</p>
              <h3 class="dish-title">${escapeHTML(item.name)}</h3>
              <p class="dish-description">${escapeHTML(item.description || "Món ngon dùng tại bàn, chế biến nóng sốt theo yêu cầu.")}</p>
              <div class="dish-footer">
                <strong class="dish-price">${money(item.price)}</strong>
                <button class="add-button" type="button" data-add="${item.id}" ${available ? "" : "disabled"} aria-label="Thêm ${escapeHTML(item.name)}">+</button>
              </div>
            </div>
          </article>
        `;
      })
      .join("");
    bindImageFallbacks(els.grid);
  }

  // ---------- Modal chọn món / chọn số lượng topping / sửa món ----------
  function getActiveToppingsPool() {
    if (activeItem && Array.isArray(activeItem.toppings)) {
      return activeItem.toppings.filter((top) => top.is_available !== false);
    }
    return [];
  }

  function openItem(itemId, editContext = null) {
    const item = menuItems.find((candidate) => String(candidate.id) === String(itemId));
    if (!item || item.is_available === false) {
      toast("Món này hiện đang tạm hết hàng.");
      return;
    }

    activeItem = item;
    editingState = editContext ? { index: editContext.index, originalKey: editContext.originalKey } : null;
    dialogToppingQty = new Map();
    recAddedCounts = new Map();

    const itemToppings = getActiveToppingsPool();
    const initialDishQty = editContext ? Math.max(1, Number(editContext.entry?.quantity || 1)) : 1;
    const initialNote = editContext ? String(editContext.entry?.note || "") : "";

    if (editContext && Array.isArray(editContext.entry?.selected_toppings)) {
      for (const top of editContext.entry.selected_toppings) {
        const topId = Number(top.id || 0);
        const topQty = Math.min(MAX_TOPPING_QTY, Math.max(0, Number(top.quantity || 1)));
        if (topId > 0 && topQty > 0 && itemToppings.some((t) => Number(t.id) === topId)) {
          dialogToppingQty.set(topId, topQty);
        }
      }
    }

    els.dialog.dataset.quantity = String(initialDishQty);
    const ctaPrefix = editingState ? "Cập nhật món" : "Thêm";

    els.dialogContent.innerHTML = `
      <div class="dialog-scroll">
        <div class="dialog-visual">${imageOrEmoji(item, "dish-emoji")}</div>
        <div class="dialog-body">
          <h2>${escapeHTML(item.name)}</h2>
          <p class="dialog-desc">${escapeHTML(item.description || "Món được chế biến nóng sốt ngay sau khi bếp nhận đơn.")}</p>
          ${itemToppings.length ? `
            <div class="option-heading">CHỌN THÊM TOPPING (TỐI ĐA ${MAX_TOPPING_QTY}/LOẠI)</div>
            <div class="topping-list">
              ${itemToppings.map((top) => {
                const tid = Number(top.id);
                const qty = dialogToppingQty.get(tid) || 0;
                const price = Number(top.extra_price ?? top.price ?? 0);
                return `
                  <div class="topping-option ${qty > 0 ? "is-selected" : ""}" data-topping-id="${tid}">
                    <div class="topping-info">
                      <span class="topping-name">${escapeHTML(top.name)}</span>
                      <strong class="topping-price">+${money(price)}</strong>
                    </div>
                    <div class="quantity-control topping-qty-control">
                      <button type="button" data-topping-delta="-1" data-topping-id="${tid}" aria-label="Giảm ${escapeHTML(top.name)}" ${qty <= 0 ? "disabled" : ""}>−</button>
                      <span data-topping-value="${tid}">${qty}</span>
                      <button type="button" data-topping-delta="1" data-topping-id="${tid}" aria-label="Tăng ${escapeHTML(top.name)}" ${qty >= MAX_TOPPING_QTY ? "disabled" : ""}>+</button>
                    </div>
                  </div>
                `;
              }).join("")}
            </div>
          ` : ""}
          ${editingState ? "" : '<div id="recommendations-box" style="margin-top: 14px;"></div>'}
          <label class="option-heading" for="item-note">GHI CHÚ CHO BẾP</label>
          <textarea id="item-note" class="note-input" placeholder="Ví dụ: ít cay, nhiều đá, không hành…">${escapeHTML(initialNote)}</textarea>
        </div>
      </div>
      <div id="dialog-feedback" class="dialog-feedback" hidden></div>
      <div class="dialog-footer-bar">
        <div class="quantity-control">
          <button type="button" data-dialog-qty="-1" aria-label="Giảm số lượng">−</button>
          <span id="dialog-quantity">${initialDishQty}</span>
          <button type="button" data-dialog-qty="1" aria-label="Tăng số lượng">+</button>
        </div>
        <button id="add-configured" class="primary-button" type="button">
          <span>${ctaPrefix} · ${money(item.price)}</span>
          <span aria-hidden="true">→</span>
        </button>
      </div>
    `;
    bindImageFallbacks(els.dialogContent);
    updateDialogPrice();
    els.dialog.showModal();

    // Chỉ ghi nhận clickstream và tải gợi ý khi khách đang xem/chọn món mới
    if (!editingState) {
      if (sessionToken) {
        fetch(`${API_BASE}/api/v1/data/clickstream`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            session_id: sessionToken,
            menu_item_id: item.id,
            event_type: "VIEW_ITEM",
          }),
        }).catch(() => {});
      }

      fetch(`${API_BASE}/api/v1/data/recommendations/${item.id}`)
        .then((res) => res.json())
        .then((data) => {
          const recs = (data && data.recommendations) || [];
          const box = $("#recommendations-box");
          if (!box || !recs.length || !activeItem || Number(activeItem.id) !== Number(item.id)) return;
          const availableRecs = recs.filter((r) => {
            const found = menuItems.find((m) => Number(m.id) === Number(r.oltp_item_id));
            return found && found.is_available !== false;
          });
          if (!availableRecs.length) return;
          box.innerHTML = `
            <div class="option-heading" style="color: #245b43;">🔥 MÓN HAY ĐƯỢC GỌI KÈM CÙNG</div>
            <div class="rec-list">
              ${availableRecs.map((r) => `
                <div class="rec-row">
                  <div class="rec-row-info">
                    <b>${escapeHTML(r.name)}</b>
                    <small>(${r.confidence_pct || 0}% khách gọi kèm)</small>
                  </div>
                  <button type="button" class="rec-add-btn" data-quick-rec="${Number(r.oltp_item_id)}" data-rec-price="${Number(r.current_price || 0)}">
                    + Thêm (${money(r.current_price)})
                  </button>
                </div>
              `).join("")}
            </div>
          `;
        })
        .catch(() => {});
    }
  }

  function getSelectedDialogToppings() {
    const pool = getActiveToppingsPool();
    const chosen = [];
    for (const top of pool) {
      const tid = Number(top.id);
      const qty = Math.min(MAX_TOPPING_QTY, Math.max(0, Number(dialogToppingQty.get(tid) || 0)));
      if (qty > 0) {
        chosen.push({
          id: tid,
          name: top.name,
          price: Number(top.extra_price ?? top.price ?? 0),
          quantity: qty,
        });
      }
    }
    return chosen;
  }

  function updateDialogPrice() {
    if (!activeItem) return;
    const qty = Math.max(1, Number(els.dialog.dataset.quantity || 1));
    const chosen = getSelectedDialogToppings();
    const topTotal = chosen.reduce((sum, top) => sum + Number(top.price || 0) * Number(top.quantity || 1), 0);
    const total = qty * (Number(activeItem.price) + topTotal);
    const ctaPrefix = editingState ? "Cập nhật món" : "Thêm";
    const btn = $("#add-configured");
    if (btn) {
      btn.innerHTML = `<span>${ctaPrefix} · ${money(total)}</span><span aria-hidden="true">→</span>`;
    }
  }

  // ---------- Giỏ hàng chung & Sửa món ----------
  function cleanCart(rawCart) {
    return (rawCart || []).map((item) => {
      const rawTops = item.selected_toppings || item.toppings || [];
      const selectedToppings = Array.isArray(rawTops)
        ? rawTops.map((top) => {
            if (typeof top === "object" && top !== null) {
              const found = toppings.find((t) => String(t.id) === String(top.id));
              const topQty = Math.min(MAX_TOPPING_QTY, Math.max(1, Number(top.quantity || 1)));
              return {
                id: Number(top.id || found?.id || 0),
                name: String(top.name || found?.name || "Topping"),
                price: Number(top.price ?? top.extra_price ?? found?.extra_price ?? 0),
                quantity: topQty,
              };
            }
            const foundByName = toppings.find((t) => String(t.name) === String(top));
            return {
              id: Number(foundByName?.id || 0),
              name: String(top),
              price: Number(foundByName?.extra_price || 0),
              quantity: 1,
            };
          }).filter((t) => t.id > 0 || t.name)
        : [];
      const note = String(item.note ?? item.notes ?? "").trim();
      const menuItemId = Number(item.menu_item_id || item.id);
      const toppingExtra = selectedToppings.reduce((sum, t) => sum + Number(t.price || 0) * Number(t.quantity || 1), 0);
      const serverBase = menuItems.find((m) => Number(m.id) === menuItemId)?.price;
      const computedPrice = serverBase !== undefined
        ? Number(serverBase) + toppingExtra
        : Number(item.unit_price ?? item.price ?? 0);
      return {
        _key: buildCartKey(menuItemId, selectedToppings, note),
        menu_item_id: menuItemId,
        name: String(item.name || "Món ăn"),
        quantity: Math.max(1, Number(item.quantity) || 1),
        unit_price: computedPrice,
        selected_toppings: selectedToppings,
        note,
      };
    });
  }

  function addToCart(item, quantity, chosenToppings = [], note = "", silentToast = false) {
    const toppingTotal = chosenToppings.reduce((sum, top) => sum + Number(top.price || 0) * Math.max(1, Number(top.quantity || 1)), 0);
    const unitPrice = Number(item.price) + toppingTotal;
    const trimmedNote = String(note || "").trim();
    const key = buildCartKey(item.id, chosenToppings, trimmedNote);
    const existing = cart.find((entry) => entry._key === key);
    if (existing) {
      existing.quantity += quantity;
    } else {
      cart.push({
        _key: key,
        menu_item_id: Number(item.id),
        name: item.name,
        quantity,
        unit_price: unitPrice,
        selected_toppings: chosenToppings,
        note: trimmedNote,
      });
    }
    syncCart();
    if (!silentToast) {
      toast(`Đã thêm ${quantity} × ${item.name} vào giỏ`);
    }
  }

  function saveEditedCartItem(item, quantity, chosenToppings = [], note = "") {
    const toppingTotal = chosenToppings.reduce((sum, top) => sum + Number(top.price || 0) * Math.max(1, Number(top.quantity || 1)), 0);
    const unitPrice = Number(item.price) + toppingTotal;
    const trimmedNote = String(note || "").trim();
    const newKey = buildCartKey(item.id, chosenToppings, trimmedNote);

    let targetIndex = -1;
    if (editingState) {
      if (cart[editingState.index] && cart[editingState.index]._key === editingState.originalKey) {
        targetIndex = editingState.index;
      } else {
        targetIndex = cart.findIndex((entry) => entry._key === editingState.originalKey);
      }
    }

    const updatedEntry = {
      _key: newKey,
      menu_item_id: Number(item.id),
      name: item.name,
      quantity,
      unit_price: unitPrice,
      selected_toppings: chosenToppings,
      note: trimmedNote,
    };

    if (targetIndex === -1) {
      addToCart(item, quantity, chosenToppings, trimmedNote, true);
      toast("Món vừa thay đổi từ thiết bị khác, đã thêm lại theo cấu hình mới.");
      return;
    }

    cart.splice(targetIndex, 1);
    const mergeMatch = cart.find((entry) => entry._key === newKey);
    if (mergeMatch) {
      mergeMatch.quantity += quantity;
    } else {
      cart.splice(targetIndex, 0, updatedEntry);
    }
    syncCart();
    toast(`Đã cập nhật ${item.name} trong giỏ`);
  }

  function showCart() {
    renderCartPanel();
    els.overlay.hidden = false;
    els.panel.classList.add("open");
    els.panel.setAttribute("aria-hidden", "false");
    document.body.classList.add("panel-open");
  }

  function closeCart() {
    els.overlay.hidden = true;
    els.panel.classList.remove("open");
    els.panel.setAttribute("aria-hidden", "true");
    document.body.classList.remove("panel-open");
  }

  function renderCartPanel() {
    if (!cart.length) {
      els.cartItems.innerHTML = '<div class="cart-empty">Giỏ hàng của bạn đang trống.<br>Hãy chọn món từ thực đơn nhé.</div>';
      els.panelTotal.textContent = money(0);
      els.cartTotal.textContent = money(0);
      els.cartCount.textContent = "0";
      updateBottomDockVisibility();
      return;
    }

    const total = cart.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    els.panelTotal.textContent = money(total);
    els.cartTotal.textContent = money(total);
    els.cartCount.textContent = String(count);
    updateBottomDockVisibility();

    els.cartItems.innerHTML = cart
      .map((item, index) => {
        const topDesc = item.selected_toppings.length ? `Topping: ${formatToppingsText(item.selected_toppings, true)}` : "";
        const noteDesc = item.note ? `Ghi chú: ${item.note}` : "";
        const meta = [topDesc, noteDesc].filter(Boolean).join(" · ");
        return `
          <div class="cart-row">
            <div>
              <h4 class="cart-row-title">${escapeHTML(item.name)}</h4>
              ${meta ? `<p class="cart-row-meta">${escapeHTML(meta)}</p>` : ""}
              <div class="quantity-control">
                <button type="button" data-cart-index="${index}" data-delta="-1" aria-label="Giảm">−</button>
                <span>${item.quantity}</span>
                <button type="button" data-cart-index="${index}" data-delta="1" aria-label="Tăng">+</button>
                <button class="edit-button" type="button" data-edit-cart="${index}">Sửa</button>
                <button class="remove-button" type="button" data-remove="${index}">Xóa</button>
              </div>
            </div>
            <strong class="cart-row-price">${money(item.quantity * item.unit_price)}</strong>
          </div>
        `;
      })
      .join("");
  }

  async function syncCart() {
    pendingIdempotencyKey = null;
    sessionStorage.removeItem(idempotencyStorageKey);
    renderCartPanel();
    if (!sessionToken) return;
    isSyncingCart = true;
    try {
      await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/cart`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          items: cart.map((item) => ({
            menu_item_id: item.menu_item_id,
            name: item.name,
            quantity: item.quantity,
            unit_price: item.unit_price,
            selected_toppings: item.selected_toppings,
            note: item.note || "",
          })),
        }),
      });
    } catch (error) {
      console.info("Không đồng bộ được giỏ hàng lên máy chủ", error);
    } finally {
      isSyncingCart = false;
    }
  }

  async function confirmAndSubmitOrder() {
    if (!cart.length || submitting) return;
    if (!sessionToken) {
      toast("Không có mã phiên hợp lệ. Bạn đang xem thử thực đơn.");
      return;
    }

    const total = cart.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    const totalCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    const fingerprint = JSON.stringify(cart);
    const tableDisplay = els.table ? els.table.textContent : "Bàn của bạn";

    const listHtml = cart.map((item) => {
      const topText = item.selected_toppings.length ? `Topping: ${formatToppingsText(item.selected_toppings, true)}` : "";
      const noteText = item.note ? `Ghi chú: ${item.note}` : "";
      const detail = [topText, noteText].filter(Boolean).join(" · ");
      return `
        <div class="confirm-summary-row">
          <div>
            <strong>${item.quantity} × ${escapeHTML(item.name)}</strong>
            ${detail ? `<small>${escapeHTML(detail)}</small>` : ""}
          </div>
          <strong>${money(item.quantity * item.unit_price)}</strong>
        </div>
      `;
    }).join("");

    const bodyHtml = `
      <p style="margin:0;">Gửi <strong>${totalCount} món</strong> cho <strong>${escapeHTML(tableDisplay)}</strong>:</p>
      <div class="confirm-summary-list">${listHtml}</div>
      <div class="confirm-total-row">
        <span>Tổng tạm tính</span>
        <strong>${money(total)}</strong>
      </div>
      <p style="margin:4px 0 0; font-size:12px; color:#6c766b;">Bếp sẽ tiếp nhận và chế biến ngay sau khi bạn xác nhận.</p>
    `;

    const confirmed = await showConfirmDialog({
      title: "Xác nhận gửi đơn đến bếp?",
      bodyHtml,
      confirmText: "Xác nhận gửi bếp",
      cancelText: "Quay lại",
      tone: "primary",
      mode: "submit-order",
      cartFingerprint: fingerprint,
    });
    if (!confirmed) return;

    if (JSON.stringify(cart) !== fingerprint) {
      toast("Giỏ hàng của bàn vừa được cập nhật từ thiết bị khác. Vui lòng kiểm tra lại trước khi gửi bếp.");
      return;
    }

    // Đối chiếu lại giỏ hàng mới nhất từ server ngay trước khi chốt đơn để loại trừ khoảng hở giữa chu kỳ poll 4s
    const latestRemoteCart = await fetchLatestCartSnapshot();
    if (latestRemoteCart !== null && JSON.stringify(latestRemoteCart) !== fingerprint) {
      cart = latestRemoteCart;
      renderCartPanel();
      toast("Giỏ hàng của bàn vừa được cập nhật từ thiết bị khác. Vui lòng kiểm tra lại trước khi gửi bếp.");
      return;
    }

    await submitOrder();
  }

  async function submitOrder() {
    if (!cart.length || submitting) return;
    if (!sessionToken) {
      toast("Không có mã phiên hợp lệ. Bạn đang xem thử thực đơn.");
      return;
    }
    submitting = true;
    els.submit.disabled = true;
    els.submit.innerHTML = 'Đang gửi đến bếp… <span>→</span>';
    if (!pendingIdempotencyKey) {
      pendingIdempotencyKey = typeof crypto.randomUUID === "function"
        ? crypto.randomUUID()
        : `menu-${sessionToken}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
      sessionStorage.setItem(idempotencyStorageKey, pendingIdempotencyKey);
    }

    try {
      const payload = cart.map((item) => ({
        menu_item_id: item.menu_item_id,
        name: item.name,
        quantity: item.quantity,
        unit_price: item.unit_price,
        selected_toppings: item.selected_toppings.map((top) => ({
          id: Number(top.id),
          name: top.name,
          price: Number(top.price || 0),
          quantity: Math.max(1, Number(top.quantity || 1)),
        })),
        note: item.note || null,
      }));

      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/submit-order`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "Idempotency-Key": pendingIdempotencyKey,
        },
        body: JSON.stringify(payload),
      });

      const result = await response.json().catch(() => ({}));
      const wasAlreadyAccepted = response.status === 409 && result.code === "DUPLICATE_ORDER";
      if (!response.ok && !wasAlreadyAccepted) {
        if (result.code === "MENU_ITEM_UNAVAILABLE" || result.code === "TOPPING_UNAVAILABLE") {
          loadMenu(true);
        }
        throw new Error(result.error || result.detail || `Không gửi được đơn (HTTP ${response.status})`);
      }
      cart = [];
      pendingIdempotencyKey = null;
      sessionStorage.removeItem(idempotencyStorageKey);
      renderCartPanel();
      await loadSentOrders();
      closeCart();
      toast(wasAlreadyAccepted ? `Đơn #${result.order_id} đã được nhận trước đó.` : `Đã gửi đơn #${result.order_id} xuống bếp thành công!`);
    } catch (error) {
      toast(error.message || "Không gửi được đơn. Vui lòng thử lại.");
    } finally {
      submitting = false;
      els.submit.disabled = false;
      els.submit.innerHTML = 'Gửi đơn đến bếp <span>→</span>';
    }
  }

  async function fetchLatestCartSnapshot() {
    if (!sessionToken) return null;
    try {
      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/cart`, {
        headers: { Accept: "application/json" },
      });
      if (!response.ok) return null;
      const data = await response.json();
      return cleanCart(data.items || []);
    } catch (_) {
      return null;
    }
  }

  async function fetchRemoteCart() {
    if (!sessionToken || isSyncingCart || submitting) return;
    try {
      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/cart`, {
        headers: { Accept: "application/json" },
      });
      if (!response.ok) return;
      const data = await response.json();
      const nextCart = cleanCart(data.items || []);
      const nextSerialized = JSON.stringify(nextCart);
      if (nextSerialized !== JSON.stringify(cart)) {
        cart = nextCart;
        renderCartPanel();
        if (activeConfirmMeta && activeConfirmMeta.mode === "submit-order" && nextSerialized !== activeConfirmMeta.cartFingerprint) {
          closeConfirmDialog(false);
          toast("Giỏ hàng của bàn vừa được cập nhật từ thiết bị khác. Vui lòng kiểm tra lại trước khi gửi bếp.");
        }
      }
    } catch (error) {
      console.info("Bỏ qua lỗi tải lại giỏ hàng chung", error);
    }
  }

  function connectSharedCart() {
    if (!sessionToken) return;
    fetchRemoteCart();
    clearInterval(cartSyncTimer);
    cartSyncTimer = setInterval(fetchRemoteCart, 4000);
  }

  // ---------- Gọi nhân viên / Hỗ trợ ----------
  function renderSupportPresets() {
    if (!els.supportPresets) return;
    els.supportPresets.innerHTML = SUPPORT_PRESETS.map((preset) => `
      <button type="button"
              role="radio"
              aria-checked="${preset.code === selectedSupportType ? "true" : "false"}"
              class="support-preset-btn ${preset.code === selectedSupportType ? "is-active" : ""}"
              data-support-type="${preset.code}">
        <span aria-hidden="true">${preset.icon}</span>
        <span>${escapeHTML(preset.label)}</span>
      </button>
    `).join("");
  }

  function showSupportFeedback(message, isError = false) {
    if (!els.supportFeedback) return;
    els.supportFeedback.textContent = message;
    els.supportFeedback.classList.toggle("is-error", Boolean(isError));
    els.supportFeedback.hidden = false;
  }

  async function loadClientSupportRequests() {
    if (!sessionToken) return;
    try {
      const res = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/service-requests`, {
        headers: { Accept: "application/json" },
      });
      if (!res.ok) return;
      const data = await res.json();
      const list = Array.isArray(data.requests) ? data.requests : [];
      const pendingCount = Number(data.pending_count || 0);
      if (els.supportPendingDot) {
        els.supportPendingDot.hidden = pendingCount === 0;
      }
      if (els.supportHistory) {
        if (!list.length) {
          els.supportHistory.hidden = true;
          els.supportHistory.innerHTML = "";
        } else {
          els.supportHistory.hidden = false;
          els.supportHistory.innerHTML = `
            <div class="option-heading" style="margin-top:0;">YÊU CẦU GẦN ĐÂY CỦA BÀN</div>
            <div class="support-history-list">
              ${list.slice(0, 5).map((req) => {
                const resolved = req.status === "RESOLVED";
                return `
                  <div class="support-history-item">
                    <span>${escapeHTML(req.message || req.request_label)}</span>
                    <span class="support-status-pill ${resolved ? "resolved" : ""}">${resolved ? "Đã xử lý" : "Đang chờ NV"}</span>
                  </div>
                `;
              }).join("")}
            </div>
          `;
        }
      }
    } catch (_) {
      // Silent fallback
    }
  }

  function openSupportDialog() {
    if (!els.supportDialog) return;
    selectedSupportType = "CALL_STAFF";
    if (els.supportNote) els.supportNote.value = "";
    if (els.supportFeedback) els.supportFeedback.hidden = true;
    renderSupportPresets();
    loadClientSupportRequests();
    els.supportDialog.showModal();
  }

  async function submitSupportRequest() {
    if (sendingSupport) return;
    if (!sessionToken) {
      showSupportFeedback("Bạn đang ở chế độ xem thử. Hãy quét mã QR tại bàn để gọi nhân viên.", true);
      return;
    }
    const note = (els.supportNote ? els.supportNote.value : "").trim();
    if (selectedSupportType === "OTHER" && !note) {
      showSupportFeedback("Vui lòng nhập nội dung bạn cần hỗ trợ.", true);
      if (els.supportNote) els.supportNote.focus();
      return;
    }

    sendingSupport = true;
    els.sendSupportBtn.disabled = true;
    try {
      const res = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/service-requests`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          request_type: selectedSupportType,
          message: note,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || `Không thể gửi yêu cầu (HTTP ${res.status})`);
      }
      if (els.supportNote) els.supportNote.value = "";
      showSupportFeedback(data.message || "Đã gửi yêu cầu! Nhân viên sẽ đến hỗ trợ ngay.", false);
      await loadClientSupportRequests();
    } catch (error) {
      showSupportFeedback(error.message || "Không thể gửi yêu cầu lúc này.", true);
    } finally {
      sendingSupport = false;
      els.sendSupportBtn.disabled = false;
    }
  }

  // ---------- Event Listeners ----------
  els.categories.addEventListener("click", (event) => {
    const button = event.target.closest("[data-category]");
    if (!button) return;
    selectedCategory = button.dataset.category;
    renderCategories();
    renderMenu();
  });

  function handleDishCardActivation(event) {
    const card = event.target.closest(".dish-card[data-item-id]");
    if (!card || card.classList.contains("unavailable")) return;
    openItem(card.dataset.itemId);
  }

  function handleDishCardKeydown(event) {
    if (event.key !== "Enter" && event.key !== " ") return;
    const card = event.target.closest(".dish-card[data-item-id]");
    if (!card || card.classList.contains("unavailable")) return;
    if (event.target.closest("button")) return;
    event.preventDefault();
    openItem(card.dataset.itemId);
  }

  els.grid.addEventListener("click", handleDishCardActivation);
  els.grid.addEventListener("keydown", handleDishCardKeydown);
  els.featured.addEventListener("click", handleDishCardActivation);
  els.featured.addEventListener("keydown", handleDishCardKeydown);

  if (els.hotPrev) {
    els.hotPrev.addEventListener("click", () => els.featured.scrollBy({ left: -320, behavior: "smooth" }));
  }
  if (els.hotNext) {
    els.hotNext.addEventListener("click", () => els.featured.scrollBy({ left: 320, behavior: "smooth" }));
  }

  els.search.addEventListener("input", renderMenu);

  if (els.closeItemDialog) {
    els.closeItemDialog.addEventListener("click", () => els.dialog.close());
  }
  els.dialog.addEventListener("close", () => {
    editingState = null;
    activeItem = null;
  });

  els.dialog.addEventListener("click", (event) => {
    const quickRecBtn = event.target.closest("[data-quick-rec]");
    if (quickRecBtn) {
      const recId = Number(quickRecBtn.dataset.quickRec);
      const recItem = menuItems.find((m) => Number(m.id) === recId);
      if (recItem && recItem.is_available !== false) {
        addToCart(recItem, 1, [], "", true);
        const nextCount = (recAddedCounts.get(recId) || 0) + 1;
        recAddedCounts.set(recId, nextCount);
        quickRecBtn.classList.add("is-added");
        quickRecBtn.textContent = `✓ Đã thêm (${nextCount})`;
        showDialogFeedback(`Đã thêm 1 × ${recItem.name} vào giỏ hàng`);
      } else {
        showDialogFeedback("Món gợi ý này hiện đang tạm hết.", true);
      }
      return;
    }

    const topBtn = event.target.closest("[data-topping-delta]");
    if (topBtn) {
      const tid = Number(topBtn.dataset.toppingId);
      const delta = Number(topBtn.dataset.toppingDelta);
      const currentQty = Number(dialogToppingQty.get(tid) || 0);
      const nextQty = Math.min(MAX_TOPPING_QTY, Math.max(0, currentQty + delta));
      dialogToppingQty.set(tid, nextQty);

      const row = els.dialog.querySelector(`.topping-option[data-topping-id="${tid}"]`);
      if (row) {
        row.classList.toggle("is-selected", nextQty > 0);
        const valueEl = row.querySelector(`[data-topping-value="${tid}"]`);
        if (valueEl) valueEl.textContent = String(nextQty);
        const minusBtn = row.querySelector('[data-topping-delta="-1"]');
        const plusBtn = row.querySelector('[data-topping-delta="1"]');
        if (minusBtn) minusBtn.disabled = nextQty <= 0;
        if (plusBtn) plusBtn.disabled = nextQty >= MAX_TOPPING_QTY;
      }
      updateDialogPrice();
      return;
    }

    const qtyButton = event.target.closest("[data-dialog-qty]");
    if (qtyButton) {
      const next = Math.max(1, Number(els.dialog.dataset.quantity || 1) + Number(qtyButton.dataset.dialogQty));
      els.dialog.dataset.quantity = String(next);
      $("#dialog-quantity").textContent = String(next);
      updateDialogPrice();
      return;
    }

    const addConfiguredBtn = event.target.closest("#add-configured");
    if (addConfiguredBtn && activeItem) {
      const chosen = getSelectedDialogToppings();
      const dishQty = Math.max(1, Number(els.dialog.dataset.quantity || 1));
      const noteValue = ($("#item-note")?.value || "").trim();
      if (editingState) {
        saveEditedCartItem(activeItem, dishQty, chosen, noteValue);
      } else {
        addToCart(activeItem, dishQty, chosen, noteValue);
      }
      els.dialog.close();
    }
  });

  els.cartBar.addEventListener("click", showCart);
  els.overlay.addEventListener("click", closeCart);
  document.querySelectorAll("[data-close]").forEach((button) => button.addEventListener("click", closeCart));

  els.cartItems.addEventListener("click", (event) => {
    const editButton = event.target.closest("[data-edit-cart]");
    if (editButton) {
      const index = Number(editButton.dataset.editCart);
      const entry = cart[index];
      if (!entry) return;
      openItem(entry.menu_item_id, { index, originalKey: entry._key, entry });
      return;
    }

    const changeButton = event.target.closest("[data-cart-index]");
    const removeButton = event.target.closest("[data-remove]");
    if (removeButton) {
      cart.splice(Number(removeButton.dataset.remove), 1);
    } else if (changeButton) {
      const index = Number(changeButton.dataset.cartIndex);
      if (!cart[index]) return;
      cart[index].quantity += Number(changeButton.dataset.delta);
      if (cart[index].quantity <= 0) cart.splice(index, 1);
    } else {
      return;
    }
    syncCart();
  });

  els.submit.addEventListener("click", confirmAndSubmitOrder);

  if (els.confirmCancel) els.confirmCancel.addEventListener("click", () => closeConfirmDialog(false));
  if (els.confirmAccept) els.confirmAccept.addEventListener("click", () => closeConfirmDialog(true));
  if (els.confirmDialog) {
    els.confirmDialog.addEventListener("cancel", (event) => {
      event.preventDefault();
      closeConfirmDialog(false);
    });
  }

  if (els.ordersToggle) els.ordersToggle.addEventListener("click", openOrdersPanel);
  if (els.closeOrders) els.closeOrders.addEventListener("click", closeOrdersPanel);
  if (els.ordersOverlay) els.ordersOverlay.addEventListener("click", closeOrdersPanel);
  if (els.orderList) {
    els.orderList.addEventListener("click", (event) => {
      const button = event.target.closest("[data-cancel-order-item]");
      if (button) confirmAndCancelSentItem(button.dataset.cancelOrderItem);
    });
  }

  if (els.supportBtn) els.supportBtn.addEventListener("click", openSupportDialog);
  if (els.closeSupportDialog) els.closeSupportDialog.addEventListener("click", () => els.supportDialog.close());
  if (els.supportPresets) {
    els.supportPresets.addEventListener("click", (event) => {
      const btn = event.target.closest("[data-support-type]");
      if (!btn) return;
      selectedSupportType = btn.dataset.supportType;
      renderSupportPresets();
    });
  }
  if (els.sendSupportBtn) els.sendSupportBtn.addEventListener("click", submitSupportRequest);

  // Khởi chạy
  updateTableLabel();
  loadSessionInfo();
  loadMenu(false);
  renderSentOrders();
  loadSentOrders();
  loadClientSupportRequests();
  connectOrderUpdates();
  connectSharedCart();

  // Đồng bộ trạng thái còn/hết món & yêu cầu hỗ trợ định kỳ
  menuPollTimer = setInterval(() => {
    if (!els.dialog.open) loadMenu(true);
    loadClientSupportRequests();
  }, 15000);
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) {
      if (!els.dialog.open) loadMenu(true);
      loadSentOrders();
      loadClientSupportRequests();
    }
  });

  if (!sessionToken) {
    showNotice("Đây là chế độ xem thử. Hãy mở liên kết QR có token phiên hợp lệ để đồng bộ giỏ hàng, gửi đơn và gọi nhân viên.");
  }
})();
