let isAutoRefreshEnabled = true;
let countdown = 5;
let refreshTimer = null;
let menuItemsCache = [];

function formatVND(val) {
    const num = parseFloat(val) || 0;
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(num);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[ch]));
}

function formatTimeAgo(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dateStr;
    const diffSec = Math.floor((new Date() - d) / 1000);
    if (diffSec < 60) return `${diffSec}s trước`;
    const diffMin = Math.floor(diffSec / 60);
    if (diffMin < 60) return `${diffMin}m trước`;
    const diffHour = Math.floor(diffMin / 60);
    return `${diffHour}h trước`;
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    const bgClass = type === 'success' ? 'bg-emerald-600' : (type === 'error' ? 'bg-rose-600' : 'bg-slate-800');
    const icon = type === 'success' ? '✓' : (type === 'error' ? '✕' : 'ℹ');

    toast.className = `p-3 rounded-lg ${bgClass} text-white text-xs font-medium shadow-lg flex items-center gap-2.5 transform transition-all duration-300 translate-y-2 opacity-0`;
    toast.innerHTML = `
        <span class="w-5 h-5 rounded-full bg-white/20 flex items-center justify-center font-bold text-xs">${icon}</span>
        <span class="flex-1">${escapeHtml(message)}</span>
        <button onclick="this.parentElement.remove()" class="text-white/60 hover:text-white ml-2 text-sm">✕</button>
    `;

    container.appendChild(toast);
    setTimeout(() => {
        toast.classList.remove('translate-y-2', 'opacity-0');
    }, 10);

    setTimeout(() => {
        toast.classList.add('opacity-0', 'translate-x-full');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

async function loadMenuDropdowns() {
    try {
        const res = await fetch('/api/v1/data/live-ops');
        const data = await res.json();
        
        // Fallback items if needed
        const itemsRes = await fetch('/api/v1/admin/menu-items');
        let items = [];
        if (itemsRes.ok) {
            items = await itemsRes.json();
        }

        if (!items || items.length === 0) {
            // Predefined items
            items = [
                { id: 1, name: 'Mì Cay Hải Sản Cấp Độ 7', price: 65000 },
                { id: 2, name: 'Lẩu Thái Tomyum Bò Mỹ', price: 189000 },
                { id: 3, name: 'Cơm Trộn Hàn Quốc Bibimbap', price: 55000 },
                { id: 4, name: 'Mì Ý Sốt Bò Bằm Bolognaise', price: 59000 },
                { id: 5, name: 'Khoai Tây Chiên Lắc Phô Mai', price: 35000 },
                { id: 6, name: 'Gà Rán Sốt Cay Hàn Quốc (4 miếng)', price: 69000 },
                { id: 7, name: 'Kim Chi Cải Thảo Truyền Thống', price: 15000 },
                { id: 8, name: 'Trà Đào Cam Sả Tươi', price: 32000 },
                { id: 9, name: 'Trà Sữa Trân Châu Hoàng Kim', price: 35000 },
                { id: 10, name: 'Coca-Cola Lon Lạnh', price: 18000 }
            ];
        }

        menuItemsCache = items;
        const selSrc = document.getElementById('selectSourceItem');
        const selTgt = document.getElementById('selectTargetItem');

        const opts = items.map(it => `<option value="${it.id}">${it.name} (${formatVND(it.price)})</option>`).join('');

        selSrc.innerHTML = opts;
        selTgt.innerHTML = opts;

        // Select second item by default
        if (items.length > 1) {
            selTgt.selectedIndex = 1;
        }

        onSourceItemChange();
    } catch (err) {
        console.error("Lỗi tải menu:", err);
    }
}

function onSourceItemChange() {
    const selSrc = document.getElementById('selectSourceItem');
    const itemId = parseInt(selSrc.value);
    if (itemId > 0) {
        loadRecommendationsPreview(itemId);
    }
}

async function pollLiveOpsAndSla() {
    try {
        const [liveOpsRes, slaRes, rulesRes] = await Promise.all([
            fetch('/api/v1/data/live-ops'),
            fetch('/api/v1/data/sla-metrics'),
            fetch('/api/v1/data/association-rules')
        ]);

        if (liveOpsRes.ok) {
            const liveData = await liveOpsRes.json();
            renderLiveOps(liveData);
        }

        if (slaRes.ok) {
            const slaData = await slaRes.json();
            renderSlaMetrics(slaData);
        }

        if (rulesRes.ok) {
            const rulesData = await rulesRes.json();
            renderAssociationRules(rulesData);
        }

        const now = new Date();
        document.getElementById('lastUpdatedTime').textContent = now.toLocaleTimeString('vi-VN');
    } catch (err) {
        console.warn("Polling error:", err);
    }
}

function renderLiveOps(data) {
    // Update Top KPIs
    document.getElementById('activeTablesCount').textContent = data.active_tables || 0;
    document.getElementById('todayRevenue').textContent = formatVND(data.today_revenue || 0);
    document.getElementById('todayOrdersCount').textContent = data.today_orders_count || 0;
    document.getElementById('kdsPendingCount').textContent = (data.kds_pending_items ? data.kds_pending_items.length : 0);
    document.getElementById('olapFactCount').textContent = data.olap_total_fact_records || 0;
    document.getElementById('olapRevenue').textContent = formatVND(data.olap_total_revenue || 0);
    document.getElementById('slaViolationsCount').textContent = data.sla_violations_count || 0;

    // Render Tables Grid
    const container = document.getElementById('tablesGrid');
    if (!data.tables || data.tables.length === 0) {
        container.innerHTML = `<div class="col-span-full py-8 text-center text-slate-400 text-xs">Không có bàn ăn nào.</div>`;
        return;
    }

    container.innerHTML = data.tables.map(tbl => {
        const isOccupied = (tbl.status === 'OCCUPIED');
        const borderClass = isOccupied ? 'border-indigo-200 bg-white ring-1 ring-indigo-50' : 'border-slate-200 bg-slate-50/50';
        const statusBadge = isOccupied 
            ? `<span class="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Đang Có Khách</span>`
            : `<span class="inline-flex items-center text-[11px] font-medium px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 border border-slate-200">Bàn Trống</span>`;

        const kitchenItemsHtml = (tbl.submitted_kitchen_items && tbl.submitted_kitchen_items.length > 0)
            ? tbl.submitted_kitchen_items.map(it => {
                const isDone = ['COMPLETED', 'SERVED', 'READY'].includes(it.status);
                const badgeColor = isDone ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700 border border-amber-200';
                const safeDish = escapeHtml(it.dish_name);
                const safeStatus = escapeHtml(it.status);
                return `
                    <div class="flex items-center justify-between text-xs py-1 border-b border-slate-100 last:border-0">
                        <span class="font-medium text-slate-800 truncate max-w-[170px]" title="${safeDish}">${safeDish}</span>
                        <div class="flex items-center gap-1.5">
                            <span class="font-bold text-slate-900">x${Number(it.quantity) || 0}</span>
                            <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded ${badgeColor}">${safeStatus}</span>
                        </div>
                    </div>
                `;
            }).join('')
            : `<div class="text-xs text-slate-400 py-1 italic">Chưa gọi món nào</div>`;

        const menuUrl = isOccupied && tbl.session_token ? `/frontend/menu/index.html?token=${encodeURIComponent(tbl.session_token)}` : '#';

        return `
            <div class="rounded-xl border ${borderClass} p-4 flex flex-col justify-between transition hover:shadow-md">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-slate-900 text-sm">${escapeHtml(tbl.table_number)}</span>
                            ${tbl.session_token ? `<span class="text-[10px] font-mono text-slate-400 truncate max-w-[80px]" title="${tbl.session_token}">${tbl.session_token}</span>` : ''}
                        </div>
                        ${statusBadge}
                    </div>

                    <div class="space-y-2 mt-3">
                        <div>
                            <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider block mb-1">Món Đã Đặt:</span>
                            <div class="bg-slate-50 rounded-lg p-2.5 max-h-32 overflow-y-auto">
                                ${kitchenItemsHtml}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] text-slate-400 uppercase tracking-wider block font-medium">Tạm Tính</span>
                        <span class="text-sm font-bold text-indigo-700">${formatVND(tbl.current_bill_total || 0)}</span>
                    </div>
                    ${isOccupied ? `
                        <a href="${menuUrl}" target="_blank" class="px-2.5 py-1.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-semibold transition flex items-center gap-1 border border-indigo-200">
                            <span>Mở Menu Bàn</span>
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        </a>
                    ` : `
                        <span class="text-xs text-slate-400 font-medium">Sẵn sàng phục vụ</span>
                    `}
                </div>
            </div>
        `;
    }).join('');

    // Render KDS Pending Table
    const kdsTable = document.getElementById('kdsPendingTableBody');
    if (data.kds_pending_items && data.kds_pending_items.length > 0) {
        kdsTable.innerHTML = data.kds_pending_items.map(it => {
            const isCooking = it.status === 'COOKING';
            const badgeClass = isCooking ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-slate-100 text-slate-700 border border-slate-200';
            return `
                <tr class="hover:bg-slate-50 transition">
                    <td class="px-3.5 py-2.5 font-medium text-slate-900">${it.item_name}</td>
                    <td class="px-3.5 py-2.5 font-semibold text-slate-700">${it.table_number || '--'}</td>
                    <td class="px-3.5 py-2.5"><span class="px-2 py-0.5 rounded text-[11px] font-semibold ${badgeClass}">${it.status}</span></td>
                    <td class="px-3.5 py-2.5 text-right font-medium text-slate-500">${formatTimeAgo(it.created_at)}</td>
                </tr>
            `;
        }).join('');
    } else {
        kdsTable.innerHTML = `<tr><td colspan="4" class="px-3.5 py-4 text-center text-slate-400 italic">Không có món nào đang chờ chế biến dưới bếp</td></tr>`;
    }
}

function renderSlaMetrics(data) {
    if (data.sla_compliance_rate !== undefined) {
        document.getElementById('slaComplianceRate').textContent = `${data.sla_compliance_rate}%`;
    }

    const tbody = document.getElementById('dishBottlenecksTableBody');
    if (!data.dish_bottlenecks || data.dish_bottlenecks.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="px-3.5 py-4 text-center text-slate-400 italic">Chưa ghi nhận điểm nghẽn món ăn nào</td></tr>`;
        return;
    }

    tbody.innerHTML = data.dish_bottlenecks.slice(0, 5).map(d => {
        const rate = parseFloat(d.violation_rate_pct) || 0;
        const rateClass = rate > 50 ? 'text-rose-600 font-bold' : (rate > 0 ? 'text-amber-600 font-semibold' : 'text-emerald-600 font-medium');
        return `
            <tr class="hover:bg-slate-50 transition">
                <td class="px-3.5 py-2.5 font-medium text-slate-900">${d.dish_name}</td>
                <td class="px-3.5 py-2.5 text-slate-500">${d.category_name}</td>
                <td class="px-3.5 py-2.5 font-semibold text-slate-700">${d.avg_prep_minutes} phút</td>
                <td class="px-3.5 py-2.5 text-right ${rateClass}">${rate.toFixed(1)}%</td>
            </tr>
        `;
    }).join('');
}

function renderAssociationRules(rows) {
    const tbody = document.getElementById('associationRulesTableBody');
    if (!rows || rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="px-3.5 py-3 text-center text-slate-400 italic">Chưa có luật kết hợp nào được nạp</td></tr>`;
        return;
    }

    tbody.innerHTML = rows.slice(0, 6).map(r => {
        const itemA = escapeHtml(r.antecedent_item || r.item_a_name || r.source_item_name || ('Món #' + (r.antecedent_id ?? r.source_item_id ?? '?')));
        const itemB = escapeHtml(r.consequent_item || r.item_b_name || r.target_item_name || ('Món #' + (r.consequent_id ?? r.target_item_id ?? '?')));
        const confPct = r.confidence_a_to_b_pct !== undefined
            ? Math.round(parseFloat(r.confidence_a_to_b_pct) || 0)
            : Math.round((parseFloat(r.confidence) || 0) * 100);
        const badgeText = r.discount_percent !== undefined
            ? `Giảm ${Number(r.discount_percent) || 0}%`
            : `Lift ${Number(r.lift_ratio || 1).toFixed(2)}x`;
        return `
            <tr class="hover:bg-slate-50 transition">
                <td class="px-3.5 py-2 font-medium text-slate-900">${itemA}</td>
                <td class="px-3.5 py-2 font-semibold text-indigo-700">${itemB}</td>
                <td class="px-3.5 py-2 font-bold text-slate-700">${confPct}%</td>
                <td class="px-3.5 py-2 text-right"><span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">${badgeText}</span></td>
            </tr>
        `;
    }).join('');
}

async function saveComboRule() {
    const selSrc = document.getElementById('selectSourceItem');
    const selTgt = document.getElementById('selectTargetItem');
    const selConf = document.getElementById('inputConfidence');
    const inputDisc = document.getElementById('inputDiscount');

    const sourceId = parseInt(selSrc.value);
    const targetId = parseInt(selTgt.value);
    const confidence = parseFloat(selConf.value) || 0.85;
    const discount = parseInt(inputDisc.value) || 10;

    if (sourceId <= 0 || targetId <= 0) {
        showToast("Vui lòng chọn đầy đủ Món A và Món B", "error");
        return;
    }

    if (sourceId === targetId) {
        showToast("Hai món trong cặp Combo phải khác nhau", "error");
        return;
    }

    const btn = document.getElementById('btnSaveCombo');
    btn.disabled = true;
    btn.textContent = "Đang lưu...";

    try {
        const res = await fetch('/api/v1/data/combo-rule', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                source_item_id: sourceId,
                target_item_id: targetId,
                confidence: confidence,
                discount_percent: discount
            })
        });

        const data = await res.json();
        if (res.ok) {
            showToast(data.message || "Đã lưu thành công cặp món gợi ý mua kèm!", "success");
            await pollLiveOpsAndSla();
            loadRecommendationsPreview(sourceId);
        } else {
            showToast(data.detail || data.message || "Lỗi khi lưu luật", "error");
        }
    } catch (err) {
        showToast("Lỗi kết nối: " + err.message, "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg><span>Lưu Cặp Món Gợi Ý</span>`;
    }
}

async function loadRecommendationsPreview(itemId) {
    const container = document.getElementById('recommendationsPreview');
    container.innerHTML = `<span class="text-slate-400 text-xs">Đang truy vấn Apriori...</span>`;

    try {
        const res = await fetch(`/api/v1/data/recommendations/${itemId}`);
        if (!res.ok) {
            container.innerHTML = `<span class="text-slate-400 text-xs italic">Chưa có món gợi ý phù hợp cho món này</span>`;
            return;
        }

        const data = await res.json();
        const recs = data.recommendations || [];

        if (recs.length === 0) {
            container.innerHTML = `<span class="text-slate-400 text-xs italic">Thuật toán Apriori chưa tìm thấy món mua kèm có độ tin cậy cao</span>`;
            return;
        }

        container.innerHTML = `
            <div class="w-full space-y-2">
                ${recs.map(r => {
                    const itemName = escapeHtml(r.name || r.target_item_name || r.item_name || ('Món #' + (r.oltp_item_id ?? r.target_item_id ?? '?')));
                    const confPct = r.confidence_pct !== undefined
                        ? Math.round(parseFloat(r.confidence_pct) || 0)
                        : Math.round((parseFloat(r.confidence) || 0) * 100);
                    const basePrice = Number(r.price ?? r.current_price ?? 0);
                    const discount = Number(r.discount_percent || 0);
                    const finalPrice = discount > 0 ? (basePrice * (100 - discount) / 100) : basePrice;
                    return `
                        <div class="bg-white p-2.5 rounded-lg border border-slate-200 flex items-center justify-between">
                            <div>
                                <div class="font-bold text-slate-900 text-xs">${itemName}</div>
                                <div class="text-[11px] text-slate-500 flex items-center gap-2 mt-0.5">
                                    <span>Độ tin cậy: <strong class="text-indigo-600">${confPct}%</strong></span>
                                    <span>•</span>
                                    <span>Lift: <strong class="text-slate-700">${Number(r.lift || r.lift_ratio || 1.2).toFixed(2)}x</strong></span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-bold text-emerald-600 text-xs">${formatVND(finalPrice)}</div>
                                ${discount > 0 ? `<div class="text-[10px] text-slate-400 line-through">${formatVND(basePrice)}</div>` : ''}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    } catch (err) {
        container.innerHTML = `<span class="text-rose-500 text-xs">Lỗi: ${err.message}</span>`;
    }
}

async function triggerBatchETL() {
    const btn = document.getElementById('btnTriggerEtl');
    btn.disabled = true;
    btn.innerHTML = `<svg class="animate-spin w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg><span>Đang đồng bộ kho OLAP...</span>`;

    try {
        const res = await fetch('/api/v1/data/trigger-batch-etl', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await res.json();
        const count = data.sessions_synced_to_olap ?? data.processed_sessions ?? 0;
        showToast(`Đồng bộ thành công ${count} phiên bàn vào Kho Dữ Liệu Star Schema (OLAP)!`, 'success');
        await pollLiveOpsAndSla();
    } catch (err) {
        showToast("Lỗi khi chạy ETL: " + err.message, "error");
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg><span>Kích Hoạt Đồng Bộ Batch ETL Ngay</span>`;
    }
}

function updateCountdownUI() {
    const label = document.getElementById('countdownLabel');
    if (label) {
        label.textContent = isAutoRefreshEnabled ? `Auto 5s (${countdown}s)` : 'Tạm dừng';
    }
}

function toggleAutoRefresh() {
    isAutoRefreshEnabled = !isAutoRefreshEnabled;
    const btn = document.getElementById('btnAutoToggle');
    if (isAutoRefreshEnabled) {
        btn.className = "px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 transition font-mono";
        countdown = 5;
    } else {
        btn.className = "px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 border border-amber-300 transition font-mono";
    }
    updateCountdownUI();
}

async function manualRefresh() {
    const icon = document.getElementById('refreshIcon');
    if (icon) icon.classList.add('animate-spin');
    countdown = 5;
    updateCountdownUI();
    await pollLiveOpsAndSla();
    if (icon) {
        setTimeout(() => icon.classList.remove('animate-spin'), 600);
    }
}

function startAutoRefreshLoop() {
    if (refreshTimer) clearInterval(refreshTimer);
    countdown = 5;
    updateCountdownUI();

    refreshTimer = setInterval(async () => {
        if (!isAutoRefreshEnabled) return;
        countdown--;
        if (countdown <= 0) {
            countdown = 5;
            await pollLiveOpsAndSla();
        }
        updateCountdownUI();
    }, 1000);
}

// Initialize on page load
(async function init() {
    await loadMenuDropdowns();
    await pollLiveOpsAndSla();
    startAutoRefreshLoop();
})();

window.addEventListener('message', (event) => {
    if (event.origin === window.location.origin && event.data?.type === 'dineflow:tab-activated') {
        pollLiveOpsAndSla();
    }
});
