/* Pellissari Notification 0.1.2 | MIT | No third-party requests or dependencies. */
(() => {
  'use strict';
  if (window.__pellissariNotificationLoaded) return;
  window.__pellissariNotificationLoaded = true;
  const source = document.currentScript?.src || [...document.scripts].find(s => /\/pellissarinotification\/(?:public\/)?js\/pellissarinotification\.js/.test(s.src))?.src;
  if (!source) return;
  const match = new URL(source, location.href).pathname.match(/^(.*?)\/(?:plugins|marketplace)\/pellissarinotification\//);
  if (!match) return;
  const base = `${match[1]}/plugins/pellissarinotification`;
  const types = {
    group_assigned: 'Atribuição ao grupo', user_assigned: 'Atribuição a pessoa',
    status_changed: 'Mudança de status', requester_comment: 'Comentário do solicitante'
  };
  const icons = {
    bell: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg>',
    check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>',
    arrow: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>'
  };
  const state = { open: false, unread: false, type: '', cursor: null, loading: false, historyGeneration: 0,
    context: '', user: 0, poll: 10, duration: 10, running: false, stopped: false, errors: 0,
    timer: null, badge: '', queue: Promise.resolve() };
  let root, bell, badge, panel, list, more, notice, toastArea, refresh, statusLine, configLink, readAll;
  const make = (tag, cls = '', text = '') => {
    const node = document.createElement(tag); node.className = cls;
    if (text !== '') node.textContent = text;
    return node;
  };
  const button = (label, cls = 'pn-btn pn-btn-quiet', action) => {
    const node = make('button', cls, label); node.type = 'button';
    if (action) node.addEventListener('click', action);
    return node;
  };
  const iconButton = (name, label, action) => {
    const node = button('', 'pn-icon-button', action);
    node.innerHTML = icons[name]; // Static SVG constant only. Never insert server/user content as HTML.
    node.setAttribute('aria-label', label); node.title = label;
    return node;
  };
  const niceDate = iso => {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('pt-BR', {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'});
  };
  async function request(path, options = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(`${base}/${path}`, {credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json'}, ...options, signal: controller.signal});
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        const error = new Error('Sessão encerrada ou resposta inesperada. Atualize a página.');
        error.status = [401, 403, 404].includes(response.status) ? response.status : 401;
        throw error;
      }
      const data = await response.json();
      if (!response.ok) { const error = new Error(data.error || 'Não foi possível concluir a operação.'); error.status = response.status; throw error; }
      return data;
    } finally { clearTimeout(timer); }
  }
  function post(payload) {
    // Serialize mutations and fetch a fresh native token only on demand (not on every poll).
    const work = async () => {
      const token = await request('Token');
      return request('Action', {method: 'POST', body: new URLSearchParams({
        ...payload, _glpi_csrf_token: token.csrf, _pellissarinotification_csrf: token.plugin_csrf
      })});
    };
    state.queue = state.queue.then(work, work);
    return state.queue;
  }
  function showNotice(message, error = false) {
    notice.textContent = message; notice.hidden = !message;
    notice.classList.toggle('pn-error-text', error);
  }
  function toggle(open = !state.open) {
    state.open = open; panel.hidden = !open;
    bell.setAttribute('aria-expanded', String(open));
    if (open) { loadHistory(false); panel.querySelector('h2').focus(); }
    else { bell.focus(); }
  }
  function build() {
    root = make('div', 'pn-root'); root.id = 'pellissarinotification-root';
    bell = button('', 'pn-bell', () => toggle());
    bell.innerHTML = icons.bell;
    bell.setAttribute('aria-label', 'Abrir central de notificações');
    bell.setAttribute('aria-controls', 'pn-panel'); bell.setAttribute('aria-expanded', 'false');
    bell.title = 'Pellissari Notification - Notificações';
    badge = make('span', 'pn-badge'); badge.hidden = true; bell.append(badge);
    panel = make('section', 'pn-panel'); panel.id = 'pn-panel'; panel.hidden = true;
    panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Central de notificações');
    const head = make('header', 'pn-panel-head');
    const heading = make('div'); heading.append(make('div', 'pn-eyebrow', 'PELLISSARI NOTIFICATION'));
    const title = make('h2', '', 'Suas notificações'); title.tabIndex = -1; heading.append(title);
    head.append(heading, iconButton('close', 'Fechar central', () => toggle(false)));
    const tools = make('div', 'pn-panel-tools');
    const tabs = make('div', 'pn-tabs');
    for (const [text, unread] of [['Todas', false], ['Não lidas', true]]) {
      const tab = button(text, `pn-tab${unread ? '' : ' is-active'}`, () => {
        state.unread = unread;
        tabs.querySelectorAll('button').forEach(b => { b.classList.toggle('is-active', b === tab); b.setAttribute('aria-pressed', String(b === tab)); });
        loadHistory(false);
      });
      tab.setAttribute('aria-pressed', String(!unread)); tabs.append(tab);
    }
    refresh = button('Atualizar', 'pn-text-button', () => loadHistory(false));
    tools.append(tabs, refresh);
    const filter = make('select', 'pn-type-filter'); filter.setAttribute('aria-label', 'Filtrar por tipo de notificação');
    const all = make('option', '', 'Todos os tipos'); all.value = ''; filter.append(all);
    for (const [value, label] of Object.entries(types)) { const option = make('option', '', label); option.value = value; filter.append(option); }
    filter.addEventListener('change', () => { state.type = filter.value; loadHistory(false); });
    notice = make('p', 'pn-notice'); notice.hidden = true; notice.setAttribute('role', 'status');
    list = make('div', 'pn-notification-list');
    more = button('Carregar mais antigas', 'pn-btn pn-load-more', () => loadHistory(true)); more.hidden = true;
    const footer = make('footer', 'pn-panel-footer');
    readAll = button('Marcar todas como lidas', 'pn-text-button', async () => {
      readAll.disabled = true;
      try {
        let next = 0, upto = 0, rounds = 0;
        do {
          const result = await post({action: 'read_all', after: next, upto});
          next = result.next; upto = result.upto; rounds++;
        } while (next !== null && rounds < 50);
        showNotice(next === null ? 'Notificações acessiveis marcadas como lidas.' : 'Parte do histórico foi atualizada. Clique novamente para continuar.');
        await loadHistory(false); tick();
      } catch (e) { showNotice(e.message, true); }
      finally { readAll.disabled = false; }
    });
    configLink = make('a', 'pn-text-button', 'Configurar'); configLink.hidden = true;
    statusLine = make('div', 'pn-connection', 'Conectando...');
    footer.append(readAll, configLink);
    panel.append(head, tools, filter, notice, list, more, footer, statusLine);
    toastArea = make('div', 'pn-toast-area'); toastArea.setAttribute('aria-live', 'polite'); toastArea.setAttribute('aria-relevant', 'additions');
    root.append(bell, panel, toastArea); document.body.append(root);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && state.open) toggle(false); });
    document.addEventListener('click', e => { if (state.open && !panel.contains(e.target) && !bell.contains(e.target) && !toastArea.contains(e.target)) toggle(false); });
  }
  async function openItem(item) {
    try {
      await post({action: 'read', id: item.id});
      if (item.url) {
        const url = new URL(item.url, location.origin);
        if (url.origin === location.origin) location.assign(url.href);
      } else {
        if (state.open) loadHistory(false);
        tick();
      }
    } catch (e) { showNotice(e.message, true); if (!state.open) toggle(true); }
  }
  function card(item, toast = false) {
    const node = make('article', `pn-notification${toast ? ' pn-toast' : ''}${item.read ? ' is-read' : ''}`);
    node.dataset.id = item.id;
    if (/^#[0-9a-f]{6}$/i.test(item.color)) node.style.setProperty('--pn-event-color', item.color);
    const line = make('div', 'pn-notification-top');
    const tag = make('span', 'pn-event-label', item.title); line.append(tag);
    if (toast) line.append(iconButton('close', 'Fechar aviso sem marcar como lido', () => node.remove()));
    else if (!item.read) line.append(make('span', 'pn-unread-dot'));
    const subject = make('strong', 'pn-ticket-title', `${item.ticket_id ? '#' + item.ticket_id + ' - ' : ''}${item.ticket_title}`);
    const message = make('p', 'pn-notification-message', item.message);
    const bottom = make('div', 'pn-notification-bottom');
    const time = make('time', '', niceDate(item.date)); time.dateTime = item.date; bottom.append(time);
    const actions = make('div', 'pn-notification-actions');
    if (!item.read) actions.append(iconButton('check', 'Marcar como lida', async () => {
      try { await post({action: 'read', id: item.id}); if (toast) node.remove(); if (state.open) loadHistory(false); tick(); }
      catch (e) { showNotice(e.message, true); }
    }));
    if (item.url) actions.append(button('Abrir chamado', 'pn-open-ticket', () => openItem(item)));
    bottom.append(actions); node.append(line, subject, message, bottom);
    return node;
  }
  function showToast(item) {
    if (toastArea.querySelector(`[data-id="${Number(item.id)}"]`)) return;
    while (toastArea.children.length >= 3) toastArea.firstElementChild.remove();
    const node = card(item, true); toastArea.append(node);
    let timer;
    const stop = () => clearTimeout(timer);
    const start = () => { stop(); if (!node.matches(':hover') && !node.contains(document.activeElement)) timer = setTimeout(() => node.remove(), state.duration * 1000); };
    node.addEventListener('mouseenter', stop); node.addEventListener('mouseleave', start);
    node.addEventListener('focusin', stop); node.addEventListener('focusout', () => setTimeout(start, 0)); start();
  }
  async function loadHistory(append) {
    if (append && state.loading) return;
    const generation = ++state.historyGeneration;
    state.loading = true; more.disabled = true; refresh.disabled = true;
    if (!append) { state.cursor = null; list.replaceChildren(make('p', 'pn-empty', 'Carregando notificações...')); }
    try {
      const query = new URLSearchParams({type: state.type, unread: state.unread ? '1' : '0'});
      if (append && state.cursor) query.set('before', state.cursor);
      const data = await request(`History?${query}`);
      if (generation !== state.historyGeneration) return;
      if (!append) list.replaceChildren();
      data.items.forEach(item => list.append(card(item)));
      state.cursor = data.next_before; more.hidden = !state.cursor;
      if (!list.children.length) {
        const empty = make('div', 'pn-empty');
        const symbol = make('div', 'pn-empty-symbol'); symbol.innerHTML = icons.check;
        empty.append(symbol, make('strong', '', 'Tudo em dia por aqui'), make('p', '', 'Nenhuma notificação disponível neste filtro e nas entidades ativas.'));
        list.append(empty);
      }
    } catch (e) {
      if (generation === state.historyGeneration) { if (!append) list.replaceChildren(); showNotice(e.message, true); }
    } finally {
      if (generation === state.historyGeneration) { state.loading = false; more.disabled = false; refresh.disabled = false; }
    }
  }
  async function tick() {
    clearTimeout(state.timer);
    if (state.running || state.stopped) return;
    state.running = true;
    try {
      const data = await request('Feed');
      root.hidden = false; state.errors = 0;
      if (state.context && state.context !== data.context) {
        toastArea.replaceChildren(); list.replaceChildren(); state.historyGeneration++;
        if (state.open) loadHistory(false);
      }
      state.context = data.context; state.user = data.user_id;
      state.poll = Math.max(5, Math.min(120, Number(data.poll_interval) || 10));
      state.duration = Math.max(5, Math.min(60, Number(data.toast_duration) || 10));
      const label = data.unread.count > 0 ? `${data.unread.count}${data.unread.partial ? '+' : ''}` : data.unread.partial ? '?' : '';
      badge.textContent = label; badge.hidden = !label;
      bell.setAttribute('aria-label', label ? `Notificações: ${label} não lidas` : 'Abrir central de notificações');
      if (state.open && state.badge !== label && state.badge !== '') showNotice('Histórico atualizado. Use Atualizar para ver as mudanças.');
      state.badge = label;
      configLink.hidden = !data.admin; configLink.href = data.settings_url;
      statusLine.textContent = data.enabled ? `Conectado - consulta a cada ${state.poll}s` : 'Avisos reais pausados pelo administrador';
      statusLine.classList.remove('pn-error-text');
      if (!document.hidden && data.pending?.length && toastArea.children.length < 3) {
        const ids = data.pending.slice(0, 3 - toastArea.children.length).map(item => item.id).join(',');
        const claimed = await post({action: 'claim', ids});
        // Never show content after changing user/entity context in another tab; server rechecked ACLs at claim.
        if (!document.hidden) claimed.items.forEach(showToast);
      }
    } catch (e) {
      state.errors++; statusLine.textContent = e.message; statusLine.classList.add('pn-error-text');
      if ([401, 403, 404].includes(e.status)) { state.stopped = true; toastArea.replaceChildren(); list.replaceChildren(); badge.hidden = true; }
    } finally {
      state.running = false;
      if (!state.stopped) {
        const interval = state.errors ? Math.min(120, state.poll * (2 ** Math.min(state.errors, 4))) : document.hidden ? Math.max(30, state.poll) : state.poll;
        state.timer = setTimeout(tick, interval * 1000 + Math.random() * 500);
      }
    }
  }
  function bindAdmin() {
    const admin = document.querySelector('#pn-admin'); if (!admin) return;
    const feedback = admin.querySelector('#pn-admin-message');
    function message(text, error = false) { feedback.textContent = text; feedback.className = `pn-banner ${error ? 'pn-error' : 'pn-success'}`; }
    admin.querySelectorAll('input[type="color"]').forEach(input => {
      const type = input.name.replace(/^color_/, '');
      input.addEventListener('input', () => {
        admin.querySelector(`[data-hex="${type}"]`).textContent = input.value.toUpperCase();
        admin.querySelector(`[data-dot="${type}"]`).style.backgroundColor = input.value;
      });
    });
    admin.querySelectorAll('.pn-test').forEach(test => test.addEventListener('click', async () => {
      test.disabled = true;
      try {
        const data = await post({action: 'test', type: test.dataset.type, color: admin.querySelector(`[name="color_${test.dataset.type}"]`).value});
        showToast(data.item); message('Teste enviado somente para você. Nenhum chamado real foi alterado.');
        if (state.open) loadHistory(false); tick();
      } catch (e) { message(e.message, true); }
      finally { test.disabled = false; }
    }));
    const search = admin.querySelector('#pn-user-search');
    const results = admin.querySelector('#pn-user-results');
    const ruleBox = admin.querySelector('#pn-user-rules');
    const label = admin.querySelector('#pn-user-label');
    const save = admin.querySelector('#pn-user-save');
    let selectedUser = 0, searchTimer, searchVersion = 0, rulesVersion = 0;
    search.addEventListener('input', () => {
      clearTimeout(searchTimer); const version = ++searchVersion; rulesVersion++;
      selectedUser = 0; ruleBox.hidden = true; results.hidden = true; label.textContent = 'Nenhum usuário selecionado.';
      if (search.value.trim().length < 2) return;
      searchTimer = setTimeout(async () => {
        try {
          const data = await request(`Users?q=${encodeURIComponent(search.value.trim())}`);
          if (version !== searchVersion) return;
          results.replaceChildren();
          const placeholder = make('option', '', data.items.length ? 'Selecione um usuário' : 'Nenhum usuário encontrado'); placeholder.value = ''; results.append(placeholder);
          data.items.forEach(item => { const option = make('option', '', item.label); option.value = item.id; results.append(option); });
          results.hidden = false;
        } catch (e) { message(e.message, true); }
      }, 350);
    });
    results.addEventListener('change', async () => {
      const user = Number(results.value); const version = ++rulesVersion; selectedUser = 0; ruleBox.hidden = true;
      if (!user) return;
      try {
        const data = await request(`UserRules?user_id=${user}`);
        if (version !== rulesVersion) return;
        selectedUser = user; label.textContent = results.selectedOptions[0].textContent;
        admin.querySelectorAll('[data-user-rule]').forEach(select => { select.value = String(data.rules[select.dataset.userRule]); });
        ruleBox.hidden = false;
      } catch (e) { message(e.message, true); }
    });
    save.addEventListener('click', async () => {
      if (!selectedUser) return; save.disabled = true;
      const payload = {action: 'user_rules', user_id: selectedUser};
      admin.querySelectorAll('[data-user-rule]').forEach(select => { payload[select.dataset.userRule] = select.value; });
      try { await post(payload); message('Regras individuais salvas. Aplicação nos próximos eventos.'); }
      catch (e) { message(e.message, true); }
      finally { save.disabled = false; }
    });
  }
  const start = () => {
    build(); bindAdmin(); tick();
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    window.addEventListener('online', tick);
    window.addEventListener('pageshow', event => { if (event.persisted) { toastArea.replaceChildren(); list.replaceChildren(); state.stopped = false; tick(); } });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
})();
