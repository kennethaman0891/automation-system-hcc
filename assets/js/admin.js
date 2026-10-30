/* ============================================================
   HCC Admin — Core Navigation & Global Interactions
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {

  /* ── 1. Sidebar Toggle & Mobile Off-Canvas Drawer ── */
  const sidebarToggles = Array.from(document.querySelectorAll('#sidebarToggle, .js-sidebar-toggle'));
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const layout = document.querySelector('.admin-layout');

  const syncToggles = (expanded) => {
    sidebarToggles.forEach((b) => b.setAttribute('aria-expanded', expanded ? 'true' : 'false'));
  };

  if (sidebarToggles.length && sidebar) {
    const isMobile = () => window.innerWidth <= 1024;
    const setSidebar = (open) => {
      sidebar.classList.toggle('open', open);
      if (overlay) overlay.classList.toggle('active', open);
      syncToggles(open);
    };

    sidebarToggles.forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (isMobile()) {
          setSidebar(!sidebar.classList.contains('open'));
        } else if (layout) {
          const collapsed = layout.classList.toggle('sidebar-collapsed');
          syncToggles(!collapsed);
        }
      });
    });

    const closeSidebar = () => setSidebar(false);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    document.addEventListener('click', (e) => {
      if (isMobile() && sidebar.classList.contains('open') && !sidebar.contains(e.target) && !sidebarToggles.some((b) => b.contains(e.target)) && e.target !== overlay) {
        closeSidebar();
      }
    });

    window.addEventListener('resize', () => {
      if (!isMobile() && sidebar.classList.contains('open')) closeSidebar();
    });

    sidebar.querySelectorAll('.sidebar-nav a').forEach((a) => {
      a.addEventListener('click', () => {
        if (isMobile()) closeSidebar();
      });
    });
  }

  /* ── 2. Global Submit Confirmation Delegation (data-confirm) ── */
  document.addEventListener('submit', (e) => {
    const msg = e.target.getAttribute('data-confirm');
    if (msg && !confirm(msg)) e.preventDefault();
  });

  /* ── 3. Global Print & Download Action Delegation ── */
  document.addEventListener('click', (e) => {
    const printBtn = e.target.closest('[data-action="print"]');
    if (printBtn) {
      window.print();
      return;
    }

    const downloadBtn = e.target.closest('[data-action="download-pdf"]');
    if (downloadBtn) {
      const name = downloadBtn.dataset.name || 'document';
      const filename = name.trim().replace(/[^a-zA-Z0-9_\-]/g, '').replace(/_+/g, '_');
      const prevTitle = document.title;
      document.title = filename;
      window.print();
      setTimeout(() => { document.title = prevTitle; }, 1000);
      return;
    }
  });

  /* ── 4. Global Alert Dismissal Helper ── */
  document.querySelectorAll('.alert-dismissible').forEach((alert) => {
    const closeBtn = alert.querySelector('.btn-close, .alert-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        alert.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-4px)';
        setTimeout(() => alert.remove(), 200);
      });
    }
  });

  /* ── 5. Global Notification Bell Dropdown ── */
  const notifTrigger = document.getElementById('notifTrigger');
  const notifDropdown = document.getElementById('notifDropdown');
  const notifBadge = document.getElementById('notifBadge');
  if (notifTrigger && notifDropdown) {
    notifTrigger.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = notifDropdown.classList.contains('show');
      notifDropdown.classList.toggle('show');
      if (!isOpen && notifBadge && notifBadge.style.display !== 'none') {
        const csrfEl = document.querySelector('input[name="csrf_token"]') || document.getElementById('csrfToken');
        const csrfToken = csrfEl ? csrfEl.value : '';
        const fd = new FormData();
        fd.append('action', 'mark_notifs_read');
        if (csrfToken) fd.append('csrf_token', csrfToken);
        fetch(window.location.pathname, { method: 'POST', body: fd })
          .then(() => { notifBadge.style.display = 'none'; })
          .catch(() => {});
      }
    });

    document.addEventListener('click', (e) => {
      if (!notifDropdown.contains(e.target) && e.target !== notifTrigger) {
        notifDropdown.classList.remove('show');
      }
    });
  }

});
