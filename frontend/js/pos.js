const API_BASE = 'http://localhost:8000/api/v1';

async function handleCheckout() {
  const inputElem = document.getElementById('session-id-input');
  const sessionId = inputElem ? inputElem.value.trim() : '';

  if (!sessionId) {
    alert("Vui lòng nhập Session ID!");
    return;
  }

  if (!confirm(`Xác nhận thanh toán và đóng bàn cho Session: ${sessionId}?`)) return;

  try {
    const res = await fetch(`${API_BASE}/staff/pos/checkout/${sessionId}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    });

    const result = await res.json();
    if (res.ok) {
      alert(`Thanh toán thành công! Tổng tiền: ${result.total_amount || 0} VNĐ`);
      inputElem.value = '';
    } else {
      alert(`Lỗi thanh toán: ${result.detail || 'Không thể thanh toán'}`);
    }
  } catch (err) {
    console.error("Lỗi khi kết nối API POS:", err);
  }
}