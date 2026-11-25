/**
 * HCC Universal Admin Auto-Save Engine
 * ------------------------------------
 * Connected automatically to EVERY admin page via `admin/includes/admin_autosave.php`.
 *
 * Two-Layer Power-Cut Resilience:
 *  1. Direct Domain DB Auto-Save (`data-autosave-mode="direct"`):
 *     Posts live edits directly to the page's DB save handler (`is_ajax=1`) so marks,
 *     attendance, remarks, and student figures commit immediately to the real MySQL tables.
 *     If a new record is incomplete or network drops, seamlessly falls back to Layer 2.
 *  2. Universal DB Draft Auto-Save (`admin/api_autosave.php` -> `admin_autosave_drafts`):
 *     Tracks every editable form & standalone input across all admin pages, mirrors
 *     instantly to localStorage (0ms) and debounces to MySQL (650ms), flushes on
 *     pagehide/visibilitychange with `keepalive: true`, and auto-restores after a power cut.
 */
(function (window, document) {
    'use strict';

    var metaEl = document.querySelector('meta[name="hcc-autosave-config"]');
    if (!metaEl) return;

    var CONFIG = null;
    try {
        CONFIG = JSON.parse(metaEl.getAttribute('content') || '{}');
    } catch (e) {
        return;
    }
    if (!CONFIG || !CONFIG.pageKey || !CONFIG.endpoint) return;

    var EXCLUDED_FORM_IDS = {
        logoutConfirmModal: true,
        deleteForm: true,
        bulkDeleteForm: true,
        deleteEntryForm: true,
        utBulkDeleteForm: true,
        removePhotoForm: true,
        hccBotForm: true,
        uploadForm: true
    };

    var EXCLUDED_ACTIONS = [
        'delete', 'delete_bulk', 'delete_student', 'delete_plan',
        'delete_substitution', 'delete_teacher_map', 'unlink_folder',
        'logout', 'change_password', 'remove_photo', 'upload_photo',
        'upload_file', 'parse', 'confirm', 'confirm_import',
        'switch_sheet', 'sync_roster', 'seed_sample_map', 'mark_notifs_read'
    ];

    var EXCLUDED_QUERY_KEYS = {
        msg: true, err: true, ok: true, saved: true, deleted: true, status: true
    };

    var DEFAULT_DEBOUNCE_MS = 650;
    var LOCAL_PREFIX = 'hcc_autosave_v1:' + String(CONFIG.adminId || 0) + ':' + CONFIG.pageKey + ':';

    var dockEl = null;
    var pillEl = null;
    var pillTextEl = null;
    var bannerEl = null;
    var hidePillTimer = null;

    var scopeRegistry = {}; // formKey -> scope object
    var isRestoring = false;

    function getContextKey() {
        try {
            var sp = new URLSearchParams(window.location.search || '');
            var entries = [];
            sp.forEach(function (val, key) {
                if (!EXCLUDED_QUERY_KEYS[key.toLowerCase()]) {
                    entries.push([key, val]);
                }
            });
            entries.sort(function (a, b) {
                return a[0] < b[0] ? -1 : (a[0] > b[0] ? 1 : 0);
            });
            return entries.map(function (p) {
                return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]);
            }).join('&').slice(0, 250);
        } catch (e) {
            return '';
        }
    }

    var CONTEXT_KEY = getContextKey();

    function nowTimeStr() {
        try {
            var d = new Date();
            var hh = String(d.getHours()).padStart(2, '0');
            var mm = String(d.getMinutes()).padStart(2, '0');
            var ss = String(d.getSeconds()).padStart(2, '0');
            return hh + ':' + mm + ':' + ss;
        } catch (e) {
            return '';
        }
    }

    function ensureDock() {
        if (dockEl) return;
        dockEl = document.createElement('div');
        dockEl.className = 'hcc-autosave-dock';
        dockEl.setAttribute('aria-live', 'polite');

        pillEl = document.createElement('div');
        pillEl.className = 'hcc-autosave-pill';
        pillEl.setAttribute('role', 'status');
        pillEl.setAttribute('data-state', 'idle');

        var dot = document.createElement('span');
        dot.className = 'hcc-autosave-dot';
        dot.setAttribute('aria-hidden', 'true');

        pillTextEl = document.createElement('span');
        pillTextEl.className = 'hcc-autosave-text';

        pillEl.appendChild(dot);
        pillEl.appendChild(pillTextEl);
        dockEl.appendChild(pillEl);
        document.body.appendChild(dockEl);
    }

    function updatePageStatusSpan(state, text) {
        var pageStatus = document.getElementById('autoSaveStatus');
        if (!pageStatus) return;
        pageStatus.textContent = text;
        if (state === 'saving') {
            pageStatus.style.color = '#d97706';
        } else if (state === 'saved') {
            pageStatus.style.color = '#166534';
        } else if (state === 'warning') {
            pageStatus.style.color = '#c2410c';
        } else if (state === 'error') {
            pageStatus.style.color = '#dc2626';
        }
    }

    function reportStatus(state, text, autoHideMs) {
        ensureDock();
        clearTimeout(hidePillTimer);
        pillEl.setAttribute('data-state', state || 'saved');
        pillTextEl.textContent = text;
        pillEl.classList.add('is-visible');
        updatePageStatusSpan(state, text);

        if (autoHideMs && autoHideMs > 0) {
            hidePillTimer = setTimeout(function () {
                if (pillEl && pillEl.getAttribute('data-state') === state) {
                    pillEl.classList.remove('is-visible');
                }
            }, autoHideMs);
        }
    }

    function showRecoveryBanner(count, onDiscard) {
        ensureDock();
        if (bannerEl && bannerEl.parentNode) {
            bannerEl.parentNode.removeChild(bannerEl);
        }
        bannerEl = document.createElement('div');
        bannerEl.className = 'hcc-autosave-banner';
        bannerEl.setAttribute('role', 'status');

        var msg = document.createElement('span');
        msg.textContent = 'Restored ' + count + ' auto-saved change' + (count === 1 ? '' : 's') + ' from DB backup.';

        var actions = document.createElement('span');
        actions.className = 'hcc-autosave-banner-actions';

        var keepBtn = document.createElement('button');
        keepBtn.type = 'button';
        keepBtn.className = 'hcc-autosave-btn';
        keepBtn.textContent = 'OK';
        keepBtn.addEventListener('click', function () {
            if (bannerEl && bannerEl.parentNode) bannerEl.parentNode.removeChild(bannerEl);
        });

        var discardBtn = document.createElement('button');
        discardBtn.type = 'button';
        discardBtn.className = 'hcc-autosave-btn hcc-autosave-btn--danger';
        discardBtn.textContent = 'Discard';
        discardBtn.addEventListener('click', function () {
            if (bannerEl && bannerEl.parentNode) bannerEl.parentNode.removeChild(bannerEl);
            if (typeof onDiscard === 'function') onDiscard();
        });

        actions.appendChild(keepBtn);
        actions.appendChild(discardBtn);
        bannerEl.appendChild(msg);
        bannerEl.appendChild(actions);
        dockEl.insertBefore(bannerEl, pillEl);
    }

    function isSensitiveName(nameOrId) {
        var s = String(nameOrId || '').toLowerCase();
        return (
            s.indexOf('password') !== -1 ||
            s.indexOf('passwd') !== -1 ||
            s.indexOf('csrf') !== -1 ||
            s.indexOf('secret') !== -1 ||
            s.indexOf('smtp_pass') !== -1 ||
            s.indexOf('client_secret') !== -1
        );
    }

    function isExcludedField(el) {
        if (!el || !el.tagName) return true;
        var tag = el.tagName.toUpperCase();
        if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') return true;
        if (el.getAttribute('data-autosave') === 'off') return true;
        if (el.closest && el.closest('[data-autosave="off"]')) return true;

        var type = (el.type || '').toLowerCase();
        if (
            type === 'password' ||
            type === 'file' ||
            type === 'hidden' ||
            type === 'submit' ||
            type === 'button' ||
            type === 'reset' ||
            type === 'image'
        ) {
            return true;
        }

        // Skip row-selection checkboxes (bulk delete / bulk print selectors)
        if (type === 'checkbox') {
            var n = el.name || '';
            if (
                el.id === 'checkAll' ||
                el.classList.contains('ut-check-box') ||
                el.classList.contains('row-check') ||
                el.classList.contains('stu-check') ||
                n === 'rolls[]' ||
                n === 'targets[]' ||
                n === 'selected[]'
            ) {
                return true;
            }
        }

        if (isSensitiveName(el.name) || isSensitiveName(el.id)) return true;
        return false;
    }

    function isExcludedForm(form) {
        if (!form || form.tagName !== 'FORM') return true;
        if (form.getAttribute('data-autosave') === 'off') return true;
        var method = (form.getAttribute('method') || 'GET').toUpperCase();
        if (method === 'GET' && form.getAttribute('data-autosave') !== 'on') return true;
        if (form.id && EXCLUDED_FORM_IDS[form.id]) return true;
        if (form.classList.contains('logout-modal-form')) return true;

        var actInput = form.querySelector('input[name="action"]');
        if (actInput) {
            var actVal = String(actInput.value || '').trim().toLowerCase();
            if (EXCLUDED_ACTIONS.indexOf(actVal) !== -1) return true;
        }
        return false;
    }

    function getFormKey(form, idx) {
        var explicit = form.getAttribute('data-autosave-key');
        if (explicit) return explicit.slice(0, 120);
        if (form.id) return ('form:' + form.id).slice(0, 120);
        var actInput = form.querySelector('input[name="action"]');
        var actVal = actInput ? String(actInput.value || '').trim() : '';
        return ('form_' + idx + (actVal ? '_' + actVal : '')).slice(0, 120);
    }

    function getScopeFields(scope) {
        var list = [];
        if (scope.form) {
            var nodes = scope.form.querySelectorAll('input, textarea, select');
            for (var i = 0; i < nodes.length; i++) {
                if (!isExcludedField(nodes[i])) list.push(nodes[i]);
            }
        } else {
            var all = document.querySelectorAll('input, textarea, select');
            for (var j = 0; j < all.length; j++) {
                if (!all[j].closest('form') && !isExcludedField(all[j])) {
                    list.push(all[j]);
                }
            }
        }
        return list;
    }

    function computeFieldKeys(fields) {
        var nameCounts = {};
        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            if (f.name) {
                nameCounts[f.name] = (nameCounts[f.name] || 0) + 1;
            }
        }
        var nameSeen = {};
        var map = [];
        for (var j = 0; j < fields.length; j++) {
            var el = fields[j];
            var type = (el.type || '').toLowerCase();
            var key = '';
            if (type === 'radio' && el.name) {
                key = 'radio:' + el.name;
            } else if (el.id) {
                key = '#' + el.id;
            } else if (el.name) {
                if (nameCounts[el.name] > 1) {
                    var idx = nameSeen[el.name] || 0;
                    nameSeen[el.name] = idx + 1;
                    key = 'name:' + el.name + '@' + idx;
                } else {
                    key = 'name:' + el.name;
                }
            } else {
                continue;
            }
            map.push({ key: key, el: el });
        }
        return map;
    }

    function readFieldValue(el) {
        var type = (el.type || '').toLowerCase();
        if (type === 'checkbox') return el.checked ? '1' : '0';
        if (type === 'radio') return el.checked ? el.value : null;
        if (el.tagName === 'SELECT' && el.multiple) {
            var vals = [];
            for (var i = 0; i < el.options.length; i++) {
                if (el.options[i].selected) vals.push(el.options[i].value);
            }
            return vals;
        }
        return el.value;
    }

    function snapshotScope(scope) {
        var fields = getScopeFields(scope);
        var pairs = computeFieldKeys(fields);
        var data = {};
        for (var i = 0; i < pairs.length; i++) {
            var p = pairs[i];
            var val = readFieldValue(p.el);
            if (val === null) continue; // unchecked radio
            data[p.key] = val;
        }
        return data;
    }

    function diffFromBaseline(scope, current) {
        var base = scope.baseline || {};
        var diff = {};
        var keys = Object.keys(current);
        for (var i = 0; i < keys.length; i++) {
            var k = keys[i];
            var curStr = JSON.stringify(current[k]);
            var baseStr = JSON.stringify(base[k]);
            if (curStr !== baseStr) {
                diff[k] = current[k];
            }
        }
        return diff;
    }

    function localStorageKey(formKey) {
        return LOCAL_PREFIX + CONTEXT_KEY + '::' + formKey;
    }

    function saveLocalBackup(formKey, fieldsObj) {
        try {
            if (!fieldsObj || Object.keys(fieldsObj).length === 0) {
                localStorage.removeItem(localStorageKey(formKey));
                return;
            }
            localStorage.setItem(localStorageKey(formKey), JSON.stringify({
                ts: Math.floor(Date.now() / 1000),
                fields: fieldsObj
            }));
        } catch (e) {}
    }

    function readLocalBackup(formKey) {
        try {
            var raw = localStorage.getItem(localStorageKey(formKey));
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            if (parsed && parsed.fields && typeof parsed.fields === 'object') {
                return parsed;
            }
        } catch (e) {}
        return null;
    }

    function clearLocalBackup(formKey) {
        try {
            localStorage.removeItem(localStorageKey(formKey));
        } catch (e) {}
    }

    function postDraftSave(scope, fieldsObj, useKeepalive) {
        var payload = {
            action: 'save',
            csrf_token: CONFIG.csrf,
            page_key: CONFIG.pageKey,
            form_key: scope.formKey,
            context_key: CONTEXT_KEY,
            page_url: window.location.pathname + window.location.search,
            fields: fieldsObj
        };

        return fetch(CONFIG.endpoint + '?action=save', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: !!useKeepalive,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CONFIG.csrf
            },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json();
        });
    }

    function postDraftClear(formKey, useKeepalive) {
        clearLocalBackup(formKey);
        return fetch(CONFIG.endpoint + '?action=clear', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: !!useKeepalive,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CONFIG.csrf
            },
            body: JSON.stringify({
                action: 'clear',
                csrf_token: CONFIG.csrf,
                page_key: CONFIG.pageKey,
                form_key: formKey || '',
                context_key: CONTEXT_KEY
            })
        }).catch(function () {});
    }

    function executeSave(scope, useKeepalive) {
        if (!scope) return;
        clearTimeout(scope.timer);
        scope.timer = null;

        var current = snapshotScope(scope);
        var diff = diffFromBaseline(scope, current);
        saveLocalBackup(scope.formKey, diff);

        if (scope.mode === 'direct' && scope.form) {
            if (scope.inFlight && !useKeepalive) {
                scope.pendingRetry = true;
                return;
            }

            // On pagehide/visibilitychange, also fire a keepalive draft backup in case direct POST is large
            if (useKeepalive) {
                if (Object.keys(diff).length > 0) {
                    postDraftSave(scope, diff, true).catch(function () {});
                }
                return;
            }

            scope.inFlight = true;
            scope.pendingRetry = false;
            reportStatus('saving', 'Saving changes to DB...');

            var fd = new FormData(scope.form);
            fd.set('is_ajax', '1');
            if (!fd.get('csrf_token') && CONFIG.csrf) {
                fd.set('csrf_token', CONFIG.csrf);
            }

            var targetUrl = scope.form.getAttribute('action') || window.location.href;

            fetch(targetUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': CONFIG.csrf,
                    'Accept': 'application/json'
                },
                body: fd
            })
            .then(function (res) {
                return res.text().then(function (txt) {
                    try {
                        return JSON.parse(txt);
                    } catch (e) {
                        return { ok: false, error: 'Unexpected server response' };
                    }
                });
            })
            .then(function (data) {
                scope.inFlight = false;
                if (data && data.ok) {
                    scope.dirty = false;
                    // Update original_roll_no if server created/renamed a student roll
                    if (data.new_roll) {
                        var origRollEl = scope.form.querySelector('input[name="original_roll_no"]');
                        if (origRollEl) origRollEl.value = String(data.new_roll);
                    }
                    // Refresh baseline to committed DB state and clear fallback draft
                    scope.baseline = snapshotScope(scope);
                    clearLocalBackup(scope.formKey);
                    postDraftClear(scope.formKey, false);

                    // Clear visual dirty markers if present
                    var dirtyRows = scope.form.querySelectorAll('.rm-dirty');
                    for (var i = 0; i < dirtyRows.length; i++) {
                        dirtyRows[i].classList.remove('rm-dirty');
                    }

                    reportStatus('saved', 'Saved to DB • ' + nowTimeStr(), 4000);
                } else if (data && data.draft_only) {
                    // Record not ready for final table yet (e.g. new student missing name) -> persist to DB draft table
                    postDraftSave(scope, diff, false)
                        .then(function (dr) {
                            if (dr && dr.ok) {
                                scope.dirty = false;
                                reportStatus('saved', 'Draft saved to DB • ' + nowTimeStr(), 4000);
                            } else {
                                reportStatus('warning', 'Saved locally (DB draft retry queued)');
                                scheduleNetworkRetry(scope);
                            }
                        })
                        .catch(function () {
                            reportStatus('warning', 'Saved locally — retrying DB...');
                            scheduleNetworkRetry(scope);
                        });
                } else {
                    // Validation or server error — still persist draft to DB so power cut won't lose edits
                    var errMsg = (data && data.error) ? data.error : 'Save failed';
                    if (Object.keys(diff).length > 0) {
                        postDraftSave(scope, diff, false).catch(function () {});
                    }
                    reportStatus('error', errMsg);
                }

                if (scope.pendingRetry) {
                    scope.pendingRetry = false;
                    scheduleSave(scope, 250);
                }
            })
            .catch(function () {
                scope.inFlight = false;
                reportStatus('warning', 'Offline/DB busy — saved locally, retrying...');
                scheduleNetworkRetry(scope);
            });
            return;
        }

        // Universal Draft DB Mode
        if (Object.keys(diff).length === 0) {
            scope.dirty = false;
            postDraftClear(scope.formKey, useKeepalive);
            return;
        }

        if (useKeepalive) {
            postDraftSave(scope, diff, true).catch(function () {});
            return;
        }

        if (scope.inFlight) {
            scope.pendingRetry = true;
            return;
        }

        scope.inFlight = true;
        scope.pendingRetry = false;
        reportStatus('saving', 'Auto-saving to DB...');

        postDraftSave(scope, diff, false)
            .then(function (data) {
                scope.inFlight = false;
                if (data && data.ok) {
                    scope.dirty = false;
                    reportStatus('saved', 'Auto-saved to DB • ' + nowTimeStr(), 3500);
                } else {
                    reportStatus('warning', 'Saved locally — retrying DB...');
                    scheduleNetworkRetry(scope);
                }
                if (scope.pendingRetry) {
                    scope.pendingRetry = false;
                    scheduleSave(scope, 250);
                }
            })
            .catch(function () {
                scope.inFlight = false;
                reportStatus('warning', 'Saved locally — retrying DB...');
                scheduleNetworkRetry(scope);
            });
    }

    function scheduleNetworkRetry(scope) {
        clearTimeout(scope.retryTimer);
        scope.retryTimer = setTimeout(function () {
            if (scope.dirty) {
                executeSave(scope, false);
            }
        }, 5000);
    }

    function scheduleSave(scope, customDelay) {
        if (!scope || isRestoring) return;
        scope.dirty = true;
        // Immediate 0ms localStorage snapshot
        var current = snapshotScope(scope);
        saveLocalBackup(scope.formKey, diffFromBaseline(scope, current));

        var delay = typeof customDelay === 'number' ? customDelay : scope.debounceMs;
        clearTimeout(scope.timer);
        scope.timer = setTimeout(function () {
            executeSave(scope, false);
        }, delay);
    }

    function applyDraftToScope(scope, fieldsObj) {
        if (!scope || !fieldsObj) return 0;
        var fields = getScopeFields(scope);
        var pairs = computeFieldKeys(fields);
        var changedCount = 0;

        isRestoring = true;
        try {
            for (var i = 0; i < pairs.length; i++) {
                var p = pairs[i];
                if (!Object.prototype.hasOwnProperty.call(fieldsObj, p.key)) continue;
                var savedVal = fieldsObj[p.key];
                var el = p.el;
                var type = (el.type || '').toLowerCase();

                if (type === 'checkbox') {
                    var wantChecked = (savedVal === '1' || savedVal === true);
                    if (el.checked !== wantChecked) {
                        el.checked = wantChecked;
                        changedCount++;
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } else if (type === 'radio') {
                    var match = (el.value === String(savedVal));
                    if (match && !el.checked) {
                        el.checked = true;
                        changedCount++;
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } else if (el.tagName === 'SELECT' && el.multiple && Array.isArray(savedVal)) {
                    var anyDiff = false;
                    for (var o = 0; o < el.options.length; o++) {
                        var shouldSel = savedVal.indexOf(el.options[o].value) !== -1;
                        if (el.options[o].selected !== shouldSel) {
                            el.options[o].selected = shouldSel;
                            anyDiff = true;
                        }
                    }
                    if (anyDiff) {
                        changedCount++;
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } else {
                    var nextStr = savedVal === null || savedVal === undefined ? '' : String(savedVal);
                    if (el.value !== nextStr) {
                        el.value = nextStr;
                        changedCount++;
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            }
        } finally {
            isRestoring = false;
        }
        return changedCount;
    }

    function registerScope(form, formKey) {
        var mode = (form && form.getAttribute('data-autosave-mode') === 'direct') ? 'direct' : 'draft';
        var delayAttr = form ? parseInt(form.getAttribute('data-autosave-delay') || '', 10) : NaN;
        var scope = {
            form: form || null,
            formKey: formKey,
            mode: mode,
            debounceMs: isFinite(delayAttr) && delayAttr >= 200 ? delayAttr : DEFAULT_DEBOUNCE_MS,
            baseline: null,
            timer: null,
            retryTimer: null,
            inFlight: false,
            pendingRetry: false,
            dirty: false
        };
        scope.baseline = snapshotScope(scope);
        scopeRegistry[formKey] = scope;

        if (form) {
            form.addEventListener('submit', function () {
                clearTimeout(scope.timer);
                clearTimeout(scope.retryTimer);
                scope.dirty = false;
                postDraftClear(scope.formKey, true);
            });
        }
        return scope;
    }

    function findScopeForElement(el) {
        if (!el || isExcludedField(el)) return null;
        var form = el.closest ? el.closest('form') : null;
        if (form) {
            if (isExcludedForm(form)) return null;
            var keys = Object.keys(scopeRegistry);
            for (var i = 0; i < keys.length; i++) {
                if (scopeRegistry[keys[i]].form === form) {
                    return scopeRegistry[keys[i]];
                }
            }
            // Dynamically injected form
            return registerScope(form, getFormKey(form, keys.length));
        }
        return scopeRegistry['__standalone__'] || registerScope(null, '__standalone__');
    }

    function restoreDrafts() {
        var url = CONFIG.endpoint +
            '?action=load&page=' + encodeURIComponent(CONFIG.pageKey) +
            '&context=' + encodeURIComponent(CONTEXT_KEY);

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, drafts: [] }; })
        .then(function (res) {
            var dbDraftMap = {};
            if (res && res.ok && Array.isArray(res.drafts)) {
                for (var i = 0; i < res.drafts.length; i++) {
                    dbDraftMap[res.drafts[i].form_key] = res.drafts[i];
                }
            }

            var totalRestored = 0;
            var restoredScopes = [];

            var keys = Object.keys(scopeRegistry);
            for (var k = 0; k < keys.length; k++) {
                var fk = keys[k];
                var scope = scopeRegistry[fk];
                var dbDraft = dbDraftMap[fk] || null;
                var localDraft = readLocalBackup(fk);

                var chosenFields = null;
                if (dbDraft && localDraft) {
                    chosenFields = (localDraft.ts > (dbDraft.updated_ts || 0)) ? localDraft.fields : dbDraft.fields;
                } else if (dbDraft) {
                    chosenFields = dbDraft.fields;
                } else if (localDraft) {
                    chosenFields = localDraft.fields;
                }

                if (chosenFields && Object.keys(chosenFields).length > 0) {
                    var applied = applyDraftToScope(scope, chosenFields);
                    if (applied > 0) {
                        totalRestored += applied;
                        restoredScopes.push(scope);
                        // If a direct-mode form had an uncommitted crash backup, commit it to DB now
                        if (scope.mode === 'direct') {
                            scheduleSave(scope, 400);
                        }
                    } else {
                        // Draft matches DB already — clean up stale backup
                        clearLocalBackup(fk);
                        if (dbDraft) postDraftClear(fk, false);
                    }
                }
            }

            if (totalRestored > 0) {
                showRecoveryBanner(totalRestored, function () {
                    for (var r = 0; r < restoredScopes.length; r++) {
                        postDraftClear(restoredScopes[r].formKey, false);
                    }
                    window.location.reload();
                });
            }
        });
    }

    function flushAllDirty() {
        var keys = Object.keys(scopeRegistry);
        for (var i = 0; i < keys.length; i++) {
            var sc = scopeRegistry[keys[i]];
            if (sc.dirty || sc.timer) {
                executeSave(sc, true);
            }
        }
    }

    function boot() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            if (!isExcludedForm(forms[i])) {
                registerScope(forms[i], getFormKey(forms[i], i));
            }
        }

        // Also register standalone inputs outside <form> (e.g. bonafide_certificate.php)
        var standaloneInputs = document.querySelectorAll('input, textarea, select');
        for (var j = 0; j < standaloneInputs.length; j++) {
            if (!standaloneInputs[j].closest('form') && !isExcludedField(standaloneInputs[j])) {
                registerScope(null, '__standalone__');
                break;
            }
        }

        document.addEventListener('input', function (ev) {
            var scope = findScopeForElement(ev.target);
            if (scope) scheduleSave(scope);
        }, true);

        document.addEventListener('change', function (ev) {
            var scope = findScopeForElement(ev.target);
            if (scope) scheduleSave(scope);
        }, true);

        // Flush on tab switch, browser close, or OS shutdown/power event
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                flushAllDirty();
            }
        });
        window.addEventListener('pagehide', flushAllDirty);
        window.addEventListener('online', function () {
            var keys = Object.keys(scopeRegistry);
            for (var i = 0; i < keys.length; i++) {
                if (scopeRegistry[keys[i]].dirty) {
                    executeSave(scopeRegistry[keys[i]], false);
                }
            }
        });

        restoreDrafts();
    }

    window.HCCAutoSave = {
        triggerForm: function (formOrId, immediate) {
            var form = typeof formOrId === 'string' ? document.getElementById(formOrId) : formOrId;
            if (!form) return;
            var keys = Object.keys(scopeRegistry);
            for (var i = 0; i < keys.length; i++) {
                if (scopeRegistry[keys[i]].form === form) {
                    if (immediate) executeSave(scopeRegistry[keys[i]], false);
                    else scheduleSave(scopeRegistry[keys[i]]);
                    return;
                }
            }
        },
        reportStatus: reportStatus,
        flushAll: flushAllDirty,
        clearPageDrafts: function () {
            var keys = Object.keys(scopeRegistry);
            for (var i = 0; i < keys.length; i++) {
                clearLocalBackup(keys[i]);
            }
            postDraftClear('', false);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window, document);
