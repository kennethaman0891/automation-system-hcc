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

    function selectStudent(row, s) {
        document.querySelectorAll('.student-row.selected').forEach(function (r) { r.classList.remove('selected'); });
        row.classList.add('selected');
        // Fill form + preview with DB values
        fillField('f_student',  s.student_name);
        fillField('f_std',      'STD – ' + s.std + ' – ' + s.division);
        fillField('f_dob',      s.date_of_birth || '');
        fillField('f_dobwords', s.dob_words ? s.dob_words : (s.date_of_birth ? wordsFromDigits(s.date_of_birth) : ''));
        fillField('f_ay',       s.academic_year);
        fillField('f_date',     todayDMY());
        var cInp = input('f_caste'); if (cInp) { cInp.value = ''; cInp.dataset.default = ''; }
        var cT = el('caste'); if (cT) { cT.textContent = '_______'; syncBlankFill(cT); }
        var rollEl = document.getElementById('sheetStudentRoll');
        if (rollEl) rollEl.textContent = s.roll_no;
    }

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
    var bDataEl = document.getElementById('bonafideData');
    var bCfg = {};
    if (bDataEl) {
        try { bCfg = JSON.parse(bDataEl.textContent || '{}'); } catch (_) {}
    }

    function logBonafideToArchiveVault() {
        try {
            var sName = (document.getElementById('f_student') ? document.getElementById('f_student').value : '').trim();
            if (!sName) return;
            var grNo = (document.getElementById('f_grno') ? document.getElementById('f_grno').value : '').trim();
            var srNo = (document.getElementById('f_srno') ? document.getElementById('f_srno').value : '').trim();
            var std = (document.getElementById('f_std') ? document.getElementById('f_std').value : '').trim();
            var ay = (document.getElementById('f_ay') ? document.getElementById('f_ay').value : '').trim() || '2024-25';
            var dob = (document.getElementById('f_dob') ? document.getElementById('f_dob').value : '').trim();
            var csrf = bCfg.csrfToken || '';

            var fd = new FormData();
            fd.append('action', 'log_bonafide');
            if (csrf) fd.append('csrf_token', csrf);
            fd.append('student_name', sName);
            fd.append('gr_no', grNo);
            fd.append('cert_serial', srNo);
            fd.append('academic_year', ay);
            fd.append('std', std);
            fd.append('dob', dob);

            fetch('bonafide_certificate.php', { method: 'POST', body: fd })
                .catch(function (e) { console.error('Archive vault log notice:', e); });
        } catch (e) {
            console.error(e);
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

            var studentName = (document.getElementById('f_student') ? document.getElementById('f_student').value : '').trim() || 'Student';
            var grNo = (document.getElementById('f_grno') ? document.getElementById('f_grno').value : '').trim() || '—';
            var certSerial = (document.getElementById('f_srno') ? document.getElementById('f_srno').value : '').trim() || '—';
            var className = (document.getElementById('f_class') ? document.getElementById('f_class').value : '').trim() || '—';
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
                pdf.addImage(imgData, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');
                var blob = pdf.output('blob');
                var cleanName = studentName.replace(/[^a-zA-Z0-9_\-]/g, '_');
                var fileName = 'Bonafide_' + cleanName + '_' + (ay.replace(/[^a-zA-Z0-9_\-]/g, '_') || 'cert') + '.pdf';

                if (sheetEl) sheetEl.dataset.mode = wasMode;

                window.HCCWhatsApp.openModal({
                    studentName: studentName,
                    roll: '',
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
                    roll: '',
                    className: className,
                    classKey: 'bonafide_' + grNo,
                    messageText: msg
                });
            }).finally(function () {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            });

            logBonafideToArchiveVault();
        });
    }

})();
