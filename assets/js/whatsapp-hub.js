/**
 * HCC Automation System — WhatsApp Hub Controller
 * Manages the interactive WhatsApp Share Hub, student roster stepper,
 * live preview bubble, companion console launcher, phone number persistence,
 * and batch automation dispatch.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const dataEl = document.getElementById('waHubData');
        if (!dataEl) return;

        let cfg = {};
        try {
            cfg = JSON.parse(dataEl.textContent || '{}');
        } catch (e) {
            console.error('Failed to parse waHubData JSON:', e);
            return;
        }

        const rawStudents   = cfg.students || [];
        const schoolName    = cfg.schoolName || '';
        const classKey      = cfg.classKey || '';
        const docType       = cfg.docType || 'report_card';
        const className     = cfg.className || '';
        const semester      = cfg.semester || '';
        const testType      = cfg.testType || '';
        const academicYear  = cfg.academicYear || '';
        const schoolTagline = cfg.schoolTagline || 'Excellence in Academic & Moral Education';
        const principalName = cfg.principalName || 'PRINCIPAL';

        const meta = {
            schoolName: schoolName,
            schoolTagline: schoolTagline,
            principalName: principalName,
            className: className,
            classKey: classKey,
            academicYear: academicYear,
            semester: semester,
            testType: testType
        };

        let activeIdx = 0;
        let seqMode = false;
        const statusMap = new Map();
        // Skip the auto-scroll on the very first render so the class/group list
        // stays at the top instead of jumping straight to the student rows.
        let initialPreviewDone = false;

        // Attachment state. When null, the chip shows the auto-generated official
        // PDF for the active student; when set, it shows the file the teacher
        // picked from disk (which can then be dragged into the WhatsApp Web chat).
        let attachedFile = null;
        let attachedUrl  = null;
        let attachedRoll = null; // roll the attachment belongs to — never carry across students

        // Per-student manually-edited message overrides (keyed by roll).
        // When present, this exact text is what gets copied/opened/auto-sent.
        let messageOverrides = {};

        // Initialize statusMap from server-persisted roster data
        rawStudents.forEach(st => {
            const roll = String(st.roll);
            const p = (st.parent_phone || '').toString().trim();
            const hasPhone = !!(window.HCCWhatsApp && window.HCCWhatsApp.cleanPhone(p));
            const initStatus = st.dispatch_status || (hasPhone ? 'pending' : 'no_phone');
            statusMap.set(roll, initStatus);
        });

        // Load saved message overrides from localStorage
        const msgStorageKey = 'hcc_wa_msg_' + classKey + '_' + docType;
        try {
            const savedMsgs = JSON.parse(localStorage.getItem(msgStorageKey) || '{}');
            if (savedMsgs && typeof savedMsgs === 'object' && !Array.isArray(savedMsgs)) {
                messageOverrides = savedMsgs;
            }
        } catch (e) {}

        function persistMessageOverrides() {
            try {
                localStorage.setItem(msgStorageKey, JSON.stringify(messageOverrides));
            } catch (e) {}
        }

        const customNoteInput     = document.getElementById('customNoteInput');
        const mockStudentName     = document.getElementById('mockStudentName');
        const mockStudentRoll     = document.getElementById('mockStudentRoll');
        const mockPhoneStatus     = document.getElementById('mockPhoneStatus');
        const mockAvatar          = document.getElementById('mockAvatar');
        const mockBubbleText      = document.getElementById('mockBubbleText');
        const mockPdfFilename     = document.getElementById('mockPdfFilename');
        const mockDownloadPdfBtn  = document.getElementById('mockDownloadPdfBtn');
        const waDragTile          = document.getElementById('waDragTile');
        const attachPickBtn       = document.getElementById('attachPickBtn');
        const attachCustomBtn     = document.getElementById('attachCustomBtn');
        const attachRemoveBtn     = document.getElementById('attachRemoveBtn');
        const attachMeta          = document.getElementById('attachMeta');
        const attachInput         = document.getElementById('pdfAttachInput');
        const openCompanionBtn    = document.getElementById('openCompanionBtn');
        const mockCopyBtn         = document.getElementById('mockCopyDirectBtn');
        const prevStudentBtn      = document.getElementById('prevStudentBtn');
        const sendAndNextBtn      = document.getElementById('sendAndNextBtn');

        // WhatsApp-Web layout shell
        const waApp          = document.getElementById('waApp');
        const waEmptyPane    = document.getElementById('waEmptyPane');
        const chatBackBtn    = document.getElementById('chatBackBtn');
        const paneSendBtn    = document.getElementById('paneSendBtn');
        const panePhoneInput = document.getElementById('panePhoneInput');
        const batchMenuBtn   = document.getElementById('batchMenuBtn');
        const batchMenu      = document.getElementById('batchMenu');

        const totalCountEl   = document.getElementById('totalCountEl');
        const sentCountEl    = document.getElementById('sentCountEl');
        const pendingCountEl = document.getElementById('pendingCountEl');
        const progressPctEl  = document.getElementById('progressPctEl');

        const seqBar          = document.getElementById('seqBar');
        const seqCountPill    = document.getElementById('seqCountPill');
        const seqStudentName  = document.getElementById('seqStudentName');
        const seqSendNextBtn  = document.getElementById('seqSendNextBtn');
        const seqStopBtn      = document.getElementById('seqStopBtn');

        const csrfTokenEl     = document.getElementById('csrfToken');
        const csrfToken       = csrfTokenEl ? csrfTokenEl.value : '';

        // In-Software Companion Window Reference
        let companionWindow = null;

        function openWhatsAppCompanion(phone, message) {
            const cleaned = (window.HCCWhatsApp && window.HCCWhatsApp.cleanPhone) ? window.HCCWhatsApp.cleanPhone(phone) : phone;
            let targetUrl;
            if (cleaned) {
                targetUrl = 'https://web.whatsapp.com/send?phone=' + encodeURIComponent(cleaned) + '&text=' + encodeURIComponent(message || '');
            } else {
                targetUrl = 'https://web.whatsapp.com';
            }

            const winWidth = 1040;
            const winHeight = 820;
            const left = Math.max(0, Math.round((screen.width - winWidth) / 2));
            const top = Math.max(0, Math.round((screen.height - winHeight) / 2));
            const windowFeatures = 'width=' + winWidth + ',height=' + winHeight + ',left=' + left + ',top=' + top + ',menubar=no,toolbar=no,location=no,status=no,resizable=yes,scrollbars=yes';

            try {
                if (companionWindow && !companionWindow.closed) {
                    companionWindow.location.href = targetUrl;
                    companionWindow.focus();
                } else {
                    companionWindow = window.open(targetUrl, 'HCC_WhatsApp_Console', windowFeatures);
                    if (!companionWindow || companionWindow.closed || typeof companionWindow.closed === 'undefined') {
                        // Popup blocker fallback
                        window.open(targetUrl, '_blank');
                    }
                }
            } catch (err) {
                companionWindow = window.open(targetUrl, 'HCC_WhatsApp_Console', windowFeatures);
            }
        }

        // Database & Cache Phone Autosave
        function saveParentPhone(roll, phone, inputEl) {
            if (inputEl) {
                inputEl.classList.remove('is-saved');
                inputEl.classList.add('is-saving');
            }

            const fd = new FormData();
            fd.append('action', 'update_parent_phone');
            fd.append('csrf_token', csrfToken);
            fd.append('roll_no', roll);
            fd.append('phone', phone);
            fd.append('doc_type', docType);
            fd.append('class_key', classKey);
            fd.append('test_type', testType);

            fetch('whatsapp_share.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(res => {
                if (inputEl) {
                    inputEl.classList.remove('is-saving');
                    if (res.ok) {
                        inputEl.classList.add('is-saved');
                        setTimeout(() => inputEl.classList.remove('is-saved'), 1800);
                    }
                }
                // Update local memory and cache
                const st = rawStudents.find(s => String(s.roll) === String(roll));
                if (st) st.parent_phone = phone;
                localStorage.setItem('hcc_wa_phone_' + classKey + '_' + roll, phone);
                updateStats();
                updateFilterCounts();
                if (rawStudents[activeIdx] && String(rawStudents[activeIdx].roll) === String(roll)) {
                    updatePreview(activeIdx);
                }
            })
            .catch(err => {
                console.error('Phone autosave failed:', err);
                if (inputEl) inputEl.classList.remove('is-saving');
                localStorage.setItem('hcc_wa_phone_' + classKey + '_' + roll, phone);
            });
        }

        // Populate phone numbers from MySQL, falling back to the localStorage cache
        // (the per-row inputs are gone — phones now live in the composer input).
        rawStudents.forEach(st => {
            const phoneKey = 'hcc_wa_phone_' + classKey + '_' + st.roll;
            const savedLocal = localStorage.getItem(phoneKey) || '';
            const dbVal = (st.parent_phone || '').toString().trim();

            if (dbVal) {
                st.parent_phone = dbVal;
                localStorage.setItem(phoneKey, dbVal);
            } else if (savedLocal) {
                st.parent_phone = savedLocal;
            }
        });

        function getStudentPhone(roll) {
            const st = rawStudents.find(s => String(s.roll) === String(roll));
            if (st && st.parent_phone) return String(st.parent_phone).trim();
            return localStorage.getItem('hcc_wa_phone_' + classKey + '_' + roll) || '';
        }

        // Filter Pills & Table Row Filtering
        let activeFilter = 'all';

        function updateStatusPill(roll, status) {
            const pill = document.getElementById('statusPill_' + roll);
            if (!pill) return;
            const pillClass = 'wa-status-' + String(status).replace(/_/g, '-');
            pill.className = 'wa-status-pill ' + pillClass;

            let label = '⚪ Pending';
            if (status === 'sent') label = '🟢 Sent';
            else if (status === 'opened') label = '🟡 Opened';
            else if (status === 'ready') label = '🔵 Ready';
            else if (status === 'skipped') label = '⚪ Skipped';
            else if (status === 'failed') label = '🔴 Failed';
            else if (status === 'no_phone') label = '⚠️ No Phone';

            pill.textContent = label;

            const row = document.querySelector(`.wa-chat-item[data-roll="${roll}"]`);
            if (row) row.dataset.status = status;
        }

        function logDispatchStatus(st, status, errorMsg) {
            if (!st) return Promise.resolve();
            const roll = String(st.roll);
            statusMap.set(roll, status);
            st.dispatch_status = status;
            updateStatusPill(roll, status);
            updateStats();
            updateFilterCounts();

            const fd = new FormData();
            fd.append('action', 'log_dispatch_status');
            fd.append('csrf_token', csrfToken);
            fd.append('student_roll', roll);
            fd.append('student_name', st.name || '');
            fd.append('parent_phone', getStudentPhone(st.roll) || '');
            fd.append('doc_type', docType);
            fd.append('class_key', classKey);
            fd.append('test_type', testType);
            fd.append('status', status);
            fd.append('pdf_filename', generatedPdfName(st));
            fd.append('message_text', getActiveMessage(st));
            if (errorMsg) fd.append('error_message', errorMsg);

            return fetch('whatsapp_share.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(res => {
                if (res && res.ok) {
                    st.dispatched_by = res.dispatched_by;
                    st.dispatched_at = res.dispatched_at;
                }
                return res;
            })
            .catch(err => {
                console.warn('Dispatch log update failed:', err);
            });
        }

        function updateFilterCounts() {
            const total = rawStudents.length;
            let sent = 0;
            let missing = 0;

            rawStudents.forEach(st => {
                const s = statusMap.get(String(st.roll)) || 'pending';
                if (s === 'sent') sent++;
                const p = getStudentPhone(st.roll);
                if (!window.HCCWhatsApp || !window.HCCWhatsApp.cleanPhone(p)) missing++;
            });

            const pending = Math.max(0, total - sent);

            const pAll = document.getElementById('pillCountAll');
            const pPending = document.getElementById('pillCountPending');
            const pSent = document.getElementById('pillCountSent');
            const pMissing = document.getElementById('pillCountMissing');

            if (pAll) pAll.textContent = total;
            if (pPending) pPending.textContent = pending;
            if (pSent) pSent.textContent = sent;
            if (pMissing) pMissing.textContent = missing;
        }

        function applyRosterFilter(filterName) {
            activeFilter = filterName;
            document.querySelectorAll('#rosterFilterPills .wa-pill').forEach(btn => {
                btn.classList.toggle('is-active', btn.dataset.filter === filterName);
            });

            document.querySelectorAll('.wa-chat-item').forEach(item => {
                const idx = parseInt(item.dataset.idx, 10);
                const st = rawStudents[idx];
                if (!st) return;

                const s = statusMap.get(String(st.roll)) || 'pending';
                const phone = getStudentPhone(st.roll);
                const hasPhone = !!(window.HCCWhatsApp && window.HCCWhatsApp.cleanPhone(phone));

                let show = true;
                if (filterName === 'pending') {
                    show = (s !== 'sent');
                } else if (filterName === 'sent') {
                    show = (s === 'sent');
                } else if (filterName === 'missing') {
                    show = !hasPhone || s === 'no_phone';
                }

                item.style.display = show ? '' : 'none';
            });
        }

        document.querySelectorAll('#rosterFilterPills .wa-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                applyRosterFilter(btn.dataset.filter || 'all');
            });
        });

        function updateStats() {
            const total = rawStudents.length;
            let sent = 0;

            rawStudents.forEach(st => {
                const s = statusMap.get(String(st.roll)) || 'pending';
                if (s === 'sent') sent++;
                updateStatusPill(String(st.roll), s);
            });

            const pending = Math.max(0, total - sent);
            const pct = total > 0 ? Math.round((sent / total) * 100) : 0;

            if (totalCountEl) totalCountEl.textContent = total;
            if (sentCountEl) sentCountEl.textContent = sent;
            if (pendingCountEl) pendingCountEl.textContent = pending;
            if (progressPctEl) progressPctEl.textContent = pct + '%';

            updateFilterCounts();
        }

        function buildMessageForStudent(st) {
            const note = customNoteInput ? customNoteInput.value.trim() : '';
            if (!window.HCCWhatsApp || !window.HCCWhatsApp.templates) return '';

            if (docType === 'unit_test') {
                return window.HCCWhatsApp.templates.unitTest({
                    schoolName: schoolName,
                    studentName: st.name,
                    roll: st.roll,
                    className: className,
                    testType: testType,
                    subjects: st.subjects,
                    obtained: st.obtained,
                    maxTotal: st.max_total,
                    percentage: st.pct,
                    grade: st.grade,
                    remark: st.remark,
                    customNote: note,
                    academicYear: academicYear,
                    principalName: principalName
                });
            } else if (docType === 'bonafide') {
                return window.HCCWhatsApp.templates.bonafide({
                    schoolName: schoolName,
                    studentName: st.name,
                    roll: st.roll,
                    className: className,
                    academicYear: st.academic_year || academicYear,
                    grNo: st.gr_no || '3875',
                    certSerial: st.cert_serial || st.roll,
                    dob: st.dob_words || st.dob_digits || '',
                    issueDate: st.issue_date || '',
                    customNote: note,
                    principalName: principalName
                });
            } else if (docType === 'announcement') {
                if (window.HCCWhatsApp.templates.announcement) {
                    return window.HCCWhatsApp.templates.announcement({
                        schoolName: schoolName,
                        studentName: st.name,
                        roll: st.roll,
                        className: className,
                        customNote: note,
                        academicYear: academicYear,
                        principalName: principalName
                    });
                }
                return window.HCCWhatsApp.templates.reportCard({
                    schoolName: schoolName,
                    studentName: st.name,
                    roll: st.roll,
                    className: className,
                    customNote: note,
                    academicYear: academicYear,
                    principalName: principalName
                });
            } else {
                return window.HCCWhatsApp.templates.reportCard({
                    schoolName: schoolName,
                    studentName: st.name,
                    roll: st.roll,
                    className: className,
                    semester: semester,
                    obtained: st.obtained,
                    maxTotal: st.max_total,
                    percentage: st.pct,
                    grade: st.grade,
                    status: st.status,
                    attendance: st.attendance,
                    remark: st.remark,
                    customNote: note,
                    academicYear: academicYear,
                    principalName: principalName
                });
            }
        }

        // Returns the message that must actually be shared for a student:
        // a manually-edited override if the teacher typed one, otherwise the
        // auto-generated template (school header, marks, teacher note, …).
        function getActiveMessage(st) {
            if (!st) return '';
            const override = messageOverrides[String(st.roll)];
            if (typeof override === 'string' && override.trim() !== '') {
                return override;
            }
            return buildMessageForStudent(st);
        }

        // Toggle the "edited / reset" affordances for the active student.
        function syncBubbleEditState(st) {
            const isCustom = !!(st && typeof messageOverrides[String(st.roll)] === 'string' && messageOverrides[String(st.roll)].trim() !== '');
            const hint = document.getElementById('bubbleEditHint');
            const resetBtn = document.getElementById('bubbleResetBtn');
            if (hint) hint.textContent = isCustom ? 'Edited for this student — this exact text will be shared.' : 'Click to edit this message — changes are used when you send.';
            if (resetBtn) resetBtn.style.display = isCustom ? 'inline-flex' : 'none';
        }

        function updatePreview(idx) {
            if (!rawStudents[idx]) return;
            activeIdx = idx;
            const st = rawStudents[idx];
            const phone = getStudentPhone(st.roll);

            document.querySelectorAll('.wa-chat-item').forEach(item => item.classList.remove('is-active-student'));
            const activeRow = document.querySelector(`.wa-chat-item[data-idx="${idx}"]`);
            if (activeRow) {
                activeRow.classList.add('is-active-student');
                if (initialPreviewDone) activeRow.scrollIntoView({ block: 'nearest' });
            }

            // Reveal the conversation + sync the composer phone field
            if (waEmptyPane) waEmptyPane.style.display = 'none';
            if (mockBubbleText) mockBubbleText.style.display = '';
            if (panePhoneInput && document.activeElement !== panePhoneInput) {
                panePhoneInput.value = phone || '';
            }

            if (mockStudentName) mockStudentName.textContent = st.name;
            if (mockStudentRoll) mockStudentRoll.textContent = 'Roll ' + (st.roll || '0');
            if (mockPhoneStatus) {
                const cleaned = (window.HCCWhatsApp && window.HCCWhatsApp.cleanPhone) ? window.HCCWhatsApp.cleanPhone(phone) : phone;
                mockPhoneStatus.innerHTML = cleaned ? '<i class="fa-brands fa-whatsapp"></i> ' + (window.HCCWhatsApp ? window.HCCWhatsApp.formatPhoneDisplay(cleaned) : cleaned) : '<i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i> No parent phone';
            }

            const initials = st.name.split(/\s+/).slice(0, 2).map(p => p.charAt(0).toUpperCase()).join('') || 'ST';
            if (mockAvatar) mockAvatar.textContent = initials;

            const msg = getActiveMessage(st);
            // Don't clobber the bubble while the teacher is actively editing it.
            if (mockBubbleText && document.activeElement !== mockBubbleText) {
                mockBubbleText.textContent = msg;
            }
            syncBubbleEditState(st);

            if (mockPdfFilename) renderAttachment();

            if (prevStudentBtn) prevStudentBtn.disabled = (idx <= 0);
            if (sendAndNextBtn) {
                const isLast = (idx >= rawStudents.length - 1);
                sendAndNextBtn.innerHTML = isLast ? '<span>Mark as Sent</span> <i class="fas fa-check"></i>' : '<span>Mark Sent &amp; Next</span> <i class="fas fa-arrow-right"></i>';
            }

            if (seqMode) updateSeqBar();

            initialPreviewDone = true;
        }

        // ---------- PDF attachment ----------
        function generatedPdfName(st) {
            if (!st) return 'Document.pdf';
            let docPrefix = 'Report_Card';
            if (docType === 'unit_test') docPrefix = (testType || 'Unit_Test').replace(/\s+/g, '_');
            else if (docType === 'bonafide') docPrefix = 'Bonafide_Certificate';
            else if (docType === 'announcement') docPrefix = 'Notice_Announcement';
            const rollStr = String(st.roll || '0').padStart(2, '0');
            const cleanName = (st.name || 'Student').replace(/[^a-zA-Z0-9_\-]/g, '_');
            return docPrefix + '_Roll_' + rollStr + '_' + cleanName + '.pdf';
        }

        function openWhatsAppForStudent(idx) {
            if (!rawStudents[idx]) return;
            const st = rawStudents[idx];
            const phone = getStudentPhone(st.roll);
            const msg = getActiveMessage(st);
            const cleaned = (window.HCCWhatsApp && window.HCCWhatsApp.cleanPhone) ? window.HCCWhatsApp.cleanPhone(phone) : phone;

            if (!cleaned) {
                if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                    window.HCCWhatsApp.showToast('⚠️ No parent phone number for ' + st.name + '.', 'fa-triangle-exclamation');
                }
                logDispatchStatus(st, 'no_phone', 'Missing parent phone');
                return;
            }

            // 1. Trigger PDF download with predictable filename
            if (window.HCCPdfEngine && window.HCCPdfEngine.downloadSinglePdf) {
                window.HCCPdfEngine.downloadSinglePdf(st, docType, meta);
            }

            // 2. Open official wa.me click-to-chat URL
            const waUrl = 'https://wa.me/' + encodeURIComponent(cleaned) + '?text=' + encodeURIComponent(msg || '');
            try {
                const win = window.open(waUrl, '_blank', 'noopener,noreferrer');
                if (!win || win.closed || typeof win.closed === 'undefined') {
                    const a = document.createElement('a');
                    a.href = waUrl;
                    a.target = '_blank';
                    a.rel = 'noopener noreferrer';
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(() => { try { a.remove(); } catch (e) {} }, 100);
                }
            } catch (e) {
                window.open(waUrl, '_blank');
            }

            // 3. Mark status as 'opened' (never mark sent merely on chat open)
            logDispatchStatus(st, 'opened');

            // 4. Toast guidance
            setTimeout(() => {
                if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                    window.HCCWhatsApp.showToast('💬 WhatsApp opened & PDF downloaded! Attach PDF in chat, send, then click "Mark as Sent".', 'fa-paperclip');
                }
            }, 600);
        }

        function markStudentSent(idx, advance) {
            if (!rawStudents[idx]) return;
            const st = rawStudents[idx];
            logDispatchStatus(st, 'sent');
            if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                window.HCCWhatsApp.showToast('✅ Confirmed sent for ' + st.name + '!', 'fa-check-double');
            }
            if (advance) {
                advanceToNextStudent();
            }
        }

        function skipStudent(idx, advance) {
            if (!rawStudents[idx]) return;
            const st = rawStudents[idx];
            logDispatchStatus(st, 'skipped');
            if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                window.HCCWhatsApp.showToast('⚪ Skipped ' + st.name + '.', 'fa-forward');
            }
            if (advance) {
                advanceToNextStudent();
            }
        }

        function advanceToNextStudent() {
            let nextIdx = activeIdx + 1;
            while (nextIdx < rawStudents.length && statusMap.get(String(rawStudents[nextIdx].roll)) === 'sent') {
                nextIdx++;
            }

            if (nextIdx < rawStudents.length) {
                activeIdx = nextIdx;
                updatePreview(activeIdx);
                if (seqMode) updateSeqBar();
            } else {
                let firstUnsent = rawStudents.findIndex(s => statusMap.get(String(s.roll)) !== 'sent');
                if (firstUnsent !== -1 && firstUnsent !== activeIdx) {
                    activeIdx = firstUnsent;
                    updatePreview(activeIdx);
                    if (seqMode) updateSeqBar();
                } else {
                    alert('🎉 Awesome! All students in this class have been processed.');
                    if (seqBar) seqBar.classList.add('is-hidden');
                    seqMode = false;
                }
            }
        }

        // Open WhatsApp Button Click
        if (openCompanionBtn) {
            openCompanionBtn.addEventListener('click', () => {
                if (panePhoneInput) panePhoneInput.blur();
                openWhatsAppForStudent(activeIdx);
            });
        }

        function humanSize(bytes) {
            if (!bytes && bytes !== 0) return '';
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // Paints the attachment chip from current state. Called on every preview
        // change so the generated filename stays in sync with the active student,
        // without ever clobbering a file the teacher explicitly attached.
        function renderAttachment() {
            const st = rawStudents[activeIdx];

            // An attachment belongs to exactly one student. If the preview moved on
            // to someone else, drop it rather than risk sending the wrong document
            // to a parent.
            if (attachedFile) {
                const roll = st ? st.roll : null;
                if (attachedRoll !== null && String(attachedRoll) !== String(roll)) {
                    dropStaleAttachment();
                    if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                        window.HCCWhatsApp.showToast('Attachment cleared \u2014 it belonged to the previous student.', 'fa-rotate-left');
                    }
                }
            }

            const custom = !!attachedFile;

            if (waDragTile) {
                waDragTile.classList.toggle('is-custom', custom);
                waDragTile.setAttribute('draggable', custom ? 'true' : 'false');
                waDragTile.title = custom
                    ? 'Drag this file into the WhatsApp Web chat'
                    : 'Save this PDF, then attach it in WhatsApp Web';
            }
            if (mockPdfFilename) {
                mockPdfFilename.textContent = custom ? attachedFile.name : generatedPdfName(st);
            }
            if (attachMeta) {
                if (custom) {
                    const size = humanSize(attachedFile.size);
                    attachMeta.innerHTML = '<span class="wa-attach-tag">Attached</span>PDF'
                        + (size ? ' &bull; ' + size : '')
                        + ' &bull; drag into the WhatsApp Web chat';
                } else {
                    attachMeta.textContent = 'Official A4 PDF \u2022 save it, then send in WhatsApp Web';
                }
            }
            if (attachRemoveBtn) attachRemoveBtn.hidden = !custom;
            if (attachPickBtn) {
                attachPickBtn.classList.toggle('has-file', custom);
                attachPickBtn.title = custom ? 'Replace the attached PDF' : 'Open the file explorer and attach a PDF';
            }
        }

        function setAttachedFile(file) {
            if (attachedUrl) { URL.revokeObjectURL(attachedUrl); attachedUrl = null; }
            attachedFile = file || null;
            const st = rawStudents[activeIdx];
            attachedRoll = attachedFile ? (st ? st.roll : null) : null;
            if (attachedFile) attachedUrl = URL.createObjectURL(attachedFile);
            renderAttachment();
        }

        // Silent reset used when the preview switches students.
        function dropStaleAttachment() {
            if (attachedUrl) { URL.revokeObjectURL(attachedUrl); attachedUrl = null; }
            attachedFile = null;
            attachedRoll = null;
            if (attachInput) attachInput.value = '';
        }

        function clearAttachment() {
            setAttachedFile(null);
            if (attachInput) attachInput.value = '';
            if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                window.HCCWhatsApp.showToast('Attachment removed.', 'fa-xmark');
            }
        }

        function openAttachPicker() {
            if (attachInput) attachInput.click();
        }

        if (mockDownloadPdfBtn) {
            mockDownloadPdfBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (attachedFile && attachedUrl) {
                    const a = document.createElement('a');
                    a.href = attachedUrl;
                    a.download = attachedFile.name;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    return;
                }
                const st = rawStudents[activeIdx];
                if (st && window.HCCPdfEngine) window.HCCPdfEngine.downloadSinglePdf(st, docType, meta);
            });
        }
        if (waDragTile) {
            waDragTile.addEventListener('click', (e) => {
                if (e.target.closest('button')) return;
                const st = rawStudents[activeIdx];
                if (st && window.HCCPdfEngine) window.HCCPdfEngine.downloadSinglePdf(st, docType, meta);
            });
            // Let the teacher drag the picked file straight into the WhatsApp Web window.
            waDragTile.addEventListener('dragstart', (e) => {
                if (!attachedFile) { e.preventDefault(); return; }
                try {
                    e.dataTransfer.effectAllowed = 'copy';
                    e.dataTransfer.items.add(attachedFile);
                } catch (err) { /* older browsers: drag silently unavailable */ }
            });
        }
        if (attachPickBtn) attachPickBtn.addEventListener('click', openAttachPicker);
        if (attachCustomBtn) attachCustomBtn.addEventListener('click', (e) => { e.stopPropagation(); openAttachPicker(); });
        if (attachRemoveBtn) attachRemoveBtn.addEventListener('click', (e) => { e.stopPropagation(); clearAttachment(); });
        if (attachInput) {
            attachInput.addEventListener('change', () => {
                const f = attachInput.files && attachInput.files[0];
                if (!f) return;
                if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') {
                    if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                        window.HCCWhatsApp.showToast('Please choose a PDF file.', 'fa-triangle-exclamation');
                    }
                    attachInput.value = '';
                    return;
                }
                setAttachedFile(f);
                if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                    window.HCCWhatsApp.showToast('PDF attached \u2014 drag it into the WhatsApp Web chat.', 'fa-paperclip');
                }
            });
        }

        // Stepper Navigation Buttons
        if (prevStudentBtn) {
            prevStudentBtn.addEventListener('click', () => {
                if (activeIdx > 0) {
                    updatePreview(activeIdx - 1);
                }
            });
        }

        if (sendAndNextBtn) {
            sendAndNextBtn.addEventListener('click', () => {
                if (panePhoneInput) panePhoneInput.blur();
                markStudentSent(activeIdx, true);
            });
        }

        const skipStudentBtn = document.getElementById('skipStudentBtn');
        if (skipStudentBtn) {
            skipStudentBtn.addEventListener('click', () => {
                if (panePhoneInput) panePhoneInput.blur();
                skipStudent(activeIdx, true);
            });
        }

        // Global Keyboard Shortcuts (Alt + ArrowRight = Send & Next; Alt + ArrowLeft = Prev)
        document.addEventListener('keydown', (e) => {
            if (e.target.matches('input, textarea, select')) {
                if (e.key === 'Enter' && e.altKey) {
                    e.preventDefault();
                    if (sendAndNextBtn) sendAndNextBtn.click();
                }
                return;
            }

            if (e.altKey && (e.key === 'ArrowRight' || e.key === 'Enter')) {
                e.preventDefault();
                if (sendAndNextBtn) sendAndNextBtn.click();
            } else if (e.altKey && e.key === 'ArrowLeft') {
                e.preventDefault();
                if (prevStudentBtn) prevStudentBtn.click();
            }
        });

        // Copy Direct Button
        if (mockCopyBtn) {
            mockCopyBtn.addEventListener('click', () => {
                const st = rawStudents[activeIdx];
                if (st && window.HCCWhatsApp) {
                    const msg = getActiveMessage(st);
                    window.HCCWhatsApp.copyText(msg, 'Message copied for ' + st.name);
                }
            });
        }

        // Custom note live re-render
        if (customNoteInput) {
            customNoteInput.addEventListener('input', () => updatePreview(activeIdx));
        }

        // ── Editable preview bubble ────────────────────────────────────────────
        // Whatever the teacher types here becomes the exact message shared for
        // that student (copy / open companion / auto-send). Stored per roll.
        if (mockBubbleText) {
            mockBubbleText.setAttribute('contenteditable', 'true');
            mockBubbleText.addEventListener('input', () => {
                const st = rawStudents[activeIdx];
                if (!st) return;
                const current = mockBubbleText.textContent || '';
                const template = buildMessageForStudent(st) || '';
                // Ignore whitespace-only drift (contenteditable can trail a newline)
                if (current.replace(/\s+$/, '') === template.replace(/\s+$/, '')) {
                    delete messageOverrides[String(st.roll)];
                } else {
                    messageOverrides[String(st.roll)] = current;
                }
                persistMessageOverrides();
                syncBubbleEditState(st);
            });
            // Force plain-text paste so rich HTML can't leak into the bubble.
            mockBubbleText.addEventListener('paste', (e) => {
                const cd = e.clipboardData || window.clipboardData;
                if (!cd) return;
                e.preventDefault();
                const text = cd.getData('text/plain');
                document.execCommand('insertText', false, text);
            });
            // Never let a stray Enter inside the bubble submit a parent <form>.
            mockBubbleText.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey && e.ctrlKey === false && e.altKey === false) {
                    e.stopPropagation();
                }
            });
        }

        const bubbleResetBtn = document.getElementById('bubbleResetBtn');
        if (bubbleResetBtn) {
            bubbleResetBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const st = rawStudents[activeIdx];
                if (!st) return;
                delete messageOverrides[String(st.roll)];
                persistMessageOverrides();
                if (mockBubbleText) mockBubbleText.textContent = buildMessageForStudent(st);
                syncBubbleEditState(st);
                if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                    window.HCCWhatsApp.showToast('Message reset to template.', 'fa-rotate-left');
                }
            });
        }

        // ── Chat list (students) ──────────────────────────────────────────────
        document.querySelectorAll('.wa-chat-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.closest('a') || e.target.closest('button')) return;
                const idx = parseInt(item.dataset.idx, 10);
                if (isNaN(idx) || !rawStudents[idx]) return;
                updatePreview(idx);
                if (waApp) waApp.classList.add('show-chat');
            });
        });

        // Mobile drill-down: back to the chat list
        if (chatBackBtn) {
            chatBackBtn.addEventListener('click', () => {
                if (waApp) waApp.classList.remove('show-chat');
            });
        }

        // Header "Mark as Sent" button (current student)
        if (paneSendBtn) {
            paneSendBtn.addEventListener('click', () => {
                if (panePhoneInput) panePhoneInput.blur();
                markStudentSent(activeIdx, false);
            });
        }

        // Composer phone field — autosaves to MySQL + localStorage
        if (panePhoneInput) {
            panePhoneInput.addEventListener('input', () => {
                const st = rawStudents[activeIdx];
                if (st) st.parent_phone = panePhoneInput.value.trim();
            });
            panePhoneInput.addEventListener('change', () => {
                const st = rawStudents[activeIdx];
                if (!st) return;
                const val = panePhoneInput.value.trim();
                st.parent_phone = val;
                localStorage.setItem('hcc_wa_phone_' + classKey + '_' + st.roll, val);
                saveParentPhone(st.roll, val, panePhoneInput);
                updateFilterCounts();
            });
            panePhoneInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    panePhoneInput.blur();
                }
            });
        }

        // ── Batch actions ⋮ menu ──────────────────────────────────────────────
        if (batchMenuBtn && batchMenu) {
            batchMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                batchMenu.hidden = !batchMenu.hidden;
            });
            batchMenu.addEventListener('click', (e) => {
                if (e.target.closest('.wa-menu-item')) batchMenu.hidden = true;
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.wa-batch-wrap')) batchMenu.hidden = true;
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') batchMenu.hidden = true;
            });
        }

        // Batch Class Downloads
        const downloadBatchZipBtn = document.getElementById('downloadBatchZipBtn');
        if (downloadBatchZipBtn) {
            downloadBatchZipBtn.addEventListener('click', () => {
                if (window.HCCPdfEngine) window.HCCPdfEngine.downloadClassBatchZip(rawStudents, docType, meta);
            });
        }

        const downloadBatchPdfBtn = document.getElementById('downloadBatchPdfBtn');
        if (downloadBatchPdfBtn) {
            downloadBatchPdfBtn.addEventListener('click', () => {
                if (window.HCCPdfEngine) window.HCCPdfEngine.downloadClassBatchPdf(rawStudents, docType, meta);
            });
        }

        // Copy All Phones
        const copyAllPhonesBtn = document.getElementById('copyAllPhonesBtn');
        if (copyAllPhonesBtn) {
            copyAllPhonesBtn.addEventListener('click', () => {
                const phones = [];
                rawStudents.forEach(st => {
                    const p = getStudentPhone(st.roll);
                    const clean = (window.HCCWhatsApp && window.HCCWhatsApp.cleanPhone) ? window.HCCWhatsApp.cleanPhone(p) : p;
                    if (clean) phones.push(clean);
                });
                if (phones.length === 0) {
                    alert('No phone numbers entered yet.');
                    return;
                }
                if (window.HCCWhatsApp) window.HCCWhatsApp.copyText(phones.join(', '), 'Copied ' + phones.length + ' phone numbers!');
            });
        }

        // Reset Sent Status in MySQL
        const resetSentStatusBtn = document.getElementById('resetSentStatusBtn');
        if (resetSentStatusBtn) {
            resetSentStatusBtn.addEventListener('click', () => {
                const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message)));
                confirmFn({
                    title: 'Reset Dispatch Status',
                    message: 'Reset dispatch status for all students in this class?',
                    confirmText: 'Reset Status',
                    type: 'danger'
                }).then((confirmed) => {
                    if (!confirmed) return;
                    const fd = new FormData();
                    fd.append('action', 'reset_class_dispatch_status');
                    fd.append('csrf_token', csrfToken);
                    fd.append('doc_type', docType);
                    fd.append('class_key', classKey);
                    fd.append('test_type', testType);

                    fetch('whatsapp_share.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (res.ok) {
                                rawStudents.forEach(st => {
                                    const roll = String(st.roll);
                                    const p = getStudentPhone(st.roll);
                                    const hasPhone = !!(window.HCCWhatsApp && window.HCCWhatsApp.cleanPhone(p));
                                    const resetStatus = hasPhone ? 'pending' : 'no_phone';
                                    statusMap.set(roll, resetStatus);
                                    updateStatusPill(roll, resetStatus);
                                });
                                updateStats();
                                if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                                    window.HCCWhatsApp.showToast('Class dispatch statuses reset.', 'fa-rotate-left');
                                }
                            } else {
                                alert('Failed to reset: ' + (res.error || 'Unknown error'));
                            }
                        })
                        .catch(err => alert('Request failed: ' + err.message));
                });
            });
        }

        // Sequential Dispatch Floating Workflow Bar
        const startSeqBtn = document.getElementById('startSeqBtn');
        if (startSeqBtn) {
            startSeqBtn.addEventListener('click', () => {
                seqMode = true;
                if (seqBar) seqBar.classList.remove('is-hidden');

                let firstUnsent = rawStudents.findIndex(st => statusMap.get(String(st.roll)) !== 'sent');
                if (firstUnsent === -1) firstUnsent = 0;
                activeIdx = firstUnsent;
                updatePreview(activeIdx);
                updateSeqBar();
            });
        }

        function updateSeqBar() {
            if (!rawStudents[activeIdx]) return;
            const st = rawStudents[activeIdx];
            const s = statusMap.get(String(st.roll)) || 'pending';
            if (seqCountPill) seqCountPill.textContent = (activeIdx + 1) + ' / ' + rawStudents.length;
            if (seqStudentName) seqStudentName.textContent = st.name + ' (Roll ' + st.roll + ')';

            const seqStatusPill = document.getElementById('seqStatusPill');
            if (seqStatusPill) {
                seqStatusPill.className = 'wa-status-pill wa-status-' + s.replace(/_/g, '-');
                let label = '⚪ Pending';
                if (s === 'sent') label = '🟢 Sent';
                else if (s === 'opened') label = '🟡 Opened';
                else if (s === 'ready') label = '🔵 Ready';
                else if (s === 'skipped') label = '⚪ Skipped';
                else if (s === 'failed') label = '🔴 Failed';
                else if (s === 'no_phone') label = '⚠️ No Phone';
                seqStatusPill.textContent = label;
            }
        }

        const seqOpenBtn = document.getElementById('seqOpenBtn');
        if (seqOpenBtn) {
            seqOpenBtn.addEventListener('click', () => {
                if (panePhoneInput) panePhoneInput.blur();
                openWhatsAppForStudent(activeIdx);
            });
        }

        if (seqSendNextBtn) {
            seqSendNextBtn.addEventListener('click', () => {
                if (panePhoneInput) panePhoneInput.blur();
                markStudentSent(activeIdx, true);
            });
        }

        const seqSkipBtn = document.getElementById('seqSkipBtn');
        if (seqSkipBtn) {
            seqSkipBtn.addEventListener('click', () => {
                if (panePhoneInput) panePhoneInput.blur();
                skipStudent(activeIdx, true);
            });
        }

        if (seqStopBtn) {
            seqStopBtn.addEventListener('click', () => {
                if (seqBar) seqBar.classList.add('is-hidden');
                seqMode = false;
            });
        }

        // Activity Alerts Dropdown (Header Bell)
        // The bell toggle is owned by admin.js (loaded on this page) — whatsapp-hub.js must NOT
        // attach its own click handler here, or the dropdown would be toggled twice per click and
        // immediately close, making the bell appear dead.
        const notifTrigger = document.getElementById('notifTrigger');
        const notifDropdown = document.getElementById('notifDropdown');
        const notifBadge = document.getElementById('notifBadge');
        if (notifTrigger && notifDropdown) {
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#notifContainer')) {
                    notifDropdown.classList.remove('show');
                }
            });
        }

        // Initial setup
        updateStats();
        if (rawStudents.length > 0) {
            updatePreview(0);
        }

        // ════════════════════════════════════════════════════════════════════
        // HUB TEMPLATE LIBRARY WIRING
        // ════════════════════════════════════════════════════════════════════
        const hubTplLib = document.getElementById('wa-template-library-hub');
        const btnTemplatesHub = document.getElementById('btnTemplatesHub');

        if (hubTplLib && btnTemplatesHub) {
            btnTemplatesHub.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const st = rawStudents[activeIdx];
                if (!st) {
                    if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                        window.HCCWhatsApp.showToast('⚠️ Select a student first.', 'fa-triangle-exclamation');
                    }
                    return;
                }
                const classLabel = (st.class_label || (st.std ? 'STD ' + st.std + ' ' + (st.division || '') : 'your class'));
                const student = {
                    roll: st.roll,
                    name: st.name,
                    parent_phone: st.parent_phone || '',
                    class_label: classLabel,
                    academic_year: st.academic_year || year,
                    semester: st.semester || semester,
                    division: st.division || division
                };
                hubTplLib.open({
                    hubMode: true,
                    student: student,
                    onSelect: (filledBody) => {
                        // Inject into the hub composer (contenteditable bubble)
                        if (mockBubbleText) {
                            mockBubbleText.textContent = filledBody;
                            mockBubbleText.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                        // Save as override for this student so it persists
                        const st2 = rawStudents[activeIdx];
                        if (st2) {
                            messageOverrides[String(st2.roll)] = filledBody;
                            persistMessageOverrides();
                            syncBubbleEditState(st2);
                        }
                        if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                            window.HCCWhatsApp.showToast('✅ Template applied to message.', 'fa-check');
                        }
                    }
                });
            });
        }
    });
})();
