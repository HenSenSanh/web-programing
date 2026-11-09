const API_BASE = 'http://localhost:8000/api/v1';

let activeSessionsMap = {}; 
let currentSelectedSessionId = null;
let currentTotalAmount = 0;
let checkoutModalInstance = null;
let successToastInstance = null;

// Tập hợp lưu danh sách các order_item_id đã thanh toán thành công
const paidItemIds = new Set();

// 1. Tải và đồng bộ danh sách Bàn từ Backend
async function fetchActiveSessions() {
  const container = document.getElementById('sessions-list-container');
  const countBadge = document.getElementById('active-session-count');

  try {
    const resKds = await fetch(`${API_BASE}/staff/kds/active-items`);
    if (!resKds.ok) throw new Error("Không thể kết nối Server");

    const items = await resKds.json();
    activeSessionsMap = {}; 
    
    if (Array.isArray(items) && items.length > 0) {
      items.forEach((item, index) => {
        // 1. Chờ bếp bấm "Bắt đầu nấu" (bỏ qua PENDING)
        const status = (item.status || item.item_status || '').toUpperCase();
        if (status === 'PENDING') return;

        // 2. Bỏ qua món ĐÃ THANH TOÁN (dù bếp chưa bấm "Nấu xong")
        const itemId = item.order_item_id || item.id || index;
        if (paidItemIds.has(itemId)) return;

        // Bóc tách ID phiên bàn (UUID) hoặc Token QR từ Backend
        const realSessId = item.session_id || item.session_uuid || (item.session && (item.session.id || item.session.uuid));
        const qrToken = item.qr_token || item.token || (item.session && item.session.token) || '';
        
        const tableNum = item.table_number || item.table_no || item.table_name || (item.session && item.session.table_number) || '01';
        const displayTable = `Bàn ${tableNum.toString().padStart(2, '0')}`;
        const sessionKey = realSessId || (qrToken ? qrToken : `session_table_${tableNum}`);

        if (!activeSessionsMap[sessionKey]) {
          activeSessionsMap[sessionKey] = {
            sessionId: realSessId || sessionKey,
            qrToken: qrToken,
            tableNumber: displayTable,
            items: []
          };
        } else {
          if (realSessId) activeSessionsMap[sessionKey].sessionId = realSessId;
          if (qrToken) activeSessionsMap[sessionKey].qrToken = qrToken;
          activeSessionsMap[sessionKey].tableNumber = displayTable;
        }

        // Gom món ăn vào hóa đơn
        if (item.item_name || item.name || item.menu_item_name) {
          const existingItems = activeSessionsMap[sessionKey].items;
          const exists = existingItems.some(i => (i.order_item_id || i.id) === itemId && itemId !== undefined);

          if (!exists) {
            activeSessionsMap[sessionKey].items.push(item);
          }
        }
      });
    }
  } catch (err) {
    console.error("Lỗi POS fetchActiveSessions:", err);
  }

  renderSessionsGrid(container, countBadge);
}

