const API_BASE = (location.protocol.startsWith('http') ? '' : 'http://localhost:8080') + '/api/v1';

let activeTablesList = [];
let currentSelectedTableId = null;
let currentBillData = null;
let checkoutModalInstance = null;
let successToastInstance = null;

// Chuẩn hóa tên bàn (tránh lặp chữ "Bàn Bàn 01")
function formatTableLabel(rawName, fallbackId) {
  const str = String(rawName || fallbackId || '01').trim();
  if (/^bàn/i.test(str)) return str;
  return `Bàn ${str.padStart(2, '0')}`;
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[ch]));
}

function formatBillToppings(item) {
  if (!item.topping_details_recorded) {
    return 'Chưa có dữ liệu lịch sử topping';
  }
  const toppings = Array.isArray(item.toppings) ? item.toppings : [];
  if (toppings.length === 0) {
    return 'Không chọn topping';
  }
  return 'Topping: ' + toppings
    .map(t => {
      const qty = Math.max(1, Number(t.quantity || 1));
      const linePrice = Number(t.unit_price || 0) * qty;
      const qtySuffix = qty > 1 ? ` x${qty}` : '';
      return `${escapeHtml(t.name)}${qtySuffix} (+${linePrice.toLocaleString('vi-VN')} đ)`;
    })
    .join(', ');
}

// Hiển thị badge trạng thái món từ KDS trên hóa đơn POS
function renderItemStatusBadge(status) {
  const st = String(status || '').toUpperCase();
  if (st === 'COMPLETED' || st === 'SERVED' || st === 'READY') {
    return '<span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Đã xong</span>';
  }
  if (st === 'COOKING') {
    return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1">Đang nấu</span>';
  }
  return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1">Chờ bếp</span>';
}

// 1. Tải danh sách bàn đang có phiên ACTIVE từ GET /api/v1/staff/pos/active-tables
async function fetchActiveSessions() {
  const container = document.getElementById('sessions-list-container');
  const countBadge = document.getElementById('active-session-count');

  try {
    const res = await fetch(`${API_BASE}/staff/pos/active-tables`);
    if (!res.ok) throw new Error('Không thể tải danh sách bàn từ POS API');

    const tables = await res.json();

    // Lọc các bàn đang có phiên phục vụ (session_id != null)
    activeTablesList = Array.isArray(tables)
      ? tables.filter(t => t.session_id !== null && t.session_id !== undefined && t.session_id !== '')
      : [];

    renderSessionsGrid(container, countBadge);

    // Nếu đang mở xem hóa đơn của 1 bàn, tự động đồng bộ lại số món/tiền mới nhất
    if (currentSelectedTableId !== null) {
      const stillActive = activeTablesList.some(t => Number(t.id) === Number(currentSelectedTableId));
      if (stillActive) {
        await selectSession(currentSelectedTableId, true);
      } else {
        resetBillPanel();
      }
    }
  } catch (err) {
    console.error('Lỗi POS fetchActiveSessions:', err);
  }
}

