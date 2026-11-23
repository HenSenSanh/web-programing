const API_BASE = (location.protocol.startsWith('http') ? '' : 'http://localhost:8080') + '/api/v1';
const KDS_CAN_UPDATE = window.KDS_CAN_UPDATE !== false;
let allKdsItems = [];
let currentFilter = 'ALL';
let currentKdsMode = 'ORDERS';
let stockFilter = 'ALL';
let kdsStockData = { items: [], toppings: [], unavailable_items_count: 0, unavailable_toppings_count: 0 };
let kdsToastTimer = null;

function showKdsToast(message, isError = false) {
  const el = document.getElementById('kds-toast');
  if (!el) return;
  el.textContent = message;
  el.className = `position-fixed bottom-0 end-0 m-3 px-3 py-2 rounded-3 text-white fw-semibold small shadow ${isError ? 'bg-danger' : 'bg-success'}`;
  clearTimeout(kdsToastTimer);
  kdsToastTimer = setTimeout(() => el.classList.add('d-none'), 3000);
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
}

// 1. Hàm đếm số phút chờ (Đã xử lý lệch múi giờ UTC+7)
function getWaitTimeInMinutes(createdAtStr) {
  if (!createdAtStr) return 0;

  // Bổ sung ký tự 'Z' nếu chuỗi Backend chưa có mốc ISO UTC chuẩn
  let dateStr = createdAtStr;
  if (typeof dateStr === 'string' && !dateStr.endsWith('Z') && !dateStr.includes('+')) {
    dateStr += 'Z';
  }

  const created = new Date(dateStr);
  const now = new Date();
  let diffMs = now - created;

  let minutes = Math.floor(diffMs / (1000 * 60));

  // Tự động triệt tiêu 7 tiếng (420 phút) nếu bị lệch múi giờ UTC+7 của Việt Nam
  if (minutes >= 400) {
    minutes = Math.max(0, minutes - 420);
  }

  return Math.max(0, minutes);
}

// 2. Tải danh sách món từ API Backend
async function fetchKdsItems() {
  try {
    const res = await fetch(`${API_BASE}/staff/kds/active-items`);
    if (!res.ok) throw new Error("Không thể tải danh sách món");
    allKdsItems = await res.json();
    renderKdsUI();
  } catch (err) {
    console.error("Lỗi KDS:", err);
  }
}

// 3. Chuyển đổi bộ lọc (ALL / PENDING / COOKING)
function setFilter(filter) {
  currentFilter = filter;
  
  // Cập nhật trạng thái active cho nút bấm
  document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
  if (filter === 'ALL') document.getElementById('filter-all')?.classList.add('active');
  if (filter === 'PENDING') document.getElementById('filter-pending')?.classList.add('active');
  if (filter === 'COOKING') document.getElementById('filter-cooking')?.classList.add('active');

  renderKdsUI();
}