// 2. Hiển thị danh sách Thẻ Bàn ra cột bên trái
function renderSessionsGrid(container, countBadge) {
  if (!container) return;

  const sessionKeys = Object.keys(activeSessionsMap);
  if (countBadge) countBadge.innerText = sessionKeys.length;

  if (sessionKeys.length === 0) {
    container.innerHTML = `
      <div class="text-center text-muted py-4">
        <i class="bi bi-inbox fs-2"></i>
        <p class="mt-1 mb-0">Hiện không có bàn nào đang hoạt động.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="row row-cols-2 g-2">
      ${sessionKeys.map(key => {
        const sessData = activeSessionsMap[key];
        const itemCount = sessData ? sessData.items.length : 0;
        const isSelected = key === currentSelectedSessionId;
        const btnClass = isSelected ? 'btn-primary' : 'btn-outline-primary';

        return `
          <div class="col">
            <button class="btn ${btnClass} w-100 py-3 text-start shadow-sm position-relative" 
                    onclick="selectSession('${key}')">
              <div class="fw-bold fs-6"><i class="bi bi-geo-alt-fill"></i> ${sessData.tableNumber}</div>
              <small class="d-block text-truncate text-muted" style="max-width: 130px;">ID: ${sessData.sessionId}</small>
              <span class="position-absolute top-0 end-0 translate-middle badge rounded-pill bg-danger">
                ${itemCount} món
              </span>
            </button>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

// 3. Chọn Bàn để xem Chi Tiết Hóa Đơn
function selectSession(sessionKey) {
  currentSelectedSessionId = sessionKey;
  const sessData = activeSessionsMap[sessionKey];

  if (!sessData) return;

  renderSessionsGrid(document.getElementById('sessions-list-container'), document.getElementById('active-session-count'));

  document.getElementById('pos-empty-state').classList.add('d-none');
  document.getElementById('pos-bill-details').classList.remove('d-none');
  document.getElementById('selected-table-title').innerHTML = `<i class="bi bi-receipt"></i> Hóa Đơn: <strong>${sessData.tableNumber}</strong>`;
  document.getElementById('selected-session-badge').innerText = String(sessData.sessionId).substring(0, 18) + '...';

  const tbody = document.getElementById('bill-items-body');
  let total = 0;

  if (!sessData.items || sessData.items.length === 0) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">Chưa có thông tin món ăn. Sẵn sàng đóng bàn.</td></tr>`;
  } else {
    tbody.innerHTML = sessData.items.map(item => {
      const name = item.item_name || item.name || item.menu_item_name || 'Món ăn';
      const qty = item.quantity || 1;
      const price = item.price || item.unit_price || 65000;
      const subtotal = price * qty;
      total += subtotal;

      return `
        <tr>
          <td class="fw-semibold">${name}</td>
          <td class="text-center"><span class="badge bg-secondary">${qty}</span></td>
          <td class="text-end">${price.toLocaleString('vi-VN')} đ</td>
          <td class="text-end fw-bold text-dark">${subtotal.toLocaleString('vi-VN')} đ</td>
        </tr>
      `;
    }).join('');
  }

  currentTotalAmount = total;
  document.getElementById('bill-total-amount').innerText = `${total.toLocaleString('vi-VN')} VNĐ`;
}

// 4. Tra cứu Session ID / Token / Số bàn (Đã tối ưu tìm kiếm linh hoạt)
function loadSessionDetailsFromInput() {
  const inputVal = document.getElementById('session-id-input').value.trim();
  if (!inputVal) {
    alert("Vui lòng nhập Session ID, Token hoặc Số bàn!");
    return;
  }

  const sessionKeys = Object.keys(activeSessionsMap);

  // 1. Tìm khớp chính xác theo Key, Session ID hoặc QR Token
  let matchedKey = sessionKeys.find(key => {
    const s = activeSessionsMap[key];
    return key === inputVal || s.sessionId === inputVal || s.qrToken === inputVal;
  });

  // 2. Nếu không thấy, tìm theo Số bàn (ví dụ gõ: 1, 01, Bàn 1)
  if (!matchedKey) {
    const cleanInput = inputVal.toLowerCase().replace('bàn', '').trim();
    matchedKey = sessionKeys.find(key => {
      const s = activeSessionsMap[key];
      const cleanTable = s.tableNumber.toLowerCase().replace('bàn', '').trim();
      return cleanTable === cleanInput || parseInt(cleanTable) === parseInt(cleanInput);
    });
  }

  // 3. Nếu vẫn không thấy nhưng đang có đúng 1 bàn active, tự động chọn luôn bàn đó
  if (!matchedKey && sessionKeys.length === 1) {
    matchedKey = sessionKeys[0];
  }

  if (matchedKey) {
    selectSession(matchedKey);
  } else {
    currentSelectedSessionId = null;
    currentTotalAmount = 0;

    document.getElementById('pos-empty-state').classList.add('d-none');
    document.getElementById('pos-bill-details').classList.remove('d-none');
    document.getElementById('selected-table-title').innerHTML = `<i class="bi bi-search"></i> Kết quả tra cứu: <strong>${inputVal.substring(0, 12)}...</strong>`;
    document.getElementById('selected-session-badge').innerText = 'Không tìm thấy món';
    
    document.getElementById('bill-items-body').innerHTML = `
      <tr>
        <td colspan="4" class="text-center text-warning py-4">
          <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>
          Chưa tìm thấy hóa đơn cho mã <strong>"${inputVal}"</strong>.<br>
          <small class="text-muted">(Hãy thử chọn trực tiếp thẻ Bàn ở danh sách bên trái hoặc gõ số bàn như <strong>"01"</strong>).</small>
        </td>
      </tr>
    `;
    document.getElementById('bill-total-amount').innerText = `0 VNĐ`;
  }
}

// 5. Bật/Tắt hiển thị VietQR
function togglePaymentMethod() {
  const isVietQR = document.getElementById('payVietQR').checked;
  const qrContainer = document.getElementById('modal-qr-container');
  
  if (isVietQR) {
    qrContainer.classList.remove('d-none');
    const qrImg = document.getElementById('vietqr-img');
    const sessObj = activeSessionsMap[currentSelectedSessionId];
    const payId = sessObj ? sessObj.sessionId : currentSelectedSessionId;
    qrImg.src = `https://img.vietqr.io/image/MB-0905000000-compact.png?amount=${currentTotalAmount}&addInfo=DineFlow%20${payId}`;
  } else {
    qrContainer.classList.add('d-none');
  }
}

// 6. Mở Modal xác nhận thanh toán
function confirmCheckout() {
  if (!currentSelectedSessionId) {
    alert("Vui lòng chọn bàn cần thanh toán!");
    return;
  }

  const sessData = activeSessionsMap[currentSelectedSessionId];
  const tableName = sessData ? sessData.tableNumber : `Session: ${currentSelectedSessionId.substring(0, 8)}...`;

  document.getElementById('modal-table-name').innerText = tableName;
  document.getElementById('modal-total-amount').innerText = `${currentTotalAmount.toLocaleString('vi-VN')} VNĐ`;

  togglePaymentMethod();

  const modalElem = document.getElementById('checkoutModal');
  checkoutModalInstance = new bootstrap.Modal(modalElem);
  checkoutModalInstance.show();
}

// 7. Thực thi Thanh toán & Đóng bàn
async function executeCheckout() {
  if (!currentSelectedSessionId) return;

  const sessData = activeSessionsMap[currentSelectedSessionId];
  
  // LƯU TẤT CẢ CÁC MÓN TRONG HÓA ĐƠN NÀY VÀO DANH SÁCH ĐÃ THANH TOÁN
  if (sessData && sessData.items) {
    sessData.items.forEach(item => {
      const itemId = item.order_item_id || item.id;
      if (itemId !== undefined) {
        paidItemIds.add(itemId);
      }
    });
  }

  const idsToTry = [];
  if (sessData) {
    if (sessData.sessionId) idsToTry.push(sessData.sessionId);
    if (sessData.qrToken) idsToTry.push(sessData.qrToken);
  }
  if (!idsToTry.includes(currentSelectedSessionId)) idsToTry.push(currentSelectedSessionId);

  // Gọi API checkout
  for (const id of idsToTry) {
    try {
      await fetch(`${API_BASE}/staff/pos/checkout/${id}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
      });
    } catch (e) {}
  }

  // 1. Đóng Modal xác nhận
  if (checkoutModalInstance) checkoutModalInstance.hide();

  // 2. Xóa bàn khỏi mảng POS ngay lập tức
  delete activeSessionsMap[currentSelectedSessionId];

  // 3. Reset giao diện xem chi tiết hóa đơn bên phải
  document.getElementById('pos-bill-details').classList.add('d-none');
  document.getElementById('pos-empty-state').classList.remove('d-none');
  currentSelectedSessionId = null;
  currentTotalAmount = 0;

  // 4. Bật Toast thông báo màu xanh thành công
  const toastMsgElem = document.getElementById('toast-message');
  if (toastMsgElem) toastMsgElem.innerText = `Thanh toán thành công & Đã đóng bàn!`;
  const toastElem = document.getElementById('successToast');
  successToastInstance = new bootstrap.Toast(toastElem);
  successToastInstance.show();

  // 5. Render lại danh sách bàn bên trái
  renderSessionsGrid(
    document.getElementById('sessions-list-container'), 
    document.getElementById('active-session-count')
  );
}

document.addEventListener('DOMContentLoaded', () => {
  fetchActiveSessions();
  setInterval(fetchActiveSessions, 5000);
});
