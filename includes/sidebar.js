document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const STORAGE_KEY = 'sidebarCollapsed';

  // Elements
  const sidebar = document.getElementById('mainSidebar');
  const toggleBtn = document.getElementById('sidebarToggleBtn');

  // Helper: set sidebar state
  function setSidebarState(collapsed) {
    if (!sidebar) return;

    document.body.classList.toggle('sidebar-collapsed', collapsed);
    sidebar.classList.toggle('collapsed', collapsed);

    // Update ARIA attributes for accessibility
    if (toggleBtn) {
      toggleBtn.setAttribute('aria-expanded', (!collapsed).toString());
      toggleBtn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      toggleBtn.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }

    try {
      localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    } catch (e) {
      console.warn('localStorage not available', e);
    }
  }

  // Restore state
  let savedState = null;
  try {
    savedState = localStorage.getItem(STORAGE_KEY);
  } catch (e) {
    savedState = null;
  }

  if (savedState === '1') {
    setSidebarState(true);
  } else if (savedState === '0') {
    setSidebarState(false);
  }

  // Toggle button click — now lives inside header (.top-navbar), always accessible even when collapsed
  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      const isCollapsed = document.body.classList.contains('sidebar-collapsed') || sidebar.classList.contains('collapsed');
      setSidebarState(!isCollapsed);
    });
  }

  // Sidebar nav: NEVER auto-expand — only toggle button controls collapse
  // If collapsed, clicking an icon navigates but sidebar stays collapsed (icons remain fixed)
  const sidebarNav = sidebar ? sidebar.querySelector('.sidebar-nav') : null;
  if (sidebarNav) {
    sidebarNav.addEventListener('click', function (e) {
      const link = e.target.closest('a');
      if (!link) return;
      // On mobile, close the overlay drawer after navigation; on desktop keep current state (collapsed stays collapsed)
      if (window.innerWidth <= 768) {
        const isOpen = !document.body.classList.contains('sidebar-collapsed') && !sidebar.classList.contains('collapsed');
        if (isOpen) setSidebarState(true);
      }
      // Desktop: do NOT call setSidebarState(false) — keep collapsed as-is; toggle-only expansion
      // Stop propagation so backdrop handler does not fire
      e.stopPropagation();
    });
  }

  // Mobile: click outside (backdrop) closes drawer — header toggle remains accessible
  document.addEventListener('click', function (e) {
    if (window.innerWidth > 768) return;
    if (!sidebar || !toggleBtn) return;
    const isOpen = !document.body.classList.contains('sidebar-collapsed') && !sidebar.classList.contains('collapsed');
    if (!isOpen) return;
    if (sidebar.contains(e.target) || toggleBtn.contains(e.target)) return;
    setSidebarState(true);
  });

  // ESC closes drawer on mobile
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (window.innerWidth > 768) return;
    const isOpen = !document.body.classList.contains('sidebar-collapsed') && !sidebar.classList.contains('collapsed');
    if (isOpen) setSidebarState(true);
  });

  // --- Collapsed sidebar: show tooltip via title attribute ---
  function setCollapsedTooltips() {
    if (!sidebar) return;
    const collapsed = document.body.classList.contains('sidebar-collapsed') || sidebar.classList.contains('collapsed');
    sidebar.querySelectorAll('.sidebar-nav a').forEach(function (a) {
      if (collapsed) {
        if (!a.getAttribute('data-label')) {
          const lbl = a.querySelector('.nav-label');
          if (lbl) a.setAttribute('data-label', lbl.textContent.trim());
        }
        a.setAttribute('title', a.getAttribute('data-label') || '');
      } else {
        a.removeAttribute('title');
      }
    });
  }

  // Observe class changes on body/sidebar to sync tooltips
  var tipTimer = null;
  function debouncedSetTooltips() {
    clearTimeout(tipTimer);
    tipTimer = setTimeout(setCollapsedTooltips, 50);
  }

  new MutationObserver(debouncedSetTooltips).observe(document.body, { attributes: true, attributeFilter: ['class'] });
  if (sidebar) new MutationObserver(debouncedSetTooltips).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
  setCollapsedTooltips();

  // --- User menu dropdown (Logout in header) ---
  const userMenuWrap = document.getElementById('userMenuWrap');
  const userMenuBtn = document.getElementById('userMenuBtn');
  const userDropdown = document.getElementById('userDropdown');

  if (userMenuWrap && userMenuBtn && userDropdown) {
    userMenuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      const isOpen = userMenuWrap.classList.toggle('open');
      userMenuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      const nd = document.getElementById('notifDropdown');
      if (nd) nd.classList.remove('show');
    });

    document.addEventListener('click', function (e) {
      if (!userMenuWrap.contains(e.target)) {
        userMenuWrap.classList.remove('open');
        userMenuBtn.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && userMenuWrap.classList.contains('open')) {
        userMenuWrap.classList.remove('open');
        userMenuBtn.setAttribute('aria-expanded', 'false');
        userMenuBtn.focus();
      }
    });
  }

  document.addEventListener('click', function (e) {
    const a = e.target.closest('a[href="logout.php"]');
    if (!a) return;
    e.preventDefault();
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = 'logout.php';
    const i = document.createElement('input');
    i.type = 'hidden';
    i.name = 'csrf_token';
    i.value = csrfMeta ? csrfMeta.content : '';
    f.appendChild(i);
    document.body.appendChild(f);
    f.submit();
  });

  // --- Notification dropdown ---
  const notifBtn = document.getElementById('notifBtn');
  const notifDropdown = document.getElementById('notifDropdown');

  if (notifBtn && notifDropdown) {
    notifBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      notifDropdown.classList.toggle('show');
      const pd = document.getElementById('profileDropdown');
      if (pd) pd.classList.remove('show');
      const umb = document.getElementById('userMenuBtn');
      if (umb) {
        umb.classList.remove('is-open');
        umb.setAttribute('aria-expanded', 'false');
      }
      if (userMenuWrap) {
        userMenuWrap.classList.remove('open');
        if (userMenuBtn) userMenuBtn.setAttribute('aria-expanded', 'false');
      }
      if (notifDropdown.classList.contains('show')) {
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        fetch('mark_notifications_read.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
          body: 'csrf_token=' + encodeURIComponent(csrfMeta ? csrfMeta.content : '')
        })
          .then(() => {
            const badge = document.getElementById('notifCountBadge');
            if (badge) badge.style.display = 'none';
          })
          .catch(() => {});
      }
    });

    document.addEventListener('click', function (e) {
      if (!notifDropdown.contains(e.target) && !notifBtn.contains(e.target)) {
        notifDropdown.classList.remove('show');
      }
    });

    let lastNotifId = 0;
    const firstItem = notifDropdown.querySelector('.notif-item[data-notification-id]');
    if (firstItem) {
      lastNotifId = parseInt(firstItem.getAttribute('data-notification-id'), 10) || 0;
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text == null ? '' : String(text);
      return div.innerHTML;
    }

    function timeAgoFromTimestamp(timestamp) {
      const now = Math.floor(Date.now() / 1000);
      const diff = now - timestamp;
      if (diff < 60) return 'Just now';
      if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
      if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
      if (diff < 2592000) return Math.floor(diff / 86400) + ' days ago';
      return Math.floor(diff / 2592000) + ' months ago';
    }

    // shortcut: notification polling removed; real-time handled by websocket_client.js.
    // Existing dropdown UI preserved; WebSocket events update badge and prepend items.
  }

  // --- Data-confirm links ---
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      const msg = this.getAttribute('data-confirm') || 'Are you sure?';
      if (!confirm(msg)) e.preventDefault();
    });
  });

  // --- Auto-dismiss alerts ---
  document.querySelectorAll('.alert').forEach(function (alert) {
    setTimeout(function () {
      alert.style.transition = 'opacity 0.5s';
      alert.style.opacity = '0';
      setTimeout(function () { alert.remove(); }, 500);
    }, 5000);
  });
});