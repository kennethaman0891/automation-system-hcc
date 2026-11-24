/* HCC Assistant — dashboard help chatbot (client).
   All server content is inserted with textContent (no innerHTML). */
(function () {
    'use strict';
    var launcher = document.getElementById('hccBotLauncher');
    var panel = document.getElementById('hccBotPanel');
    if (!launcher || !panel) return;

    var body = document.getElementById('hccBotBody');
    var form = document.getElementById('hccBotForm');
    var input = document.getElementById('hccBotInput');
    var sendBtn = document.getElementById('hccBotSend');
    var closeBtn = document.getElementById('hccBotClose');
    var resetBtn = document.getElementById('hccBotReset');
    var endpoint = panel.getAttribute('data-endpoint') || 'api_assistant_chat.php';
    var busy = false;
    var greeted = false;

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        if (m && m.content) return m.content;
        var i = document.querySelector('input[name="csrf_token"]');
        return i ? i.value : '';
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function timeNow() {
        var d = new Date();
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    function scrollDown() { body.scrollTop = body.scrollHeight; }
    function safeHref(h) { return /^[a-z0-9_]+\.php$/.test(h || '') ? h : null; }

    function addUser(text) {
        var row = el('div', 'hcc-bot-msg user');
        var b = el('div', 'hcc-bot-bubble');
        b.appendChild(el('p', null, text));
        b.appendChild(el('div', 'hcc-bot-time', timeNow()));
        row.appendChild(b);
        body.appendChild(row);
        scrollDown();
    }
    function addBot(reply, isError) {
        var row = el('div', 'hcc-bot-msg bot' + (isError ? ' error' : ''));
        var mini = el('span', 'hcc-bot-mini');
        mini.setAttribute('aria-hidden', 'true');
        mini.appendChild(el('i', 'fa-solid fa-robot'));
        row.appendChild(mini);
        var b = el('div', 'hcc-bot-bubble');
        if (reply.title) b.appendChild(el('h4', null, reply.title));
        if (reply.text) b.appendChild(el('p', null, reply.text));
        if (reply.steps && reply.steps.length) {
            var list = el(reply.steps.length > 1 && reply.ordered !== false ? 'ol' : 'ul');
            reply.steps.forEach(function (s) { list.appendChild(el('li', null, s)); });
            b.appendChild(list);
        }
        if (reply.links && reply.links.length) {
            var wrap = el('div', 'hcc-bot-links');
            reply.links.forEach(function (l) {
                var href = safeHref(l.href);
                if (!href) return;
                var a = el('a');
                a.href = href;
                a.appendChild(el('i', 'fa-solid fa-arrow-up-right-from-square'));
                a.appendChild(document.createTextNode(l.label));
                wrap.appendChild(a);
            });
            if (wrap.childNodes.length) b.appendChild(wrap);
        }
        b.appendChild(el('div', 'hcc-bot-time', timeNow()));
        row.appendChild(b);
        body.appendChild(row);
        if (reply.followups && reply.followups.length) addChips(reply.followups);
        scrollDown();
    }
    function addChips(items) {
        var old = body.querySelectorAll('.hcc-bot-chips');
        old.forEach(function (c) { c.remove(); });
        var wrap = el('div', 'hcc-bot-chips');
        items.slice(0, 4).forEach(function (q) {
            var btn = el('button', null, q);
            btn.type = 'button';
            btn.addEventListener('click', function () { ask(q); });
            wrap.appendChild(btn);
        });
        body.appendChild(wrap);
    }
    function showTyping() {
        var row = el('div', 'hcc-bot-msg bot hcc-bot-typing');
        row.id = 'hccBotTyping';
        var mini = el('span', 'hcc-bot-mini');
        mini.appendChild(el('i', 'fa-solid fa-robot'));
        row.appendChild(mini);
        var b = el('div', 'hcc-bot-bubble');
        b.setAttribute('aria-label', 'Assistant is typing');
        b.appendChild(el('i')); b.appendChild(el('i')); b.appendChild(el('i'));
        row.appendChild(b);
        body.appendChild(row);
        scrollDown();
    }
    function hideTyping() { var t = document.getElementById('hccBotTyping'); if (t) t.remove(); }
    function setBusy(v) { busy = v; sendBtn.disabled = v || !input.value.trim(); input.setAttribute('aria-busy', v ? 'true' : 'false'); }

    function greet() {
        var name = panel.getAttribute('data-admin') || '';
        addBot({
            title: 'Hi' + (name ? ' ' + name : '') + ' 👋',
            text: "I'm the HCC Assistant. I can explain how the system works and take you to the right page. What would you like to know?",
            followups: ['Explain the system workflow', 'How do I enter marks?', 'How does WhatsApp sharing work?', "Show me today's stats"]
        });
    }

    function ask(text) {
        text = (text || '').trim();
        if (!text || busy) return;
        body.querySelectorAll('.hcc-bot-chips').forEach(function (c) { c.remove(); });
        addUser(text);
        input.value = '';
        setBusy(true);
        showTyping();
        var started = Date.now();
        var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 15000) : null;
        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
            body: JSON.stringify({ message: text }),
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (HTTP ' + r.status + ').' }; });
        }).then(function (data) {
            var wait = Math.max(0, 450 - (Date.now() - started)); // natural typing feel
            setTimeout(function () {
                hideTyping();
                if (data && data.ok && data.reply) addBot(data.reply, false);
                else addBot({ text: (data && data.error) || 'Something went wrong. Please try again.' }, true);
                setBusy(false);
                input.focus();
            }, wait);
        }).catch(function (err) {
            hideTyping();
            addBot({ text: err && err.name === 'AbortError' ? 'The request timed out. Please try again.' : 'Could not reach the server. Check your connection and try again.' }, true);
            setBusy(false);
        }).finally(function () { if (timer) clearTimeout(timer); });
    }

    function open() {
        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        launcher.setAttribute('aria-expanded', 'true');
        launcher.setAttribute('aria-label', 'Close assistant');
        if (!greeted) { greet(); greeted = true; }
        setTimeout(function () { input.focus(); }, 50);
    }
    function close() {
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        launcher.setAttribute('aria-expanded', 'false');
        launcher.setAttribute('aria-label', 'Open HCC Assistant');
        launcher.focus();
    }

    launcher.addEventListener('click', function () { panel.classList.contains('is-open') ? close() : open(); });
    closeBtn.addEventListener('click', close);
    resetBtn.addEventListener('click', function () { body.textContent = ''; greet(); input.focus(); });
    input.addEventListener('input', function () { sendBtn.disabled = busy || !input.value.trim(); });
    form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('is-open')) close();
    });
})();
