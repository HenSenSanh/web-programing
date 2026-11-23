import json
from typing import Dict, List
from fastapi import WebSocket
from app.core.database import redis_client

class RealtimeManager:
    def __init__(self):
        # Lưu các điện thoại đang mở cùng 1 bàn: { "session_token": [ws1, ws2] }
        self.table_connections: Dict[str, List[WebSocket]] = {}
        # Lưu các màn hình Bếp (KDS) & Thu ngân (POS)
        self.staff_connections: List[WebSocket] = []

    async def connect_table(self, session_token: str, websocket: WebSocket):
        await websocket.accept()
        self.table_connections.setdefault(session_token, []).append(websocket)

    def disconnect_table(self, session_token: str, websocket: WebSocket):
        if session_token in self.table_connections:
            self.table_connections[session_token].remove(websocket)

    async def connect_staff(self, websocket: WebSocket):
        await websocket.accept()
        self.staff_connections.append(websocket)

    def disconnect_staff(self, websocket: WebSocket):
        if websocket in self.staff_connections:
            self.staff_connections.remove(websocket)

    async def broadcast_to_table(self, session_token: str, message: dict):
        """Gửi cập nhật giỏ hàng hoặc trạng thái món về tất cả điện thoại cùng bàn"""
        for ws in self.table_connections.get(session_token, []):
            await ws.send_json(message)

    async def broadcast_to_staff(self, message: dict):
        """Bắn chuông báo đơn mới hoặc yêu cầu gọi nhân viên xuống màn hình Bếp/POS"""
        for ws in self.staff_connections:
            await ws.send_json(message)

ws_manager = RealtimeManager()

# --- HÀM XỬ LÝ GIỎ HÀNG CHUNG TRÊN REDIS (SHARED CART) ---
class SharedCartService:
    @staticmethod
    def _key(session_token: str) -> str:
        return f"shared_cart:{session_token}"

    @classmethod
    def get_cart(cls, session_token: str) -> List[dict]:
        data = redis_client.get(cls._key(session_token))
        return json.loads(data) if data else []

    @classmethod
    def update_cart(cls, session_token: str, cart_items: List[dict]):
        # Lưu giỏ hàng trên Redis với TTL 4 tiếng
        redis_client.setex(cls._key(session_token), 14400, json.dumps(cart_items))

    @classmethod
    def clear_cart(cls, session_token: str):
        redis_client.delete(cls._key(session_token))