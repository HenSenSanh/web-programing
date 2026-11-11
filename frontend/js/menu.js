// Phần 2: mã riêng của trang khách quét QR (kết nối Clickstream & Gợi ý món của TV4).
(() => {
  "use strict";

  const params = new URLSearchParams(location.search);
  const sessionToken = params.get("token") || "";
  const tableNumber = params.get("table") || "";
  const API_BASE = (params.get("api") || localStorage.getItem("dineflow_api_base") || (location.protocol.startsWith("http") ? location.origin : "http://localhost:8000")).replace(/\/$/, "");
  const FRONTEND_ROOT = new URL("../", document.currentScript.src);

  const $ = (selector) => document.querySelector(selector);
  const els = {
    table: $("#table-label"), notice: $("#notice"), categories: $("#category-list"), grid: $("#menu-grid"), featured: $("#featured-grid"), search: $("#search-input"),
    cartBar: $("#cart-bar"), cartCount: $("#cart-count"), cartTotal: $("#cart-total"), overlay: $("#overlay"), panel: $("#cart-panel"),
    cartItems: $("#cart-items"), panelTotal: $("#panel-total"), submit: $("#submit-order"), dialog: $("#item-dialog"), dialogContent: $("#dialog-content"), toast: $("#toast"),
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

  const money = (amount) => new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND", maximumFractionDigits: 0 }).format(Number(amount) || 0);
  const escapeHTML = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);
  function resolveImageUrl(source) {
    const raw = String(source || "").trim();
    if (!raw) return "";
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
          <button id="add-configured" class="primary-button" type="button">Thêm · ${money(item.price)} <span>→</span></button>
        </div>
      </div>
    `;
    bindImageFallbacks(els.dialogContent);
    els.dialog.showModal();

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

  function updateDialogPrice() {
    if (!activeItem) return;
    const qty = Number(els.dialog.dataset.quantity || 1);
    const chosen = [...els.dialog.querySelectorAll(".topping-option input:checked")];
    const topTotal = chosen.reduce((sum, input) => {
      const found = toppings.find((top) => String(top.id) === input.value);
      return sum + (found ? Number(found.extra_price) : 0);
    }, 0);
    const total = qty * (Number(activeItem.price) + topTotal);
    $("#add-configured").innerHTML = `Thêm · ${money(total)} <span>→</span>`;
  }

  function cleanCart(rawCart) {
    return (rawCart || []).map((item) => ({
      menu_item_id: Number(item.menu_item_id),
      name: String(item.name || "Món ăn"),
      quantity: Number(item.quantity) || 1,
      unit_price: Number(item.unit_price) || 0,
      selected_toppings: (item.selected_toppings || []).map((top) => ({ id: Number(top.id), name: String(top.name), price: Number(top.price || 0) })),
      note: item.note || "",
    }));
  }

  function addToCart(item, quantity, chosenToppings = [], note = "") {
    const toppingTotal = chosenToppings.reduce((sum, top) => sum + Number(top.price), 0);
    const unitPrice = Number(item.price) + toppingTotal;
    const key = `${item.id}-${chosenToppings.map((top) => top.id).sort().join(",")}-${note.trim()}`;
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
      els.cartItems.innerHTML = '<div class="cart-empty">Giỏ hàng của bạn đang trống.<br>Hãy chọn món từ thực đơn bên dưới.</div>';
      els.panelTotal.textContent = money(0);
      els.cartBar.hidden = true;
      return;
    }

    const total = cart.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    els.panelTotal.textContent = money(total);
    els.cartTotal.textContent = money(total);
    els.cartCount.textContent = count;
    els.cartBar.hidden = false;

    els.cartItems.innerHTML = cart
      .map((item, index) => {
        const topDesc = item.selected_toppings.length ? `Topping: ${item.selected_toppings.map((top) => top.name).join(", ")}` : "";
        const noteDesc = item.note ? `Ghi chú: ${item.note}` : "";
        const meta = [topDesc, noteDesc].filter(Boolean).join(" · ");
        return `
          <div class="cart-row">
            <div>
              <h4 class="cart-row-title">${escapeHTML(item.name)}</h4>
              ${meta ? `<p class="cart-row-meta">${escapeHTML(meta)}</p>` : ""}
              <div class="quantity-control">
                <button type="button" data-cart-index="${index}" data-delta="-1" aria-label="Giảm">-</button>
                <span>${item.quantity}</span>
                <button type="button" data-cart-index="${index}" data-delta="1" aria-label="Tăng">+</button>
                <button class="remove-button" type="button" data-remove="${index}">Xóa</button>
              </div>
            </div>
            <strong class="cart-row-price">${money(item.quantity * item.unit_price)}</strong>
          </div>
        `;
      })
      .join("");
  }

  function syncCart() {
    renderCartPanel();
    if (socket && socket.readyState === WebSocket.OPEN) {
      socket.send(JSON.stringify({ event: "UPDATE_CART", cart }));
    }
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
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(payload),
      });

      const result = await response.json();
      if (!response.ok) throw new Error(result.detail || `Không gửi được đơn (HTTP ${response.status})`);
      cart = [];
      syncCart();
      closeCart();
      toast(`Đã gửi đơn #${result.order_id} xuống bếp thành công!`);
    } catch (error) {
      toast(error.message || "Không gửi được đơn. Vui lòng thử lại.");
    } finally {
      submitting = false;
      els.submit.disabled = false;
      els.submit.innerHTML = 'Gửi đơn đến bếp <span>→</span>';
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
          if (message.event === "CART_SYNC") { cart = cleanCart(message.cart); renderCartPanel(); updateCartTotalsOnly(); }
          if (message.event === "ORDER_SUBMITTED") {
            cart = [];
            renderCartPanel();
            updateCartTotalsOnly();
            toast("Đơn của bàn đã được gửi đến bếp");
          }
          if (message.event === "ITEM_STATUS_UPDATED") toast(`Trạng thái món đã cập nhật: ${message.new_status}`);
        } catch (error) { console.info("Bỏ qua tin nhắn WebSocket không hợp lệ", error); }
      });
      socket.addEventListener("close", () => { clearTimeout(reconnectTimer); reconnectTimer = setTimeout(connectSharedCart, 3000); });
      socket.addEventListener("error", () => socket.close());
    } catch (error) { console.info("Không thể khởi tạo WebSocket", error); }
  }
  function updateCartTotalsOnly() {
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    const total = cart.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    els.cartBar.hidden = count === 0;
    els.cartCount.textContent = count;
    els.cartTotal.textContent = money(total);
    els.panelTotal.textContent = money(total);
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
  els.dialog.addEventListener("click", (event) => {
    if (event.target.closest("[data-dialog-qty]")) queueMicrotask(updateDialogPrice);
  });
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
      $("#add-configured").innerHTML = `Thêm · ${money(next * Number(activeItem.price))} <span>→</span>`;
      return;
    }
    if (event.target.id === "add-configured") {
      const chosen = [...els.dialog.querySelectorAll(".topping-option input:checked")].map((input) => toppings.find((top) => String(top.id) === input.value)).filter(Boolean).map((top) => ({ id: Number(top.id), name: top.name, price: Number(top.extra_price) }));
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

  updateTableLabel();
  loadMenu();
  connectSharedCart();
  if (!sessionToken) showNotice("Đây là chế độ xem thử. Hãy mở liên kết QR có token phiên hợp lệ để đồng bộ giỏ hàng và gửi đơn.");
})();
