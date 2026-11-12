// Phần 2: mã riêng của trang khách quét QR (kết nối Clickstream & Gợi ý món của TV4).
(() => {
  "use strict";

  // Load client menu from API (public endpoint)
  const loadClientMenu = async () => {
    try {
      const resp = await fetch(`${API_BASE}/api/v1/client/menu`, {
        headers: { Accept: "application/json" },
      });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();
      categories = data.categories;
      // flatten all items for search
      menuItems = [];
      categories.forEach(c => {
        c.items.forEach(i => menuItems.push(i));
      });
      renderCategories();
      renderMenuItems(); // default "Tất cả"
    } catch (e) {
      console.error("Không tải được thực đơn:", e);
      toast("Không tải được thực đơn, vui lòng thử lại.");
    }
  };

  const params = new URLSearchParams(location.search);
  const sessionToken = params.get("token") || "";
  const tableNumber = params.get("table") || "";
  const API_BASE = (params.get("api") || localStorage.getItem("dineflow_api_base") || "http://localhost:8000").replace(/\/$/, "");
  const FRONTEND_ROOT = new URL("../", document.currentScript.src);

  const $ = (selector) => document.querySelector(selector);
  const els = {
    table: $("#table-label"), notice: $("#notice"), categories: $("#category-list"), grid: $("#menu-grid"), featured: $("#featured-grid"), search: $("#search-input"),
    cartBar: $("#cart-bar"), cartCount: $("#cart-count"), cartTotal: $("#cart-total"), overlay: $("#overlay"), panel: $("#cart-panel"),
    cartItems: $("#cart-items"), panelTotal: $("#panel-total"), submit: $("#submit-order"), dialog: $("#item-dialog"), dialogContent: $("#dialog-content"), toast: $("#toast"),
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
  let socket = null;
  let reconnectTimer = null;
  let toastTimer = null;
  let submitting = false;
  let ordersPanelOpen = false;
  let sessionTotal = null;
  let orderHistoryLoaded = false;
  let cartQuoteTotal = null;
  let cartQuoteError = false;
  let cartQuoteErrorMessage = "";
  let cartQuoteRequestId = 0;
  let dialogQuoteRequestId = 0;
  const orderStorageKey = sessionToken ? `dineflow:orders:${sessionToken}` : "";
  const requestStorageKey = sessionToken ? `dineflow:pending-order:${sessionToken}` : "";
  let sentOrders = readSentOrders();
  let pendingRequest = readPendingRequest();

  function readPendingRequest() {
    if (!requestStorageKey) return null;
    try {
      return JSON.parse(sessionStorage.getItem(requestStorageKey) || "null");
    } catch {
      return null;
    }
  }

  function savePendingRequest(request) {
    pendingRequest = request;
    if (!requestStorageKey) return;
    if (request) sessionStorage.setItem(requestStorageKey, JSON.stringify(request));
    else sessionStorage.removeItem(requestStorageKey);
  }

  function createRequestKey() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    const randomPart = Math.random().toString(36).slice(2);
    return `order-${Date.now()}-${randomPart}`;
  }

  function readSentOrders() {
    if (!orderStorageKey) return [];
    try { return JSON.parse(sessionStorage.getItem(orderStorageKey) || "[]"); }
    catch { return []; }
  }
  function saveSentOrders() {
    if (!orderStorageKey) return;
    try { sessionStorage.setItem(orderStorageKey, JSON.stringify(sentOrders.slice(-30))); }
    catch { console.info("Không thể lưu lịch sử đơn trong phiên trình duyệt."); }
  }
  const statusLabels = { PENDING: "Đang chờ bếp", COOKING: "Đang nấu", COMPLETED: "Hoàn thành", CANCELLED: "Đã hủy" };
  function renderSentOrders() {
    if (!els.orders || !els.orderList) return;
    const itemCount = sentOrders.reduce(
      (count, order) => count + order.items.length,
      0,
    );

    els.orders.classList.toggle("open", ordersPanelOpen && sentOrders.length > 0);
    els.orders.setAttribute("aria-hidden", String(!ordersPanelOpen));
    els.ordersOverlay.hidden = !ordersPanelOpen || sentOrders.length === 0;
    els.ordersToggle.hidden = sentOrders.length === 0 || ordersPanelOpen;
    els.ordersToggle.setAttribute("aria-expanded", String(ordersPanelOpen));
    els.ordersCount.textContent = itemCount;
    els.ordersTotal.textContent = orderHistoryLoaded && sessionTotal !== null
      ? money(sessionTotal)
      : "Chưa lấy được tổng tiền từ máy chủ";
    els.orderList.innerHTML = [...sentOrders].reverse().map((order) => `
      <article class="sent-order-card">
        <header>
          <strong>Đơn #${escapeHTML(order.id)}</strong>
          <time>${escapeHTML(order.time)}</time>
        </header>
        ${order.items.map((item, index) => `
          <div class="sent-order-item">
            <div>
              <strong>${escapeHTML(item.name)}</strong>
              <small>
                ${item.quantity} phần
                ${orderHistoryLoaded && Number.isFinite(Number(item.unit_price)) && Number.isFinite(Number(item.line_total))
                  ? ` · ${money(item.unit_price)} / phần · Thành tiền ${money(item.line_total)}`
                  : ""}
                ${item.note ? ` · ${escapeHTML(item.note)}` : ""}
              </small>
              ${item.selected_toppings?.length ? `<small>Topping: ${escapeHTML(item.selected_toppings.map((topping) => topping.name).join(", "))}</small>` : ""}
            </div>
            <div class="sent-order-actions">
              <span class="order-status" data-status="${escapeHTML(item.status || "PENDING")}">
                ${escapeHTML(statusLabels[item.status] || statusLabels.PENDING)}
              </span>
              ${item.status === "PENDING" ? `<button type="button" class="cancel-order-button" data-cancel-order-item="${Number(order.itemIds[index])}">Hủy món</button>` : ""}
            </div>
          </div>`).join("")}
      </article>`).join("");
  }
  function updateSentItemStatus(itemId, status) {
    let changed = false;
    for (const order of sentOrders) {
      const index = order.itemIds.indexOf(Number(itemId));
      if (index >= 0 && order.items[index]) {
        order.items[index].status = status;
        changed = true;
        if (status === "COMPLETED") {
          toast(`${order.items[index].name} đã nấu xong`);
        }
      }
    }
    if (changed) {
      saveSentOrders();
      renderSentOrders();
      if (status !== "COMPLETED") {
        toast(`Trạng thái món: ${statusLabels[status] || status}`);
      }
    }
  }

  function openOrdersPanel() {
    ordersPanelOpen = true;
    renderSentOrders();
  }

  function closeOrdersPanel() {
    ordersPanelOpen = false;
    renderSentOrders();
  }

  async function loadOrderHistory() {
    if (!sessionToken) return;
    try {
      const response = await fetch(
        `${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/orders`,
        { headers: { Accept: "application/json" } },
      );
      if (!response.ok) {
        orderHistoryLoaded = false;
        sessionTotal = null;
        renderSentOrders();
        return;
      }
      const data = await response.json();
      const returnedTotal = Number(data.total_amount);
      sessionTotal = Number.isFinite(returnedTotal) ? returnedTotal : null;
      sentOrders = (data.orders || []).map((order) => ({
        id: order.order_id,
        time: order.created_at ? new Date(order.created_at).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" }) : "",
        itemIds: (order.items || []).map((item) => Number(item.order_item_id)),
        items: (order.items || []).map((item) => ({ ...item, name: item.name, status: item.status })),
      }));
      orderHistoryLoaded = true;
      saveSentOrders();
      renderSentOrders();
    } catch (error) {
      orderHistoryLoaded = false;
      sessionTotal = null;
      renderSentOrders();
      console.info("Chưa tải được lịch sử đơn:", error.message);
    }
  }

  async function cancelSentItem(orderItemId) {
    try {
      const response = await fetch(
        `${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/order-items/${orderItemId}/cancel`,
        { method: "POST", headers: { Accept: "application/json" } },
      );
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.detail || "Không thể hủy món");
      updateSentItemStatus(orderItemId, "CANCELLED");
      await loadOrderHistory();
    } catch (error) {
      toast(error.message || "Không thể hủy món lúc này");
    }
  }

  const money = (amount) => new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND", maximumFractionDigits: 0 }).format(Number(amount) || 0);
  const escapeHTML = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);
  function resolveImageUrl(source) {
    const raw = String(source || "").trim();
    if (!raw) return "";
    // Local computer paths are not reachable by customers. Images must be
    // provided as URLs by the menu API (or as paths served by this backend).
    if (/^(file:\/\/|[a-z]:[\\/]|\\\\)/i.test(raw)) return "";
    if (/^(https?:|data:image\/|blob:)/i.test(raw)) {
      const url = new URL(raw, FRONTEND_ROOT);
      if (["localhost", "127.0.0.1"].includes(url.hostname) && !["localhost", "127.0.0.1"].includes(location.hostname)) url.hostname = location.hostname;
      return url.href;
    }
    return new URL(raw.replace(/\\/g, "/").replace(/^\.\//, "").replace(/^\/+/, ""), FRONTEND_ROOT).href;
  }
  const imageOrEmoji = (item, className = "dish-emoji") => {
    const imageUrl = resolveImageUrl(item.image_url);
    return imageUrl
      ? `<img data-dish-image="${escapeHTML(className)}" src="${escapeHTML(imageUrl)}" alt="${escapeHTML(item.name)}" loading="lazy">`
      : `<span class="${className}" aria-hidden="true">🍽️</span>`;
  };

  function handleDishImageError(image) {
    const fallback = document.createElement("span");
    fallback.className = image.dataset.dishImage || "dish-emoji";
    fallback.setAttribute("aria-hidden", "true");
    fallback.textContent = "🍽️";
    image.replaceWith(fallback);
  }

  function bindImageFallbacks(root) {
    root.querySelectorAll("img[data-dish-image]").forEach((image) => image.addEventListener(
      "error",
      () => handleDishImageError(image),
      { once: true },
    ));
  }

  function showNotice(message) {
    els.notice.textContent = message;
    els.notice.hidden = false;
  }
  function toast(message) {
    els.toast.textContent = message;
    els.toast.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => els.toast.classList.remove("show"), 2400);
  }
  function updateTableLabel() {
    const label = tableNumber ? `Bàn ${tableNumber}` : "Menu DineFlow";
    els.table.textContent = sessionToken ? label : `${label} · Xem thử`;
  }

  async function loadMenu() {
    try {
      const response = await fetch(`${API_BASE}/api/v1/admin/menu-items`, { headers: { Accept: "application/json" } });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      categories = (data.categories || []).map((category) => ({ ...category, items: category.items || [] }));
      menuItems = categories.flatMap((category) => category.items.map((item) => ({ ...item, category: category.name })));
      toppings = data.available_toppings || [];
      if (!menuItems.length) showNotice("Menu từ máy chủ hiện chưa có món. Hãy chạy dữ liệu mẫu hoặc kiểm tra danh mục món.");
    } catch (error) {
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
    const featured = menuItems.filter((item) => item.is_hot && item.is_available).slice(0, 3);
    els.featured.innerHTML = featured
      .map((item) => `
        <article class="dish-card" data-item-id="${item.id}">
          <div class="dish-image">${imageOrEmoji(item, "dish-emoji")}<span class="badge">Nổi bật</span></div>
          <div class="dish-info">
            <p class="dish-category">${escapeHTML(item.category || "Món ngon")}</p>
            <h3 class="dish-title">${escapeHTML(item.name)}</h3>
            <p class="dish-description">${escapeHTML(item.description || "Hương vị hấp dẫn, chế biến tươi mới mỗi ngày.")}</p>
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
          <article class="dish-card ${available ? "" : "unavailable"}" data-item-id="${item.id}">
            <div class="dish-image">
              ${imageOrEmoji(item, "dish-emoji")}
              ${available ? (item.is_hot ? '<span class="badge">Bán chạy</span>' : "") : '<span class="badge sold">Tạm hết</span>'}
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

  function openItem(itemId) {
    const item = menuItems.find((candidate) => String(candidate.id) === String(itemId));
    if (!item || item.is_available === false) return;
    activeItem = item;
    els.dialog.dataset.quantity = "1";
    els.dialogContent.innerHTML = `
      <div class="dialog-visual">${imageOrEmoji(item, "dish-emoji")}</div>
      <div class="dialog-body">
        <h2>${escapeHTML(item.name)}</h2>
        <p>${escapeHTML(item.description || "Món được chế biến nóng sốt ngay sau khi bếp nhận đơn.")}</p>
        ${toppings.length ? `
          <div class="option-heading">CHỌN THÊM TOPPING</div>
          <div class="topping-list">
            ${toppings.map((top) => `
              <label class="topping-option">
                <input type="checkbox" value="${top.id}">
                <span>${escapeHTML(top.name)}</span>
                <strong>+${money(top.extra_price)}</strong>
              </label>
            `).join("")}
          </div>
        ` : ""}
        <div id="tv4-recommendations-box" style="margin-top: 14px;"></div>
        <div class="option-heading">GHI CHÚ CHO BẾP</div>
        <textarea id="item-note" class="note-input" placeholder="Ví dụ: ít cay, nhiều đá, không hành…"></textarea>
        <div class="dialog-add-row">
          <div class="quantity-control">
            <button type="button" data-dialog-qty="-1" aria-label="Giảm số lượng">-</button>
            <span id="dialog-quantity">1</span>
            <button type="button" data-dialog-qty="1" aria-label="Tăng số lượng">+</button>
          </div>
          <button id="add-configured" class="primary-button" type="button" disabled>Đang lấy giá từ máy chủ…</button>
        </div>
      </div>
    `;
    bindImageFallbacks(els.dialogContent);
    els.dialog.showModal();
    updateDialogPrice();

    // [TV4] Ghi nhận sự kiện khách xem món (Clickstream VIEW_ITEM)
    if (sessionToken) {
      fetch(`${API_BASE}/api/v1/data/clickstream`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          session_id: sessionToken,
          menu_item_id: item.id,
          event_type: "VIEW_ITEM"
        })
      }).catch(() => {});
    }

    // [TV4] Lấy gợi ý món hay mua kèm (Apriori Recommendations) từ đơn thực tế
    fetch(`${API_BASE}/api/v1/data/recommendations/${item.id}`)
      .then((res) => res.json())
      .then((data) => {
        const recs = (data && data.recommendations) || [];
        const box = $("#tv4-recommendations-box");
        if (!box || !recs.length) return;
        box.innerHTML = `
          <div class="option-heading" style="color: #245b43;">🔥 MÓN HAY ĐƯỢC GỌI KÈM CÙNG</div>
          <div style="display:flex; flex-direction:column; gap:6px;">
            ${recs.map((r) => `
              <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 10px; background:#eef2e8; border-radius:4px; font-size:12px;">
                <span><b>${escapeHTML(r.name)}</b> <small style="color:#5c9b65;">(${r.confidence_pct || 0}% khách gọi kèm)</small></span>
                <button type="button" data-quick-rec="${r.oltp_item_id}" style="border:0; background:#245b43; color:#fff; padding:4px 10px; border-radius:3px; cursor:pointer; font-size:11px; font-weight:700;">
                  + Thêm (${money(r.current_price)})
                </button>
              </div>
            `).join("")}
          </div>
        `;
      })
      .catch(() => {});
  }

  function cartPayload(items) {
    return items.map((item) => ({
      menu_item_id: item.menu_item_id,
      quantity: item.quantity,
      selected_toppings: item.selected_toppings.map((topping) => ({ id: topping.id })),
      note: item.note || null,
    }));
  }

  function cartSignature(items) {
    return JSON.stringify(cartPayload(items));
  }

  async function requestPriceQuote(items) {
    const response = await fetch(
      `${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/price-cart`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(cartPayload(items)),
      },
    );
    const result = await response.json();
    if (!response.ok) throw new Error(result.detail || `Không lấy được giá (HTTP ${response.status})`);
    return result;
  }

  function getPriceErrorMessage(error) {
    const message = error?.message || "";
    if (/hết hạn/i.test(message)) return "Mã QR hết hạn — nhờ nhân viên cấp mã mới";
    if (/không còn phục vụ/i.test(message)) return "Món hoặc topping đã hết hàng — vui lòng chỉnh giỏ";
    return message || "Không thể kết nối máy chủ để lấy giá";
  }

  async function updateDialogPrice() {
    if (!activeItem) return;
    const requestId = ++dialogQuoteRequestId;
    const item = activeItem;
    const payload = [{
      menu_item_id: Number(item.id),
      quantity: Number(els.dialog.dataset.quantity || 1),
      selected_toppings: [...els.dialog.querySelectorAll(".topping-option input:checked")]
        .map((input) => ({ id: Number(input.value) })),
      note: null,
    }];
    const button = $("#add-configured");
    button.disabled = true;
    button.textContent = "Đang lấy giá từ máy chủ…";
    try {
      const quote = await requestPriceQuote(payload);
      if (requestId !== dialogQuoteRequestId || activeItem !== item) return;
      const pricedItem = quote.items?.[0];
      if (!pricedItem || Number(pricedItem.menu_item_id) !== Number(item.id)
        || !Number.isFinite(Number(pricedItem.line_total))) {
        throw new Error("Máy chủ không trả về giá hợp lệ");
      }
      button.innerHTML = `Thêm · ${money(pricedItem.line_total)} <span>→</span>`;
      button.disabled = false;
    } catch (error) {
      if (requestId !== dialogQuoteRequestId || activeItem !== item) return;
      button.textContent = getPriceErrorMessage(error);
      toast(getPriceErrorMessage(error));
    }
  }

  function cleanCart(rawCart) {
    return (rawCart || []).map((item) => ({
      menu_item_id: Number(item.menu_item_id),
      quantity: Number(item.quantity) || 1,
      selected_toppings: (item.selected_toppings || []).map((top) => ({ id: Number(top.id) })),
      note: item.note || "",
      _key: item._key || `${item.menu_item_id}-${(item.selected_toppings || []).map((top) => top.id).sort().join(",")}-${String(item.note || "").trim()}`,
    }));
  }

  function addToCart(item, quantity, chosenToppings = [], note = "") {
    const selectedToppings = chosenToppings.map((top) => ({ id: Number(top.id) }));
    const key = `${item.id}-${selectedToppings.map((top) => top.id).sort().join(",")}-${note.trim()}`;
    const existing = cart.find((entry) => entry._key === key);
    if (existing) {
      existing.quantity += quantity;
    } else {
      cart.push({
        _key: key,
        menu_item_id: Number(item.id),
        quantity,
        selected_toppings: selectedToppings,
        note: note.trim(),
      });
    }
    syncCart();
    toast(`Đã thêm ${quantity} × ${item.name} vào giỏ`);
  }

  function showCart() {
    if (cart.length && cartQuoteTotal === null) refreshCartQuote();
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
      els.cartItems.innerHTML = '<div class="cart-empty">Giỏ hàng của bạn đang trống.<br>Hãy chọn món từ thực đơn bên dưới.</div>';
      els.panelTotal.textContent = money(0);
      els.cartTotal.textContent = money(0);
      els.cartCount.textContent = "0";
      els.cartBar.hidden = true;
      els.submit.disabled = submitting;
      return;
    }

    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    const unavailablePrice = cartQuoteError
      ? cartQuoteErrorMessage
      : "Đang lấy giá từ máy chủ…";
    els.panelTotal.textContent = cartQuoteTotal === null ? unavailablePrice : money(cartQuoteTotal);
    els.cartTotal.textContent = cartQuoteTotal === null ? "—" : money(cartQuoteTotal);
    els.cartCount.textContent = count;
    els.cartBar.hidden = false;
    els.submit.disabled = submitting || cartQuoteTotal === null;

    els.cartItems.innerHTML = cart
      .map((item, index) => {
        const topDesc = item.selected_toppings.length ? `Topping: ${item.selected_toppings.map((top) => top.name || `#${top.id}`).join(", ")}` : "";
        const noteDesc = item.note ? `Ghi chú: ${item.note}` : "";
        const meta = [topDesc, noteDesc].filter(Boolean).join(" · ");
        return `
          <div class="cart-row">
            <div>
              <h4 class="cart-row-title">${escapeHTML(item.name || `Món #${item.menu_item_id}`)}</h4>
              ${meta ? `<p class="cart-row-meta">${escapeHTML(meta)}</p>` : ""}
              <div class="quantity-control">
                <button type="button" data-cart-index="${index}" data-delta="-1" aria-label="Giảm">-</button>
                <span>${item.quantity}</span>
                <button type="button" data-cart-index="${index}" data-delta="1" aria-label="Tăng">+</button>
                <button class="remove-button" type="button" data-remove="${index}">Xóa</button>
              </div>
            </div>
            <strong class="cart-row-price">${Number.isFinite(Number(item.line_total)) && cartQuoteTotal !== null ? money(item.line_total) : "—"}</strong>
          </div>
        `;
      })
      .join("");
  }

  function syncCart() {
    cartQuoteTotal = cart.length ? null : 0;
    cartQuoteError = false;
    cartQuoteErrorMessage = "";
    renderCartPanel();
    if (socket && socket.readyState === WebSocket.OPEN) {
      socket.send(JSON.stringify({ event: "UPDATE_CART", cart: cartPayload(cart) }));
    }
    refreshCartQuote();
  }

  async function refreshCartQuote() {
    const requestId = ++cartQuoteRequestId;
    if (!cart.length) {
      cartQuoteTotal = 0;
      cartQuoteError = false;
      cartQuoteErrorMessage = "";
      renderCartPanel();
      return;
    }
    if (!sessionToken) {
      cartQuoteTotal = null;
      renderCartPanel();
      return;
    }

    const signature = cartSignature(cart);
    cartQuoteTotal = null;
    cartQuoteError = false;
    cartQuoteErrorMessage = "";
    renderCartPanel();
    try {
      const quote = await requestPriceQuote(cart);
      if (requestId !== cartQuoteRequestId) return;
      if (signature !== cartSignature(cart)) {
        refreshCartQuote();
        return;
      }
      if (!Array.isArray(quote.items) || quote.items.length !== cart.length) {
        throw new Error("Báo giá từ máy chủ không khớp với giỏ hàng");
      }
      if (quote.items.some((item, index) =>
        Number(item.menu_item_id) !== Number(cart[index].menu_item_id)
        || !Number.isFinite(Number(item.unit_price))
        || !Number.isFinite(Number(item.line_total)))) {
        throw new Error("Máy chủ trả về chi tiết giá không hợp lệ");
      }
      cart = cart.map((entry, index) => ({
        ...entry,
        ...quote.items[index],
        _key: entry._key,
      }));
      cartQuoteTotal = quote.total_amount;
      if (typeof cartQuoteTotal !== "number" || !Number.isFinite(cartQuoteTotal)) {
        cartQuoteTotal = null;
        throw new Error("Máy chủ trả về tổng tiền không hợp lệ");
      }
      renderCartPanel();
    } catch (error) {
      if (requestId !== cartQuoteRequestId) return;
      cartQuoteError = true;
      cartQuoteErrorMessage = getPriceErrorMessage(error);
      renderCartPanel();
      toast(cartQuoteErrorMessage);
    }
  }

  async function submitOrder() {
    if (!cart.length || submitting) return;
    if (!sessionToken) {
      toast("Không có mã phiên hợp lệ. Bạn đang xem thử thực đơn.");
      return;
    }
    if (cartQuoteTotal === null) {
      toast("Cần lấy được báo giá từ máy chủ trước khi gửi đơn.");
      return;
    }
    submitting = true;
    els.submit.disabled = true;
    els.submit.innerHTML = 'Đang gửi đến bếp… <span>→</span>';

    try {
      const submittedCart = cart.map((item) => ({ ...item }));
      const payload = cartPayload(submittedCart);

      const signature = JSON.stringify(payload);
      if (!pendingRequest || pendingRequest.signature !== signature) {
        savePendingRequest({ signature, key: createRequestKey() });
      }

      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/submit-order`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "Idempotency-Key": pendingRequest.key,
        },
        body: JSON.stringify(payload),
      });

      const result = await response.json();
      if (!response.ok) throw new Error(result.detail || `Không gửi được đơn (HTTP ${response.status})`);
      savePendingRequest(null);
      sentOrders.push({
        id: result.order_id,
        time: new Date().toLocaleTimeString("vi-VN", {
          hour: "2-digit",
          minute: "2-digit",
        }),
        itemIds: (result.items || []).map((item) => Number(item.order_item_id)),
        items: result.items || [],
      });
      orderHistoryLoaded = false;
      sessionTotal = Number.isFinite(Number(result.session_total)) ? Number(result.session_total) : null;
      saveSentOrders();
      renderSentOrders();
      await loadOrderHistory();
      cart = [];
      syncCart();
      closeCart();
      toast(`Đã gửi đơn #${result.order_id} xuống bếp thành công!`);
    } catch (error) {
      toast(error.message || "Không gửi được đơn. Vui lòng thử lại.");
    } finally {
      submitting = false;
      els.submit.innerHTML = 'Gửi đơn đến bếp <span>→</span>';
      renderCartPanel();
    }
  }

  function connectSharedCart() {
    if (!sessionToken || !("WebSocket" in window)) return;
    const wsBase = API_BASE.replace(/^http:/, "ws:").replace(/^https:/, "wss:");
    try {
      socket = new WebSocket(`${wsBase}/api/v1/client/ws/table/${encodeURIComponent(sessionToken)}`);
      socket.addEventListener("message", (event) => {
        try {
          const message = JSON.parse(event.data);
          if (message.event === "CART_SYNC") {
            cart = cleanCart(message.cart);
            cartQuoteTotal = null;
            refreshCartQuote();
          }
          if (message.event === "CART_ERROR") toast(message.detail || "Giỏ hàng không hợp lệ");
          if (message.event === "ORDER_SUBMITTED") {
            cart = [];
            cartQuoteTotal = 0;
            renderCartPanel();
            toast("Đơn của bàn đã được gửi đến bếp");
          }
          if (message.event === "ITEM_STATUS_UPDATED") updateSentItemStatus(message.order_item_id, message.new_status);
        } catch (error) { console.info("Bỏ qua tin nhắn WebSocket không hợp lệ", error); }
      });
      socket.addEventListener("close", () => { clearTimeout(reconnectTimer); reconnectTimer = setTimeout(connectSharedCart, 3000); });
      socket.addEventListener("error", () => socket.close());
    } catch (error) { console.info("Không thể khởi tạo WebSocket", error); }
  }
  els.categories.addEventListener("click", (event) => {
    const button = event.target.closest("[data-category]");
    if (!button) return;
    selectedCategory = button.dataset.category;
    renderCategories();
    renderMenu();
  });
  els.grid.addEventListener("click", (event) => {
    const button = event.target.closest("[data-add]");
    if (button) openItem(button.dataset.add);
  });
  els.featured.addEventListener("click", (event) => {
    const button = event.target.closest("[data-add]");
    if (button) openItem(button.dataset.add);
  });
  els.search.addEventListener("input", renderMenu);
  els.dialog.addEventListener("change", (event) => {
    if (event.target.matches(".topping-option input")) updateDialogPrice();
  });
  els.dialog.addEventListener("click", (event) => {
    const quickRecBtn = event.target.closest("[data-quick-rec]");
    if (quickRecBtn) {
      const recItem = menuItems.find((m) => String(m.id) === String(quickRecBtn.dataset.quickRec));
      if (recItem) addToCart(recItem, 1, [], "");
      return;
    }
    const qtyButton = event.target.closest("[data-dialog-qty]");
    if (qtyButton) {
      const next = Math.max(1, Number(els.dialog.dataset.quantity || 1) + Number(qtyButton.dataset.dialogQty));
      els.dialog.dataset.quantity = next;
      $("#dialog-quantity").textContent = next;
      updateDialogPrice();
      return;
    }
    if (event.target.closest("#add-configured")) {
      const chosen = [...els.dialog.querySelectorAll(".topping-option input:checked")]
        .map((input) => ({ id: Number(input.value) }));
      addToCart(activeItem, Number(els.dialog.dataset.quantity || 1), chosen, $("#item-note").value);
      els.dialog.close();
    }
  });
  els.cartBar.addEventListener("click", showCart);
  els.overlay.addEventListener("click", closeCart);
  document.querySelectorAll("[data-close]").forEach((button) => button.addEventListener("click", closeCart));
  els.cartItems.addEventListener("click", (event) => {
    const changeButton = event.target.closest("[data-cart-index]");
    const removeButton = event.target.closest("[data-remove]");
    if (removeButton) cart.splice(Number(removeButton.dataset.remove), 1);
    else if (changeButton) {
      const index = Number(changeButton.dataset.cartIndex);
      cart[index].quantity += Number(changeButton.dataset.delta);
      if (cart[index].quantity <= 0) cart.splice(index, 1);
    } else return;
    syncCart();
  });
  els.submit.addEventListener("click", submitOrder);
  els.ordersToggle.addEventListener("click", openOrdersPanel);
  els.closeOrders.addEventListener("click", closeOrdersPanel);
  els.ordersOverlay.addEventListener("click", closeOrdersPanel);
  els.orderList?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-cancel-order-item]");
    if (button) cancelSentItem(button.dataset.cancelOrderItem);
  });

  updateTableLabel();
  renderSentOrders();
  loadOrderHistory();
  loadMenu();
  connectSharedCart();
  if (!sessionToken) showNotice("Đây là chế độ xem thử. Hãy mở liên kết QR có token phiên hợp lệ để đồng bộ giỏ hàng và gửi đơn.");
})();
