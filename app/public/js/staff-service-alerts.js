(() => {
  'use strict';

  const root = document.getElementById('staff-service-bell-root');
  if (!root) return;

  const API_BASE = '/api/v1';
  let knownIds = new Set();
  let hasLoadedOnce = false;
  let popoverOpen = false;
  let requests = [];
  let toastContainer = null;

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));
  }

  function formatRelativeTime(dateStr) {
    if (!dateStr) return '';
    const parsed = new Date(String(dateStr).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return String(dateStr);
    const diffSec = Math.max(0, Math.floor((Date.now() - parsed.getTime()) / 1000));
    if (diffSec < 45) return 'Vừa xong';
    const mins = Math.floor(diffSec / 60);
    if (mins < 60) return `${mins} phút trước`;
    return `${Math.floor(mins / 60)} giờ trước`;
  }

  function playGentleChime() {
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      const ctx = new AudioCtx();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, ctx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(660, ctx.currentTime + 0.22);
      gain.gain.setValueAtTime(0.08, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.26);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.28);
    } catch (_) {
      // Ignore autoplay restrictions
    }
  }

  function ensureToastContainer() {
    if (toastContainer) return toastContainer;
    toastContainer = document.createElement('div');
    toastContainer.className = 'service-alert-toast-stack';
    document.body.appendChild(toastContainer);
    return toastContainer;
  }

  function showAlertToast(req) {
    const stack = ensureToastContainer();
    const el = document.createElement('div');
    el.className = 'service-alert-toast';
    el.innerHTML = `
      <div class="service-alert-toast-body">
        <strong>🔔 ${escapeHtml(req.table_number)} cần hỗ trợ</strong>
        <span>${escapeHtml(req.message || req.request_label)}</span>
      </div>
      <button type="button" class="service-alert-toast-close" aria-label="Đóng">×</button>
    `;
    el.querySelector('.service-alert-toast-close').addEventListener('click', () => el.remove());
    stack.appendChild(el);
    setTimeout(() => el.remove(), 7000);
  }

  root.innerHTML = `
    <div class="service-bell-wrap">
      <button type="button" id="service-bell-btn" class="service-bell-btn" aria-expanded="false" aria-haspopup="true" title="Yêu cầu hỗ trợ từ khách tại bàn">
        <span aria-hidden="true">🔔</span>
        <span>Hỗ trợ</span>
        <span id="service-bell-badge" class="service-bell-badge" hidden>0</span>
      </button>
      <div id="service-bell-popover" class="service-bell-popover" hidden role="dialog" aria-label="Danh sách yêu cầu hỗ trợ">
        <div class="service-bell-popover-head">
          <strong>Yêu cầu hỗ trợ bàn</strong>
          <button type="button" id="service-bell-refresh" class="service-bell-refresh" title="Tải lại">↻</button>
        </div>
        <div id="service-bell-list" class="service-bell-list">
          <p class="service-bell-empty">Chưa có yêu cầu nào đang chờ.</p>
        </div>
      </div>
    </div>
  `;

  const bellBtn = document.getElementById('service-bell-btn');
  const badgeEl = document.getElementById('service-bell-badge');
  const popoverEl = document.getElementById('service-bell-popover');
  const listEl = document.getElementById('service-bell-list');
  const refreshBtn = document.getElementById('service-bell-refresh');

  function setPopover(open) {
    popoverOpen = Boolean(open);
    popoverEl.hidden = !popoverOpen;
    bellBtn.setAttribute('aria-expanded', String(popoverOpen));
    if (popoverOpen) {
      fetchRequests();
    }
  }

  function render() {
    const count = requests.length;
    badgeEl.textContent = String(count);
    badgeEl.hidden = count === 0;
    bellBtn.classList.toggle('has-pending', count > 0);

    if (!count) {
      listEl.innerHTML = '<p class="service-bell-empty">Hiện không có bàn nào gọi hỗ trợ.</p>';
      return;
    }

    listEl.innerHTML = requests.map((req) => `
      <div class="service-bell-item">
        <div class="service-bell-item-main">
          <div class="service-bell-item-top">
            <strong>${escapeHtml(req.table_number)}</strong>
            <span class="service-bell-type">${escapeHtml(req.request_label)}</span>
          </div>
          <p class="service-bell-msg">${escapeHtml(req.message)}</p>
          <span class="service-bell-time">${escapeHtml(formatRelativeTime(req.created_at))}</span>
        </div>
        <button type="button" class="service-bell-resolve" data-resolve-id="${Number(req.id)}">✓ Đã xử lý</button>
      </div>
    `).join('');
  }

  async function fetchRequests() {
    try {
      const res = await fetch(`${API_BASE}/staff/service-requests?status=PENDING`, {
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      const data = await res.json();
      const nextList = Array.isArray(data.requests) ? data.requests : [];

      if (hasLoadedOnce) {
        const newlyArrived = nextList.filter((item) => !knownIds.has(Number(item.id)));
        if (newlyArrived.length > 0) {
          playGentleChime();
          newlyArrived.slice(0, 2).forEach(showAlertToast);
        }
      }

      knownIds = new Set(nextList.map((item) => Number(item.id)));
      hasLoadedOnce = true;
      requests = nextList;
      render();
    } catch (_) {
      // Ignore transient network hiccups during polling
    }
  }

  async function resolveRequest(id, buttonEl) {
    if (buttonEl) {
      buttonEl.disabled = true;
      buttonEl.textContent = 'Đang lưu…';
    }
    try {
      const res = await fetch(`${API_BASE}/staff/service-requests/${encodeURIComponent(id)}/resolve`, {
        method: 'POST',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      await fetchRequests();
    } catch (_) {
      if (buttonEl) {
        buttonEl.disabled = false;
        buttonEl.textContent = '✓ Đã xử lý';
      }
    }
  }

  bellBtn.addEventListener('click', (event) => {
    event.stopPropagation();
    setPopover(!popoverOpen);
  });

  if (refreshBtn) {
    refreshBtn.addEventListener('click', (event) => {
      event.stopPropagation();
      fetchRequests();
    });
  }

  listEl.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-resolve-id]');
    if (!btn) return;
    resolveRequest(Number(btn.dataset.resolveId), btn);
  });

  document.addEventListener('click', (event) => {
    if (popoverOpen && !root.contains(event.target)) {
      setPopover(false);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && popoverOpen) {
      setPopover(false);
    }
  });

  fetchRequests();
  setInterval(fetchRequests, 5000);
})();
