/* ============================================================
   HCC Admin — Global Custom Confirmation Modal (window.hccConfirm)
   Replaces default browser window.confirm() across the entire admin
   ============================================================ */
window.hccConfirm = function (options) {
  return new Promise((resolve) => {
    if (typeof options === 'string') {
      options = { message: options };
    }
    options = options || {};
    const title = options.title || 'Confirm Action';
    const message = options.message || 'Are you sure you want to proceed?';
    const confirmText = options.confirmText || 'Confirm';
    const cancelText = options.cancelText || 'Cancel';
    const type = options.type || (
      options.message && /delete|remove|revert|rollback|reset|clear|discard|overwrite|logout|backup/i.test(options.message + ' ' + title)
        ? 'danger'
        : 'warning'
    );
    const icon = options.icon || (
      type === 'danger' ? 'fa-triangle-exclamation' : (type === 'info' ? 'fa-circle-info' : 'fa-circle-question')
    );

    let modal = document.getElementById('hccConfirmModal');
    if (!modal) {
      const tpl = document.createElement('div');
      tpl.innerHTML = `
        <div class="hcc-confirm-backdrop" id="hccConfirmModal" aria-hidden="true" role="alertdialog" aria-modal="true" aria-labelledby="hccConfirmTitle" aria-describedby="hccConfirmDesc">
          <div class="hcc-confirm-card">
            <button type="button" class="hcc-confirm-close" id="hccConfirmClose" aria-label="Close dialog">&times;</button>
            <div class="hcc-confirm-body">
              <div class="hcc-confirm-icon-wrap" id="hccConfirmIconWrap">
                <div class="hcc-confirm-icon" id="hccConfirmIcon">
                  <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
              </div>
              <h3 class="hcc-confirm-title" id="hccConfirmTitle">Confirm Action</h3>
              <p class="hcc-confirm-desc" id="hccConfirmDesc">Are you sure you want to proceed?</p>
              <div class="hcc-confirm-actions">
                <button type="button" class="hcc-confirm-btn-cancel" id="hccConfirmCancel">
                  <i class="fa-solid fa-xmark"></i>
                  <span id="hccConfirmCancelText">Cancel</span>
                </button>
                <button type="button" class="hcc-confirm-btn-confirm" id="hccConfirmSubmit">
                  <i class="fa-solid fa-check" id="hccConfirmSubmitIcon"></i>
                  <span id="hccConfirmSubmitText">Confirm</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      `;
      modal = tpl.firstElementChild;
      document.body.appendChild(modal);
    }

    const titleEl = document.getElementById('hccConfirmTitle');
    const descEl = document.getElementById('hccConfirmDesc');
    const cancelBtn = document.getElementById('hccConfirmCancel');
    const submitBtn = document.getElementById('hccConfirmSubmit');
    const cancelTextEl = document.getElementById('hccConfirmCancelText');
    const submitTextEl = document.getElementById('hccConfirmSubmitText');
    const closeBtn = document.getElementById('hccConfirmClose');
    const iconEl = document.getElementById('hccConfirmIcon');

    if (titleEl) titleEl.textContent = title;
    if (descEl) descEl.textContent = message;
    if (cancelTextEl) cancelTextEl.textContent = cancelText;
    if (submitTextEl) submitTextEl.textContent = confirmText;

    modal.setAttribute('data-type', type);
    if (iconEl) {
      iconEl.className = 'hcc-confirm-icon ' + type;
      iconEl.innerHTML = `<i class="fa-solid ${icon}"></i>`;
    }
    if (submitBtn) {
      submitBtn.className = 'hcc-confirm-btn-confirm ' + type;
    }

    let resolved = false;
    const cleanup = () => {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('hcc-confirm-open');
      cancelBtn.removeEventListener('click', onCancel);
      if (closeBtn) closeBtn.removeEventListener('click', onCancel);
      submitBtn.removeEventListener('click', onConfirm);
      modal.removeEventListener('click', onBackdrop);
      document.removeEventListener('keydown', onKeyDown);
    };

    const onCancel = (e) => {
      if (e) e.preventDefault();
      if (resolved) return;
      resolved = true;
      cleanup();
      resolve(false);
    };

    const onConfirm = (e) => {
      if (e) e.preventDefault();
      if (resolved) return;
      resolved = true;
      cleanup();
      resolve(true);
    };

    const onBackdrop = (e) => {
      if (e.target === modal) {
        onCancel(e);
      }
    };

    const onKeyDown = (e) => {
      if (e.key === 'Escape') {
        onCancel(e);
      }
    };

    cancelBtn.addEventListener('click', onCancel);
    if (closeBtn) closeBtn.addEventListener('click', onCancel);
    submitBtn.addEventListener('click', onConfirm);
    modal.addEventListener('click', onBackdrop);
    document.addEventListener('keydown', onKeyDown);

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('hcc-confirm-open');
    if (cancelBtn) cancelBtn.focus();
  });
};

