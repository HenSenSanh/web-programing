const API_BASE = 'http://localhost:8000/api/v1';

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
      const formattedTotal = result.total_amount ? Number(result.total_amount).toLocaleString('vi-VN') : 0;
      alert(`🎉 THANH TOÁN THÀNH CÔNG!\n\n• Tổng tiền: ${formattedTotal} VNĐ\n• Trạng thái: Bàn đã được đóng và chuyển về TRỐNG\n• Hệ thống: Background ETL đã tự động đẩy dữ liệu sang Star Schema (OLAP).`);
      inputElem.value = '';
    } else {
      alert(`⚠️ Lỗi thanh toán: ${result.detail || 'Không thể thanh toán'}`);
    }
  } catch (err) {
    console.error("Lỗi khi kết nối API POS:", err);
  }
}