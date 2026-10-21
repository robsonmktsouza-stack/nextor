(() => {
  'use strict';

  // Mesmo padrão visual e posicionamento dos tooltips próprios do Nextor ERP.
  // Mantém o tooltip em um único componente; nenhum atributo HTML title é exibido.
  const tooltip = document.createElement('div');
  tooltip.id = 'lumeronControlTooltip';
  tooltip.className = 'nextor-tooltip';
  tooltip.setAttribute('role', 'tooltip');
  tooltip.hidden = true;
  document.body.appendChild(tooltip);

  let active = null;
  let hoverTimer = null;
  let originalDescribedBy = null;

  const stopTimer = () => {
    if (hoverTimer !== null) {
      window.clearTimeout(hoverTimer);
      hoverTimer = null;
    }
  };

  const convertNativeTitle = element => {
    if (!(element instanceof Element) || !element.hasAttribute('title')) return;
    const native = (element.getAttribute('title') || '').trim();
    if (native && !element.hasAttribute('data-tooltip')) {
      element.setAttribute('data-tooltip', native);
    }
    const hasAccessibleName = element.hasAttribute('aria-label') ||
      element.hasAttribute('aria-labelledby') || (element.textContent || '').trim();
    if (native && !hasAccessibleName &&
      element.matches('button,a,input,[role="button"],[role="switch"]')) {
      element.setAttribute('aria-label', native);
    }
    element.removeAttribute('title');
  };

  const prepare = node => {
    if (!(node instanceof Element)) return;
    if (node.hasAttribute('title')) convertNativeTitle(node);
    node.querySelectorAll('[title]').forEach(convertNativeTitle);
  };
  prepare(document.body);

  // O modal de detalhes recebe conteúdo via fetch. Títulos de novos elementos
  // também são convertidos antes de poderem produzir o balão do navegador.
  new MutationObserver(records => {
    for (const record of records) {
      if (record.type === 'attributes') {
        convertNativeTitle(record.target);
      } else {
        for (const node of record.addedNodes) prepare(node);
      }
    }
    if (active && !active.isConnected) hide();
  }).observe(document.body, {
    subtree: true, childList: true, attributes: true, attributeFilter: ['title']
  });

  function hide() {
    stopTimer();
    if (active) {
      if (originalDescribedBy === null) active.removeAttribute('aria-describedby');
      else active.setAttribute('aria-describedby', originalDescribedBy);
    }
    active = null;
    originalDescribedBy = null;
    tooltip.hidden = true;
    tooltip.classList.remove('below');
  }

  const position = element => {
    window.requestAnimationFrame(() => {
      if (active !== element || tooltip.hidden) return;
      const rect = element.getBoundingClientRect();
      const bubble = tooltip.getBoundingClientRect();
      const gap = 9;
      const padding = 9;
      let top = rect.top - bubble.height - gap;
      let below = false;
      if (top < padding) {
        top = rect.bottom + gap;
        below = true;
      }
      let left = rect.left + (rect.width - bubble.width) / 2;
      left = Math.max(padding, Math.min(left, window.innerWidth - bubble.width - padding));
      top = Math.max(padding, Math.min(top, window.innerHeight - bubble.height - padding));
      tooltip.style.left = Math.round(left) + 'px';
      tooltip.style.top = Math.round(top) + 'px';
      tooltip.classList.toggle('below', below);
    });
  };

  const show = element => {
    if (!element || !element.isConnected) return;
    convertNativeTitle(element);
    const content = (element.getAttribute('data-tooltip') || '').trim();
    if (!content || element.matches(':disabled,[aria-disabled="true"]')) return;
    stopTimer();
    if (active !== element) hide();
    active = element;
    originalDescribedBy = element.getAttribute('aria-describedby');
    const descriptions = [originalDescribedBy, tooltip.id].filter(Boolean);
    element.setAttribute('aria-describedby', descriptions.join(' '));
    tooltip.textContent = content;
    const dialog = element.closest('dialog[open]');
    const host = dialog || document.body;
    if (tooltip.parentElement !== host) host.appendChild(tooltip);
    tooltip.hidden = false;
    tooltip.classList.remove('below');
    tooltip.style.left = '0px';
    tooltip.style.top = '0px';
    position(element);
  };

  const targetFor = node => {
    if (!(node instanceof Element)) return null;
    const target = node.closest('[data-tooltip],[title]');
    if (target) convertNativeTitle(target);
    return target;
  };

  document.addEventListener('pointerover', event => {
    if (event.pointerType === 'touch') return;
    const candidate = targetFor(event.target);
    if (candidate === active) return;
    hide();
    if (!candidate) return;
    hoverTimer = window.setTimeout(() => {
      hoverTimer = null;
      if (candidate.isConnected && candidate.matches(':hover')) show(candidate);
    }, 160);
  });
  document.addEventListener('pointerout', event => {
    const leaving = targetFor(event.target);
    const entering = targetFor(event.relatedTarget);
    if (leaving === entering) return;
    if (leaving === active || hoverTimer !== null) hide();
  });
  document.addEventListener('focusin', event => {
    const candidate = targetFor(event.target);
    if (candidate) show(candidate);
  });
  document.addEventListener('focusout', event => {
    const candidate = targetFor(event.target);
    if (candidate === active && !candidate.contains(event.relatedTarget)) hide();
  });
  document.addEventListener('pointerdown', hide, true);
  document.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') hide();
  }, true);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) hide();
  });
})();
