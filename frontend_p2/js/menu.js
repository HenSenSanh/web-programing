// Phần 2: mã riêng của trang khách quét QR.
(() => {
  "use strict";

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
      showNotice("Chưa kết nối được menu từ máy chủ. Kiểm tra backend và tải lại trang để xem thực đơn hiện tại.");
      console.info("DineFlow menu API chưa truy cập được:", error.message);
    }
    renderCategories();
    renderMenu();
    renderFeatured();
  }

  function renderFeatured() {
    const featured = menuItems.filter((item) => item.is_hot && item.is_available !== false).slice(0, 4);
    els.featured.closest(".featured-section").hidden = featured.length === 0;
    els.featured.innerHTML = featured.map(dishCard).join("");
  }

  function dishCard(item) {
    const soldOut = item.is_available === false;
    const badge = soldOut ? '<span class="badge sold">Hết món</span>' : item.is_hot ? '<span class="badge">Được yêu thích</span>' : "";
    const label = soldOut ? "Món đã hết" : `Chọn ${escapeHTML(item.name)}`;
    return `<article class="dish-card"><div class="dish-image">${imageOrEmoji(item)}${badge}</div><div class="dish-info"><p class="dish-category">${escapeHTML(item.category || "Món ngon")}</p><h3 class="dish-title">${escapeHTML(item.name)}</h3><p class="dish-description">${escapeHTML(item.description || "Món ngon được chuẩn bị tươi mới tại quán.")}</p><div class="dish-footer"><span class="dish-price">${money(item.price)}</span><button class="add-button" type="button" data-add="${item.id}" ${soldOut ? "disabled" : ""} aria-label="${label}">${soldOut ? "–" : "+"}</button></div></div></article>`;
  }

  function renderCategories() {
    const names = ["Tất cả", ...categories.map((category) => category.name)];
    els.categories.innerHTML = names.map((name) => `<button class="category-button${name === selectedCategory ? " active" : ""}" type="button" data-category="${escapeHTML(name)}">${escapeHTML(name)}</button>`).join("");
  }

  function renderMenu() {
    const query = els.search.value.trim().toLocaleLowerCase("vi");
    const filtered = menuItems.filter((item) => (selectedCategory === "Tất cả" || item.category === selectedCategory)
      && (!query || `${item.name} ${item.description || ""} ${item.category || ""}`.toLocaleLowerCase("vi").includes(query)));
    if (!filtered.length) {
      els.grid.innerHTML = `<div class="empty-state">${menuItems.length ? "Không tìm thấy món phù hợp." : "Chưa có món ăn để hiển thị."}</div>`;
      return;
    }
    els.grid.innerHTML = filtered.map((item) => `<article class="dish-card">
      <div class="dish-image">${imageOrEmoji(item)}${item.is_available === false ? '<span class="badge sold">Hết món</span>' : item.is_hot ? '<span class="badge">Được yêu thích</span>' : ""}</div>
      <div class="dish-info"><p class="dish-category">${escapeHTML(item.category || "Món ngon")}</p><h3 class="dish-title">${escapeHTML(item.name)}</h3><p class="dish-description">${escapeHTML(item.description || "Món ngon được chuẩn bị tươi mới tại quán.")}</p><div class="dish-footer"><span class="dish-price">${money(item.price)}</span><button class="add-button" type="button" data-add="${item.id}" ${item.is_available === false ? "disabled" : ""} aria-label="${item.is_available === false ? "Món đã hết" : `Chọn ${escapeHTML(item.name)}`}">${item.is_available === false ? "–" : "+"}</button></div></div>
    </article>`).join("");
  }

  function openItem(itemId) {
    activeItem = menuItems.find((item) => String(item.id) === String(itemId));
    if (!activeItem || activeItem.is_available === false) return;
    els.dialogContent.innerHTML = `<div class="dialog-visual">${imageOrEmoji(activeItem, "dish-emoji")}</div><div class="dialog-body"><p class="eyebrow">${escapeHTML(activeItem.category || "DINEFLOW MENU")}</p><h2>${escapeHTML(activeItem.name)}</h2><p>${escapeHTML(activeItem.description || "Món ngon được chuẩn bị tươi mới tại quán.")}</p><div class="option-heading">Tùy chọn thêm</div>${toppings.length ? toppings.map((topping) => `<label class="topping-option"><input type="checkbox" value="${topping.id}"><span>${escapeHTML(topping.name)}</span><strong>+${money(topping.extra_price)}</strong></label>`).join("") : '<p class="dish-description">Hiện chưa có tùy chọn thêm.</p>'}<div class="option-heading">Ghi chú cho bếp</div><textarea id="item-note" class="note-input" maxlength="255" placeholder="Ví dụ: ít cay, không hành…"></textarea><div class="dialog-add-row"><div class="quantity-control"><button type="button" data-dialog-qty="-1" aria-label="Giảm số lượng">−</button><span id="dialog-quantity">1</span><button type="button" data-dialog-qty="1" aria-label="Tăng số lượng">+</button></div><button id="add-configured" class="primary-button" type="button">Thêm · ${money(activeItem.price)} <span>→</span></button></div></div>`;
    els.dialog.showModal();
    els.dialog.dataset.quantity = "1";
    updateDialogPrice();
  }

  function updateDialogPrice() {
    if (!activeItem || !$("#add-configured")) return;
    const toppingTotal = [...els.dialog.querySelectorAll(".topping-option input:checked")].reduce((sum, input) => {
      const topping = toppings.find((item) => String(item.id) === input.value);
      return sum + Number(topping?.extra_price || 0);
    }, 0);
    const quantity = Number(els.dialog.dataset.quantity || 1);
    $("#add-configured").textContent = `Thêm · ${money((Number(activeItem.price) + toppingTotal) * quantity)} →`;
  }

  function cleanCart(items) {
    return (Array.isArray(items) ? items : []).filter((item) => item && item.menu_item_id && Number(item.quantity) > 0).map((item) => ({
      menu_item_id: Number(item.menu_item_id), name: String(item.name || "Món ăn"), quantity: Number(item.quantity), unit_price: Number(item.unit_price),
      selected_toppings: Array.isArray(item.selected_toppings) ? item.selected_toppings : [], note: item.note || null,
    }));
  }
  function syncCart() {
    cart = cleanCart(cart);
    const count = cart.reduce((sum, item) => sum + item.quantity, 0);
    const total = cart.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    els.cartBar.hidden = count === 0;
    els.cartCount.textContent = count;
    els.cartTotal.textContent = money(total);
    els.panelTotal.textContent = money(total);
    renderCartPanel();
    if (socket && socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ event: "UPDATE_CART", cart }));
  }
  function renderCartPanel() {
    if (!cart.length) {
      els.cartItems.innerHTML = '<div class="cart-empty">Giỏ hàng đang trống.<br>Chọn món ngon để bắt đầu nhé!</div>';
      return;
    }
    els.cartItems.innerHTML = cart.map((item, index) => `<div class="cart-row"><div><h3 class="cart-row-title">${escapeHTML(item.name)}</h3><p class="cart-row-meta">${item.selected_toppings.length ? escapeHTML(item.selected_toppings.map((top) => top.name).join(", ")) : ""}${item.note ? `${item.selected_toppings.length ? " · " : ""}Ghi chú: ${escapeHTML(item.note)}` : ""}</p><div class="quantity-control"><button type="button" data-cart-index="${index}" data-delta="-1" aria-label="Giảm">−</button><span>${item.quantity}</span><button type="button" data-cart-index="${index}" data-delta="1" aria-label="Tăng">+</button><button type="button" class="remove-button" data-remove="${index}">Xóa</button></div></div><span class="cart-row-price">${money(item.quantity * item.unit_price)}</span></div>`).join("");
  }
  function addToCart(item, quantity = 1, selectedToppings = [], note = "") {
    const toppingSum = selectedToppings.reduce((sum, topping) => sum + Number(topping.price || 0), 0);
    const unitPrice = Number(item.price) + toppingSum;
    const signature = `${item.id}|${selectedToppings.map((top) => top.id).sort().join(",")}|${note.trim()}`;
    const existing = cart.find((line) => line.signature === signature);
    if (existing) existing.quantity += quantity;
    else cart.push({ menu_item_id: Number(item.id), name: item.name, quantity, unit_price: unitPrice, selected_toppings: selectedToppings, note: note.trim() || null, signature });
    syncCart();
    toast(`Đã thêm ${item.name} vào giỏ`);
  }

  function showCart() {
    els.overlay.hidden = false;
    els.panel.classList.add("open");
    els.panel.setAttribute("aria-hidden", "false");
    document.body.classList.add("panel-open");
  }
  function closeCart() {
    els.panel.classList.remove("open");
    els.panel.setAttribute("aria-hidden", "true");
    els.overlay.hidden = true;
    document.body.classList.remove("panel-open");
  }

  async function submitOrder() {
    if (!cart.length || submitting) return;
    if (!sessionToken) { toast("Hãy mở menu bằng mã QR của bàn để gửi đơn."); return; }
    submitting = true;
    els.submit.disabled = true;
    els.submit.innerHTML = "Đang gửi đơn…";
    try {
      const payload = cart.map(({ signature, ...line }) => line);
      const response = await fetch(`${API_BASE}/api/v1/client/session/${encodeURIComponent(sessionToken)}/submit-order`, {
        method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(payload),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.detail || `Không gửi được đơn (HTTP ${response.status})`);
      cart = [];
      syncCart();
      closeCart();
      toast(`Đã gửi đơn #${result.order_id} xuống bếp`);
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
