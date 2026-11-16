/**
 * HCC Automation System — Unified Data & Action Bridge
 * Unifies student row data binding, PDF generation orchestration, and
 * WhatsApp sharing modal interactions across Results and Unit Test consoles.
 */
(function (window, document) {
    'use strict';

    const HCCBridge = window.HCCBridge || {};

    /**
     * Helper to safely parse JSON from script tags
     */
    function getJsonData(elementId) {
        const el = document.getElementById(elementId);
        if (!el) return null;
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (e) {
            console.error('HCCBridge: Failed to parse JSON from #' + elementId, e);
            return null;
        }
    }

    /**
     * Initialize the Results Console (Report Cards) row click and WhatsApp bridge
     */
    HCCBridge.initResultsBridge = function (cfg) {
        if (!cfg) return;
        const marksMap   = cfg.marksMap || {};
        const schoolName = cfg.schoolName || '';
        const hdrStd     = cfg.hdrStd || '';
        const hdrDiv     = cfg.hdrDiv || '';
        const hdrSem     = cfg.hdrSem || '';
        const hdrYear    = cfg.hdrYear || '';

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.js-wa-row-btn');
            if (!btn) return;
            const d = btn.dataset;

            // Resolve class labels per student row
            const parts = (d.class || '').split('|');
            const rowYear = parts[0] || hdrYear;
            const rowStd  = parts[1] || hdrStd;
            const rowDiv  = parts[2] || hdrDiv;
            const rowSem  = parts[3] || hdrSem;
            const rowClsName = rowStd ? ('STD ' + rowStd + ' ' + rowDiv) : 'Student Report';

            const meta = {
                schoolName: schoolName,
                schoolTagline: cfg.schoolTagline || 'Excellence in Academic & Moral Education',
                className: rowClsName,
                classKey: d.class,
                semester: rowSem,
                academicYear: rowYear
            };

            // Retrieve subjects from marksMap if available
            const sMarks = marksMap[d.roll] || {};
            const subs = Object.keys(sMarks).map(function (k) {
                const m = sMarks[k];
                const faM = Number(m.fa_max) || 0;
                const saM = Number(m.sa_max) || 0;
                const faO = Number(m.fa_obtained) || 0;
                const saO = Number(m.sa_obtained) || 0;
                return {
                    name: m.subject_name || k,
                    fa_max: faM,
                    fa_obtained: faO,
                    sa_max: saM,
                    sa_obtained: saO,
                    total_max: faM + saM,
                    total_obtained: faO + saO,
                    obtained: faO + saO,
                    max: faM + saM
                };
            });

            const stObj = {
                roll: d.roll,
                name: d.name,
                obtained: d.obtained,
                max_total: d.total,
                pct: d.pct,
                grade: d.grade,
                status: d.status,
                attendance: d.attendance,
                remark: d.remark,
                subjects: subs,
                std: rowStd,
                division: rowDiv,
                semester: rowSem,
                academic_year: rowYear
            };

            const phoneKey = 'hcc_wa_phone_' + d.class + '_' + d.roll;
            const savedPhone = ((d.phone || '').trim()) || localStorage.getItem(phoneKey) || '';

            if (!window.HCCWhatsApp || !window.HCCWhatsApp.openModal) {
                alert('WhatsApp sharing module is loading or unavailable.');
                return;
            }

            const msg = window.HCCWhatsApp.templates.reportCard({
                schoolName: meta.schoolName,
                studentName: stObj.name,
                roll: stObj.roll,
                className: meta.className,
                semester: meta.semester,
                academicYear: meta.academicYear,
                obtained: stObj.obtained,
                maxTotal: stObj.max_total,
                percentage: stObj.pct,
                grade: stObj.grade,
                status: stObj.status,
                attendance: stObj.attendance,
                remark: stObj.remark,
                customNote: ''
            });

            function persistPhone(phone) {
                if (!phone) return;
                try { localStorage.setItem(phoneKey, phone); } catch (_) {}
                const csrfEl = document.querySelector('input[name="csrf_token"]');
                const csrfToken = csrfEl ? csrfEl.value : '';
                const headers = { 'Content-Type': 'application/json' };
                if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
                fetch('api_save_phone.php', {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify({
                        class_key: d.class,
                        roll: d.roll,
                        phone: phone,
                        table: 'report_card',
                        csrf_token: csrfToken
                    })
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.success) { btn.dataset.phone = phone; }
                }).catch(function (err) { console.warn('Phone save failed:', err); });
            }

            if (window.HCCPdfEngine && typeof window.HCCPdfEngine.generateSinglePdf === 'function') {
                if (window.HCCWhatsApp.showToast) {
                    window.HCCWhatsApp.showToast('Preparing A4 PDF for WhatsApp...', 'fa-spinner fa-spin');
                }
                window.HCCPdfEngine.generateSinglePdf(stObj, 'report_card', meta).then(function (res) {
                    window.HCCWhatsApp.openModal({
                        studentName: stObj.name,
                        roll: stObj.roll,
                        className: meta.className,
                        classKey: d.class,
                        phone: savedPhone,
                        messageText: msg,
                        pdfBlob: res.blob,
                        fileName: res.fileName,
                        onDownloadPdf: function () {
                            res.pdf.save(res.fileName);
                        },
                        onSent: persistPhone
                    });
                }).catch(function (err) {
                    console.warn('PDF generation fallback to text share:', err);
                    window.HCCWhatsApp.openModal({
                        studentName: stObj.name,
                        roll: stObj.roll,
                        className: meta.className,
                        classKey: d.class,
                        phone: savedPhone,
                        messageText: msg,
                        onSent: persistPhone
                    });
                });
            } else {
                window.HCCWhatsApp.openModal({
                    studentName: stObj.name,
                    roll: stObj.roll,
                    className: meta.className,
                    classKey: d.class,
                    phone: savedPhone,
                    messageText: msg,
                    onSent: persistPhone
                });
            }
        });
    };

    /**
     * Initialize the Unit Test Console student row and WhatsApp bridge
     */
    HCCBridge.initUnitTestBridge = function (cfg) {
        if (!cfg) return;
        const studentsData = cfg.students || [];
        const subjectsData = cfg.subjects || [];
        const totalMax     = Number(cfg.totalMax) || 100;
        const schoolName   = cfg.schoolName || '';
        const className    = cfg.className || '';
        const testType     = cfg.testType || '';
        const classKey     = cfg.classKey || '';
        const academicYear = cfg.academicYear || '';

        const meta = {
            schoolName: schoolName,
            className: className,
            classKey: classKey,
            academicYear: academicYear,
            testType: testType
        };

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.js-ut-wa-btn');
            if (!btn) return;
            const roll = btn.dataset.roll;
            const stu = studentsData.find(s => String(s.roll_no) === String(roll));
            if (!stu) return;

            const subs = subjectsData.map(s => {
                const sm = stu.subjects ? stu.subjects[s.subject_name] : null;
                let val = '0';
                if (sm) {
                    if (sm.is_absent) val = 'Absent';
                    else if (sm.marks_obtained !== null && sm.marks_obtained !== undefined) val = String(sm.marks_obtained);
                }
                return { name: s.subject_name, max: Number(s.max_marks), obtained: val };
            });

            const fStu = {
                roll: stu.roll_no,
                name: stu.student_name,
                gender: stu.gender || '',
                subjects: subs,
                obtained: stu.total_obtained,
                max_total: totalMax,
                pct: stu.percentage !== null ? Number(stu.percentage).toFixed(2) : '',
                grade: stu.grade || '',
                remark: stu.remark || '',
                attendance: stu.attendance || '',
                parents_sign: !!stu.parents_sign
            };

            const phoneKey = 'hcc_wa_phone_' + classKey + '_' + stu.roll_no;
            const dbPhone = String(stu.parent_phone || btn.dataset.phone || '').replace(/\D+/g, '');
            const savedPhone = dbPhone || localStorage.getItem(phoneKey) || '';

            if (window.HCCPdfEngine && window.HCCPdfEngine.shareWhatsAppWithPdf) {
                window.HCCPdfEngine.shareWhatsAppWithPdf(fStu, 'unit_test', meta, savedPhone, '');
            } else {
                alert('PDF engine is not ready.');
            }
        });
    };

    /**
     * Initialize Unit Test Bulk Select, Bulk Delete, and Bulk WhatsApp Dispatch
     */
    HCCBridge.initUnitTestBulk = function (cfg) {
        if (!cfg) return;
        const checkAll   = document.getElementById('utCheckAll');
        const bulkBtn    = document.getElementById('utBulkDeleteBtn');
        const bulkCount  = document.getElementById('utBulkDeleteCount');
        const bulkModal  = document.getElementById('utBulkDeleteModal');
        const bulkWaBtn  = document.getElementById('utBulkWaBtn');
        const bulkWaCount = document.getElementById('utBulkWaCount');

        if (!bulkBtn || !bulkModal) return;

        const boxes = () => Array.from(document.querySelectorAll('.ut-row-check[name="rolls[]"]:not(:disabled)'));
        let lastFocus = null;

        function refresh() {
            const checked = boxes().filter(b => b.checked);
            const n = checked.length;
            bulkBtn.disabled = n === 0;
            if (bulkCount) bulkCount.textContent = n;
            if (bulkWaBtn) bulkWaBtn.disabled = n === 0;
            if (bulkWaCount) bulkWaCount.textContent = n;
            if (checkAll) {
                const all = boxes();
                checkAll.checked = all.length > 0 && all.every(b => b.checked);
                checkAll.indeterminate = n > 0 && n < all.length;
            }
            document.querySelectorAll('#unitTestTable tbody tr').forEach(tr => {
                const box = tr.querySelector('.ut-row-check[name="rolls[]"]');
                tr.classList.toggle('is-checked', !!(box && box.checked));
            });
        }

        if (checkAll) {
            checkAll.addEventListener('change', () => {
                boxes().forEach(b => { b.checked = checkAll.checked; });
                refresh();
            });
        }

        document.addEventListener('change', (e) => {
            if (e.target && e.target.classList && e.target.classList.contains('ut-row-check')) {
                refresh();
            }
        });

        refresh();

        if (bulkWaBtn) {
            bulkWaBtn.addEventListener('click', () => {
                const checked = boxes().filter(b => b.checked);
                if (checked.length === 0) return;
                if (checked.length === 1) {
                    const tr = checked[0].closest('tr');
                    const rowWaBtn = tr ? tr.querySelector('.js-ut-wa-btn') : null;
                    if (rowWaBtn) { rowWaBtn.click(); return; }
                }
                const rolls = checked.map(b => b.value).filter(Boolean);
                const classKeyVal = cfg.classKey || '';
                const testTypeVal = cfg.testType || '';
                const url = 'whatsapp_share.php?doc_type=unit_test&test_type=' + encodeURIComponent(testTypeVal) +
                            '&class=' + encodeURIComponent(classKeyVal) +
                            '&selected_rolls=' + encodeURIComponent(rolls.join(','));
                window.location.href = url;
            });
        }

        function openBulkModal() {
            const n = boxes().filter(b => b.checked).length;
            if (n === 0) return;
            const countEl = document.getElementById('utBulkDeleteModalCount');
            if (countEl) countEl.textContent = n;
            lastFocus = document.activeElement;
            bulkModal.hidden = false;
            document.body.style.overflow = 'hidden';
            const cancelBtn = document.getElementById('utBulkDeleteCancelBtn');
            if (cancelBtn) cancelBtn.focus();
        }

        function closeBulkModal() {
            bulkModal.hidden = true;
            document.body.style.overflow = '';
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        bulkBtn.addEventListener('click', openBulkModal);
        const cancelBtn = document.getElementById('utBulkDeleteCancelBtn');
        if (cancelBtn) cancelBtn.addEventListener('click', closeBulkModal);
        const closeTrigger = bulkModal.querySelector('[data-close]');
        if (closeTrigger) closeTrigger.addEventListener('click', closeBulkModal);

        document.addEventListener('keydown', (e) => {
            if (!bulkModal.hidden && e.key === 'Escape') closeBulkModal();
        });

        const confirmBtn = document.getElementById('utBulkDeleteConfirmBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                confirmBtn.disabled = true;
                const form = document.getElementById('utBulkDeleteForm');
                if (form) form.submit();
            });
        }
    };

    /**
     * Auto-bind when DOM is ready if script configurations are detected
     */
    function autoInit() {
        // Results Bridge
        const resultsData = getJsonData('resultsBridgeData');
        if (resultsData) {
            HCCBridge.initResultsBridge(resultsData);
        }

        // Unit Test Bridge
        const utData = getJsonData('unitTestBridgeData');
        if (utData) {
            HCCBridge.initUnitTestBridge(utData);
            HCCBridge.initUnitTestBulk(utData);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }

    window.HCCBridge = HCCBridge;

})(window, document);