// 4. Render danh sách thẻ món ăn ra màn hình
function renderKdsUI() {
  const container = document.getElementById('kds-container');
  if (!container) return;

  // Lọc danh sách món theo bộ lọc hiện tại
  const filteredItems = allKdsItems.filter(item => {
    if (currentFilter === 'ALL') return true;
    return item.status === currentFilter;
  });

  // Hiển thị giao diện trống nếu không có món nào
  if (filteredItems.length === 0) {
    container.innerHTML = `
      <div class="col-12 text-center text-muted py-5">
        <i class="bi bi-check2-circle fs-1 text-success"></i>
        <h5 class="mt-2">Không có món ăn nào trong danh mục này.</h5>
      </div>
    `;
    return;
  }

  // Render danh sách thẻ món
  container.innerHTML = filteredItems.map(item => {
    const itemName = item.item_name || item.name || item.menu_item_name || 'Món ăn';
    const itemId = item.order_item_id || item.id;
    const tableNo = item.table_number || item.table_no || item.table_name || (item.session_id ? `Bàn ${item.session_id}` : 'Bàn 01');
    const quantity = item.quantity || 1;
    const note = item.notes || item.note || 'Không có';
    const toppingDetails = item.topping_details_recorded
      ? (Array.isArray(item.toppings) && item.toppings.length
        ? item.toppings.map(topping => {
            const qty = Math.max(1, Number(topping.quantity || 1));
            return qty > 1 ? `${escapeHtml(topping.name)} x${qty}` : escapeHtml(topping.name);
          }).join(', ')
        : 'Không chọn topping')
      : 'Đơn cũ chưa lưu chi tiết topping';
    const isPending = item.status === 'PENDING';

    // Đếm thời gian chờ SLA và màu cảnh báo
    const waitMinutes = getWaitTimeInMinutes(item.created_at);
    let slaBadgeClass = 'bg-success';
    if (waitMinutes >= 10 && waitMinutes < 15) slaBadgeClass = 'bg-warning text-dark';
    if (waitMinutes >= 15) slaBadgeClass = 'bg-danger';

    const cardBorder = isPending ? (waitMinutes >= 15 ? 'border-danger border-2' : 'border-warning') : 'border-primary';
    const thumbHtml = item.image_url
      ? `<img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(itemName)}" style="width:52px;height:52px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;flex-shrink:0;" onerror="this.style.display='none'">`
      : '';

    return `
      <div class="col">
        <div class="card h-100 shadow-sm ${cardBorder}">
          <div class="card-header d-flex justify-content-between align-items-center bg-dark text-white">
            <span class="fw-bold text-warning fs-5"><i class="bi bi-geo-alt-fill"></i> ${escapeHtml(tableNo)}</span>
            <span class="badge ${slaBadgeClass}">
              <i class="bi bi-clock-history"></i> ${waitMinutes} phút
            </span>
          </div>
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
              <div class="d-flex align-items-center gap-2 min-w-0">
                ${thumbHtml}
                <h5 class="card-title fw-bold text-dark mb-0">${escapeHtml(itemName)}</h5>
              </div>
              <span class="badge bg-danger fs-6 flex-shrink-0">x${quantity}</span>
            </div>
            <p class="card-text text-secondary mb-0">
              <strong>Ghi chú:</strong> <span class="text-danger fw-semibold">${escapeHtml(note)}</span>
            </p>
            <p class="card-text text-secondary mt-2 mb-0">
              <strong>Topping:</strong> <span class="fw-semibold">${toppingDetails}</span>
            </p>
          </div>
          <div class="card-footer bg-white border-0 pb-3">
            ${isPending ? `
              <button class="btn btn-warning w-100 fw-bold shadow-sm" onclick="updateItemStatus('${itemId}', 'COOKING')">
                <i class="bi bi-play-fill"></i> Bắt đầu nấu
              </button>
            ` : ''}
            ${item.status === 'COOKING' ? `
              <button class="btn btn-success w-100 fw-bold shadow-sm" onclick="updateItemStatus('${itemId}', 'COMPLETED')">
                <i class="bi bi-check-lg"></i> Nấu xong
              </button>
            ` : ''}
          </div>
        </div>
      </div>
    `;
  }).join('');
}

// 5. Cập nhật trạng thái món lên Backend (Đã khớp đúng Schema: new_status và chef_id)
async function updateItemStatus(orderItemId, status) {
  try {
    const parsedId = !isNaN(orderItemId) ? parseInt(orderItemId, 10) : orderItemId;

    const res = await fetch(`${API_BASE}/staff/kds/item-status`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ 
        order_item_id: parsedId, 
        chef_id: 1,
        status: status,
        new_status: status
      })
    });

    if (res.ok) {
      fetchKdsItems(); // Tải lại danh sách sau khi cập nhật thành công
    } else {
      const errData = await res.json();
      let errorMsg = errData.error || 'Lỗi không xác định';
      if (Array.isArray(errData.detail)) {
        errorMsg = errData.detail.map(e => `${e.loc ? e.loc.join(' -> ') : ''}: ${e.msg}`).join('\n');
      } else if (typeof errData.detail === 'string') {
        errorMsg = errData.detail;
      }

      alert(`⚠️ Không thể cập nhật:\n${errorMsg}`);
    }
  } catch (err) {
    console.error("Lỗi cập nhật trạng thái:", err);
  }
}

// 6. Chuyển chế độ Đơn đang nấu / Báo hết món & Topping
function setKdsMode(mode) {
  currentKdsMode = mode === 'STOCK' ? 'STOCK' : 'ORDERS';
  const ordersSection = document.getElementById('kds-orders-section');
  const stockSection = document.getElementById('kds-stock-section');
  const ordersFilters = document.getElementById('kds-orders-filters');
  const btnOrders = document.getElementById('kds-mode-orders');
  const btnStock = document.getElementById('kds-mode-stock');

  if (ordersSection) ordersSection.classList.toggle('d-none', currentKdsMode !== 'ORDERS');
  if (stockSection) stockSection.classList.toggle('d-none', currentKdsMode !== 'STOCK');
  if (ordersFilters) ordersFilters.classList.toggle('d-none', currentKdsMode !== 'ORDERS');

  if (btnOrders) {
    btnOrders.classList.toggle('btn-dark', currentKdsMode === 'ORDERS');
    btnOrders.classList.toggle('btn-outline-dark', currentKdsMode !== 'ORDERS');
  }
  if (btnStock) {
    btnStock.classList.toggle('btn-danger', currentKdsMode === 'STOCK');
    btnStock.classList.toggle('btn-outline-danger', currentKdsMode !== 'STOCK');
  }

  if (currentKdsMode === 'STOCK') {
    fetchKdsStockStatus();
  }
}

