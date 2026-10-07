(() => {
  'use strict';
  const cfg = window.SearchWoo || {};
  const uuid = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`);
  const text = value => { const span = document.createElement('span'); span.textContent = value || ''; return span.innerHTML; };
  document.querySelectorAll('.swoo-search').forEach(root => {
    const form = root.querySelector('.swoo-form');
    const input = root.querySelector('.swoo-input');
    const results = root.querySelector('.swoo-results');
    const status = root.querySelector('.swoo-status');
    const eventField = root.querySelector('.swoo-event-id');
    let timer = 0, controller = null, requestNo = 0, active = -1, composing = false, eventId = '';
    const begin = () => { if (!eventId) { eventId = uuid(); eventField.value = eventId; } };
    const close = () => { results.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; };
    const open = () => { results.hidden = false; input.setAttribute('aria-expanded', 'true'); };
    const select = index => {
      const options = [...results.querySelectorAll('[role="option"]')];
      options.forEach((item, i) => item.classList.toggle('is-active', i === index));
      active = options.length ? (index + options.length) % options.length : -1;
      if (options[active]) { options.forEach((item, i) => item.classList.toggle('is-active', i === active)); options[active].scrollIntoView({block:'nearest'}); }
    };
    const render = payload => {
      const data = payload && payload.data ? payload.data : {items:[], total:0};
      const items = data.items || [];
      if (!items.length) {
        results.innerHTML = `<div class="swoo-message">${text(cfg.noResults)}</div>`;
        status.textContent = cfg.noResults;
        open(); return;
      }
      const note = data.approximate ? '<div class="swoo-approx">Mostrando coincidencias aproximadas</div>' : '';
      results.innerHTML = note + `<div class="swoo-results-list">${items.map(item => `<a class="swoo-result" role="option" href="${text(item.url)}" data-product="${Number(item.id)}"><span class="swoo-result-main">${item.image ? `<img src="${text(item.image)}" alt="" loading="lazy">` : ''}<span><span class="swoo-result-title">${text(item.title)}</span>${item.categories ? `<small>${text(item.categories)}</small>` : ''}${item.description ? `<small>${text(item.description)}</small>` : ''}${item.sku ? `<small>SKU: ${text(item.sku)}</small>` : ''}</span></span>${item.price ? `<span class="swoo-price">${item.price}</span>` : ''}</a>`).join('')}</div>` + `<button class="swoo-see-all" type="button">${text(cfg.seeAll)}</button>`;
      results.querySelectorAll('.swoo-result').forEach(link => link.addEventListener('click', () => {
        const body = new URLSearchParams({event:eventId, product:link.dataset.product});
        if (navigator.sendBeacon) navigator.sendBeacon(cfg.clickEndpoint, body); else fetch(cfg.clickEndpoint, {method:'POST', body, keepalive:true});
      }));
      results.querySelector('.swoo-see-all').addEventListener('click', () => form.requestSubmit());
      status.textContent = `${data.total} resultados disponibles`;
      active = -1; open();
    };
    const run = async () => {
      const term = input.value.trim();
      if (term.length < Number(cfg.minChars || 3)) { close(); return; }
      begin(); controller?.abort(); controller = new AbortController(); const current = ++requestNo; root.classList.add('is-loading');
      try {
        const url = new URL(cfg.endpoint, location.href); url.searchParams.set('term', term); url.searchParams.set('event', eventId);
        const response = await fetch(url, {signal:controller.signal, credentials:'same-origin'}); if (!response.ok) throw new Error('network');
        const payload = await response.json(); if (current === requestNo) render(payload);
      } catch (error) {
        if (error.name !== 'AbortError' && current === requestNo) { results.innerHTML = `<div class="swoo-message swoo-error">${text(cfg.networkError)}</div>`; status.textContent = cfg.networkError; open(); }
      } finally { if (current === requestNo) root.classList.remove('is-loading'); }
    };
    input.addEventListener('focus', begin);
    input.addEventListener('compositionstart', () => composing = true);
    input.addEventListener('compositionend', () => { composing = false; clearTimeout(timer); timer = setTimeout(run, Number(cfg.debounce || 250)); });
    input.addEventListener('input', () => { if (composing) return; clearTimeout(timer); timer = setTimeout(run, Number(cfg.debounce || 250)); });
    input.addEventListener('keydown', event => {
      const options = [...results.querySelectorAll('[role="option"]')];
      if (event.key === 'ArrowDown' && options.length) { event.preventDefault(); select(active + 1); }
      else if (event.key === 'ArrowUp' && options.length) { event.preventDefault(); select(active - 1); }
      else if (event.key === 'Escape') close();
      else if (event.key === 'Enter' && active >= 0 && options[active]) { event.preventDefault(); options[active].click(); }
    });
    form.addEventListener('submit', () => { begin(); eventField.value = eventId; });
    document.addEventListener('pointerdown', event => { if (!root.contains(event.target)) close(); });
  });
})();
