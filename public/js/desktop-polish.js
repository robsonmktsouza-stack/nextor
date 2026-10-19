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

  // Padrão desktop: a aba é o título da seção. Evitar repetir esse
  // título dentro de formulários em qualquer módulo, sem ocultar ações,
  // mensagens de validação ou avisos da SEFAZ.
  const removeRepeatedTabHeadings = () => {
    document.querySelectorAll('[role="tab"][aria-controls]').forEach(tab => {
      const id=tab.getAttribute('aria-controls');
      if(!id)return;
      const panel=document.getElementById(id);
      if(!panel || panel.getAttribute('role')!=='tabpanel')return;
      const title=tab.textContent.trim().replace(/\\s+/g,' ').toLocaleLowerCase('pt-BR');
      if(!title)return;
      panel.querySelectorAll(
        ':scope > h2,:scope > h3,:scope > .nfe-general-card > h2,'+
        ':scope > .nfe-general-card > h3,'+
        ':scope > .nfe-general-card > .nfe-section-title,'+
        ':scope > .editor-panel > .editor-panel-title'
      ).forEach(node=>{
        if(node.querySelector('a,button,input,select,textarea,[role="button"]'))return;
        if(node.textContent.trim().replace(/\\s+/g,' ').toLocaleLowerCase('pt-BR')===title){
          node.remove();
        }
      });
    });
  };
  const polish = () => {
    keepCurrentModuleVisible();
    removeRepeatedTabHeadings();
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', polish, { once: true });
  } else {
    polish();
  }
})();
