const API_BASE = (location.protocol.startsWith('http') ? '' : 'http://localhost:8080') + '/api/v1';

async function handleCheckout() {
  const inputElem = document.getElementById('session-id-input');
  let sessionId = inputElem ? inputElem.value.trim() : '';

  if (!sessionId) {
    alert("Vui lòng nhập Session Token (sess_...) hoặc Số bàn!");
    return;
  }

  // Tự động nhận diện nếu người dùng gõ "Bàn 1" -> lấy số 1
  const tableMatch = sessionId.match(/\d+/);
  if (sessionId.toLowerCase().includes('bàn') && tableMatch) {
    sessionId = tableMatch[0];
  }

  if (!confirm(`Xác nhận thanh toán và đóng bàn cho: ${sessionId}?`)) return;

  try {
    const res = await fetch(`${API_BASE}/staff/pos/checkout/${encodeURIComponent(sessionId)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    });

    const result = await res.json();
    if (res.ok) {
      const rawTotal = result.final_amount ?? result.total_amount ?? 0;
      const formattedTotal = Number(rawTotal).toLocaleString('vi-VN');
      alert(`🎉 THANH TOÁN THÀNH CÔNG!\n\n• Hóa đơn: ${result.invoice_code || '--'}\n• Tổng tiền (VAT 8%): ${formattedTotal} VNĐ\n• Trạng thái: Bàn đã được đóng và chuyển về TRỐNG\n• Hệ thống: ETL đã tự động đẩy dữ liệu sang Star Schema (OLAP).`);
      inputElem.value = '';
    } else {
      alert(`⚠️ Lỗi thanh toán: ${result.error || result.detail || 'Không thể thanh toán'}`);
    }
  } catch (err) {
    console.error("Lỗi khi kết nối API POS:", err);
  }
}
