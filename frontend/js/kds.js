const API_BASE = 'http://localhost:8000/api/v1';
let allKdsItems = [];
let currentFilter = 'ALL';

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
    const note = item.note || 'Không có';
    const isPending = item.status === 'PENDING';

    // Đếm thời gian chờ SLA và màu cảnh báo
    const waitMinutes = getWaitTimeInMinutes(item.created_at);
    let slaBadgeClass = 'bg-success';
    if (waitMinutes >= 10 && waitMinutes < 15) slaBadgeClass = 'bg-warning text-dark';
    if (waitMinutes >= 15) slaBadgeClass = 'bg-danger';

    const cardBorder = isPending ? (waitMinutes >= 15 ? 'border-danger border-2' : 'border-warning') : 'border-primary';

    return `
      <div class="col">
        <div class="card h-100 shadow-sm ${cardBorder}">
          <div class="card-header d-flex justify-content-between align-items-center bg-dark text-white">
            <span class="fw-bold text-warning fs-5"><i class="bi bi-geo-alt-fill"></i> ${tableNo}</span>
            <span class="badge ${slaBadgeClass}">
              <i class="bi bi-clock-history"></i> ${waitMinutes} phút
            </span>
          </div>
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h5 class="card-title fw-bold text-dark mb-0">${itemName}</h5>
              <span class="badge bg-danger fs-6">x${quantity}</span>
            </div>
            <p class="card-text text-secondary mb-0">
              <strong>Ghi chú:</strong> <span class="text-danger fw-semibold">${note}</span>
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
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ 
        order_item_id: parsedId, 
        chef_id: 1,             // Mặc định chef_id = 1 (do TV1/TV2 chưa xong phân quyền)
        new_status: status       // Đổi tên trường thành new_status theo yêu cầu Backend
      })
    });

    if (res.ok) {
      fetchKdsItems(); // Tải lại danh sách sau khi cập nhật thành công
    } else {
      const errData = await res.json();
      
      let errorMsg = 'Lỗi không xác định';
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

// Tự động làm mới dữ liệu mỗi 5 giây
setInterval(fetchKdsItems, 5000);

// Chạy ngay khi trang được tải xong
document.addEventListener('DOMContentLoaded', fetchKdsItems);