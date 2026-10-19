/* Keep the active module visible inside desktop-style scrolling tab strips.
 * Safe enhancement: navigation remains ordinary links without JavaScript. */
(() => {
  'use strict';

  const keepCurrentModuleVisible = () => {
    document.querySelectorAll(
      '.app-root .sales-module-tabs, .app-root .finance-module-tabs, .app-root .settings-main-tabs'
    ).forEach(tabs => {
      const active = tabs.querySelector('a.active');
      if (!active) return;

      active.setAttribute('aria-current', 'page');
      if (tabs.scrollWidth <= tabs.clientWidth + 2) return;

      const strip = tabs.getBoundingClientRect();
      const item = active.getBoundingClientRect();
      const inset = 6;

      if (item.left < strip.left + inset) {
        tabs.scrollLeft -= (strip.left + inset) - item.left;
      } else if (item.right > strip.right - inset) {
        tabs.scrollLeft += item.right - (strip.right - inset);
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', keepCurrentModuleVisible, { once: true });
  } else {
    keepCurrentModuleVisible();
  }
})();
