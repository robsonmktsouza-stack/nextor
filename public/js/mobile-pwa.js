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

  const app = document.getElementById('appRoot');
  if (!app || !app.classList.contains('app-root')) return;

  const menuButton = app.querySelector('[data-sidebar-toggle]');
  const overlay = document.getElementById('mobileOverlay');
  const sidebar = document.getElementById('sidebar');
  const bottomMenu = app.querySelector('[data-mobile-more]');
  const isMobile = () => window.matchMedia('(max-width: 760px)').matches;
  const syncMenu = () => {
    const open = isMobile() && app.classList.contains('mobile-menu-open');
    if (menuButton) {
      menuButton.setAttribute('aria-expanded', String(open));
      menuButton.setAttribute('aria-controls', 'sidebar');
    }
    if (bottomMenu) bottomMenu.setAttribute('aria-expanded', String(open));
    if (sidebar) sidebar.setAttribute('aria-label', 'Menu principal');
  };
  const closeMenu = () => {
    app.classList.remove('mobile-menu-open');
    if (overlay) overlay.hidden = true;
    syncMenu();
  };
  menuButton?.addEventListener('click', () => queueMicrotask(syncMenu));
  overlay?.addEventListener('click', syncMenu);
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
