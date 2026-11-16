(function () {
    'use strict';

    /* ── Element mapping: form input ↔ cert-sheet data-editable ── */
    var MAP = {
        f_student:   'student',
        f_std:       'std',
        f_dob:       'dob',
        f_dobwords:  'dobwords',
        f_ay:        'ay',
        f_caste:     'caste',
        f_date:      'date',
        f_grno:      'grno',
        f_srno:      'srno',
        f_principal: 'principal',
        f_school:    'school'
    };
    var PLACEHOLDERS = {
        student:   '____',
        std:       'STD – ____',
        dob:       '____',
        dobwords:  '____',
        ay:        '____',
        caste:     '_______'
    };
    var sheet   = document.getElementById('certSheet');
    var editOn  = true;
    var dirty   = {}; // field key → true when user overrode

    function el(field)   { return document.querySelector('[data-editable="' + field + '"]'); }
    function input(key)  { return document.getElementById(key); }

    /* ── Blank-fill underlines: a fill holding only underscores/dashes
       shows its own single line; real content gets the underline instead.
       Synced on load, on form input, and on in-place edits. ── */
    function syncBlankFill(targetEl) {
        if (!targetEl || !targetEl.classList || !targetEl.classList.contains('cert-fill')) return;
        var txt = (targetEl.textContent || '').trim();
        var isBlank = /^[\s_–—-]*$/.test(txt) || /^STD(\s*[–—\-]?\s*[_–—-]*)?$/i.test(txt);
        targetEl.classList.toggle('is-blank', isBlank);
    }

    /* Two-way binding: form input → preview */
    Object.keys(MAP).forEach(function (inputId) {
        var inp = input(inputId);
        if (!inp) return;
        inp.addEventListener('input', function () {
            var field = MAP[inputId];
            var target = el(field);
            if (target) {
                var ph = PLACEHOLDERS[field];
                target.textContent = inp.value || (ph ? ph : (field === 'caste' ? '_______' : ''));
                target.removeAttribute('data-default-empty');
                syncBlankFill(target);
            }
            dirty[inputId] = inp.value !== '';
        });
    });

    /* In-place editing: preview → form */
    if (editOn) {
        Object.keys(MAP).forEach(function (inputId) {
            var field = MAP[inputId];
            var target = el(field);
            if (!target) return;
            target.contentEditable = 'true';
            target.addEventListener('focus', function () {
                var rawTxt = (target.textContent || '').trim();
                var ph = PLACEHOLDERS[field];
                if (ph && (rawTxt === ph || /^[\s_–—-]*$/.test(rawTxt) || /^STD(\s*[–—\-]?\s*[_–—-]*)?$/i.test(rawTxt))) {
                    setTimeout(function () {
                        try {
                            var range = document.createRange();
                            range.selectNodeContents(target);
                            var sel = window.getSelection();
                            sel.removeAllRanges();
                            sel.addRange(range);
                        } catch (_) {}
                    }, 0);
                }
            });
            target.addEventListener('blur', function () {
                var inp = input(inputId);
                var rawTxt = target.textContent || '';
                var trimmed = rawTxt.trim();
                var ph = PLACEHOLDERS[field];
                if (!trimmed || (ph && /^[\s_–—-]*$/.test(trimmed))) {
                    if (ph) target.textContent = ph;
                    if (inp) inp.value = '';
                } else {
                    if (inp) inp.value = trimmed;
                }
                syncBlankFill(target);
            });
            target.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); target.blur(); }
            });
        });
    }

    /* Edit-in-place toggle */
    document.getElementById('editModeBtn').addEventListener('click', function () {
        editOn = !editOn;
        sheet.dataset.mode = editOn ? 'edit' : 'view';
        document.getElementById('editModeLabel').textContent = editOn ? 'ON' : 'OFF';
        this.classList.toggle('off', !editOn);
        Object.values(MAP).forEach(function (f) {
            var t = el(f);
            if (t) t.contentEditable = editOn ? 'true' : 'false';
        });
    });
    // Enable contenteditable on all editables by default
    Object.values(MAP).forEach(function (f) {
        var t = el(f);
        if (t) t.contentEditable = 'true';
    });

    /* Reset: restore auto-filled defaults (the values PHP pre-filled into inputs) */
    document.getElementById('resetBtn').addEventListener('click', function () {
        Object.keys(MAP).forEach(function (inputId) {
            var inp = input(inputId);
            if (!inp) return;
            // Read original defaults from data attribute (stored on page load below)
            var def = inp.dataset.default || '';
            inp.value = def;
            var field = MAP[inputId];
            var target = el(field);
            if (target) {
                var ph = PLACEHOLDERS[field];
                target.textContent = def || (ph ? ph : (field === 'caste' ? '_______' : def));
                syncBlankFill(target);
            }
            delete dirty[inputId];
        });
    });

    // Capture original auto-filled defaults on load
    Object.keys(MAP).forEach(function (inputId) {
        var inp = input(inputId);
        if (inp) inp.dataset.default = inp.value;
    });

    /* ── Student picker ── */
    var listEl = document.getElementById('studentList');
    var loadBtn = document.getElementById('loadBtn');
    var pickClass = document.getElementById('pickClass');
    function syncPickTitle() {
        if (!pickClass) return;
        var opt = pickClass.options[pickClass.selectedIndex];
        pickClass.title = opt ? opt.text.trim() : '';
    }
    if (pickClass) { pickClass.addEventListener('change', syncPickTitle); syncPickTitle(); }

    loadBtn.addEventListener('click', function () {
        var classVal = document.getElementById('pickClass').value;
        var search   = document.getElementById('pickSearch').value.trim();
        var qs = new URLSearchParams();
        if (classVal) qs.set('class', classVal);
        if (search)   qs.set('search', search);
        qs.set('action', 'list');

        fetch(location.pathname + '?' + qs.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                listEl.style.display = 'block';
                listEl.innerHTML = '';
                if (!data.length) {
                    listEl.innerHTML = '<div class="no-students"><i class="fas fa-user-slash"></i>No students found.</div>';
                    return;
                }
                data.forEach(function (s) {
                    var row = document.createElement('div');
                    row.className = 'student-row';
                    row.dataset.roll = s.roll_no;
                    row.dataset.key = s.class_key;
                    row.dataset.dob = s.date_of_birth || '';
                    row.innerHTML =
                        '<span><span class="s-name">' + esc(s.student_name) + '</span>' +
                        '<span class="s-roll">Roll ' + esc(s.roll_no) + '</span></span>' +
                        '<span class="s-go"><i class="fas fa-chevron-right"></i></span>';
                    row.addEventListener('click', function () {
                        selectStudent(row, s);
                    });
                    listEl.appendChild(row);
                });
            });
    });

    document.getElementById('clearPickBtn').addEventListener('click', function () {
        listEl.style.display = 'none';
        listEl.innerHTML = '<div class="no-students">No students found.</div>';
    });

    document.getElementById('pickSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') loadBtn.click();
    });

    var bDataEl = document.getElementById('bonafideData');
    var bCfg = {};
    if (bDataEl) {
        try { bCfg = JSON.parse(bDataEl.textContent || '{}'); } catch (_) {}
    }
    var studentMeta = {
        roll_no: (bCfg.initialMeta && bCfg.initialMeta.roll_no) ? String(bCfg.initialMeta.roll_no) : '',
        std: (bCfg.initialMeta && bCfg.initialMeta.std) ? String(bCfg.initialMeta.std) : '',
        division: (bCfg.initialMeta && bCfg.initialMeta.division) ? String(bCfg.initialMeta.division) : '',
        parent_phone: (bCfg.initialMeta && bCfg.initialMeta.parent_phone) ? String(bCfg.initialMeta.parent_phone) : ''
    };

    function updateQrBadge(grNo, certSerial, verifyUrl, qrSvg) {
        var qrImg  = document.getElementById('certQrImg');
        var qrLink = document.getElementById('certQrLink');
        var qrGr   = document.getElementById('certQrGr');
        var qrSr   = document.getElementById('certQrSr');
        if (qrGr && grNo) qrGr.textContent = grNo;
        if (qrSr && certSerial) qrSr.textContent = certSerial;
        if (qrLink && verifyUrl) qrLink.href = verifyUrl;
        if (qrImg && qrSvg) qrImg.src = qrSvg;
    }

    var qrRefreshTimer = null;
    function scheduleQrRefresh() {
        clearTimeout(qrRefreshTimer);
        qrRefreshTimer = setTimeout(function () {
            var gr = (input('f_grno') ? input('f_grno').value : '').trim();
            var sr = (input('f_srno') ? input('f_srno').value : '').trim();
            if (!gr && !sr) return;
            var qs = new URLSearchParams({ action: 'qr_preview', gr: gr || '0', sr: sr || '01' });
            fetch(location.pathname + '?' + qs.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.ok) updateQrBadge(gr, sr, d.verify_url, d.qr_svg);
                })
                .catch(function () {});
        }, 240);
    }

    ['f_grno', 'f_srno'].forEach(function (id) {
        var inp = input(id);
        if (inp) inp.addEventListener('input', scheduleQrRefresh);
    });

    function selectStudent(row, s) {
        document.querySelectorAll('.student-row.selected').forEach(function (r) { r.classList.remove('selected'); });
        if (row) row.classList.add('selected');

        studentMeta = {
            roll_no: s.roll_no ? String(s.roll_no) : '',
            std: s.std ? String(s.std) : '',
            division: s.division ? String(s.division) : '',
            parent_phone: s.parent_phone ? String(s.parent_phone) : ''
        };

        // Fill form + preview with DB values
        fillField('f_student',  s.student_name || '');
        if (s.std || s.division) {
            fillField('f_std', 'STD – ' + (s.std || '') + (s.division ? ' – ' + s.division : ''));
        }
        fillField('f_dob',      s.date_of_birth || '');
        fillField('f_dobwords', s.dob_words ? s.dob_words : (s.date_of_birth ? wordsFromDigits(s.date_of_birth) : ''));
        fillField('f_ay',       s.academic_year || (bCfg.activeYear || ''));
        fillField('f_date',     todayDMY());
        if (s.gr_no) {
            fillField('f_grno', s.gr_no);
        }
        if (s.next_cert_serial) {
            fillField('f_srno', s.next_cert_serial);
        }
        if (s.caste_category) {
            fillField('f_caste', s.caste_category);
        } else {
            var cInp = input('f_caste'); if (cInp) { cInp.value = ''; cInp.dataset.default = ''; }
            var cT = el('caste'); if (cT) { cT.textContent = '_______'; syncBlankFill(cT); }
        }
        var rollEl = document.getElementById('sheetStudentRoll');
        if (rollEl) rollEl.textContent = s.roll_no || '';

        if (s.qr_svg || s.verify_url) {
            updateQrBadge(s.gr_no || (input('f_grno') ? input('f_grno').value : ''), s.next_cert_serial || (input('f_srno') ? input('f_srno').value : ''), s.verify_url || '', s.qr_svg || '');
        } else {
            scheduleQrRefresh();
        }
    }

    /* ── Instant GR / Student Auto-Fill ── */
    (function () {
        var grInp = document.getElementById('instantGrInput');
        var grBtn = document.getElementById('instantGrBtn');
        var grRes = document.getElementById('instantGrResults');
        if (!grInp || !grBtn || !grRes) return;

        var debounceTimer = null;
        function runGrLookup(autoPickExact) {
            var q = grInp.value.trim();
            if (!q) {
                grRes.style.display = 'none';
                grRes.innerHTML = '';
                return;
            }
            var qs = new URLSearchParams({ action: 'lookup_student', q: q });
            fetch(location.pathname + '?' + qs.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var rows = (data && Array.isArray(data.students)) ? data.students : [];
                    grRes.style.display = 'block';
                    grRes.innerHTML = '';
                    if (!rows.length) {
                        grRes.innerHTML = '<div class="instant-gr-item" style="color:#64748b;cursor:default;"><span>No matching student found.</span></div>';
                        return;
                    }
                    if (autoPickExact && rows.length === 1 && rows[0].gr_no && String(rows[0].gr_no).toLowerCase() === q.toLowerCase()) {
                        selectStudent(null, rows[0]);
                        grRes.style.display = 'none';
                        return;
                    }
                    rows.forEach(function (s) {
                        var item = document.createElement('div');
                        item.className = 'instant-gr-item';
                        var cls = (s.std ? ('STD ' + s.std + (s.division ? '-' + s.division : '')) : 'No Class');
                        var grTxt = s.gr_no ? ('GR #' + s.gr_no) : ('Roll #' + (s.roll_no || '—'));
                        item.innerHTML =
                            '<span><strong>' + esc(s.student_name) + '</strong>' +
                            '<small>' + esc(grTxt) + ' &bull; ' + esc(cls) + (s.date_of_birth ? ' &bull; DOB ' + esc(s.date_of_birth) : '') + '</small></span>' +
                            '<span style="color:var(--bf-brand);font-weight:700;font-size:.72rem;">Fill <i class="fas fa-arrow-right"></i></span>';
                        item.addEventListener('click', function () {
                            selectStudent(null, s);
                            grRes.style.display = 'none';
                        });
                        grRes.appendChild(item);
                    });
                })
                .catch(function () {});
        }

        grInp.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () { runGrLookup(false); }, 240);
        });
        grInp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(debounceTimer);
                runGrLookup(true);
            }
        });
        grBtn.addEventListener('click', function () {
            clearTimeout(debounceTimer);
            runGrLookup(true);
        });
    })();

    function fillField(inputId, val) {
        var inp = input(inputId);
        if (inp) { inp.value = val; inp.dataset.default = val; }
        var field = MAP[inputId];
        var target = el(field);
        if (target) {
            var ph = PLACEHOLDERS[field];
            target.textContent = val || (ph ? ph : '____');
            syncBlankFill(target);
        }
    }

    function todayDMY() {
        var d = new Date();
        return String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0') + '/' + d.getFullYear();
    }

    function wordsFromDigits(dobStr) {
        // Mirror PHP dobToWords on the client (simple dd/mm/yyyy → words)
        var m = String(dobStr || '').match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) return dobStr || '';
        var d = +m[1], mo = +m[2], y = +m[3];
        if (d < 1 || d > 31 || mo < 1 || mo > 12) return dobStr;
        var days = ['','First','Second','Third','Fourth','Fifth','Sixth','Seventh','Eighth','Ninth','Tenth','Eleventh','Twelfth','Thirteenth','Fourteenth','Fifteenth','Sixteenth','Seventeenth','Eighteenth','Nineteenth','Twentieth','Twenty First','Twenty Second','Twenty Third','Twenty Fourth','Twenty Fifth','Twenty Sixth','Twenty Seventh','Twenty Eighth','Twenty Ninth','Thirtieth','Thirty First'];
        var months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
        var ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
        var tens = ['','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
        function numWords(n) {
            var out = '';
            var th = Math.floor(n/1000), h = Math.floor(n%1000/100), lr = n%100;
            if (th>0) out = ones[th]+' Thousand';
            if (h>0)  out += (out?' ':'') + ones[h]+' Hundred';
            if (lr>0) {
                if (lr>=20) { var tw=Math.floor(lr/10), os=lr%10; out += (out?' ':'')+tens[tw]; if(os>0) out+=' '+ones[os]; }
                else { out += (out?' ':'')+ones[lr]; }
            }
            return out.trim();
        }
        return days[d] + ' ' + months[mo] + ' ' + numWords(y);
    }

    function esc(s) {
        var div = document.createElement('div');
        div.textContent = String(s || '');
        return div.innerHTML;
    }

    /* ── Passport photo: click box to upload, preview instantly ── */
    (function () {
        var box = document.getElementById('photoBox');
        var inp = document.getElementById('photoInput');
        var img = document.getElementById('photoImg');
        if (!box || !inp || !img) return;
        box.addEventListener('click', function (e) {
            if (sheet.dataset.mode !== 'edit') return;
            inp.click();
        });
        inp.addEventListener('change', function () {
            var f = inp.files && inp.files[0];
            if (!f) return;
            if (!/^image\//.test(f.type)) { alert('Please choose an image file.'); return; }
            if (f.size > 5 * 1024 * 1024) { alert('Image too large (max 5MB).'); return; }
            var rd = new FileReader();
            rd.onload = function () { img.src = rd.result; box.classList.add('has-img'); };
            rd.readAsDataURL(f);
        });
    })();

    /* ── Print (hardened: clean view-mode snapshot during print) ── */
    var printBtn = document.getElementById('printBtn');
    var savedMode = null;
    function enterPrintView() {
        savedMode = sheet.dataset.mode;
        sheet.dataset.mode = 'view';
        Object.values(MAP).forEach(function (f) { var t = el(f); if (t) t.contentEditable = 'false'; });
    }
    function exitPrintView() {
        sheet.dataset.mode = savedMode || 'edit';
        if ((savedMode || 'edit') === 'edit') Object.values(MAP).forEach(function (f) { var t = el(f); if (t) t.contentEditable = 'true'; });
        savedMode = null;
    }
    if ('onbeforeprint' in window) {
        window.addEventListener('beforeprint', enterPrintView);
        window.addEventListener('afterprint', exitPrintView);
    }
    if (printBtn) printBtn.addEventListener('click', function () {
        try {
            var prev = document.title;
            var sEl = document.getElementById('f_student');
            var aEl = document.getElementById('f_ay');
            var name = sEl ? sEl.value.trim() : '';
            var ay   = aEl ? aEl.value.trim() : '';
            document.title = 'Bonafide_' + (name ? name.replace(/\s+/g,'_') : 'Student') + '_' + (ay || 'cert');
            enterPrintView();
            window.print();
            setTimeout(function () { document.title = prev; exitPrintView(); }, 1000);
        } catch (e) { console.error(e); window.print(); }
    });

    /* ── PDF Download (client-side via html2canvas + jsPDF) ── */
    document.getElementById('pdfBtn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        var origText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';

        // Temporarily switch to view mode for clean render
        var wasMode = sheet.dataset.mode;
        sheet.dataset.mode = 'view';
        Object.values(MAP).forEach(function (f) { var t = el(f); if (t) t.contentEditable = 'false'; });

        var target = sheet;
        html2canvas(target, { scale: 2, useCORS: true, backgroundColor: '#ffffff', onclone: function (doc) {
            doc.getElementById('certSheet').dataset.mode = 'view';
        } })
        .then(function (canvas) {
            var imgData = canvas.toDataURL('image/png');
            var jsPDF = window.jspdf.jsPDF;
            var pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
            var pageW = 210, pageH = 297;
            var imgW  = pageW;
            var imgH  = (canvas.height / canvas.width) * pageW;
            if (imgH > pageH) { imgH = pageH; imgW = (canvas.width / canvas.height) * pageH; }
            var x = (pageW - imgW) / 2, y = (pageH - imgH) / 2;
            pdf.addImage(imgData, 'PNG', x, y, imgW, imgH, undefined, 'FAST');
            var name = document.getElementById('f_student').value.trim().replace(/\s+/g,'_') || 'student';
            var ay   = document.getElementById('f_ay').value.trim() || 'cert';
            pdf.save('Bonafide_' + name + '_' + ay + '.pdf');
            restoreMode(wasMode);
        })
        .catch(function (err) {
            alert('PDF generation failed: ' + err.message);
            console.error(err);
            restoreMode(wasMode);
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = origText;
        });

        function restoreMode(m) {
            sheet.dataset.mode = m;
            if (m === 'edit') Object.values(MAP).forEach(function (f) { var t = el(f); if (t) t.contentEditable = 'true'; });
        }
    });

    document.querySelectorAll('.cert-body .cert-fill').forEach(syncBlankFill);
    var certSheet = document.getElementById('certSheet');
    if (certSheet) {
        certSheet.addEventListener('input', function (e) {
            var t = e.target && e.target.closest ? e.target.closest('.cert-body .cert-fill') : null;
            if (t) {
                syncBlankFill(t);
                var fieldName = t.getAttribute('data-editable');
                if (fieldName) {
                    var inputId = Object.keys(MAP).find(function (k) { return MAP[k] === fieldName; });
                    if (inputId) {
                        var inp = input(inputId);
                        if (inp) {
                            var val = (t.textContent || '').trim();
                            inp.value = (/^[\s_–—-]*$/.test(val) || /^STD(\s*[–—\-]?\s*[_–—-]*)?$/i.test(val)) ? '' : t.textContent;
                        }
                    }
                }
            }
        });
    }

    /* ── Archive Vault Logging & WhatsApp Share Integration ── */
    function dmyToIso(dobStr) {
        var s = String(dobStr || '').trim();
        if (!s) return '';
        var iso = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (iso) return s;
        var m = s.match(/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/);
        if (m) {
            return m[3] + '-' + String(m[2]).padStart(2, '0') + '-' + String(m[1]).padStart(2, '0');
        }
        return s;
    }

    function parseStdAndDivision(stdDisplay) {
        var s = String(stdDisplay || '').trim();
        var m = s.match(/^STD\s*[–—\-:]?\s*([A-Za-z0-9]+)\s*[–—\-:]\s*([A-Za-z])$/i);
        if (m) return { std: m[1].trim(), division: m[2].trim().toUpperCase() };
        return {
            std: studentMeta.std || s,
            division: studentMeta.division || ''
        };
    }

    function logBonafideToArchiveVault() {
        try {
            var sName = (document.getElementById('f_student') ? document.getElementById('f_student').value : '').trim();
            if (!sName) return Promise.resolve(null);
            var grNo = (document.getElementById('f_grno') ? document.getElementById('f_grno').value : '').trim();
            var srNo = (document.getElementById('f_srno') ? document.getElementById('f_srno').value : '').trim();
            var stdRaw = (document.getElementById('f_std') ? document.getElementById('f_std').value : '').trim();
            var parsedCls = parseStdAndDivision(stdRaw);
            var ay = (document.getElementById('f_ay') ? document.getElementById('f_ay').value : '').trim() || (bCfg.activeYear || '');
            var dobDmy = (document.getElementById('f_dob') ? document.getElementById('f_dob').value : '').trim();
            var dobIso = dmyToIso(dobDmy);
            var caste = (document.getElementById('f_caste') ? document.getElementById('f_caste').value : '').trim();
            var csrf = bCfg.csrfToken || '';

            var fd = new FormData();
            fd.append('action', 'log_bonafide');
            if (csrf) fd.append('csrf_token', csrf);
            fd.append('student_name', sName);
            fd.append('gr_no', grNo);
            fd.append('cert_serial', srNo);
            fd.append('academic_year', ay);
            fd.append('roll_no', studentMeta.roll_no || '');
            fd.append('std', parsedCls.std);
            fd.append('division', parsedCls.division);
            fd.append('dob', dobIso);
            if (caste) fd.append('caste', caste);

            return fetch('bonafide_certificate.php', { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) {
                        if (res.cert_serial) fillField('f_srno', res.cert_serial);
                        updateQrBadge(res.gr_no || grNo, res.cert_serial || srNo, res.verify_url || '', res.qr_svg || '');
                    }
                    return res;
                })
                .catch(function (e) { console.error('Archive vault log notice:', e); return null; });
        } catch (e) {
            console.error(e);
            return Promise.resolve(null);
        }
    }

    var prn = document.getElementById('printBtn');
    if (prn) prn.addEventListener('click', logBonafideToArchiveVault);

    var pdfB = document.getElementById('pdfBtn');
    if (pdfB) pdfB.addEventListener('click', logBonafideToArchiveVault);

    var waCertBtn = document.getElementById('whatsappCertBtn');
    if (waCertBtn) {
        waCertBtn.addEventListener('click', function () {
            var btn = this;
            var origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing PDF...';

            logBonafideToArchiveVault().then(function () {
                var studentName = (document.getElementById('f_student') ? document.getElementById('f_student').value : '').trim() || 'Student';
                var grNo = (document.getElementById('f_grno') ? document.getElementById('f_grno').value : '').trim() || '—';
                var certSerial = (document.getElementById('f_srno') ? document.getElementById('f_srno').value : '').trim() || '—';
                var className = (document.getElementById('f_std') ? document.getElementById('f_std').value : '').trim() || '—';
                var ay = (document.getElementById('f_ay') ? document.getElementById('f_ay').value : '').trim() || '—';
                var dob = (document.getElementById('f_dob') ? document.getElementById('f_dob').value : '').trim() || '';

                if (!window.HCCWhatsApp) {
                    alert('WhatsApp sharing library is not loaded.');
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    return;
                }

                var msg = window.HCCWhatsApp.templates.bonafide({
                    schoolName: bCfg.schoolName || '',
                    studentName: studentName,
                    grNo: grNo,
                    certSerial: certSerial,
                    className: className,
                    academicYear: ay,
                    dob: dob
                });

                var sheetEl = document.getElementById('certSheet');
                var wasMode = sheetEl ? sheetEl.dataset.mode : 'edit';
                if (sheetEl) sheetEl.dataset.mode = 'view';

                html2canvas(sheetEl || document.body, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff'
                }).then(function (canvas) {
                    var imgData = canvas.toDataURL('image/jpeg', 0.95);
                    var jsPDF = window.jspdf.jsPDF;
                    var pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                    var pageW = 210, pageH = 297;
                    var imgW  = pageW;
                    var imgH  = (canvas.height / canvas.width) * pageW;
                    if (imgH > pageH) { imgH = pageH; imgW = (canvas.width / canvas.height) * pageH; }
                    var x = (pageW - imgW) / 2, y = (pageH - imgH) / 2;
                    pdf.addImage(imgData, 'JPEG', x, y, imgW, imgH, undefined, 'FAST');
                    var blob = pdf.output('blob');
                    var cleanName = studentName.replace(/[^a-zA-Z0-9_\-]/g, '_');
                    var fileName = 'Bonafide_' + cleanName + '_' + (ay.replace(/[^a-zA-Z0-9_\-]/g, '_') || 'cert') + '.pdf';

                    if (sheetEl) sheetEl.dataset.mode = wasMode;

                    window.HCCWhatsApp.openModal({
                        studentName: studentName,
                        roll: studentMeta.roll_no || '',
                        phone: studentMeta.parent_phone || '',
                        className: className,
                        classKey: 'bonafide_' + grNo,
                        messageText: msg,
                        pdfBlob: blob,
                        fileName: fileName,
                        onDownloadPdf: function () {
                            pdf.save(fileName);
                        }
                    });
                }).catch(function (err) {
                    console.error(err);
                    if (sheetEl) sheetEl.dataset.mode = wasMode;
                    window.HCCWhatsApp.openModal({
                        studentName: studentName,
                        roll: studentMeta.roll_no || '',
                        phone: studentMeta.parent_phone || '',
                        className: className,
                        classKey: 'bonafide_' + grNo,
                        messageText: msg
                    });
                }).finally(function () {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                });
            });
        });
    }

})();
