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
    var logo = panel.getAttribute('data-logo') || '';
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
    function iconOk(cls) { return /^fa-(solid|brands) fa-[a-z0-9-]+$/.test(cls || ''); }
    function badgeOk(cls) { return /^(gold|wa|archive|crm|pipeline|drive)$/.test(cls || ''); }

    function appendIcon(parent, cls) {
        if (!iconOk(cls)) return;
        var i = document.createElement('i');
        i.className = cls;
        i.setAttribute('aria-hidden', 'true');
        parent.appendChild(i);
    }
    function addPoints(parent, points) {
        if (!points || !points.length) return;
        var ol = el('ol', 'hcc-bot-points');
        points.forEach(function (p) {
            var t = p == null ? '' : String(p);
            if (!t) return;
            ol.appendChild(el('li', null, t));
        });
        if (ol.childNodes.length) parent.appendChild(ol);
    }
    function addOpenButton(parent, item) {
        var href = safeHref(item && item.href);
        if (!href || !item.label) return;
        var wrap = el('div', 'hcc-bot-links');
        var a = el('a');
        a.href = href;
        a.setAttribute('aria-label', 'Open ' + item.label);
        if (iconOk(item.icon)) {
            var i = document.createElement('i');
            i.className = item.icon;
            i.setAttribute('aria-hidden', 'true');
            a.appendChild(i);
        }
        a.appendChild(document.createTextNode('Open'));
        wrap.appendChild(a);
        parent.appendChild(wrap);
    }
    /* Explanation cards are never links. href is rendered only as Open. */
    function addModuleCard(parent, item) {
        var row = el('div', 'hcc-bot-nav');
        var ico = el('span', 'hcc-bot-ico');
        appendIcon(ico, item.icon);
        row.appendChild(ico);
        var navBody = el('span', 'hcc-bot-nav-body');
        if (item.label || item.badge) {
            var title = el('span', 'hcc-bot-nav-title');
            if (item.label) title.appendChild(el('strong', null, item.label));
            if (item.badge) {
                var badgeCls = 'hcc-bot-badge' + (badgeOk(item.badgeClass) ? ' is-' + item.badgeClass : '');
                title.appendChild(el('span', badgeCls, item.badge));
            }
            navBody.appendChild(title);
        }
        if (item.points && item.points.length) addPoints(navBody, item.points);
        else if (item.text) navBody.appendChild(el('span', 'hcc-bot-nav-text', item.text));
        addOpenButton(navBody, item);
        row.appendChild(navBody);
        parent.appendChild(row);
    }
    function addFlow(parent, items) {
        var list = el('div', 'hcc-bot-flow');
        items.forEach(function (item) {
            if (!item || typeof item === 'string') {
                list.appendChild(el('p', 'hcc-bot-nav-text', item || ''));
                return;
            }
            addModuleCard(list, item);
        });
        if (list.childNodes.length) parent.appendChild(list);
    }

    function miniMark() {
        var mini = el('span', 'hcc-bot-mini');
        mini.setAttribute('aria-hidden', 'true');
        if (logo) {
            var img = document.createElement('img');
            img.src = logo;
            img.alt = '';
            mini.appendChild(img);
        } else {
            appendIcon(mini, 'fa-solid fa-shield-halved');
        }
        return mini;
    }
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
        row.appendChild(miniMark());
        var b = el('div', 'hcc-bot-bubble');
        if (reply.title) b.appendChild(el('h4', null, reply.title));
        if (reply.text) b.appendChild(el('p', null, reply.text));
        if (reply.groups && reply.groups.length) {
            reply.groups.forEach(function (g) {
                if (!g) return;
                if (g.label) b.appendChild(el('p', 'hcc-bot-group-label', g.label));
                if (g.items && g.items.length) addFlow(b, g.items);
            });
        }
        if (reply.steps && reply.steps.length) {
            var blockSteps = reply.steps.some(function (s) { return s && typeof s === 'object'; });
            if (blockSteps) {
                var flow = el('div', 'hcc-bot-flow');
                reply.steps.forEach(function (s) {
                    if (s && typeof s === 'object') addModuleCard(flow, s);
                    else flow.appendChild(el('p', 'hcc-bot-nav-text', s == null ? '' : String(s)));
                });
                b.appendChild(flow);
            } else {
                var ordered = reply.steps.length > 1 && reply.ordered !== false;
                var list = el(ordered ? 'ol' : 'ul', ordered ? 'hcc-bot-steps' : 'hcc-bot-steps is-plain');
                reply.steps.forEach(function (s) {
                    list.appendChild(el('li', null, s == null ? '' : String(s)));
                });
                b.appendChild(list);
            }
        }
        if (reply.links && reply.links.length) {
            var wrap = el('div', 'hcc-bot-links');
            reply.links.forEach(function (l) {
                var href = safeHref(l.href);
                if (!href || !l.label) return;
                var a = el('a');
                a.href = href;
                if (iconOk(l.icon)) {
                    var i = document.createElement('i');
                    i.className = l.icon;
                    i.setAttribute('aria-hidden', 'true');
                    a.appendChild(i);
                }
                a.appendChild(document.createTextNode(l.label));
                wrap.appendChild(a);
            });
            if (wrap.childNodes.length) b.appendChild(wrap);
        }
        b.appendChild(el('div', 'hcc-bot-time', timeNow()));
        row.appendChild(b);
        body.appendChild(row);
        if (reply.followups && reply.followups.length) addChips(reply.followups);
        var longAnswer = (reply.groups && reply.groups.length) || (reply.steps && reply.steps.length > 3);
        if (longAnswer) {
            var top = row.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop;
            body.scrollTop = Math.max(0, top - 8);
        } else {
            scrollDown();
        }
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
        row.appendChild(miniMark());
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
            text: "I'm the HCC Assistant. I explain each part of the system in steps. Open is the button that takes you to a page. What would you like to know?",
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
