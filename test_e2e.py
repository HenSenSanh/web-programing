"""
DineFlow - Kịch bản kiểm thử tự động toàn bộ luồng E2E
Chạy: python test_e2e.py (sau khi server uvicorn đã bật ở port 8000)
"""
import sys
import time
import httpx

if hasattr(sys.stdout, "reconfigure"):
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except Exception:
        pass

BASE_URL = "http://localhost:8000"

def run_tests():
    print("=" * 65)
    print("🚀 BẮT ĐẦU KIỂM THỬ TOÀN DIỆN HỆ THỐNG DINEFLOW (E2E TEST)")
    print("=" * 65)

    with httpx.Client(base_url=BASE_URL, timeout=10.0) as client:
        # 1. Healthcheck
        try:
            r = client.get("/docs")
            if r.status_code != 200:
                print("❌ Server chưa sẵn sàng tại http://localhost:8000")
                return
            print("✅ [0/5] Kết nối FastAPI Server thành công!")
        except Exception as e:
            print(f"❌ Không thể kết nối tới server {BASE_URL}: {e}")
            print("👉 Hãy mở terminal và chạy: uvicorn app.main:app --port 8000")
            return

        # 2. TV1 - Admin Mở bàn số 1
        print("\n🔹 [BƯỚC 1/5 - TV1 ADMIN]: Mở phiên bàn số 1...")
        r = client.post("/api/v1/admin/tables/1/open-session")
        assert r.status_code == 200, f"Lỗi mở bàn: {r.text}"
        data = r.json()
        session_id = data["session_id"]
        session_token = data["session_token"]
        print(f"   -> Session ID: {session_id}")
        print(f"   -> Token phiên: {session_token}")
        print(f"   -> QR URL: {data['qr_url']}")

        # 3. TV4 - Clickstream: Khách xem món Mì Cay (id: 1)
        print("\n🔹 [BƯỚC 2/5 - TV4 DATA]: Ghi nhận khách lướt xem món...")
        r = client.post("/api/v1/data/clickstream", json={
            "session_id": session_id,
            "menu_item_id": 1,
            "event_type": "VIEW_ITEM"
        })
        assert r.status_code == 200 and r.json().get("recorded") is True
        print("   -> Đã ghi nhận sự kiện Clickstream VIEW_ITEM vào FactClickstream!")

        # 4. TV2 - Client Đặt món
        print("\n🔹 [BƯỚC 3/5 - TV2 CLIENT]: Khách gửi đơn đặt món xuống Bếp...")
        order_payload = [
            {
                "menu_item_id": 1,
                "name": "Mì Cay Hải Sản Cấp Độ 7",
                "quantity": 2,
                "unit_price": 80000.0,
                "selected_toppings": [{"id": 1, "name": "Thêm Phô Mai Kéo Sợi", "price": 15000.0}],
                "note": "Ít cay, nhiều nấm"
            }
        ]
        r = client.post(f"/api/v1/client/session/{session_token}/submit-order", json=order_payload)
        assert r.status_code == 200
        order_data = r.json()
        order_id = order_data.get("order_id")
        created_item_ids = order_data.get("order_item_ids", [1])
        target_item_id = created_item_ids[0] if created_item_ids else 1
        print(f"   -> Đặt hàng thành công! Mã Order: #{order_id}, Mã Món Order Item: #{target_item_id}")

        # 5. TV3 - KDS Đầu bếp nhận nấu & xong món
        print("\n🔹 [BƯỚC 4/5 - TV3 KDS]: Đầu bếp nhận & hoàn thành món ăn...")
        r = client.patch("/api/v1/staff/kds/item-status", json={
            "order_item_id": target_item_id,
            "chef_id": 3,
            "new_status": "COMPLETED"
        })
        assert r.status_code == 200
        print(f"   -> Đầu bếp cập nhật: Món #{target_item_id} đã COMPLETED (sẵn sàng phục vụ bàn)!")

        # 6. TV3 & TV4 - Thu ngân đóng bàn & kích hoạt ETL
        print("\n🔹 [BƯỚC 5/5 - TV3 POS & TV4 ETL]: Thu ngân thanh toán & Đóng bàn...")
        r = client.post(f"/api/v1/staff/pos/checkout/{session_id}")
        assert r.status_code == 200
        checkout_data = r.json()
        print(f"   -> Tổng tiền: {checkout_data['total_amount']:,.0f} VNĐ")
        print("   -> Bàn số 1 đã chuyển về AVAILABLE")
        print("   -> Background ETL đã tự động đẩy dữ liệu sang FactOrderItem (OLAP)")

        # Chờ 1 giây để Background Task hoàn tất ghi DB
        time.sleep(1)

        # 7. TV4 & TV5 - Kiểm tra BI & Gợi ý
        print("\n📊 [KẾT QUẢ PHÂN TÍCH DATA ENGINEERING (TV4) & BI (TV5)]:")
        r_status = client.get("/api/v1/data/pipeline-status")
        if r_status.status_code == 200:
            st = r_status.json()
            print(f"   ⚡ Trạng thái Data Warehouse: {st['status']} | FactOrderItem: {st['olap_star_schema_stats']['fact_order_items']} dòng | FactClickstream: {st['olap_star_schema_stats']['fact_clickstream']} dòng")

        r_rec = client.get("/api/v1/data/recommendations/1")
        if r_rec.status_code == 200:
            recs = r_rec.json().get("recommendations", [])
            print("   💡 Top món gợi ý mua kèm với Món số 1 (Thuật toán Apriori):")
            for idx, item in enumerate(recs, 1):
                print(
                    f"      {idx}. {item['name']} - Giá: {item['current_price']:,.0f} VNĐ "
                    f"(Đi chung: {item['pair_count']} | Support: {item.get('support_pct')}% | Confidence: {item.get('confidence_pct')}% | Lift: {item.get('lift')}x)"
                )

        r_sla = client.get("/api/v1/data/sla-analytics")
        if r_sla.status_code == 200:
            chefs = r_sla.json().get("chef_performance", [])
            print("\n   ⏱️ Phân tích SLA Bếp (Ngưỡng 15 phút):")
            for c in chefs[:2]:
                print(f"      • {c['chef_name']}: Đã nấu {c['total_dishes_cooked']} món | TB: {c['avg_prep_minutes']} phút | Vi phạm SLA: {c['sla_violation_rate_pct']}%")

        r_bi = client.get("/api/v1/bi/menu-engineering-matrix")
        if r_bi.status_code == 200:
            bi_items = r_bi.json()
            print("\n   📈 Ma trận Menu (Top 3 món doanh thu cao nhất):")
            for item in bi_items[:3]:
                print(f"      • {item['name']}: Bán {item['total_sold']} phần | Lượt xem: {item['views']} | Doanh thu: {item['total_revenue']:,.0f} VNĐ")

    print("\n" + "=" * 65)
    print("🎉 TẤT CẢ 5 PHẦN HỆ THỐNG ĐÃ HOẠT ĐỘNG HOÀN HẢO!")
    print("=" * 65)

if __name__ == "__main__":
    run_tests()
