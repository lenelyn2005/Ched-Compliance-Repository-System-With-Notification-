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

  // --- Logout confirmation (shared by all roles) ---
  // The logout link only opens the modal; the POST happens from the modal form.
  const logoutModal  = document.getElementById('logoutConfirmModal');
  const logoutForm   = document.getElementById('logoutConfirmForm');
  const logoutCancel = document.getElementById('logoutCancelBtn');
  let lastLogoutTrigger = null;

  function closeLogoutModal() {
    if (!logoutModal) return;
    logoutModal.classList.remove('show');
    logoutModal.setAttribute('aria-hidden', 'true');
    if (lastLogoutTrigger && document.contains(lastLogoutTrigger)) lastLogoutTrigger.focus();
    lastLogoutTrigger = null;
  }

  document.addEventListener('click', function (e) {
    const a = e.target.closest('a[href="logout.php"]');
    if (!a || !logoutModal || !logoutForm) return;
    e.preventDefault();
    if (logoutModal.classList.contains('show')) return; // ignore repeat clicks
    lastLogoutTrigger = a;
    logoutModal.classList.add('show');
    logoutModal.setAttribute('aria-hidden', 'false');
    (logoutCancel || logoutForm.querySelector('button')).focus();
  });

  if (logoutModal && logoutForm) {
    if (logoutCancel) logoutCancel.addEventListener('click', closeLogoutModal);

    // Backdrop clicks and Cancel only dismiss; they never submit the form.
    logoutModal.addEventListener('click', function (e) {
      if (e.target === logoutModal) closeLogoutModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && logoutModal.classList.contains('show')) closeLogoutModal();
    });

    // Guard against double submissions.
    logoutForm.addEventListener('submit', function () {
      const btn = document.getElementById('logoutConfirmBtn');
      if (btn) { btn.disabled = true; btn.textContent = 'Logging out…'; }
    });
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