function setStockFilter(nextFilter) {
  stockFilter = nextFilter === 'OUT' ? 'OUT' : 'ALL';
  const btnAll = document.getElementById('stock-filter-all');
  const btnOut = document.getElementById('stock-filter-out');
  if (btnAll) {
    btnAll.classList.toggle('btn-dark', stockFilter === 'ALL');
    btnAll.classList.toggle('btn-outline-dark', stockFilter !== 'ALL');
  }
  if (btnOut) {
    btnOut.classList.toggle('btn-danger', stockFilter === 'OUT');
    btnOut.classList.toggle('btn-outline-danger', stockFilter !== 'OUT');
  }
  renderKdsStockUI();
}

async function fetchKdsStockStatus() {
  try {
    const res = await fetch(`${API_BASE}/staff/kds/stock-status`, { headers: { Accept: 'application/json' } });
    if (!res.ok) return;
    const data = await res.json();
    kdsStockData = {
      items: Array.isArray(data.items) ? data.items : [],
      toppings: Array.isArray(data.toppings) ? data.toppings : [],
      unavailable_items_count: Number(data.unavailable_items_count || 0),
      unavailable_toppings_count: Number(data.unavailable_toppings_count || 0),
    };
    const totalOut = kdsStockData.unavailable_items_count + kdsStockData.unavailable_toppings_count;
    const badge = document.getElementById('kds-out-of-stock-badge');
    if (badge) {
      badge.textContent = String(totalOut);
      badge.classList.toggle('d-none', totalOut === 0);
    }
    if (currentKdsMode === 'STOCK') {
      renderKdsStockUI();
    }
  } catch (err) {
    console.error('Lỗi tải kho bếp KDS:', err);
  }
}

function renderKdsStockUI() {
  const query = String(document.getElementById('kds-stock-search')?.value || '').trim().toLowerCase();
  const topContainer = document.getElementById('kds-stock-toppings');
  const dishContainer = document.getElementById('kds-stock-dishes');
  const topSummary = document.getElementById('kds-toppings-summary');
  const dishSummary = document.getElementById('kds-dishes-summary');

  const filteredTops = kdsStockData.toppings.filter((top) => {
    const matchOut = stockFilter === 'ALL' || !top.is_available;
    const matchQ = !query || String(top.name || '').toLowerCase().includes(query);
    return matchOut && matchQ;
  });

  const filteredDishes = kdsStockData.items.filter((dish) => {
    const matchOut = stockFilter === 'ALL' || !dish.is_available;
    const matchQ = !query
      || String(dish.name || '').toLowerCase().includes(query)
      || String(dish.category_name || '').toLowerCase().includes(query);
    return matchOut && matchQ;
  });

  if (topSummary) {
    topSummary.textContent = `${kdsStockData.unavailable_toppings_count} hết / ${kdsStockData.toppings.length} topping`;
    topSummary.className = kdsStockData.unavailable_toppings_count > 0 ? 'badge bg-danger' : 'badge bg-success';
  }
  if (dishSummary) {
    dishSummary.textContent = `${kdsStockData.unavailable_items_count} hết / ${kdsStockData.items.length} món`;
    dishSummary.className = kdsStockData.unavailable_items_count > 0 ? 'badge bg-danger' : 'badge bg-success';
  }

  if (topContainer) {
    topContainer.innerHTML = filteredTops.length
      ? filteredTops.map((top) => {
          const avail = Boolean(top.is_available);
          const actionHtml = KDS_CAN_UPDATE
            ? `<button type="button"
                       class="btn btn-sm w-100 fw-semibold ${avail ? 'btn-outline-danger' : 'btn-success'}"
                       onclick="toggleKdsToppingAvailability(${Number(top.id)}, ${avail ? 'false' : 'true'}, this)">
                   ${avail ? '<i class="bi bi-x-circle"></i> Báo hết' : '<i class="bi bi-check-circle"></i> Mở lại'}
               </button>`
            : `<span class="badge ${avail ? 'bg-success' : 'bg-danger'}">${avail ? 'Còn hàng' : 'Hết hàng'}</span>`;
          return `
            <div class="col">
              <div class="card h-100 shadow-sm ${avail ? 'border-light' : 'border-danger bg-danger-subtle bg-opacity-10'}">
                <div class="card-body p-3 d-flex flex-column justify-content-between gap-2">
                  <div>
                    <div class="d-flex justify-content-between align-items-start gap-1">
                      <strong class="small text-dark">${escapeHtml(top.name)}</strong>
                      <span class="badge ${avail ? 'bg-success-subtle text-success' : 'bg-danger text-white'}">${avail ? 'Còn' : 'Hết'}</span>
                    </div>
                    <div class="text-muted" style="font-size: 11px;">+${Number(top.price || 0).toLocaleString('vi-VN')} đ</div>
                  </div>
                  ${actionHtml}
                </div>
              </div>
            </div>
          `;
        }).join('')
      : '<div class="col-12 text-muted small py-2">Không có topping phù hợp.</div>';
  }

  if (dishContainer) {
    dishContainer.innerHTML = filteredDishes.length
      ? filteredDishes.map((dish) => {
          const avail = Boolean(dish.is_available);
          const thumb = dish.image_url
            ? `<img src="${escapeHtml(dish.image_url)}" alt="${escapeHtml(dish.name)}" style="width:46px;height:46px;object-fit:cover;border-radius:8px;flex-shrink:0;" onerror="this.style.display='none'">`
            : '';
          const actionHtml = KDS_CAN_UPDATE
            ? `<button type="button"
                       class="btn btn-sm fw-semibold flex-shrink-0 ${avail ? 'btn-outline-danger' : 'btn-success'}"
                       onclick="toggleKdsDishAvailability(${Number(dish.id)}, ${avail ? 'false' : 'true'}, this)">
                   ${avail ? '<i class="bi bi-slash-circle"></i> Báo hết món' : '<i class="bi bi-check2-circle"></i> Mở bán lại'}
               </button>`
            : `<span class="badge ${avail ? 'bg-success' : 'bg-danger'}">${avail ? 'Đang bán' : 'Hết món'}</span>`;
          return `
            <div class="col">
              <div class="card h-100 shadow-sm ${avail ? 'border-light' : 'border-danger border-2'}">
                <div class="card-body p-3 d-flex align-items-center justify-content-between gap-2">
                  <div class="d-flex align-items-center gap-2 min-w-0">
                    ${thumb}
                    <div class="min-w-0">
                      <div class="fw-bold text-dark small text-truncate" title="${escapeHtml(dish.name)}">${escapeHtml(dish.name)}</div>
                      <div class="text-muted" style="font-size: 11px;">
                        ${escapeHtml(dish.category_name)} ·
                        <span class="fw-semibold ${avail ? 'text-success' : 'text-danger'}">${avail ? 'Đang bán' : 'Tạm hết'}</span>
                      </div>
                    </div>
                  </div>
                  ${actionHtml}
                </div>
              </div>
            </div>
          `;
        }).join('')
      : '<div class="col-12 text-muted small py-3 text-center">Không có món ăn phù hợp.</div>';
  }
}

