(() => {
  'use strict';
  document.querySelectorAll('[data-fiscal-code-kind]').forEach(input => {
    const list = document.getElementById(input.getAttribute('list'));
    if (!list) return;
    let timer;
    let pending;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      if (pending) pending.abort();
      list.replaceChildren();
      const term = input.value.trim();
      if (term.length < 2) return;
      timer = setTimeout(async () => {
        pending = new AbortController();
        const url = new URL(input.dataset.fiscalCodeUrl, window.location.origin);
        url.searchParams.set('kind', input.dataset.fiscalCodeKind);
        url.searchParams.set('q', term);
        const ncm = document.querySelector('[data-fiscal-code-kind="ncm"]');
        if (input.dataset.fiscalCodeKind === 'cest' && ncm) url.searchParams.set('ncm', ncm.value);
        try {
          const response = await fetch(url, {headers: {'Accept': 'application/json'}, signal: pending.signal});
          if (!response.ok) return;
          const data = await response.json();
          if (input.value.trim() !== term) return;
          list.replaceChildren(...(data.items || []).map(item => {
            const option = document.createElement('option');
            option.value = item.code;
            option.label = item.description;
            return option;
          }));
        } catch (error) {
          // Classification entry remains available when a lookup is interrupted/offline.
          if (error.name !== 'AbortError') list.replaceChildren();
        }
      }, 250);
    });
  });
})();
