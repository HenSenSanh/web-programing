const API_BASE = 'http://localhost:8000/api/v1';

// 1. Lấy danh sách món ăn đang chờ/đang nấu từ Bếp
async function fetchKdsItems() {
  try {
    const res = await fetch(`${API_BASE}/staff/kds/active-items`);
    const data = await res.json();
    renderKdsUI(data);
  } catch (err) {
    console.error("Lỗi tải danh sách Bếp:", err);
  }
}

// 2. Hiển thị danh sách món lên giao diện HTML
function renderKdsUI(items) {
  const container = document.getElementById('kds-container');
  if (!container) return;
  if (!items || items.length === 0) {
    container.innerHTML = '<p class="text-muted">Chưa có món nào cần chế biến.</p>';
    return;
  }
  
  container.innerHTML = items.map(item => {
    // Tự động nhận diện trường tên món và ID từ Backend
    const itemName = item.item_name || item.name || item.menu_item_name || 'Món ăn';
    const itemId = item.order_item_id || item.id;

    return `
      <div class="col">
        <div class="card h-100 shadow-sm border-${item.status === 'PENDING' ? 'warning' : 'info'}">
          <div class="card-body">
            <h5 class="card-title">${itemName} <span class="badge bg-secondary">x${item.quantity || 1}</span></h5>
            <p class="card-text mb-1"><strong>Ghi chú:</strong> ${item.note || 'Không'}</p>
            <p class="card-text mb-3"><small class="text-muted">Trạng thái: <strong>${item.status}</strong></small></p>
            ${item.status === 'PENDING' ? 
              `<button class="btn btn-warning btn-sm w-100" onclick="updateItemStatus('${itemId}', 'COOKING')">Bắt đầu nấu</button>` : ''}
            ${item.status === 'COOKING' ? 
              `<button class="btn btn-success btn-sm w-100" onclick="updateItemStatus('${itemId}', 'COMPLETED')">Nấu xong</button>` : ''}
          </div>
        </div>
      </div>
    `;
  }).join('');
}

// 3. Đổi trạng thái món (PENDING -> COOKING -> COMPLETED)
async function updateItemStatus(orderItemId, status) {
  try {
    const res = await fetch(`${API_BASE}/staff/kds/item-status`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        order_item_id: orderItemId,
        status: status
      })
    });
    if (res.ok) {
      fetchKdsItems();
    }
  } catch (err) {
    console.error("Lỗi cập nhật trạng thái món:", err);
  }
}

// Tự động làm mới dữ liệu mỗi 5 giây
setInterval(fetchKdsItems, 5000);
document.addEventListener('DOMContentLoaded', fetchKdsItems);