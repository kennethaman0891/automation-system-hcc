/**
 * HCC WhatsApp — editable template cards for the composer.
 * Custom element: <wa-template-library>
 */
(function () {
  const css = `
    :host { position: fixed; inset: 0; z-index: 10050; display: none; align-items: stretch; justify-content: flex-end; background: rgba(11,20,26,0.45); }
    :host([open]) { display: flex; }
    .panel { width: min(420px, 100vw); height: 100%; background: #fff; box-shadow: -8px 0 32px rgba(0,0,0,0.2); display: flex; flex-direction: column; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    .hdr { padding: 14px 16px; border-bottom: 1px solid #e9edef; display: flex; align-items: center; gap: 10px; }
    .hdr h3 { margin: 0; font-size: 16px; flex: 1; color: #111b21; }
    .hdr button { border: none; background: #f0f2f5; border-radius: 18px; padding: 6px 10px; cursor: pointer; font-weight: 600; font-size: 12px; }
    .tools { padding: 10px 12px; display: flex; flex-wrap: wrap; gap: 8px; border-bottom: 1px solid #f0f2f5; }
    .tools input, .tools select { border: 1px solid #d1d7db; border-radius: 8px; padding: 7px 10px; font-size: 13px; }
    .tools input { flex: 1; min-width: 120px; }
    .list { flex: 1; overflow: auto; padding: 12px; display: flex; flex-direction: column; gap: 10px; background: #f0f2f5; }
    .card { background: #fff; border-radius: 12px; padding: 12px; box-shadow: 0 1px 3px rgba(11,20,26,0.08); border: 1px solid #e9edef; }
    .card.editing { border-color: #00a884; box-shadow: 0 0 0 2px rgba(0,168,132,0.15); }
    .card-top { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 8px; }
    .card-title { font-weight: 700; font-size: 14px; color: #111b21; flex: 1; }
    .chip { font-size: 10px; background: #e7f8f3; color: #008069; border-radius: 999px; padding: 2px 8px; font-weight: 700; text-transform: uppercase; }
    .chip.scope { background: #eef2ff; color: #3730a3; }
    .body-view { white-space: pre-wrap; font-size: 13px; line-height: 1.45; color: #3b4a54; max-height: 140px; overflow: auto; }
    .body-edit { width: 100%; min-height: 120px; border: 1px solid #d1d7db; border-radius: 8px; padding: 8px; font-size: 13px; font-family: inherit; box-sizing: border-box; resize: vertical; }
    .title-edit { width: 100%; border: 1px solid #d1d7db; border-radius: 8px; padding: 6px 8px; font-size: 13px; font-weight: 700; box-sizing: border-box; margin-bottom: 6px; }
    .actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
    .actions button { border: none; border-radius: 8px; padding: 7px 10px; font-size: 12px; font-weight: 700; cursor: pointer; }
    .btn-send { background: #00a884; color: #fff; }
    .btn-edit { background: #e9edef; color: #111b21; }
    .btn-save { background: #008069; color: #fff; }
    .btn-new { background: #0ea5e9; color: #fff; }
    .btn-danger { background: #fee2e2; color: #b91c1c; }
    .empty { text-align: center; color: #667781; padding: 40px 16px; font-size: 13px; }
    .hint { font-size: 11px; color: #8696a0; padding: 0 16px 10px; }
  `;

  class WaTemplateLibrary extends HTMLElement {
    constructor() {
      super();
      this.attachShadow({ mode: 'open' });
      this.store = window.WhatsAppStore;
      this.templates = [];
      this.editingId = null;
      this.draftBodies = {};
      this.draftTitles = {};
      this.scopeFilter = 'both';
      this.query = '';
      this.category = '';
      this.broadcastIds = [];
      // Hub mode properties
      this.hubMode = false;
      this.hubStudent = null;   // { roll, name, parent_phone, class_label, ... }
      this.hubOnSelect = null;  // callback(filledBody) -> inject into hub composer
    }

    connectedCallback() {
      this.store = window.WhatsAppStore || this.store;
      this.renderShell();
    }

    getBase() {
      return (this.store && this.store.getBaseUrl) ? this.store.getBaseUrl() : '/hcc/admin';
    }

    open(opts = {}) {
      this.broadcastIds = Array.isArray(opts.chat_ids) ? opts.chat_ids.slice() : [];
      // Hub mode
      this.hubMode = !!opts.hubMode;
      this.hubStudent = opts.student || null;
      this.hubOnSelect = opts.onSelect || null;
      if (this.hubMode) {
        this.scopeFilter = 'both';
      } else {
        const chat = this.store?.activeChat;
        if (chat?.chat_type === 'group' || chat?.chat_type === 'broadcast') this.scopeFilter = 'group';
        else if (chat) this.scopeFilter = 'personal';
        else this.scopeFilter = 'both';
      }
      this.setAttribute('open', '');
      this.load();
    }

    close() { this.removeAttribute('open'); }

    // Build template placeholder vars from a hub student row
    studentVars(st) {
      const classLabel = (st && (st.class_label || st.className || st.division || '')) || 'your class';
      const name = st && (st.name || st.student_name || 'Student');
      const today = new Date();
      const dateStr = today.getDate().toString().padStart(2,'0') + ' ' +
        ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][today.getMonth()] + ' ' +
        today.getFullYear();
      return {
        parent_name: 'Parent of ' + name,
        student_name: name,
        class: classLabel,
        date: dateStr,
        announcement: ''
      };
    }

    async api(action, payload, method) {
      const isGet = !method && (action === 'list' || action === 'get' || action === 'fill');
      // Use the real admin session only — session cookie only.
      if (isGet || method === 'GET') {
        const u = new URL(this.getBase() + '/api_whatsapp_templates.php', window.location.origin);
        u.searchParams.set('action', action);
        Object.entries(payload || {}).forEach(([k, v]) => {
          if (v !== undefined && v !== null && v !== '') u.searchParams.set(k, v);
        });
        const r = await fetch(u.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        return r.json();
      }
      let body = { action, ...(payload || {}) };
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const r = await fetch(this.getBase() + '/api_whatsapp_templates.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {})
        },
        body: JSON.stringify(body)
      });
      return r.json();
    }

    async load() {
      const listEl = this.shadowRoot.querySelector('.list');
      if (listEl) listEl.innerHTML = '<div class="empty">Loading templates…</div>';
      const res = await this.api('list', { scope: this.scopeFilter === 'both' ? '' : this.scopeFilter, q: this.query, category: this.category });
      this.templates = (res && res.success && res.data) ? res.data : [];
      this.renderList();
    }

    renderShell() {
      this.shadowRoot.innerHTML = `
        <style>${css}</style>
        <div class="panel">
          <div class="hdr">
            <h3>Message templates</h3>
            <button type="button" id="btn-new">New</button>
            <button type="button" id="btn-close">Close</button>
          </div>
          <div class="tools">
            <input id="q" type="search" placeholder="Search templates…">
            <select id="scope">
              <option value="both">All scopes</option>
              <option value="personal">Personal</option>
              <option value="group">Group</option>
            </select>
            <select id="category">
              <option value="">All categories</option>
              <option value="admission">Admission</option>
              <option value="followup">Follow-up</option>
              <option value="fees">Fees</option>
              <option value="announcement">Announcement</option>
              <option value="meeting">Meeting</option>
              <option value="exams">Exams</option>
              <option value="documents">Documents</option>
              <option value="greeting">Greeting</option>
              <option value="custom">Custom</option>
            </select>
          </div>
          <div class="hint">Select a template to fill the message composer. Placeholders like {parent_name}, {student_name}, {class}, {date} fill from the selected student. Edit & Save to customize templates.</div>
          <div class="list"></div>
        </div>
      `;
      this.shadowRoot.getElementById('btn-close').onclick = () => this.close();
      this.shadowRoot.getElementById('btn-new').onclick = () => this.createBlank();
      this.shadowRoot.getElementById('q').oninput = (e) => { this.query = e.target.value; this.load(); };
      this.shadowRoot.getElementById('scope').onchange = (e) => { this.scopeFilter = e.target.value; this.load(); };
      this.shadowRoot.getElementById('category').onchange = (e) => { this.category = e.target.value; this.load(); };
      this.shadowRoot.querySelector('.panel').addEventListener('click', (e) => e.stopPropagation());
      this.addEventListener('click', () => this.close());
    }

    async filledBody(tpl) {
      // Hub mode: fill placeholders from student data, no chat needed
      if (this.hubMode && this.hubStudent) {
        const vars = this.studentVars(this.hubStudent);
        const draft = this.draftBodies[tpl.id] != null ? this.draftBodies[tpl.id] : tpl.body;
        return this.applyVars(draft, vars);
      }
      // Replica mode: fill from active chat via API
      const chatId = this.store?.activeChatId || 0;
      const draft = this.draftBodies[tpl.id];
      if (draft != null) {
        const res = await this.api('fill', { id: tpl.id, chat_id: chatId });
        const vars = res?.data?.vars || {};
        return this.applyVars(draft, vars);
      }
      const res = await this.api('fill', { id: tpl.id, chat_id: chatId });
      return res?.data?.body || tpl.body;
    }

    applyVars(body, vars) {
      let out = String(body || '');
      Object.keys(vars || {}).forEach((k) => {
        out = out.split('{' + k + '}').join(String(vars[k] ?? ''));
      });
      return out;
    }

    renderList() {
      const listEl = this.shadowRoot.querySelector('.list');
      if (!this.templates.length) {
        listEl.innerHTML = '<div class="empty">No templates match. Create one with New.</div>';
        return;
      }
      listEl.innerHTML = this.templates.map((t) => {
        const editing = this.editingId === t.id;
        const body = this.draftBodies[t.id] != null ? this.draftBodies[t.id] : t.body;
        const title = this.draftTitles[t.id] != null ? this.draftTitles[t.id] : t.title;
        return `
          <div class="card ${editing ? 'editing' : ''}" data-id="${t.id}">
            <div class="card-top">
              ${editing
                ? `<input class="title-edit" data-act="title" value="${this.esc(title)}">`
                : `<div class="card-title">${this.esc(title)}</div>`}
              <span class="chip">${this.esc(t.category)}</span>
              <span class="chip scope">${this.esc(t.scope)}</span>
            </div>
            ${editing
              ? `<textarea class="body-edit" data-act="body">${this.esc(body)}</textarea>`
              : `<div class="body-view">${this.esc(body)}</div>`}
            <div class="actions">
              ${editing ? `
                <button type="button" class="btn-save" data-act="save-update">Save update</button>
                <button type="button" class="btn-new" data-act="save-new">Save as new</button>
                <button type="button" class="btn-edit" data-act="cancel">Cancel</button>
              ` : `
                <button type="button" class="btn-edit" data-act="edit">Edit</button>
              `}
              <button type="button" class="btn-send" data-act="send">Send</button>
              <button type="button" class="btn-edit" data-act="duplicate">Duplicate</button>
              <button type="button" class="btn-danger" data-act="delete">Delete</button>
            </div>
          </div>`;
      }).join('');

      listEl.querySelectorAll('.card').forEach((card) => {
        const id = parseInt(card.dataset.id, 10);
        card.querySelectorAll('[data-act]').forEach((el) => {
          const act = el.getAttribute('data-act');
          if (act === 'title' || act === 'body') {
            el.addEventListener('input', () => {
              if (act === 'title') this.draftTitles[id] = el.value;
              else this.draftBodies[id] = el.value;
            });
            return;
          }
          el.addEventListener('click', (e) => {
            e.preventDefault();
            this.onAction(act, id);
          });
        });
      });
    }

    esc(s) {
      return String(s ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    async onAction(act, id) {
      const tpl = this.templates.find((t) => t.id === id);
      if (!tpl && act !== 'create') return;
      if (act === 'edit') {
        this.editingId = id;
        this.draftBodies[id] = tpl.body;
        this.draftTitles[id] = tpl.title;
        this.renderList();
        return;
      }
      if (act === 'cancel') {
        this.editingId = null;
        delete this.draftBodies[id];
        delete this.draftTitles[id];
        this.renderList();
        return;
      }
      if (act === 'save-update') {
        const body = this.draftBodies[id] != null ? this.draftBodies[id] : tpl.body;
        const title = this.draftTitles[id] != null ? this.draftTitles[id] : tpl.title;
        await this.api('update', { id, body, title });
        this.editingId = null;
        delete this.draftBodies[id];
        delete this.draftTitles[id];
        await this.load();
        return;
      }
      if (act === 'save-new') {
        const body = this.draftBodies[id] != null ? this.draftBodies[id] : tpl.body;
        const title = (this.draftTitles[id] != null ? this.draftTitles[id] : tpl.title) + ' (edited)';
        await this.api('save_as_new', { id, body, title, scope: tpl.scope, category: tpl.category });
        this.editingId = null;
        delete this.draftBodies[id];
        delete this.draftTitles[id];
        await this.load();
        return;
      }
      if (act === 'duplicate') {
        await this.api('duplicate', { id });
        await this.load();
        return;
      }
      if (act === 'delete') {
        if (!confirm('Archive this template?')) return;
        await this.api('delete', { id });
        await this.load();
        return;
      }
      if (act === 'send') {
        // Hub mode: inject filled body into the hub composer instead of calling send API
        if (this.hubMode && typeof this.hubOnSelect === 'function') {
          const body = await this.filledBody(tpl);
          this.hubOnSelect(body);
          this.close();
          return;
        }
        const chatId = this.store?.activeChatId;
        const chatIds = this.broadcastIds.length ? this.broadcastIds : (chatId ? [chatId] : []);
        if (!chatIds.length) {
          alert('Open a chat (or multi-select) before sending a template.');
          return;
        }
        const body = await this.filledBody(tpl);
        const edited = this.draftBodies[id] != null && this.draftBodies[id] !== tpl.body;
        const res = await this.api('send', {
          id,
          chat_ids: chatIds,
          body,
          save_edit: edited ? 1 : 0,
          save_mode: 'update'
        });
        if (!res?.success) {
          alert(res?.error || 'Send failed');
          return;
        }
        // Refresh open conversation
        try {
          if (chatId) await this.store.loadMessages(chatId, true);
        } catch (e) {}
        this.close();
        return;
      }
    }

    async createBlank() {
      const res = await this.api('create', {
        title: 'Custom template',
        category: 'custom',
        scope: this.scopeFilter === 'both' ? 'both' : this.scopeFilter,
        body: 'Dear {parent_name},\n\nRegarding {student_name} ({class}) — \n\nDate: {date}\n\nHCC High School'
      });
      if (res?.success && res.data) {
        this.editingId = res.data.id;
        this.draftBodies[res.data.id] = res.data.body;
        this.draftTitles[res.data.id] = res.data.title;
      }
      await this.load();
    }
  }

  if (!customElements.get('wa-template-library')) {
    customElements.define('wa-template-library', WaTemplateLibrary);
  }
  window.WaTemplateLibrary = WaTemplateLibrary;
})();
