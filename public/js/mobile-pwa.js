/* Lumeron Mobile — installation and responsive progressive enhancement. */
(() => {
  'use strict';
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || /^(localhost|127\.0\.0\.1)$/.test(location.hostname))) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
        // Never interrupt authentication or ERP operations because PWA setup failed.
      });
    }, { once: true });
  }

  // Browser-native installation only. Never display a simulated install action.
  const installButton = document.querySelector('[data-pwa-install]');
  let installEvent = null;
  window.addEventListener('beforeinstallprompt', (event) => {
    if (!installButton) return;
    event.preventDefault();
    installEvent = event;
    installButton.hidden = false;
  });
  installButton?.addEventListener('click', async () => {
    if (!installEvent) return;
    const event = installEvent;
    installEvent = null;
    installButton.disabled = true;
    try {
      await event.prompt();
      await event.userChoice;
    } catch (_) {
      // If unsupported/denied, Chrome's own install controls remain available.
    } finally {
      installButton.hidden = true;
      installButton.disabled = false;
    }
  });
  window.addEventListener('appinstalled', () => {
    installEvent = null;
    if (installButton) installButton.hidden = true;
  });

  const app = document.getElementById('appRoot');
  if (!app || !app.classList.contains('app-root')) return;

  const menuButton = app.querySelector('[data-sidebar-toggle]');
  const overlay = document.getElementById('mobileOverlay');
  const sidebar = document.getElementById('sidebar');
  const bottomMenu = app.querySelector('[data-mobile-more]');
  const topMenu = app.querySelector('[data-mobile-top-menu]');
  const isMobile = () => window.matchMedia('(max-width: 760px)').matches;
  const syncMenu = () => {
    const open = isMobile() && app.classList.contains('mobile-menu-open');
    if (menuButton) {
      menuButton.setAttribute('aria-expanded', String(open));
      menuButton.setAttribute('aria-controls', 'sidebar');
    }
    if (bottomMenu) bottomMenu.setAttribute('aria-expanded', String(open));
    if (topMenu) topMenu.setAttribute('aria-expanded', String(open));
    if (sidebar) sidebar.setAttribute('aria-label', 'Menu principal');
  };
  const closeMenu = () => {
    app.classList.remove('mobile-menu-open');
    if (overlay) overlay.hidden = true;
    syncMenu();
  };
  menuButton?.addEventListener('click', () => queueMicrotask(syncMenu));
  overlay?.addEventListener('click', syncMenu);
  topMenu?.addEventListener('click', () => bottomMenu?.click());
  bottomMenu?.addEventListener('click', () => {
    if (!isMobile()) return;
    if (app.classList.contains('mobile-menu-open')) {
      closeMenu();
    } else {
      app.classList.add('mobile-menu-open');
      if (overlay) overlay.hidden = false;
      syncMenu();
      sidebar?.querySelector('a')?.focus({ preventScroll: true });
    }
  });
  sidebar?.addEventListener('click', (event) => {
    if (isMobile() && event.target.closest('a')) closeMenu();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && app.classList.contains('mobile-menu-open')) {
      closeMenu();
      (bottomMenu || menuButton)?.focus();
    }
  });
  window.addEventListener('resize', () => {
    if (!isMobile()) closeMenu();
    else syncMenu();
  }, { passive: true });
  syncMenu();

  // Only mark explicit read-only listing tables; never reflow editors, PDV,
  // fiscal grids, editable rows or tables with nested/colspan structures.
  const labelTables = (root = document) => {
    root.querySelectorAll('table[data-mobile-cards]').forEach((table) => {
      const headings = Array.from(table.querySelectorAll('thead tr:first-child > th'))
        .map((th) => th.textContent.trim().replace(/\s+/g, ' '));
      // Keep the existing bulk-select behavior available on a small screen.
      const master = table.querySelector('thead input[data-check-all]');
      const holder = table.parentElement;
      if (master && holder && !holder.querySelector('.lumeron-mobile-select-all')) {
        const label = document.createElement('label');
        label.className = 'lumeron-mobile-select-all';
        const mirror = document.createElement('input');
        mirror.type = 'checkbox';
        mirror.setAttribute('aria-label', 'Selecionar todos nesta página');
        const sync = () => {
          mirror.checked = master.checked;
          mirror.indeterminate = master.indeterminate;
        };
        sync();
        mirror.addEventListener('change', () => {
          master.checked = mirror.checked;
          master.dispatchEvent(new Event('change', { bubbles: true }));
          sync();
        });
        holder.addEventListener('change', (event) => {
          if (event.target.matches('[data-row-select], [data-check-all]')) {
            queueMicrotask(sync);
          }
        });
        label.append(mirror, document.createTextNode('Selecionar todos'));
        holder.insertBefore(label, table);
      }
      table.querySelectorAll('tbody > tr').forEach((row) => {
        const cells = Array.from(row.children).filter((cell) => cell.tagName === 'TD');
        if (cells.some((cell) => cell.colSpan > 1) || cells.length !== headings.length) {
          row.classList.add('mobile-row-regular');
          return;
        }
        row.classList.remove('mobile-row-regular');
        cells.forEach((cell, index) => {
          cell.dataset.mobileLabel = headings[index] || '';
        });
      });
    });
  };
  labelTables();

  // Live-search may replace table bodies; update only new table markup.
  const results = document.querySelectorAll('[data-live-search-results]');
  results.forEach((container) => {
    new MutationObserver(() => labelTables(container))
      .observe(container, { subtree: true, childList: true });
  });
})();