async function toggleKdsDishAvailability(itemId, nextAvailable, btn) {
  if (!KDS_CAN_UPDATE) return;
  if (btn) btn.disabled = true;
  try {
    const res = await fetch(`${API_BASE}/staff/kds/menu-items/${encodeURIComponent(itemId)}/availability`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ is_available: Boolean(nextAvailable) }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    await fetchKdsStockStatus();
    showKdsToast(nextAvailable ? 'Đã mở bán lại món trên thực đơn khách.' : 'Đã báo HẾT MÓN — thực đơn khách sẽ tự khóa món này.');
  } catch (err) {
    showKdsToast(`Lỗi cập nhật món: ${err.message}`, true);
    if (btn) btn.disabled = false;
  }
}

async function toggleKdsToppingAvailability(toppingId, nextAvailable, btn) {
  if (!KDS_CAN_UPDATE) return;
  if (btn) btn.disabled = true;
  try {
    const res = await fetch(`${API_BASE}/staff/kds/toppings/${encodeURIComponent(toppingId)}/availability`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ is_available: Boolean(nextAvailable) }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    await fetchKdsStockStatus();
    showKdsToast(nextAvailable ? 'Đã mở lại topping.' : 'Đã báo HẾT TOPPING — thực đơn khách sẽ tự ẩn topping này.');
  } catch (err) {
    showKdsToast(`Lỗi cập nhật topping: ${err.message}`, true);
    if (btn) btn.disabled = false;
  }
}

// Tự động làm mới dữ liệu mỗi 5 giây (đơn) và 15 giây (kho bếp)
setInterval(fetchKdsItems, 5000);
setInterval(fetchKdsStockStatus, 15000);

// Chạy ngay khi trang được tải xong hoặc khi chuyển tab mượt trong Portal
document.addEventListener('DOMContentLoaded', () => {
  fetchKdsItems();
  fetchKdsStockStatus();
});
window.addEventListener('message', (event) => {
  if (event.origin === window.location.origin && event.data?.type === 'dineflow:tab-activated') {
    fetchKdsItems();
    fetchKdsStockStatus();
  }
});