window.hccWhatsAppLogout = function (button) {
  const confirmFn = window.hccConfirm || function (opts) {
    const message = opts && opts.message ? opts.message : 'Log out of WhatsApp?';
    return Promise.resolve(window.confirm(message));
  };
  return confirmFn({
    title: 'Log out of WhatsApp',
    message: 'This unlinks the school WhatsApp from this computer. New parent messages stop until someone scans the QR again.',
    confirmText: 'Log out',
    cancelText: 'Stay linked',
    type: 'danger'
  }).then(async (confirmed) => {
    if (!confirmed) return false;
    if (button) {
      button.dataset.busy = '1';
      button.disabled = true;
    }
    try {
      const res = await fetch('api_whatsapp_session.php?action=logout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      const data = await res.json();
      if (!data || !data.success) {
        window.alert((data && data.error) || 'Could not log out of WhatsApp.');
        return false;
      }
      const status = document.getElementById('waInboundStatus');
      if (status) {
        status.textContent = 'WhatsApp logged out. Scan the QR on the WhatsApp page to link again.';
      }
      document.querySelectorAll('#waLogoutBtn').forEach((btn) => {
        if (!btn.closest('#waLinkChip')) btn.style.display = 'none';
      });
      document.dispatchEvent(new CustomEvent('hcc:whatsapp-logout'));
      return true;
    } catch (e) {
      window.alert('Could not log out of WhatsApp.');
      return false;
    } finally {
      if (button) {
        button.disabled = false;
        delete button.dataset.busy;
      }
    }
  });
};

/* ============================================================
   HCC Admin — Core Navigation & Global Interactions
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  const waLogoutBtn = document.getElementById('waLogoutBtn');
  if (waLogoutBtn && waLogoutBtn.dataset.bound !== '1') {
    waLogoutBtn.dataset.bound = '1';
    waLogoutBtn.addEventListener('click', () => {
      window.hccWhatsAppLogout(waLogoutBtn);
    });
  }


  /* ── 1. Sidebar Toggle & Mobile Off-Canvas Drawer ── */
  const sidebarToggles = Array.from(document.querySelectorAll('#sidebarToggle, .js-sidebar-toggle'));
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const layout = document.querySelector('.admin-layout');

  const syncToggles = (expanded) => {
    sidebarToggles.forEach((b) => {
      b.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      b.setAttribute('title', expanded ? 'Collapse sidebar' : 'Expand sidebar');
    });
  };

  if (sidebarToggles.length && sidebar) {
    const isMobile = () => window.innerWidth <= 1024;
    const setSidebar = (open) => {
      sidebar.classList.toggle('open', open);
      if (overlay) overlay.classList.toggle('active', open);
      syncToggles(open);
      if (open && typeof window.hccScrollActiveSidebar === 'function') {
        window.hccScrollActiveSidebar(false);
      }
    };

    // Initialize toggle state on page load
    if (!isMobile() && layout) {
      const isCollapsed = layout.classList.contains('sidebar-collapsed');
      syncToggles(!isCollapsed);
    }

    sidebarToggles.forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (isMobile()) {
          setSidebar(!sidebar.classList.contains('open'));
        } else if (layout) {
          const collapsed = layout.classList.toggle('sidebar-collapsed');
          syncToggles(!collapsed);
          try {
            if (collapsed) {
              localStorage.setItem('hcc_sidebar_collapsed', '1');
            } else {
              localStorage.removeItem('hcc_sidebar_collapsed');
            }
          } catch (err) {}
          if (hideRailTooltip) hideRailTooltip();
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
      if (typeof window.hccScrollActiveSidebar === 'function') {
        window.hccScrollActiveSidebar(false);
      }
      if (hideRailTooltip) hideRailTooltip();
    });

    // Auto-scroll active sidebar item into view upon full document/asset load
    if (typeof window.hccScrollActiveSidebar === 'function') {
      window.hccScrollActiveSidebar(false);
    }
    window.addEventListener('load', () => {
      if (typeof window.hccScrollActiveSidebar === 'function') {
        window.hccScrollActiveSidebar(false);
      }
    });

    sidebar.querySelectorAll('.sidebar-nav a').forEach((a) => {
      a.addEventListener('click', () => {
        if (isMobile()) closeSidebar();
      });
    });

    /* ── 1.1 Floating Tooltip Engine for Collapsed Icons Rail ── */
    let railTooltip = document.getElementById('sidebarRailTooltip');
    if (!railTooltip) {
      railTooltip = document.createElement('div');
      railTooltip.id = 'sidebarRailTooltip';
      railTooltip.className = 'sidebar-rail-tooltip';
      railTooltip.setAttribute('role', 'tooltip');
      railTooltip.setAttribute('aria-hidden', 'true');
      document.body.appendChild(railTooltip);
    }

    var hideRailTooltip = () => {
      if (railTooltip) {
        railTooltip.classList.remove('visible');
        railTooltip.setAttribute('aria-hidden', 'true');
      }
    };

    var showRailTooltip = (el) => {
      if (!layout || !layout.classList.contains('sidebar-collapsed') || window.innerWidth <= 1024) {
        hideRailTooltip();
        return;
      }
      const label = el.getAttribute('data-tooltip') || el.querySelector('span')?.textContent?.trim();
      if (!label) {
        hideRailTooltip();
        return;
      }
      const badge = el.getAttribute('data-badge') || el.querySelector('.sidebar-badge')?.textContent?.trim();

      railTooltip.innerHTML = '';
      const textNode = document.createElement('span');
      textNode.textContent = label;
      railTooltip.appendChild(textNode);

      if (badge) {
        const badgeNode = document.createElement('span');
        badgeNode.className = 'sidebar-rail-tooltip-badge badge-' + badge.toLowerCase();
        badgeNode.textContent = badge;
        railTooltip.appendChild(badgeNode);
      }

      const rect = el.getBoundingClientRect();
      railTooltip.style.top = '0px';
      railTooltip.style.left = '0px';
      railTooltip.classList.add('visible');
      railTooltip.setAttribute('aria-hidden', 'false');

      const tipHeight = railTooltip.offsetHeight;
      const topPos = Math.round(rect.top + (rect.height / 2) - (tipHeight / 2));
      const leftPos = Math.round(rect.right + 10);

      railTooltip.style.top = Math.max(8, topPos) + 'px';
      railTooltip.style.left = leftPos + 'px';
    };

    sidebar.addEventListener('mouseenter', (e) => {
      const target = e.target.closest('a, button');
      if (target && sidebar.contains(target)) {
        showRailTooltip(target);
      }
    }, true);

    sidebar.addEventListener('mouseleave', (e) => {
      const target = e.target.closest('a, button');
      if (target) {
        hideRailTooltip();
      }
    }, true);

    sidebar.addEventListener('focusin', (e) => {
      const target = e.target.closest('a, button');
      if (target && sidebar.contains(target)) {
        showRailTooltip(target);
      }
    }, true);

    sidebar.addEventListener('focusout', () => {
      hideRailTooltip();
    }, true);

    sidebar.addEventListener('click', () => {
      hideRailTooltip();
    });

    window.addEventListener('scroll', hideRailTooltip, { passive: true });
  }

  /* ── 2. Custom CSS Logout Confirmation Modal Popup ── */
  const logoutModal = document.getElementById('logoutConfirmModal');
  if (logoutModal) {
    const cancelBtn = document.getElementById('logoutModalCancel');
    const closeBtn = document.getElementById('logoutModalClose');

    const openLogoutModal = () => {
      logoutModal.classList.add('is-open');
      logoutModal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('logout-modal-open');
      if (cancelBtn) cancelBtn.focus();
    };

    const closeLogoutModal = () => {
      logoutModal.classList.remove('is-open');
      logoutModal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('logout-modal-open');
    };

    if (cancelBtn) cancelBtn.addEventListener('click', closeLogoutModal);
    if (closeBtn) closeBtn.addEventListener('click', closeLogoutModal);

    logoutModal.addEventListener('click', (e) => {
      if (e.target === logoutModal) {
        closeLogoutModal();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && logoutModal.classList.contains('is-open')) {
        closeLogoutModal();
      }
    });
  }

  /* Intercept any logout link / button click to open custom CSS modal */
  document.addEventListener('click', (e) => {
    const logoutLink = e.target.closest('.js-logout-trigger, #sidebarLogoutBtn, .sidebar-logout, a[href="logout.php"], a[href$="/logout.php"]');
    if (logoutLink && !logoutLink.closest('.logout-modal-card')) {
      const modal = document.getElementById('logoutConfirmModal');
      if (modal) {
        e.preventDefault();
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('logout-modal-open');
        const cancel = document.getElementById('logoutModalCancel');
        if (cancel) cancel.focus();
      }
    }
  });

  /* ── 3. Global Submit Confirmation Delegation (data-confirm) ── */
  document.addEventListener('submit', (e) => {
    if (e.target.classList.contains('sidebar-logout-form') || e.target.classList.contains('logout-modal-form')) {
      if (e.target.querySelector('input[name="confirm"]')?.value === '1') {
        return; // confirmed submission from modal, let it proceed!
      }
      e.preventDefault();
      const modal = document.getElementById('logoutConfirmModal');
      if (modal) {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('logout-modal-open');
        const cancel = document.getElementById('logoutModalCancel');
        if (cancel) cancel.focus();
      } else {
        window.location.href = 'logout.php';
      }
      return;
    }

    if (e.target.dataset.hccConfirmed === 'true') {
      delete e.target.dataset.hccConfirmed;
      return; // verified confirmation, let it submit!
    }

    const msg = e.target.getAttribute('data-confirm');
    if (msg) {
      e.preventDefault();
      const form = e.target;
      window.hccConfirm({
        title: form.getAttribute('data-confirm-title') || 'Confirm Action',
        message: msg,
        confirmText: form.getAttribute('data-confirm-btn') || 'Proceed',
        type: /delete|remove|revert|rollback|reset|clear|overwrite/i.test(msg) ? 'danger' : 'warning'
      }).then((confirmed) => {
        if (confirmed) {
          form.dataset.hccConfirmed = 'true';
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
          } else {
            form.submit();
          }
        }
      });
      return;
    }
  });

  /* ── 4. Global Click Confirmation Delegation (data-confirm on buttons/links) ── */
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-confirm]:not(form)');
    if (trigger) {
      if (trigger.dataset.hccConfirmed === 'true') {
        delete trigger.dataset.hccConfirmed;
        return; // allow click
      }
      e.preventDefault();
      const msg = trigger.getAttribute('data-confirm');
      window.hccConfirm({
        title: trigger.getAttribute('data-confirm-title') || 'Confirm Action',
        message: msg,
        confirmText: trigger.getAttribute('data-confirm-btn') || 'Proceed',
        type: /delete|remove|revert|rollback|reset|clear|overwrite/i.test(msg) ? 'danger' : 'warning'
      }).then((confirmed) => {
        if (confirmed) {
          trigger.dataset.hccConfirmed = 'true';
          trigger.click();
        }
      });
    }
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
  if (notifTrigger && notifDropdown && !notifTrigger.dataset.notifBound) {
    notifTrigger.dataset.notifBound = '1'; // guard against double binding
    let markedRead = false;
    const setOpen = (open) => {
      notifDropdown.classList.toggle('show', open);
      notifTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    const markRead = () => {
      if (markedRead || !notifBadge) return;
      markedRead = true;
      const meta = document.querySelector('meta[name="csrf-token"]');
      const csrfEl = document.querySelector('input[name="csrf_token"]') || document.getElementById('csrfToken');
      const csrfToken = (meta && meta.content) || (csrfEl ? csrfEl.value : '');
      const fd = new FormData();
      if (csrfToken) fd.append('csrf_token', csrfToken);
      const base = (window.location.pathname.match(/^(.*\/admin\/)/) || [, ''])[1];
      fetch(base + 'api_notifications_read.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
      })
        .then((r) => r.json())
        .then((d) => {
          if (!d || !d.ok) { markedRead = false; return; }
          notifBadge.style.display = 'none';
          notifTrigger.classList.remove('has-unread');
          notifTrigger.setAttribute('aria-label', 'Notifications');
          notifDropdown.querySelectorAll('.notif-item.is-unread').forEach((el) => el.classList.remove('is-unread'));
        })
        .catch(() => { markedRead = false; });
    };

    notifTrigger.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const willOpen = !notifDropdown.classList.contains('show');
      setOpen(willOpen);
      if (willOpen) markRead();
    });
    document.addEventListener('click', (e) => {
      if (!notifDropdown.contains(e.target) && !notifTrigger.contains(e.target)) setOpen(false);
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && notifDropdown.classList.contains('show')) {
        setOpen(false);
        notifTrigger.focus();
      }
    });
  }

});