// 2. Render danh sách thẻ bàn bên cột trái
function renderSessionsGrid(container, countBadge) {
  if (!container) return;
  if (countBadge) countBadge.innerText = activeTablesList.length;

  if (activeTablesList.length === 0) {
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
      ${activeTablesList.map(tbl => {
        const tableId = Number(tbl.id);
        const label = formatTableLabel(tbl.table_number, tableId);
        const itemCount = Number(tbl.total_items || 0);
        const isSelected = tableId === Number(currentSelectedTableId);
        const btnClass = isSelected ? 'btn-primary' : 'btn-outline-primary';
        const tokenDisplay = tbl.session_token || `Session #${tbl.session_id}`;

        return `
          <div class="col">
            <button class="btn ${btnClass} w-100 py-3 text-start shadow-sm position-relative"
                    onclick="selectSession(${tableId})">
              <div class="fw-bold fs-6"><i class="bi bi-geo-alt-fill"></i> ${escapeHtml(label)}</div>
              <small class="d-block text-truncate ${isSelected ? 'text-white-50' : 'text-muted'}" style="max-width: 135px;">
                ${escapeHtml(tokenDisplay)}
              </small>
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

// 3. Gọi GET /api/v1/staff/pos/table-bill/{tableId} lấy hóa đơn chuẩn VAT 8% & làm tròn 500đ
async function selectSession(tableId, silent = false) {
  currentSelectedTableId = Number(tableId);
  renderSessionsGrid(
    document.getElementById('sessions-list-container'),
    document.getElementById('active-session-count')
  );

  try {
    const res = await fetch(`${API_BASE}/staff/pos/table-bill/${currentSelectedTableId}`);
    if (!res.ok) throw new Error('Không thể tải chi tiết hóa đơn');

    const bill = await res.json();
    currentBillData = bill;

    const tableInfo = activeTablesList.find(t => Number(t.id) === currentSelectedTableId);
    const label = formatTableLabel(bill.table_number, currentSelectedTableId);
    const badgeText = tableInfo?.session_token || `Session #${bill.session_id}`;

    document.getElementById('pos-empty-state').classList.add('d-none');
    document.getElementById('pos-bill-details').classList.remove('d-none');
    document.getElementById('selected-table-title').innerHTML = `<i class="bi bi-receipt"></i> Hóa Đơn: <strong>${escapeHtml(label)}</strong>`;
    document.getElementById('selected-session-badge').innerText = badgeText;

    const tbody = document.getElementById('bill-items-body');
    const items = Array.isArray(bill.items) ? bill.items : [];

    if (items.length === 0) {
      tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">Bàn đã mở nhưng khách chưa gửi món nào.</td></tr>`;
    } else {
      tbody.innerHTML = items.map(item => {
        const qty = Number(item.quantity || 1);
        const unitPrice = Number(item.unit_price || 0);
        const subtotal = Number(item.subtotal || unitPrice * qty);
        const imgThumb = item.image_url
          ? `<img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.item_name || 'Món ăn')}" style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;flex-shrink:0;" onerror="this.style.display='none'">`
          : '';

        return `
          <tr>
            <td class="fw-semibold">
              <div class="d-flex align-items-center gap-2">
                ${imgThumb}
                <div>
                  ${escapeHtml(item.item_name || 'Món ăn')}
                  ${renderItemStatusBadge(item.status)}
                  <div class="small fw-normal text-muted mt-1">${formatBillToppings(item)}</div>
                </div>
              </div>
            </td>
            <td class="text-center"><span class="badge bg-secondary">${qty}</span></td>
            <td class="text-end">${unitPrice.toLocaleString('vi-VN')} đ</td>
            <td class="text-end fw-bold text-dark">${subtotal.toLocaleString('vi-VN')} đ</td>
          </tr>
        `;
      }).join('');
    }

    // Hiển thị chi tiết Tạm tính + VAT 8% + Phí phục vụ 0đ + Tổng làm tròn 500đ
    const subtotalVal = Number(bill.subtotal || 0);
    const vatVal = Number(bill.vat_amount || 0);
    const finalVal = Number(bill.final_amount || 0);

    let breakdownBox = document.getElementById('pos-tax-breakdown');
    if (!breakdownBox) {
      const totalRow = document.getElementById('bill-total-amount').parentElement;
      breakdownBox = document.createElement('div');
      breakdownBox.id = 'pos-tax-breakdown';
      breakdownBox.className = 'small text-secondary mb-2 pb-2 border-bottom';
      totalRow.parentElement.insertBefore(breakdownBox, totalRow);
    }

    breakdownBox.innerHTML = `
      <div class="d-flex justify-content-between mb-1">
        <span>Tạm tính:</span>
        <span class="fw-semibold text-dark">${subtotalVal.toLocaleString('vi-VN')} đ</span>
      </div>
      <div class="d-flex justify-content-between mb-1">
        <span>Thuế VAT (${bill.vat_percent ?? 8}%):</span>
        <span class="fw-semibold text-dark">+${vatVal.toLocaleString('vi-VN')} đ</span>
      </div>
      <div class="d-flex justify-content-between">
        <span>Phí phục vụ (0đ) & Làm tròn 500đ:</span>
        <span class="text-success fw-semibold">Đã áp dụng</span>
      </div>
    `;

    document.getElementById('bill-total-amount').innerText = `${finalVal.toLocaleString('vi-VN')} VNĐ`;
  } catch (err) {
    if (!silent) {
      console.error('Lỗi tải chi tiết hóa đơn:', err);
      alert('Không thể tải chi tiết hóa đơn của bàn này!');
    }
  }
}

// 4. Tra cứu theo Session ID, Token QR hoặc Số bàn
function loadSessionDetailsFromInput() {
  const inputVal = document.getElementById('session-id-input').value.trim();
  if (!inputVal) {
    alert('Vui lòng nhập Session ID hoặc Số bàn!');
    return;
  }

  const normalized = inputVal.toLowerCase().replace(/^bàn\s*/i, '').trim();

  const matched = activeTablesList.find(tbl => {
    const tableNumClean = String(tbl.table_number || '').toLowerCase().replace(/^bàn\s*/i, '').trim();
    return (
      String(tbl.session_id) === inputVal ||
      String(tbl.session_token || '').toLowerCase() === inputVal.toLowerCase() ||
      String(tbl.id) === normalized ||
      tableNumClean === normalized ||
      parseInt(tableNumClean, 10) === parseInt(normalized, 10)
    );
  });

  if (matched) {
    selectSession(matched.id);
  } else {
    alert(`Không tìm thấy phiên bàn đang mở khớp với "${inputVal}"!`);
  }
}

// 5. Bật/Tắt mã VietQR động chuẩn Napas 247 theo số tiền sau VAT 8%
function togglePaymentMethod() {
  const isVietQR = document.getElementById('payVietQR').checked;
  const qrContainer = document.getElementById('modal-qr-container');

  if (isVietQR && currentBillData) {
    qrContainer.classList.remove('d-none');
    const amount = Math.round(Number(currentBillData.final_amount || 0));
    const tableLabel = formatTableLabel(currentBillData.table_number, currentBillData.table_id);
    const addInfo = encodeURIComponent(`DineFlow ${tableLabel} S${currentBillData.session_id}`);
    document.getElementById('vietqr-img').src =
      `https://img.vietqr.io/image/MB-0905000000-compact.png?amount=${amount}&addInfo=${addInfo}`;
  } else {
    qrContainer.classList.add('d-none');
  }
}

// 6. Mở Modal xác nhận thanh toán
function confirmCheckout() {
  if (!currentSelectedTableId || !currentBillData) {
    alert('Vui lòng chọn bàn cần thanh toán!');
    return;
  }

  const label = formatTableLabel(currentBillData.table_number, currentBillData.table_id);
  const finalAmount = Number(currentBillData.final_amount || 0);

  document.getElementById('modal-table-name').innerText = label;
  document.getElementById('modal-total-amount').innerText = `${finalAmount.toLocaleString('vi-VN')} VNĐ`;

  togglePaymentMethod();

  const modalElem = document.getElementById('checkoutModal');
  checkoutModalInstance = new bootstrap.Modal(modalElem);
  checkoutModalInstance.show();
}

// 7. Chốt thanh toán qua POST /api/v1/staff/pos/checkout/{sessionIdentifier}
async function executeCheckout() {
  if (!currentBillData) return;

  const isVietQR = document.getElementById('payVietQR').checked;
  const paymentMethod = isVietQR ? 'TRANSFER' : 'CASH';
  const sessionIdentifier = currentBillData.session_id || currentSelectedTableId;

  try {
    const res = await fetch(`${API_BASE}/staff/pos/checkout/${sessionIdentifier}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payment_method: paymentMethod })
    });

    const result = await res.json();

    if (!res.ok) {
      alert(`⚠️ ${result.error || 'Không thể thanh toán phiên bàn này!'}`);
      return;
    }

    if (checkoutModalInstance) checkoutModalInstance.hide();

    resetBillPanel();
    await fetchActiveSessions();

    const invoiceCode = result.invoice_code ? ` (${result.invoice_code})` : '';
    const toastMsgElem = document.getElementById('toast-message');
    if (toastMsgElem) {
      toastMsgElem.innerHTML = `Thanh toán thành công${invoiceCode}! <a href="/documents.php" class="text-white text-decoration-underline ms-2 pos-open-documents">In hóa đơn</a>`;
      const docLink = toastMsgElem.querySelector('.pos-open-documents');
      if (docLink) {
        docLink.addEventListener('click', (event) => {
          if (window.parent !== window) {
            event.preventDefault();
            window.parent.postMessage({ type: 'dineflow:navigate-tab', tab: 'documents' }, window.location.origin);
          } else {
            docLink.target = '_blank';
          }
        });
      }
    }

    const toastElem = document.getElementById('successToast');
    successToastInstance = new bootstrap.Toast(toastElem, { delay: 6000 });
    successToastInstance.show();
  } catch (err) {
    console.error('Lỗi khi checkout POS:', err);
    alert('Lỗi kết nối tới server PHP khi thanh toán!');
  }
}

function resetBillPanel() {
  currentSelectedTableId = null;
  currentBillData = null;
  document.getElementById('pos-bill-details').classList.add('d-none');
  document.getElementById('pos-empty-state').classList.remove('d-none');
  document.getElementById('selected-table-title').innerHTML = `<i class="bi bi-receipt"></i> Chi Tiết Hóa Đơn`;
  document.getElementById('selected-session-badge').innerText = 'Chưa chọn session';
}

document.addEventListener('DOMContentLoaded', () => {
  fetchActiveSessions();
  setInterval(fetchActiveSessions, 5000);
});

window.addEventListener('message', (event) => {
  if (event.origin === window.location.origin && event.data?.type === 'dineflow:tab-activated') {
    fetchActiveSessions();
  }
});