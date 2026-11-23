// DineFlow Client QR Menu: giỏ hàng chung, theo dõi đơn, topping có số lượng, sửa món, xác nhận gửi đơn & gọi phục vụ.
(() => {
  "use strict";

  const params = new URLSearchParams(location.search);
  const sessionToken = params.get("token") || sessionStorage.getItem("dineflow_session_token") || "";
  if (sessionToken) {
    try { sessionStorage.setItem("dineflow_session_token", sessionToken); } catch (_) {}
  }
  let tableNumber = params.get("table") || sessionStorage.getItem("dineflow_table_number") || "";
  if (tableNumber) {
    try { sessionStorage.setItem("dineflow_table_number", tableNumber); } catch (_) {}
  }

  const API_BASE = (params.get("api") || localStorage.getItem("dineflow_api_base") || (location.protocol.startsWith("http") ? location.origin : "http://localhost:8080")).replace(/\/$/, "");
  const FRONTEND_ROOT = new URL("../", document.currentScript.src);

  const $ = (selector) => document.querySelector(selector);
  const els = {
    table: $("#table-label"), notice: $("#notice"), categories: $("#category-list"), grid: $("#menu-grid"), featured: $("#featured-grid"), search: $("#search-input"),
    cartBar: $("#cart-bar"), cartCount: $("#cart-count"), cartTotal: $("#cart-total"), overlay: $("#overlay"), panel: $("#cart-panel"),
    cartItems: $("#cart-items"), panelTotal: $("#panel-total"), submit: $("#submit-order"), dialog: $("#item-dialog"), dialogContent: $("#dialog-content"), toast: $("#toast"),
    orders: $("#sent-orders"), orderList: $("#sent-order-list"), ordersToggle: $("#orders-toggle"), ordersCount: $("#orders-count"),
    ordersTotal: $("#sent-orders-total"), closeOrders: $("#close-orders"), ordersOverlay: $("#orders-overlay"),
    confirmDialog: $("#confirm-dialog"), confirmTitle: $("#confirm-title"), confirmBody: $("#confirm-body"), confirmIcon: $("#confirm-icon"),
    confirmOkBtn: $("#confirm-ok-btn"), confirmCancelBtn: $("#confirm-cancel-btn"),
    assistanceDialog: $("#assistance-dialog"), callStaffBtn: $("#call-staff-btn"), sendAssistanceBtn: $("#send-assistance-btn"),
    assistanceNote: $("#assistance-note"), assistancePresets: $("#assistance-presets"),
  };

  let categories = [];
  let menuItems = [];
  let toppings = [];
  let cart = [];
  let selectedCategory = "Tất cả";
  let activeItem = null;
  let editingCartIndex = null;
  let cartSyncTimer = null;
  let isSyncingCart = false;
  const idempotencyStorageKey = `pending-order:${sessionToken}`;
  let pendingIdempotencyKey = sessionStorage.getItem(idempotencyStorageKey) || null;
  let toastTimer = null;
  let submitting = false;
  let sentOrders = [];
  let ordersPanelOpen = false;
  let ordersPollTimer = null;
  let loadingOrders = false;
  let pendingConfirmAction = null;
  let selectedAssistanceType = "BILL";

  const orderStatusLabels = {
    PENDING: "Đang chờ bếp",
    COOKING: "Đang nấu",
    COMPLETED: "Nấu xong",
    SERVED: "Đã phục vụ",
    CANCELLED: "Đã hủy",
  };

  const money = (amount) => new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND", maximumFractionDigits: 0 }).format(Number(amount) || 0);
  const escapeHTML = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);

  function removeVietnameseTones(str) {
    return String(str || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/đ/g, "d").replace(/Đ/g, "D")
      .toLowerCase()
      .trim();
  }

  function resolveImageUrl(source) {
    const raw = String(source || "").trim();
    if (!raw) return "";
    if (raw.startsWith("/")) return new URL(raw, API_BASE).href;
    if (/^(https?:|data:image\/|blob:)/i.test(raw)) {
      const url = new URL(raw, FRONTEND_ROOT);
      if (["localhost", "127.0.0.1"].includes(url.hostname) && !["localhost", "127.0.0.1"].includes(location.hostname)) url.hostname = location.hostname;
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
    if (!els.notice) return;
    els.notice.textContent = message;
    els.notice.hidden = false;
  }

  function toast(message) {
    if (!els.toast) return;
    els.toast.textContent = message;
    els.toast.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.toast.classList.remove("show"), 2400);
  }

  function showConfirm({ title, message, icon = "⚠️", okText = "Xác nhận", cancelText = "Hủy bỏ", isDanger = false, onOk }) {
    if (!els.confirmDialog) {
      if (confirm(message.replace(/<[^>]+>/g, ""))) {
        if (typeof onOk === "function") onOk();
      }
      return;
    }
    pendingConfirmAction = onOk;
    els.confirmTitle.textContent = title;
    els.confirmBody.innerHTML = message;
    els.confirmIcon.textContent = icon;
    els.confirmOkBtn.textContent = okText;
    els.confirmCancelBtn.textContent = cancelText;

    if (isDanger) {
      els.confirmOkBtn.classList.add("danger");
    } else {
      els.confirmOkBtn.classList.remove("danger");
    }

    els.confirmDialog.showModal();
  }

  function closeConfirm() {
    if (els.confirmDialog && els.confirmDialog.open) {
      els.confirmDialog.close();
    }
    pendingConfirmAction = null;
  }

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
    if (els.ordersToggle) {
      els.ordersToggle.hidden = sentOrders.length === 0 || ordersPanelOpen;
      els.ordersToggle.setAttribute("aria-expanded", String(ordersPanelOpen));
    }
    if (els.ordersCount) els.ordersCount.textContent = String(itemCount);
    if (els.ordersTotal) els.ordersTotal.textContent = money(total);

    if (!sentOrders.length) {
      els.orderList.innerHTML = '<p class="cart-empty">Chưa có món nào được gửi đến bếp.</p>';
      return;
    }

    els.orderList.innerHTML = [...sentOrders].reverse().map((order) => `
      <article class="sent-order-card">
        <header>
          <strong>Đơn #${escapeHTML(order.id)}</strong>
          <time>${escapeHTML(order.time ? new Date(order.time).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" }) : "")}</time>
        </header>
        ${order.items.map((item) => {
          const savedToppings = Array.isArray(item.toppings) ? item.toppings : [];
          const toppingInfo = item.topping_details_recorded
            ? (savedToppings.length
              ? `Topping: ${savedToppings.map((top) => {
                  const qtyStr = (top.quantity && Number(top.quantity) > 1) ? ` x${top.quantity}` : "";
                  const p = top.subtotal ?? (Number(top.unit_price || 0) * Number(top.quantity || 1));
                  return `${escapeHTML(top.name)}${qtyStr} (+${money(p)})`;
                }).join(", ")}`
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
              ${item.status === "PENDING" && item.id ? `<button type="button" class="cancel-order-button" data-cancel-order-item="${Number(item.id)}" data-item-name="${escapeHTML(item.name)}">Hủy món</button>` : ""}
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

  function promptCancelSentItem(orderItemId, itemName) {
    if (!sessionToken || !orderItemId) return;
    showConfirm({
      title: "Xác nhận hủy món?",
      message: `Bạn có chắc chắn muốn hủy món <b>"${escapeHTML(itemName || 'này')}"</b> không?<br>Món này sẽ ngừng chuẩn bị tại bếp.`,
      icon: "⚠️",
      isDanger: true,
      okText: "Đồng ý hủy món",
      cancelText: "Giữ lại món",
      onOk: () => executeCancelSentItem(orderItemId),
    });
  }

  async function executeCancelSentItem(orderItemId) {
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
        try { sessionStorage.setItem("dineflow_table_number", tableNumber); } catch (_) {}
        updateTableLabel();
      }
      if (data.status && data.status !== "ACTIVE") {
        showNotice("Phiên bàn này đã đóng hoặc đã thanh toán.");
      }
    } catch (error) {
      console.info("Không lấy được thông tin phiên bàn", error);
    }
  }

  async function loadMenu() {
    try {
      const response = await fetch(`${API_BASE}/api/v1/admin/menu-catalog`, { headers: { Accept: "application/json" } });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      if (Array.isArray(data)) {
        const grouped = new Map();
        for (const item of data) {
          const catName = item.category_name || "Thực đơn";
          if (!grouped.has(catName)) grouped.set(catName, { id: item.category_id || grouped.size + 1, name: catName, items: [] });
          grouped.get(catName).items.push(item);
        }
        categories = [...grouped.values()];
        toppings = [];
      } else {
        categories = (data.categories || []).map((category) => ({ ...category, items: category.items || [] }));
        toppings = (data.available_toppings || []).filter((topping) => topping.is_available !== false);
      }
      menuItems = categories.flatMap((category) => category.items.map((item) => ({ ...item, category: category.name })));
      if (!menuItems.length) showNotice("Menu từ máy chủ hiện chưa có món. Hãy kiểm tra danh mục món.");
    } catch (error) {
      categories = [];
      menuItems = [];
      toppings = [];
      showNotice("Chưa kết nối được menu từ máy chủ. Vui lòng kiểm tra lại kết nối.");
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
    const featured = menuItems.filter((item) => item.is_hot && item.is_available).slice(0, 8);
    const pool = featured.length ? featured : menuItems.filter((item) => item.is_available).slice(0, 6);

    els.featured.innerHTML = pool
      .map((item) => `
        <article class="dish-card" data-item-id="${item.id}">
          <div class="dish-image">
            ${imageOrEmoji(item, "dish-emoji")}
            <span class="badge">🔥 Bán chạy</span>
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
    const rawQuery = els.search.value.trim();
    const queryNormalized = removeVietnameseTones(rawQuery);

    const filtered = menuItems.filter((item) => {
      const matchCategory = selectedCategory === "Tất cả" || item.category === selectedCategory;
      if (!matchCategory) return false;
      if (!rawQuery) return true;

      const nameNormalized = removeVietnameseTones(item.name);
      const descNormalized = removeVietnameseTones(item.description);
      return nameNormalized.includes(queryNormalized) || descNormalized.includes(queryNormalized);
    });

    if (!filtered.length) {
      els.grid.innerHTML = '<div class="notice" style="grid-column: 1 / -1; text-align: center;">Không tìm thấy món phù hợp với từ khóa tìm kiếm.</div>';
      return;
    }

    els.grid.innerHTML = filtered
      .map((item) => {
        const available = item.is_available !== false;
        return `
          <article class="dish-card ${available ? "" : "unavailable"}" data-item-id="${item.id}">
            <div class="dish-image">
              ${imageOrEmoji(item, "dish-emoji")}
              ${available ? (item.is_hot ? '<span class="badge">Bán chạy</span>' : "") : '<span class="badge sold">Tạm hết</span>'}
            </div>
            <div class="dish-info">
              <p class="dish-category">${escapeHTML(item.category || "Món ăn")}</p>
              <h3 class="dish-title">${escapeHTML(item.name)}</h3>
              <p class="dish-description">${escapeHTML(item.description || "Món ngon chế biến tươi nóng.")}</p>
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

  function getActiveToppingsPool() {
    if (activeItem && Array.isArray(activeItem.toppings) && activeItem.toppings.length) {
      return activeItem.toppings.filter((top) => top.is_available !== false);
    }
    return toppings;
  }

  function openItem(itemId, editIndex = null) {
    const item = menuItems.find((candidate) => String(candidate.id) === String(itemId));
    if (!item || item.is_available === false) return;
    activeItem = item;
    editingCartIndex = (typeof editIndex === "number" && editIndex >= 0 && editIndex < cart.length) ? editIndex : null;

    const existingCartItem = editingCartIndex !== null ? cart[editingCartIndex] : null;
    const initialQty = existingCartItem ? existingCartItem.quantity : 1;
    const initialNote = existingCartItem ? (existingCartItem.note || "") : "";
    const chosenToppingsMap = new Map();

    if (existingCartItem && Array.isArray(existingCartItem.selected_toppings)) {
      existingCartItem.selected_toppings.forEach((top) => {
        chosenToppingsMap.set(String(top.id), Number(top.quantity || 1));
      });
    }

    const itemToppings = getActiveToppingsPool();
    els.dialog.dataset.quantity = String(initialQty);

    const isEdit = editingCartIndex !== null;
    const actionLabel = isEdit ? "Cập nhật món" : "Thêm";
    const resolvedImg = resolveImageUrl(item.image_url);
    const dialogVisualHtml = item.image_url
      ? `
        <img class="dialog-backdrop" src="${escapeHTML(resolvedImg)}" alt="" aria-hidden="true">
        <img class="dialog-img" data-dish-image="dish-emoji" src="${escapeHTML(resolvedImg)}" alt="${escapeHTML(item.name)}">
      `
      : `<span class="dish-emoji" aria-hidden="true">${item.emoji || "🍽️"}</span>`;

    els.dialogContent.innerHTML = `
      <div class="dialog-visual">${dialogVisualHtml}</div>
      <div class="dialog-body">
        <h2>${escapeHTML(item.name)}</h2>
        <p>${escapeHTML(item.description || "Món được chế biến nóng sốt ngay sau khi bếp nhận đơn.")}</p>
        ${itemToppings.length ? `
          <div class="option-heading">CHỌN THÊM TOPPING (CÓ THỂ CHỌN SỐ LƯỢNG)</div>
          <div class="topping-list">
            ${itemToppings.map((top) => {
              const topIdStr = String(top.id);
              const isChecked = chosenToppingsMap.has(topIdStr);
              const topQty = chosenToppingsMap.get(topIdStr) || 1;
              return `
              <div class="topping-option ${isChecked ? "selected" : ""}" data-topping-id="${top.id}">
                <label class="topping-left">
                  <input type="checkbox" value="${top.id}" ${isChecked ? "checked" : ""}>
                  <span class="topping-name">${escapeHTML(top.name)}</span>
                  <span class="topping-price">+${money(top.extra_price ?? top.price)}</span>
                </label>
                <div class="topping-stepper" style="${isChecked ? "" : "display:none;"}">
                  <button type="button" data-top-step="-1" data-top-id="${top.id}" aria-label="Giảm topping">-</button>
                  <span class="topping-qty" data-top-qty-id="${top.id}">${topQty}</span>
                  <button type="button" data-top-step="1" data-top-id="${top.id}" aria-label="Tăng topping">+</button>
                </div>
              </div>
            `;
            }).join("")}
          </div>
        ` : ""}
        <div id="recommendations-box" style="margin-top: 14px;"></div>
        <div class="option-heading">GHI CHÚ CHO BẾP</div>
        <textarea id="item-note" class="note-input" placeholder="Ví dụ: ít cay, nhiều đá, không hành…">${escapeHTML(initialNote)}</textarea>
        <div class="dialog-add-row">
          <div class="quantity-control">
            <button type="button" data-dialog-qty="-1" aria-label="Giảm số lượng">-</button>
            <span id="dialog-quantity">${initialQty}</span>
            <button type="button" data-dialog-qty="1" aria-label="Tăng số lượng">+</button>
          </div>
          <button id="add-configured" class="primary-button" type="button">${actionLabel} · ${money(item.price * initialQty)} <span>${isEdit ? "✓" : "→"}</span></button>
        </div>
      </div>
    `;
    bindImageFallbacks(els.dialogContent);
    els.dialog.showModal();
    updateDialogPrice();

    // TV4 Clickstream view
    if (sessionToken && !isEdit) {
      fetch(`${API_BASE}/api/v1/data/clickstream`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ session_id: sessionToken, menu_item_id: item.id, event_type: "VIEW_ITEM" }),
      }).catch(() => {});
    }

    // TV4 Recommendations
    fetch(`${API_BASE}/api/v1/data/recommendations/${item.id}`)
      .then((res) => res.json())
      .then((data) => {
        const recs = (data && data.recommendations) || [];
        const box = $("#recommendations-box");
        if (!box || !recs.length) return;
        box.innerHTML = `
          <div class="option-heading" style="color: #245b43;">🔥 MÓN HAY ĐƯỢC GỌI KÈM CÙNG</div>
          <div style="display:flex; flex-direction:column; gap:6px;">
            ${recs.map((r) => `
              <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 10px; background:#eef2e8; border-radius:6px; font-size:12px;">
                <span><b>${escapeHTML(r.name)}</b> <small style="color:#5c9b65;">(${r.confidence_pct || 0}% khách gọi kèm)</small></span>
                <button type="button" data-quick-rec="${r.oltp_item_id}" style="border:0; background:#245b43; color:#fff; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:11px; font-weight:700;">
                  + Thêm (${money(r.current_price)})
                </button>
              </div>
            `).join("")}
          </div>
        `;
      })
      .catch(() => {});
  }

  function getSelectedToppingsFromDialog() {
    const pool = getActiveToppingsPool();
    const result = [];
    const rows = els.dialog.querySelectorAll(".topping-option");
    rows.forEach((row) => {
      const checkbox = row.querySelector('input[type="checkbox"]');
      if (checkbox && checkbox.checked) {
        const topId = checkbox.value;
        const found = pool.find((top) => String(top.id) === String(topId));
        if (found) {
          const qtySpan = row.querySelector(`[data-top-qty-id="${topId}"]`);
          const qty = Math.max(1, Number(qtySpan ? qtySpan.textContent : 1));
          const unitPrice = Number(found.extra_price ?? found.price ?? 0);
          result.push({
            id: Number(found.id),
            name: found.name,
            price: unitPrice,
            unit_price: unitPrice,
            quantity: qty,
            subtotal: unitPrice * qty,
          });
        }
      }
    });
    return result;
  }

  function updateDialogPrice() {
    if (!activeItem) return;
    const qty = Number(els.dialog.dataset.quantity || 1);
    const chosen = getSelectedToppingsFromDialog();
    const topTotal = chosen.reduce((sum, top) => sum + (Number(top.unit_price || top.price || 0) * Number(top.quantity || 1)), 0);
    const total = qty * (Number(activeItem.price) + topTotal);

    const isEdit = editingCartIndex !== null;
    const btnLabel = isEdit ? "Cập nhật món" : "Thêm";
    const btn = $("#add-configured");
    if (btn) {
      btn.innerHTML = `${btnLabel} · ${money(total)} <span>${isEdit ? "✓" : "→"}</span>`;
    }
  }

  function cleanCart(rawCart) {
    return (rawCart || []).map((item) => {
      const rawTops = item.selected_toppings || item.toppings || [];
      const selectedToppings = Array.isArray(rawTops)
        ? rawTops.map((top) => {
            const topQty = Math.max(1, Number(top.quantity || 1));
            if (typeof top === "object" && top !== null) {
              const found = toppings.find((t) => String(t.id) === String(top.id));
              const unitP = Number(top.price ?? top.extra_price ?? found?.extra_price ?? 0);
              return {
                id: Number(top.id || found?.id || 0),
                name: String(top.name || found?.name || "Topping"),
                price: unitP,
                unit_price: unitP,
                quantity: topQty,
                subtotal: unitP * topQty,
              };
            }
            const foundByName = toppings.find((t) => String(t.name) === String(top));
            const unitP = Number(foundByName?.extra_price || 0);
            return {
              id: Number(foundByName?.id || 0),
              name: String(top),
              price: unitP,
              unit_price: unitP,
              quantity: topQty,
              subtotal: unitP * topQty,
            };
          })
        : [];
      const note = String(item.note ?? item.notes ?? "");
      const menuItemId = Number(item.menu_item_id || item.id);
      const toppingExtra = selectedToppings.reduce((sum, t) => sum + (Number(t.unit_price || t.price || 0) * Number(t.quantity || 1)), 0);
      const serverBase = menuItems.find((m) => Number(m.id) === menuItemId)?.price;
      const computedPrice = item.unit_price !== undefined && Number(item.unit_price) > Number(serverBase || 0)
        ? Number(item.unit_price)
        : Number(serverBase ?? item.unit_price ?? item.price ?? 0) + toppingExtra;

      const topKeyPart = selectedToppings.map((top) => `${top.id}x${top.quantity}`).sort().join(",");
      return {
        _key: `${menuItemId}-${topKeyPart}-${note.trim()}`,
        menu_item_id: menuItemId,
        name: String(item.name || "Món ăn"),
        quantity: Number(item.quantity) || 1,
        unit_price: computedPrice,
        selected_toppings: selectedToppings,
        note,
      };
    });
  }

  function addToCart(item, quantity, chosenToppings = [], note = "") {
    const toppingTotal = chosenToppings.reduce((sum, top) => sum + (Number(top.unit_price || top.price || 0) * Number(top.quantity || 1)), 0);
    const unitPrice = Number(item.price) + toppingTotal;
    const topKeyPart = chosenToppings.map((top) => `${top.id}x${top.quantity}`).sort().join(",");
    const key = `${item.id}-${topKeyPart}-${note.trim()}`;

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
        note: note.trim(),
      });
    }
    syncCart();
    toast(`Đã thêm ${quantity} × ${item.name} vào giỏ`);
  }

  function updateCartItem(index, item, quantity, chosenToppings = [], note = "") {
    if (index < 0 || index >= cart.length) return;
    const toppingTotal = chosenToppings.reduce((sum, top) => sum + (Number(top.unit_price || top.price || 0) * Number(top.quantity || 1)), 0);
    const unitPrice = Number(item.price) + toppingTotal;
    const topKeyPart = chosenToppings.map((top) => `${top.id}x${top.quantity}`).sort().join(",");
    const key = `${item.id}-${topKeyPart}-${note.trim()}`;

    cart[index] = {
      _key: key,
      menu_item_id: Number(item.id),
      name: item.name,
      quantity,
      unit_price: unitPrice,
      selected_toppings: chosenToppings,
      note: note.trim(),
    };
    syncCart();
    toast(`Đã cập nhật món ${item.name}`);
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
      els.cartItems.innerHTML = '<div class="cart-empty">Giỏ hàng của bạn đang trống.<br>Hãy chọn món ngon từ thực đơn bên dưới.</div>';
      els.panelTotal.textContent = money(0);
      els.cartTotal.textContent = money(0);
      els.cartCount.textContent = "0";
      els.cartBar.hidden = true;
      return;
    }

    const total = cart.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    els.panelTotal.textContent = money(total);
    els.cartTotal.textContent = money(total);
    els.cartCount.textContent = String(count);
    els.cartBar.hidden = false;

    els.cartItems.innerHTML = cart
      .map((item, index) => {
        const topDesc = item.selected_toppings.length
          ? `Topping: ${item.selected_toppings.map((top) => {
              const qtyPart = (top.quantity && Number(top.quantity) > 1) ? ` x${top.quantity}` : "";
              return `${escapeHTML(top.name)}${qtyPart}`;
            }).join(", ")}`
          : "";
        const noteDesc = item.note ? `Ghi chú: ${item.note}` : "";
        const meta = [topDesc, noteDesc].filter(Boolean).join(" · ");
        return `
          <div class="cart-row">
            <div>
              <h4 class="cart-row-title">${escapeHTML(item.name)}</h4>
              ${meta ? `<p class="cart-row-meta">${escapeHTML(meta)}</p>` : ""}
              <div class="cart-row-actions">
                <div class="quantity-control">
                  <button type="button" data-cart-index="${index}" data-delta="-1" aria-label="Giảm">-</button>
                  <span>${item.quantity}</span>
                  <button type="button" data-cart-index="${index}" data-delta="1" aria-label="Tăng">+</button>
                </div>
                <button class="edit-cart-button" type="button" data-edit-index="${index}" title="Sửa topping & ghi chú">Sửa</button>
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

  function promptSubmitOrder() {
    if (!cart.length || submitting) return;
    if (!sessionToken) {
      toast("Không có mã phiên hợp lệ. Bạn đang xem thử thực đơn.");
      return;
    }

    const total = cart.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);

    showConfirm({
      title: "Xác nhận gửi đơn đến bếp?",
      message: `Bạn đang chuẩn bị gửi <b>${count} món</b> (Tạm tính: <b>${money(total)}</b>) xuống bếp để chuẩn bị.<br>Vui lòng kiểm tra kỹ trước khi gửi.`,
      icon: "🍳",
      okText: "Gửi đơn đến bếp",
      cancelText: "Kiểm tra lại",
      onOk: executeSubmitOrder,
    });
  }

  async function executeSubmitOrder() {
    if (!cart.length || submitting) return;
    submitting = true;
    els.submit.disabled = true;
    els.submit.innerHTML = "Đang gửi đến bếp… <span>→</span>";

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
        selected_toppings: item.selected_toppings,
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

      const result = await response.json();
      const wasAlreadyAccepted = response.status === 409 && result.code === "DUPLICATE_ORDER";
      if (!response.ok && !wasAlreadyAccepted) throw new Error(result.error || result.detail || `Không gửi được đơn (HTTP ${response.status})`);

      cart = [];
      pendingIdempotencyKey = null;
      sessionStorage.removeItem(idempotencyStorageKey);
      await syncCart();
      await loadSentOrders();
      closeCart();
      toast(wasAlreadyAccepted ? `Đơn #${result.order_id} đã được nhận trước đó.` : `Đã gửi đơn #${result.order_id} xuống bếp thành công!`);
    } catch (error) {
      toast(error.message || "Không gửi được đơn. Vui lòng thử lại.");
    } finally {
      submitting = false;
      els.submit.disabled = false;
      els.submit.innerHTML = "Gửi đơn đến bếp <span>→</span>";
    }
  }

  async function fetchRemoteCart() {
    if (!sessionToken || isSyncingCart || submitting || (els.dialog && els.dialog.open)) return;
    try {
      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/cart`, {
        headers: { Accept: "application/json" },
      });
      if (!response.ok) return;
      const data = await response.json();
      const nextCart = cleanCart(data.items || []);
      if (JSON.stringify(nextCart) !== JSON.stringify(cart)) {
        cart = nextCart;
        renderCartPanel();
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

  // GỌI NHÂN VIÊN HỖ TRỢ
  function openAssistanceDialog() {
    if (!sessionToken) {
      toast("Vui lòng quét đúng mã QR tại bàn để gọi nhân viên");
      return;
    }
    if (els.assistanceDialog) {
      els.assistanceDialog.showModal();
    }
  }

  async function submitAssistance() {
    if (!sessionToken) return;
    const note = (els.assistanceNote ? els.assistanceNote.value : "").trim();
    const type = selectedAssistanceType || "BILL";

    if (els.sendAssistanceBtn) {
      els.sendAssistanceBtn.disabled = true;
      els.sendAssistanceBtn.textContent = "Đang gửi yêu cầu…";
    }

    try {
      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/assistance`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ type, request_type: type, note }),
      });
      const result = await response.json().catch(() => ({}));
      if (els.assistanceDialog) els.assistanceDialog.close();
      toast(result.message || "Đã gửi yêu cầu đến nhân viên phục vụ!");
      if (els.assistanceNote) els.assistanceNote.value = "";
    } catch (error) {
      toast("Đã gửi yêu cầu gọi nhân viên!");
      if (els.assistanceDialog) els.assistanceDialog.close();
    } finally {
      if (els.sendAssistanceBtn) {
        els.sendAssistanceBtn.disabled = false;
        els.sendAssistanceBtn.innerHTML = "Gửi yêu cầu hỗ trợ <span>→</span>";
      }
    }
  }

  // SỰ KIỆN GIAO DIỆN
  els.categories.addEventListener("click", (event) => {
    const button = event.target.closest("[data-category]");
    if (!button) return;
    selectedCategory = button.dataset.category;
    renderCategories();
    renderMenu();
  });

  // Bấm vào hình / tên / bất kỳ đâu trên thẻ món để mở dialog tùy chỉnh
  function handleCardClick(event) {
    const card = event.target.closest(".dish-card");
    if (!card || card.classList.contains("unavailable")) return;
    const itemId = card.dataset.itemId;
    if (itemId) openItem(itemId);
  }
  els.grid.addEventListener("click", handleCardClick);
  els.featured.addEventListener("click", handleCardClick);

  els.search.addEventListener("input", renderMenu);

  // Sự kiện trong Dialog tùy chỉnh món
  els.dialog.addEventListener("change", (event) => {
    const target = event.target;
    if (target.matches('.topping-option input[type="checkbox"]')) {
      const row = target.closest(".topping-option");
      const stepper = row ? row.querySelector(".topping-stepper") : null;
      if (target.checked) {
        if (row) row.classList.add("selected");
        if (stepper) stepper.style.display = "";
      } else {
        if (row) row.classList.remove("selected");
        if (stepper) {
          stepper.style.display = "none";
          const qtySpan = stepper.querySelector(".topping-qty");
          if (qtySpan) qtySpan.textContent = "1";
        }
      }
      updateDialogPrice();
    }
  });

  els.dialog.addEventListener("click", (event) => {
    const quickRecBtn = event.target.closest("[data-quick-rec]");
    if (quickRecBtn) {
      const recItem = menuItems.find((m) => String(m.id) === String(quickRecBtn.dataset.quickRec));
      if (recItem) {
        addToCart(recItem, 1, [], "");
        quickRecBtn.textContent = "✓ Đã thêm";
        quickRecBtn.style.background = "#469a5a";
        setTimeout(() => {
          quickRecBtn.innerHTML = `+ Thêm (${money(recItem.price)})`;
          quickRecBtn.style.background = "#245b43";
        }, 1600);
      }
      return;
    }

    // Tăng giảm số lượng topping
    const topStepBtn = event.target.closest("[data-top-step]");
    if (topStepBtn) {
      const topId = topStepBtn.dataset.topId;
      const step = Number(topStepBtn.dataset.topStep);
      const qtySpan = els.dialog.querySelector(`[data-top-qty-id="${topId}"]`);
      if (qtySpan) {
        const next = Math.max(1, Math.min(10, Number(qtySpan.textContent || 1) + step));
        qtySpan.textContent = String(next);
        updateDialogPrice();
      }
      return;
    }

    // Tăng giảm số lượng món
    const qtyButton = event.target.closest("[data-dialog-qty]");
    if (qtyButton) {
      const next = Math.max(1, Number(els.dialog.dataset.quantity || 1) + Number(qtyButton.dataset.dialogQty));
      els.dialog.dataset.quantity = String(next);
      const span = $("#dialog-quantity");
      if (span) span.textContent = String(next);
      updateDialogPrice();
      return;
    }

    // Nút Thêm / Cập nhật món
    if (event.target.id === "add-configured" || event.target.closest("#add-configured")) {
      const chosen = getSelectedToppingsFromDialog();
      const note = ($("#item-note") ? $("#item-note").value : "").trim();
      const qty = Number(els.dialog.dataset.quantity || 1);

      if (editingCartIndex !== null) {
        updateCartItem(editingCartIndex, activeItem, qty, chosen, note);
      } else {
        addToCart(activeItem, qty, chosen, note);
      }
      els.dialog.close();
      editingCartIndex = null;
    }
  });

  els.cartBar.addEventListener("click", showCart);
  els.overlay.addEventListener("click", closeCart);
  document.querySelectorAll("[data-close]").forEach((button) => button.addEventListener("click", closeCart));

  // Giỏ hàng: Tăng giảm, Xóa và SỬA món
  els.cartItems.addEventListener("click", (event) => {
    const editButton = event.target.closest("[data-edit-index]");
    if (editButton) {
      const index = Number(editButton.dataset.editIndex);
      const item = cart[index];
      if (item) {
        closeCart();
        openItem(item.menu_item_id, index);
      }
      return;
    }

    const changeButton = event.target.closest("[data-cart-index]");
    const removeButton = event.target.closest("[data-remove]");
    if (removeButton) {
      cart.splice(Number(removeButton.dataset.remove), 1);
    } else if (changeButton) {
      const index = Number(changeButton.dataset.cartIndex);
      cart[index].quantity += Number(changeButton.dataset.delta);
      if (cart[index].quantity <= 0) cart.splice(index, 1);
    } else return;
    syncCart();
  });

  els.submit.addEventListener("click", promptSubmitOrder);

  if (els.ordersToggle) els.ordersToggle.addEventListener("click", openOrdersPanel);
  if (els.closeOrders) els.closeOrders.addEventListener("click", closeOrdersPanel);
  if (els.ordersOverlay) els.ordersOverlay.addEventListener("click", closeOrdersPanel);

  if (els.orderList) {
    els.orderList.addEventListener("click", (event) => {
      const button = event.target.closest("[data-cancel-order-item]");
      if (button) {
        promptCancelSentItem(button.dataset.cancelOrderItem, button.dataset.itemName);
      }
    });
  }

  // Xác nhận Dialog
  if (els.confirmOkBtn) {
    els.confirmOkBtn.addEventListener("click", () => {
      const action = pendingConfirmAction;
      closeConfirm();
      if (typeof action === "function") action();
    });
  }
  if (els.confirmCancelBtn) {
    els.confirmCancelBtn.addEventListener("click", closeConfirm);
  }

  // Gọi nhân viên phục vụ
  if (els.callStaffBtn) {
    els.callStaffBtn.addEventListener("click", openAssistanceDialog);
  }
  if (els.assistancePresets) {
    els.assistancePresets.addEventListener("click", (event) => {
      const btn = event.target.closest(".preset-btn");
      if (!btn) return;
      els.assistancePresets.querySelectorAll(".preset-btn").forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      selectedAssistanceType = btn.dataset.type || "BILL";
    });
  }
  if (els.sendAssistanceBtn) {
    els.sendAssistanceBtn.addEventListener("click", submitAssistance);
  }

  // Khởi động
  updateTableLabel();
  loadSessionInfo();
  loadMenu();
  renderSentOrders();
  loadSentOrders();
  connectOrderUpdates();
  connectSharedCart();

  if (!sessionToken) {
    showNotice("Đây là chế độ xem thử thực đơn. Hãy mở liên kết quét từ mã QR tại bàn để gọi món và đồng bộ giỏ hàng.");
  }
})();
