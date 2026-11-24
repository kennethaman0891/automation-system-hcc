/* Parent-signature tick / untick + Interactive Digital Signature Board.
 * Sends POST to api_parent_sign.php with roll + class_key (or unit test keys)
 * + signed state + optional base64 PNG signature_data.
 */
(function () {
    'use strict';

    var STYLE_ID = 'hcc-parent-sign-board-styles';
    var DIALOG_ID = 'hccParentSignDialog';

    function csrfToken() {
        var el = document.querySelector('input[name="csrf_token"]');
        if (el && el.value) return el.value;
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function notify(message, type) {
        if (window.HCCToast && typeof window.HCCToast[type || 'info'] === 'function') {
            window.HCCToast[type || 'info'](message);
        } else if (typeof window.showToast === 'function') {
            window.showToast(message, type || 'info');
        } else if (type === 'error') {
            alert(message);
        }
    }

    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = [
            '.psb-trigger{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:30px;padding:4px 10px;border-radius:8px;border:1.5px solid #cbd5e1;background:#ffffff;color:#334155;font-size:0.75rem;font-weight:700;cursor:pointer;transition:all .15s ease;max-width:130px;}',
            '.psb-trigger:hover{border-color:#7a2238;color:#7a2238;background:#fdf5f6;}',
            '.psb-trigger.is-signed{border-color:#bbf7d0;background:#f0fdf4;color:#15803d;padding:3px 8px;}',
            '.psb-trigger.is-signed:hover{border-color:#16a34a;background:#dcfce7;}',
            '.psb-trigger img.psb-thumb{display:block;height:22px;max-width:74px;object-fit:contain;}',
            '.psb-trigger.is-busy{opacity:0.55;pointer-events:none;}',
            '.psb-dialog{margin:auto;padding:0;border:1px solid #e2e8f0;border-radius:16px;width:min(540px,calc(100vw - 24px));max-width:540px;background:#ffffff;color:#1e293b;box-shadow:0 24px 60px rgba(15,23,42,0.28);overflow:hidden;}',
            '.psb-dialog::backdrop{background:rgba(15,23,42,0.58);backdrop-filter:blur(3px);}',
            '.psb-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid #e2e8f0;background:#f8fafc;}',
            '.psb-title-wrap{display:flex;align-items:center;gap:10px;min-width:0;}',
            '.psb-title-icon{width:36px;height:36px;flex:0 0 36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:#f9eef1;color:#7a2238;font-size:0.95rem;}',
            '.psb-title{margin:0;font-size:1rem;font-weight:800;color:#0f172a;line-height:1.25;}',
            '.psb-sub{margin:2px 0 0;font-size:0.78rem;font-weight:600;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
            '.psb-close{width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#ffffff;color:#475569;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:0.95rem;}',
            '.psb-close:hover{background:#f1f5f9;color:#0f172a;}',
            '.psb-body{padding:18px 20px;}',
            '.psb-hint{margin:0 0 10px;font-size:0.8rem;color:#475569;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;}',
            '.psb-canvas-wrap{position:relative;border:2px dashed #cbd5e1;border-radius:12px;background:#ffffff;overflow:hidden;height:210px;}',
            '.psb-canvas-wrap.has-ink{border-style:solid;border-color:#94a3b8;}',
            '.psb-canvas{display:block;width:100%;height:100%;touch-action:none;cursor:crosshair;}',
            '.psb-guide{position:absolute;left:22px;right:22px;bottom:42px;border-bottom:1.5px solid #e2e8f0;pointer-events:none;}',
            '.psb-guide span{position:absolute;right:0;bottom:4px;font-size:0.68rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#94a3b8;}',
            '.psb-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;}',
            '.psb-foot-left,.psb-foot-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}',
            '.psb-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;padding:0 14px;border-radius:9px;font-size:0.82rem;font-weight:700;cursor:pointer;border:1.5px solid #cbd5e1;background:#ffffff;color:#334155;transition:all .15s ease;}',
            '.psb-btn:hover:not(:disabled){background:#f1f5f9;color:#0f172a;}',
            '.psb-btn:disabled{opacity:0.55;cursor:not-allowed;}',
            '.psb-btn-danger{border-color:#fecaca;color:#b91c1c;background:#fff5f5;}',
            '.psb-btn-danger:hover:not(:disabled){background:#fee2e2;color:#991b1b;}',
            '.psb-btn-primary{background:#7a2238;border-color:#7a2238;color:#ffffff;}',
            '.psb-btn-primary:hover:not(:disabled){background:#5a1a2a;border-color:#5a1a2a;color:#ffffff;}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function sendSignRequest(payload) {
        var body = new URLSearchParams({
            csrf_token: csrfToken(),
            roll: payload.roll,
            sign: String(payload.sign)
        });
        if (payload.table) body.append('table', payload.table);
        if (payload.classKey) body.append('class_key', payload.classKey);
        if (payload.year) body.append('year', payload.year);
        if (payload.std) body.append('std', payload.std);
        if (payload.division) body.append('division', payload.division);
        if (payload.testType) body.append('test_type', payload.testType);
        if (typeof payload.signatureData === 'string') {
            body.append('signature_data', payload.signatureData);
        }

        return fetch('api_parent_sign.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: body
        }).then(function (r) {
            return r.json().catch(function () {
                return { ok: false, error: 'Server returned HTTP ' + r.status };
            });
        });
    }

    function toggleCheckboxSign(ev) {
        var t = ev.target;
        if (!t || !t.classList || !t.classList.contains('parent-sign-toggle')) return;
        var roll = t.dataset.roll;
        if (!roll) return;

        var newState = t.checked ? 1 : 0;
        var prevState = newState ? 0 : 1;
        t.classList.toggle('is-busy', true);

        sendSignRequest({
            roll: roll,
            sign: newState,
            classKey: t.dataset.class || ''
        }).then(function (data) {
            t.classList.toggle('is-busy', false);
            if (!data || !data.ok) {
                t.checked = !!prevState;
                notify((data && data.error) || 'Could not update parent signature', 'error');
            }
        }).catch(function (e) {
            t.classList.toggle('is-busy', false);
            t.checked = !!prevState;
            notify('Network error: ' + (e && e.message ? e.message : 'unknown'), 'error');
        });
    }

    function updateTriggerUI(btn, signed, signatureData) {
        if (!btn) return;
        btn.dataset.signed = signed ? '1' : '0';
        btn.dataset.signature = signatureData || '';
        btn.classList.toggle('is-signed', !!signed);
        btn.setAttribute('aria-pressed', signed ? 'true' : 'false');

        if (btn.classList.contains('psb-keep-label')) {
            return;
        }

        btn.textContent = '';
        if (signed && signatureData) {
            var img = document.createElement('img');
            img.src = signatureData;
            img.alt = 'Parent signature';
            img.className = 'psb-thumb';
            btn.appendChild(img);
            btn.title = 'Parent digital signature saved (click to view or re-sign)';
        } else if (signed) {
            var iconOk = document.createElement('i');
            iconOk.className = 'fas fa-check';
            iconOk.setAttribute('aria-hidden', 'true');
            var spanOk = document.createElement('span');
            spanOk.textContent = 'Signed';
            btn.appendChild(iconOk);
            btn.appendChild(spanOk);
            btn.title = 'Parent signature confirmed (click to draw or update signature)';
        } else {
            var iconPen = document.createElement('i');
            iconPen.className = 'fas fa-signature';
            iconPen.setAttribute('aria-hidden', 'true');
            var spanSign = document.createElement('span');
            spanSign.textContent = 'Sign';
            btn.appendChild(iconPen);
            btn.appendChild(spanSign);
            btn.title = 'Open digital signature board';
        }
    }

    var boardState = null;

    function buildDialog() {
        ensureStyles();
        var existing = document.getElementById(DIALOG_ID);
        if (existing) return existing;

        var dialog = document.createElement('dialog');
        dialog.id = DIALOG_ID;
        dialog.className = 'psb-dialog';
        dialog.setAttribute('aria-labelledby', 'psbDialogTitle');

        dialog.innerHTML = [
            '<div class="psb-head">',
            '  <div class="psb-title-wrap">',
            '    <span class="psb-title-icon" aria-hidden="true"><i class="fas fa-signature"></i></span>',
            '    <div>',
            '      <h3 class="psb-title" id="psbDialogTitle">Parent Digital Signature Board</h3>',
            '      <p class="psb-sub" id="psbDialogSub"></p>',
            '    </div>',
            '  </div>',
            '  <button type="button" class="psb-close" id="psbCloseBtn" aria-label="Close signature board"><i class="fas fa-times" aria-hidden="true"></i></button>',
            '</div>',
            '<div class="psb-body">',
            '  <p class="psb-hint">',
            '    <span>Draw parent signature using mouse, touch, or stylus.</span>',
            '    <span id="psbStatusBadge" style="font-weight:700;"></span>',
            '  </p>',
            '  <div class="psb-canvas-wrap" id="psbCanvasWrap">',
            '    <canvas class="psb-canvas" id="psbCanvas"></canvas>',
            '    <div class="psb-guide" aria-hidden="true"><span>Parent Signature</span></div>',
            '  </div>',
            '</div>',
            '<div class="psb-foot">',
            '  <div class="psb-foot-left">',
            '    <button type="button" class="psb-btn" id="psbClearBtn"><i class="fas fa-eraser" aria-hidden="true"></i> Clear</button>',
            '    <button type="button" class="psb-btn psb-btn-danger" id="psbRemoveBtn" hidden><i class="fas fa-trash" aria-hidden="true"></i> Remove Sign</button>',
            '  </div>',
            '  <div class="psb-foot-right">',
            '    <button type="button" class="psb-btn" id="psbCancelBtn">Cancel</button>',
            '    <button type="button" class="psb-btn psb-btn-primary" id="psbSaveBtn"><i class="fas fa-check" aria-hidden="true"></i> Save Signature</button>',
            '  </div>',
            '</div>'
        ].join('');

        document.body.appendChild(dialog);

        var canvas = dialog.querySelector('#psbCanvas');
        var wrap = dialog.querySelector('#psbCanvasWrap');
        var ctx = canvas.getContext('2d');
        var drawing = false;
        var hasInk = false;
        var lastPt = null;

        function resizeCanvas(preserveDataUrl) {
            var rect = wrap.getBoundingClientRect();
            var dpr = Math.max(window.devicePixelRatio || 1, 1);
            var w = Math.max(Math.round(rect.width), 300);
            var h = Math.max(Math.round(rect.height), 180);
            canvas.width = w * dpr;
            canvas.height = h * dpr;
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0f172a';
            ctx.lineWidth = 2.4;

            if (preserveDataUrl) {
                var img = new Image();
                img.onload = function () {
                    ctx.drawImage(img, 0, 0, w, h);
                    hasInk = true;
                    wrap.classList.add('has-ink');
                };
                img.src = preserveDataUrl;
            }
        }

        function getPoint(e) {
            var r = canvas.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        }

        canvas.addEventListener('pointerdown', function (e) {
            if (e.button && e.button !== 0) return;
            e.preventDefault();
            canvas.setPointerCapture(e.pointerId);
            drawing = true;
            lastPt = getPoint(e);
            ctx.beginPath();
            ctx.moveTo(lastPt.x, lastPt.y);
            ctx.lineTo(lastPt.x + 0.01, lastPt.y + 0.01);
            ctx.stroke();
            hasInk = true;
            wrap.classList.add('has-ink');
        });

        canvas.addEventListener('pointermove', function (e) {
            if (!drawing || !lastPt) return;
            var pt = getPoint(e);
            var midX = (lastPt.x + pt.x) / 2;
            var midY = (lastPt.y + pt.y) / 2;
            ctx.beginPath();
            ctx.moveTo(lastPt.x, lastPt.y);
            ctx.quadraticCurveTo(lastPt.x, lastPt.y, midX, midY);
            ctx.lineTo(pt.x, pt.y);
            ctx.stroke();
            lastPt = pt;
        });

        function stopDrawing(e) {
            if (!drawing) return;
            drawing = false;
            lastPt = null;
            if (e && e.pointerId !== undefined && canvas.hasPointerCapture(e.pointerId)) {
                canvas.releasePointerCapture(e.pointerId);
            }
        }
        canvas.addEventListener('pointerup', stopDrawing);
        canvas.addEventListener('pointercancel', stopDrawing);

        function clearBoard() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hasInk = false;
            wrap.classList.remove('has-ink');
        }

        function closeDialog() {
            if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        }

        dialog.querySelector('#psbClearBtn').addEventListener('click', clearBoard);
        dialog.querySelector('#psbCloseBtn').addEventListener('click', closeDialog);
        dialog.querySelector('#psbCancelBtn').addEventListener('click', closeDialog);
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) closeDialog();
        });

        dialog.querySelector('#psbSaveBtn').addEventListener('click', function () {
            if (!boardState || !boardState.trigger) return;
            if (!hasInk) {
                notify('Please draw a signature on the board before saving.', 'error');
                return;
            }
            var saveBtn = dialog.querySelector('#psbSaveBtn');
            saveBtn.disabled = true;
            var pngData = canvas.toDataURL('image/png');
            var trg = boardState.trigger;
            trg.classList.add('is-busy');

            sendSignRequest({
                roll: boardState.roll,
                sign: 1,
                table: boardState.table,
                classKey: boardState.classKey,
                year: boardState.year,
                std: boardState.std,
                division: boardState.division,
                testType: boardState.testType,
                signatureData: pngData
            }).then(function (data) {
                saveBtn.disabled = false;
                trg.classList.remove('is-busy');
                if (!data || !data.ok) {
                    notify((data && data.error) || 'Could not save digital signature.', 'error');
                    return;
                }
                updateTriggerUI(trg, true, data.signature_data || pngData);
                document.dispatchEvent(new CustomEvent('hcc:parent-sign-updated', {
                    detail: {
                        roll: boardState.roll,
                        signed: true,
                        signature_data: data.signature_data || pngData,
                        trigger: trg
                    }
                }));
                notify('Digital signature saved.', 'success');
                closeDialog();
            }).catch(function (e) {
                saveBtn.disabled = false;
                trg.classList.remove('is-busy');
                notify('Network error: ' + (e && e.message ? e.message : 'unknown'), 'error');
            });
        });

        dialog.querySelector('#psbRemoveBtn').addEventListener('click', function () {
            if (!boardState || !boardState.trigger) return;
            var remBtn = dialog.querySelector('#psbRemoveBtn');
            remBtn.disabled = true;
            var trg = boardState.trigger;
            trg.classList.add('is-busy');

            sendSignRequest({
                roll: boardState.roll,
                sign: 0,
                table: boardState.table,
                classKey: boardState.classKey,
                year: boardState.year,
                std: boardState.std,
                division: boardState.division,
                testType: boardState.testType
            }).then(function (data) {
                remBtn.disabled = false;
                trg.classList.remove('is-busy');
                if (!data || !data.ok) {
                    notify((data && data.error) || 'Could not remove digital signature.', 'error');
                    return;
                }
                updateTriggerUI(trg, false, '');
                document.dispatchEvent(new CustomEvent('hcc:parent-sign-updated', {
                    detail: {
                        roll: boardState.roll,
                        signed: false,
                        signature_data: '',
                        trigger: trg
                    }
                }));
                notify('Parent signature removed.', 'info');
                closeDialog();
            }).catch(function (e) {
                remBtn.disabled = false;
                trg.classList.remove('is-busy');
                notify('Network error: ' + (e && e.message ? e.message : 'unknown'), 'error');
            });
        });

        dialog._psbReset = function (opts) {
            boardState = opts;
            dialog.querySelector('#psbDialogSub').textContent = 'Roll #' + opts.roll + (opts.studentName ? ' — ' + opts.studentName : '');
            var badge = dialog.querySelector('#psbStatusBadge');
            badge.textContent = opts.signed ? 'Signed' : 'Pending';
            badge.style.color = opts.signed ? '#15803d' : '#b45309';
            var remBtn = dialog.querySelector('#psbRemoveBtn');
            remBtn.hidden = !opts.signed;
            clearBoard();
            if (typeof dialog.showModal === 'function') {
                if (!dialog.open) dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
            requestAnimationFrame(function () {
                resizeCanvas(opts.signatureData || '');
            });
        };

        return dialog;
    }

    function openBoardForTrigger(btn) {
        var roll = btn.dataset.roll || '';
        if (!roll) return;
        var dialog = buildDialog();
        dialog._psbReset({
            trigger: btn,
            roll: roll,
            studentName: btn.dataset.studentName || '',
            table: btn.dataset.table || 'report_card',
            classKey: btn.dataset.class || '',
            year: btn.dataset.year || '',
            std: btn.dataset.std || '',
            division: btn.dataset.division || '',
            testType: btn.dataset.testType || '',
            signed: btn.dataset.signed === '1',
            signatureData: btn.dataset.signature || ''
        });
    }

    function handleClick(ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('.js-open-sign-board') : null;
        if (!btn) return;
        ev.preventDefault();
        openBoardForTrigger(btn);
    }

    function boot() {
        ensureStyles();
        document.addEventListener('change', toggleCheckboxSign);
        document.addEventListener('click', handleClick);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
