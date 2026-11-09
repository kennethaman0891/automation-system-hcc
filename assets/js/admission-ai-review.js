(function () {
  'use strict';

  const api = 'api_admission_ai_review.php';
  let csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  let selectedChatId = null;
  let selectedDraftId = null;
  let bridgeConnected = false;
  let currentEngine = 'local';
  let engineSettings = null;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function toast(msg, type) {
    if (typeof showToast === 'function') showToast(msg, type || 'info');
    else console.log('[AIR]', type, msg);
  }

  async function call(action, payload, method) {
    const isGet = (method || (payload ? 'POST' : 'GET')) === 'GET';
    if (isGet) {
      const u = new URL(api, window.location.href);
      u.searchParams.set('action', action);
      Object.entries(payload || {}).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') u.searchParams.set(k, v);
      });
      const r = await fetch(u.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      return r.json();
    }
    const body = Object.assign({ action: action, csrf_token: csrf }, payload || {});
    const r = await fetch(api + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify(body)
    });
    return r.json();
  }

  function setAiPill(ai) {
    const el = $('#airAiStatus');
    if (!el) return;
    if (ai && ai.ollama) {
      el.className = 'air-status-pill ok';
      el.textContent = 'Local AI ready · ' + (ai.default_model || 'model');
    } else {
      el.className = 'air-status-pill bad';
      el.textContent = 'Local AI offline — start Ollama';
    }
  }

  async function refreshHealth() {
    const res = await call('health', {}, 'GET');
    if (res && res.csrf_token) csrf = res.csrf_token;
    if (res && res.ai) setAiPill(res.ai);
    if (res && res.engine_settings) applySettings(res.engine_settings);
    bridgeConnected = !!(res && res.bridge_connected);
    const b = $('#airBridgeStatus');
    if (b) {
      b.className = 'air-status-pill ' + (bridgeConnected ? 'ok' : 'warn');
      b.textContent = bridgeConnected ? 'WhatsApp linked' : 'WhatsApp not linked (Send disabled until linked)';
    }
    const inbound = $('#airInboundNote');
    if (inbound && res) {
      inbound.textContent = 'Stored inbound messages: ' + (res.inbound_message_count || 0);
    }
    return res;
  }

  async function loadChats() {
    const list = $('#airChatList');
    list.innerHTML = '<div class="air-empty">Loading chats…</div>';
    const res = await call('list_chats', { limit: 50 }, 'GET');
    if (!res || !res.success) {
      list.innerHTML = '<div class="air-empty">Could not load chats.</div>';
      return;
    }
    if (res.empty) {
      list.innerHTML = '<div class="air-empty">' + esc(res.empty_message || 'No received messages available') + '</div>';
      $('#airManualWrap').style.display = 'block';
      return;
    }
    $('#airManualWrap').style.display = 'block';
    list.innerHTML = res.data.map(c => `
      <div class="air-chat-item" data-id="${c.chat_id}">
        <input type="radio" name="air_chat" value="${c.chat_id}" ${selectedChatId === c.chat_id ? 'checked' : ''}>
        <div>
          <div class="name">${esc(c.name)}</div>
          <div class="meta">${esc(c.phone_masked)} · ${c.inbound_count} inbound · ${esc((c.last_snippet || '').slice(0, 80))}</div>
        </div>
        <div class="meta">${c.last_inbound_ts ? new Date(c.last_inbound_ts * 1000).toLocaleString() : ''}</div>
      </div>
    `).join('');
    $all('.air-chat-item', list).forEach(el => {
      el.addEventListener('click', () => {
        selectedChatId = parseInt(el.getAttribute('data-id'), 10);
        $all('.air-chat-item', list).forEach(x => x.classList.remove('active'));
        el.classList.add('active');
        const radio = el.querySelector('input');
        if (radio) radio.checked = true;
        loadMessages(selectedChatId);
      });
    });
  }

  async function loadMessages(chatId) {
    const list = $('#airMsgList');
    const hint = $('#airContextHint');
    list.innerHTML = '<div class="air-empty">Loading messages…</div>';
    const res = await call('list_messages', { chat_id: chatId, limit: 20 }, 'GET');
    if (!res || !res.success) {
      list.innerHTML = '<div class="air-empty">No messages.</div>';
      return;
    }
    if (hint) hint.textContent = res.analyze_hint || '';
    if (!res.data.length) {
      list.innerHTML = '<div class="air-empty">No received messages available in this chat.</div>';
      return;
    }
    list.innerHTML = res.data.map(m => `
      <label class="air-msg-item">
        <input type="checkbox" class="air-msg-cb" value="${m.id}" checked>
        <div>
          <div class="name">${esc((m.body || '').slice(0, 140))}</div>
          <div class="meta">${esc(m.sender_name || 'Parent')} · ${m.timestamp ? new Date(m.timestamp * 1000).toLocaleString() : ''}</div>
        </div>
        <div class="meta">#${m.id}</div>
      </label>
    `).join('');
  }

  function selectedMessageIds() {
    return $all('.air-msg-cb:checked').map(cb => parseInt(cb.value, 10)).filter(Boolean);
  }

  async function analyze() {
    const manual = ($('#airManualText') || {}).value || '';
    const ids = selectedMessageIds();
    const contextEl = $('#airContextHint');
    if (!selectedChatId && !manual.trim()) {
      toast('Select a chat with inbound messages, or paste text.', 'error');
      return;
    }
    if (selectedChatId && !ids.length && !manual.trim()) {
      toast('Tick the inbound messages to analyze, or use manual paste.', 'error');
      return;
    }
    let explained = '';
    if (manual.trim() && !ids.length) {
      explained = 'Will analyze your pasted text only (no chat identity / phone).';
    } else {
      explained = 'Will analyze chat #' + selectedChatId + ' messages: [' + ids.join(', ') + ']. Local Ollama only. Nothing is sent on WhatsApp.';
    }
    if (contextEl) contextEl.textContent = explained;
    if (currentEngine === 'api' || (($('#airEngineSelect') || {}).value === 'api')) {
      explained += '\n\nAPI DISCLOSURE: selected text will be sent to your configured HTTPS provider and may incur usage. No silent fallback to Local.';
    }
    if (!window.confirm(explained + '\n\nRun Analyze now?')) return;

    const btn = $('#airBtnAnalyze');
    btn.disabled = true;
    btn.textContent = 'Analyzing…';
    try {
      const payload = {
        chat_id: selectedChatId || 0,
        message_ids: ids,
        manual_text: ids.length ? '' : manual.trim(),
        engine: ($('#airEngineSelect') || {}).value || currentEngine || 'local'
      };
      const res = await call('analyze', payload, 'POST');
      if (!res || !res.success) {
        toast((res && res.error) || 'Analyze failed', 'error');
        return;
      }
      toast('Draft ready — review before Send', 'success');
      selectedDraftId = res.draft.id;
      await loadDrafts();
      renderDraft(res.draft);
    } catch (e) {
      toast(String(e.message || e), 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Analyze selected';
    }
  }

  async function loadDrafts() {
    const list = $('#airDraftList');
    const res = await call('list_drafts', { limit: 40 }, 'GET');
    if (!res || !res.success) {
      list.innerHTML = '<div class="air-empty">Could not load drafts.</div>';
      return;
    }
    if (!res.data.length) {
      list.innerHTML = '<div class="air-empty">No AI review drafts yet.</div>';
      return;
    }
    list.innerHTML = res.data.map(d => {
      const isSelected = selectedDraftId === d.id;
      const classLabel = (d.classification || 'admission').replace(/_/g, ' ');
      const statusLabel = (d.status || 'needs_review').replace(/_/g, ' ');
      const statusClass = d.status === 'reviewed' ? 'ok' : (d.status === 'sent' ? 'ok' : 'warn');
      const title = d.question_summary || d.parent_name || ('Draft #' + d.id);
      return `
        <div class="air-draft-item ${isSelected ? 'active' : ''}" data-id="${d.id}">
          <div class="air-draft-top">
            <span class="air-badge ${esc(d.classification)}">${esc(classLabel)}</span>
            <span class="air-status-pill air-status-micro ${statusClass}">${esc(statusLabel)}</span>
            <span class="air-draft-id">#${d.id}</span>
          </div>
          <div class="air-draft-title">${esc(title)}</div>
          <div class="air-draft-meta">
            <span><i class="fa-brands fa-whatsapp" style="color:#25d366;margin-right:4px;"></i>Chat #${d.chat_id || '—'}</span>
            <span class="air-meta-dot">•</span>
            <span><i class="fa-solid fa-microchip" style="color:#6366f1;margin-right:4px;"></i>${esc(d.model_used || 'local AI')}</span>
          </div>
        </div>
      `;
    }).join('');

    $all('.air-draft-item', list).forEach(el => {
      el.addEventListener('click', async () => {
        selectedDraftId = parseInt(el.getAttribute('data-id'), 10);
        $all('.air-draft-item', list).forEach(item => item.classList.remove('active'));
        el.classList.add('active');
        const res = await call('get_draft', { id: selectedDraftId }, 'GET');
        if (res && res.success) renderDraft(res.data);
      });
    });

    // Auto-select the first draft if none is currently selected
    if (!selectedDraftId && res.data.length > 0) {
      selectedDraftId = res.data[0].id;
      const firstEl = list.querySelector(`.air-draft-item[data-id="${selectedDraftId}"]`);
      if (firstEl) firstEl.classList.add('active');
      renderDraft(res.data[0]);
    }
  }

  function renderDraft(d) {
    selectedDraftId = d.id;
    $all('.air-draft-item').forEach(el => {
      el.classList.toggle('active', parseInt(el.getAttribute('data-id'), 10) === d.id);
    });
    $('#airDraftEditor').style.display = 'block';
    $('#airDraftEmpty').style.display = 'none';
    $('#airClassBadge').className = 'air-badge ' + d.classification;
    $('#airClassBadge').textContent = d.classification;
    $('#airDraftStatus').textContent = d.status;
    $('#airExplain').textContent = d.explanation || '';
    $('#airFParent').value = d.parent_name || '';
    $('#airFStudent').value = d.student_name || '';
    $('#airFClass').value = d.interested_class || '';
    $('#airFYear').value = d.academic_year || '';
    $('#airFAge').value = d.age_or_dob || '';
    $('#airFPhone').value = d.contact_number_masked || (d.contact_number_source === 'unknown' ? 'unknown' : '');
    $('#airFSummary').value = d.question_summary || '';
    $('#airFMissing').value = (d.missing_details || []).join('\n');
    $('#airFReply').value = d.edited_reply != null && d.edited_reply !== '' ? d.edited_reply : (d.suggested_reply || '');
    $('#airSourceMeta').textContent = 'Source chat #' + (d.chat_id || 'none') +
      ' · msgs ' + JSON.stringify(d.source_message_ids || []) +
      ' · engine ' + (d.analysis_engine || 'local') +
      (d.analysis_provider ? (' / ' + d.analysis_provider) : '') +
      (d.analysis_endpoint_host ? (' @ ' + d.analysis_endpoint_host) : '') +
      ' · model ' + (d.analysis_model || d.model_used || '') +
      ' · ' + (d.analyzed_at || '');
    const sendBtn = $('#airBtnSend');
    const reviewBtn = $('#airBtnReview');
    reviewBtn.disabled = d.classification === 'not_admission' || d.status === 'sent';
    sendBtn.disabled = d.status !== 'reviewed' || !d.chat_id || !bridgeConnected;
    sendBtn.title = !bridgeConnected ? 'WhatsApp not linked' : (d.status !== 'reviewed' ? 'Mark Reviewed first' : 'Send to bound chat');
  }

  function collectEdits() {
    return {
      id: selectedDraftId,
      parent_name: $('#airFParent').value.trim(),
      student_name: $('#airFStudent').value.trim(),
      interested_class: $('#airFClass').value.trim(),
      academic_year: $('#airFYear').value.trim(),
      age_or_dob: $('#airFAge').value.trim(),
      question_summary: $('#airFSummary').value.trim(),
      missing_details: $('#airFMissing').value.split('\n').map(s => s.trim()).filter(Boolean),
      edited_reply: $('#airFReply').value
    };
  }

  async function saveDraft() {
    if (!selectedDraftId) return;
    const res = await call('save_draft', collectEdits(), 'POST');
    if (!res || !res.success) { toast((res && res.error) || 'Save failed', 'error'); return; }
    toast('Draft saved', 'success');
    renderDraft(res.draft);
    loadDrafts();
  }

  async function markReviewed() {
    if (!selectedDraftId) return;
    const payload = { id: selectedDraftId, edited_reply: $('#airFReply').value };
    const res = await call('mark_reviewed', payload, 'POST');
    if (!res || !res.success) { toast((res && res.error) || 'Review failed', 'error'); return; }
    toast('Marked reviewed — Send is now available if WhatsApp is linked', 'success');
    renderDraft(res.draft);
    loadDrafts();
  }

  async function sendDraft() {
    if (!selectedDraftId) return;
    const body = $('#airFReply').value;
    const preview = 'Send this reply to the BOUND chat of draft #' + selectedDraftId + '?\n\n' + body.slice(0, 500);
    if (!window.confirm(preview)) return;
    const btn = $('#airBtnSend');
    btn.disabled = true;
    try {
      const res = await call('send', { id: selectedDraftId, confirm_preview: '1' }, 'POST');
      if (!res || !res.success) {
        toast((res && res.error) || 'Send failed', 'error');
        if (res && res.draft) renderDraft(res.draft);
        return;
      }
      toast(res.already_sent ? 'Already queued (idempotent)' : ('Queued #' + res.queue_id), 'success');
      renderDraft(res.draft);
      loadDrafts();
    } finally {
      // re-enable based on draft state via renderDraft
    }
  }

  async function createEnquiry() {
    if (!selectedDraftId) return;
    const res = await call('create_enquiry', {
      id: selectedDraftId,
      parent_name: $('#airFParent').value.trim(),
      student_name: $('#airFStudent').value.trim(),
      interested_class: $('#airFClass').value.trim(),
      academic_year: $('#airFYear').value.trim()
    }, 'POST');
    if (!res || !res.success) {
      if (res && res.code === 'already_linked') {
        if (window.confirm(res.error + '\nUpdate existing enquiry?')) {
          const res2 = await call('create_enquiry', Object.assign(collectEdits(), { update_existing: 1 }), 'POST');
          if (res2 && res2.success) { toast('Enquiry updated #' + res2.enquiry_id, 'success'); renderDraft(res2.draft); }
          else toast((res2 && res2.error) || 'Update failed', 'error');
        }
        return;
      }
      toast((res && res.error) || 'Create enquiry failed', 'error');
      return;
    }
    toast('Enquiry created #' + res.enquiry_id, 'success');
    renderDraft(res.draft);
  }


  function applySettings(s) {
    engineSettings = s || {};
    currentEngine = s.engine || 'local';
    const sel = $('#airEngineSelect');
    if (sel) sel.value = currentEngine;
    const prov = $('#airProvider');
    if (prov) prov.value = s.provider || 'nvidia';
    const base = $('#airBaseUrl');
    if (base) base.value = s.base_url || '';
    const model = $('#airApiModel');
    if (model) model.value = s.model || '';
    const keyInput = $('#airApiKey');
    if (keyInput) keyInput.value = ''; // never echo key
    const ks = $('#airKeyStatus');
    if (ks) {
      ks.textContent = 'API key: ' + (s.key_status || 'Not configured');
      ks.className = 'air-key-status' + (s.key_configured ? '' : ' missing');
    }
    const disc = $('#airDisclosure');
    if (disc) disc.textContent = s.disclosure || '';
    updateEngineHint();
  }

  function updateEngineHint() {
    const hint = $('#airContextHint');
    if (!hint) return;
    if (currentEngine === 'api') {
      hint.textContent = 'API engine selected: Analyze will send ONLY the selected text to the configured HTTPS provider. No silent fallback to Local.';
    }
  }

  async function saveSettings() {
    const payload = {
      engine: ($('#airEngineSelect') || {}).value || 'local',
      provider: ($('#airProvider') || {}).value || 'nvidia',
      base_url: ($('#airBaseUrl') || {}).value || '',
      model: ($('#airApiModel') || {}).value || ''
    };
    const key = (($('#airApiKey') || {}).value || '').trim();
    if (key) payload.api_key = key;
    const res = await call('save_settings', payload, 'POST');
    if (!res || !res.success) { toast((res && res.error) || 'Save failed', 'error'); return; }
    applySettings(res.settings);
    toast(res.message || 'Settings saved', 'success');
  }

  async function testConnection() {
    const payload = {
      provider: ($('#airProvider') || {}).value || 'nvidia',
      base_url: ($('#airBaseUrl') || {}).value || '',
      model: ($('#airApiModel') || {}).value || ''
    };
    const key = (($('#airApiKey') || {}).value || '').trim();
    if (key) payload.api_key = key;
    const res = await call('test_connection', payload, 'POST');
    if (!res || !res.success) { toast((res && res.error) || 'Test failed', 'error'); return; }
    toast((res.message || 'OK') + ' · host ' + (res.endpoint_host || '') + ' · ' + (res.ms || '?') + 'ms', 'success');
  }

  async function removeKey() {
    if (!window.confirm('Remove the saved API key from this server?')) return;
    const res = await call('save_settings', {
      engine: ($('#airEngineSelect') || {}).value || 'local',
      provider: ($('#airProvider') || {}).value || 'nvidia',
      base_url: ($('#airBaseUrl') || {}).value || '',
      model: ($('#airApiModel') || {}).value || '',
      remove_key: true
    }, 'POST');
    if (!res || !res.success) { toast((res && res.error) || 'Remove failed', 'error'); return; }
    applySettings(res.settings);
    toast('API key removed', 'success');
  }

  function bind() {
    const root = $('#airSection');
    if (!root) return;
    $('#airBtnRefresh').addEventListener('click', async () => { await refreshHealth(); await loadChats(); await loadDrafts(); });
    $('#airBtnAnalyze').addEventListener('click', analyze);
    $('#airBtnSave').addEventListener('click', saveDraft);
    $('#airBtnReview').addEventListener('click', markReviewed);
    $('#airBtnSend').addEventListener('click', sendDraft);
    $('#airBtnEnquiry').addEventListener('click', createEnquiry);
    const eng = $('#airEngineSelect');
    if (eng) eng.addEventListener('change', () => {
      currentEngine = eng.value;
      updateEngineHint();
      // Persist engine preference only when Save settings is clicked — no auto API call.
    });
    const tog = $('#airBtnToggleSettings');
    if (tog) tog.addEventListener('click', () => {
      const panel = $('#airSettingsPanel');
      if (panel) panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    });
    const saveBtn = $('#airBtnSaveSettings');
    if (saveBtn) saveBtn.addEventListener('click', saveSettings);
    const testBtn = $('#airBtnTestConn');
    if (testBtn) testBtn.addEventListener('click', testConnection);
    const rmBtn = $('#airBtnRemoveKey');
    if (rmBtn) rmBtn.addEventListener('click', removeKey);
    const prov = $('#airProvider');
    if (prov) prov.addEventListener('change', () => {
      if (prov.value === 'nvidia') {
        const base = $('#airBaseUrl');
        if (base && !base.value) base.placeholder = 'https://integrate.api.nvidia.com/v1';
      }
    });
  }

  let lastInboundCount = -1;
  let lastInboundTs = 0;
  let lastBridgeState = null;

  async function pollAirBackground() {
    if (!$('#airSection')) return;
    try {
      const res = await refreshHealth();
      if (!res) return;
      const inboundCount = res.inbound_message_count || 0;
      const inboundTs = res.latest_inbound_ts || 0;
      const isLinked = !!res.bridge_connected;
      const countChanged = lastInboundCount !== -1 && inboundCount !== lastInboundCount;
      const tsChanged = lastInboundTs > 0 && inboundTs !== lastInboundTs;
      const linkChanged = lastBridgeState !== null && isLinked !== lastBridgeState;
      if (countChanged || tsChanged || linkChanged) {
        await loadChats();
      }
      lastInboundCount = inboundCount;
      lastInboundTs = inboundTs;
      lastBridgeState = isLinked;
    } catch (e) {}
  }

  document.addEventListener('DOMContentLoaded', async () => {
    if (!$('#airSection')) return;
    bind();
    await refreshHealth();
    await loadChats();
    await loadDrafts();
    setInterval(pollAirBackground, 3000);
  });
})();
