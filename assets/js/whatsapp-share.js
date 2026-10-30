/**
 * HCC Automation System — WhatsApp Share Engine
 * Provides unified WhatsApp deep-linking, Web Share API integration,
 * formatted academic templates, smart modal manager, and sequential dispatch.
 */
(function (window, document) {
    'use strict';

    var HCCWhatsApp = {};

    // ── 1. PHONE NUMBER FORMATTING ──
    HCCWhatsApp.cleanPhone = function (raw) {
        if (!raw) return '';
        var digits = String(raw).replace(/\D+/g, '');
        if (!digits) return '';
        // If 10 digits (Standard Indian mobile), prepend 91
        if (digits.length === 10) {
            return '91' + digits;
        }
        // If 11 digits starting with 0, replace 0 with 91
        if (digits.length === 11 && digits.charAt(0) === '0') {
            return '91' + digits.substring(1);
        }
        return digits;
    };

    HCCWhatsApp.formatPhoneDisplay = function (cleaned) {
        if (!cleaned) return '';
        if (cleaned.length === 12 && cleaned.startsWith('91')) {
            return '+91 ' + cleaned.substring(2, 7) + ' ' + cleaned.substring(7);
        }
        return cleaned;
    };

    // ── 2. WHATSAPP LINK GENERATOR ──
    HCCWhatsApp.buildUrl = function (phone, text) {
        var cleaned = HCCWhatsApp.cleanPhone(phone);
        var enc = encodeURIComponent(text || '');
        if (cleaned) {
            return 'https://wa.me/' + cleaned + '?text=' + enc;
        }
        return 'https://api.whatsapp.com/send?text=' + enc;
    };

    HCCWhatsApp.openChat = function (phone, text) {
        var url = HCCWhatsApp.buildUrl(phone, text);
        try {
            var win = window.open(url, '_blank', 'noopener,noreferrer');
            if (!win || win.closed || typeof win.closed === 'undefined') {
                // Pop-up blocker intercepted window.open, fallback to simulated anchor click
                var a = document.createElement('a');
                a.href = url;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                document.body.appendChild(a);
                a.click();
                setTimeout(function () {
                    try { document.body.removeChild(a); } catch (e) {}
                }, 100);
            }
            return win;
        } catch (err) {
            window.location.assign(url);
        }
    };

    // ── 3. TOAST NOTIFICATIONS ──
    HCCWhatsApp.showToast = function (msg, icon) {
        icon = icon || 'fa-check';
        var toast = document.getElementById('hccWaToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'hccWaToast';
            toast.className = 'wa-toast';
            document.body.appendChild(toast);
        }
        // Build with textContent so student names/messages can't inject HTML
        toast.innerHTML = '';
        var ic = document.createElement('i');
        ic.className = 'fas ' + String(icon).replace(/[^a-zA-Z0-9 \-]/g, '');
        ic.style.color = '#25D366';
        var sp = document.createElement('span');
        sp.textContent = msg;
        toast.appendChild(ic);
        toast.appendChild(document.createTextNode(' '));
        toast.appendChild(sp);
        toast.classList.add('is-show');
        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(function () {
            toast.classList.remove('is-show');
        }, 3200);
    };

    // ── 4. CLIPBOARD COPY ──
    HCCWhatsApp.copyText = function (text, successMsg) {
        successMsg = successMsg || 'Message copied to clipboard!';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                HCCWhatsApp.showToast(successMsg, 'fa-copy');
            }).catch(function () {
                HCCWhatsApp.fallbackCopy(text, successMsg);
            });
        } else {
            HCCWhatsApp.fallbackCopy(text, successMsg);
        }
    };

    HCCWhatsApp.fallbackCopy = function (text, successMsg) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
            HCCWhatsApp.showToast(successMsg, 'fa-copy');
        } catch (e) {
            alert('Failed to copy. Please manually copy the message.');
        }
        document.body.removeChild(ta);
    };

    // ── 5. TEMPLATE GENERATORS ──
    // Messages read like a short, well-formatted school letter: letterhead,
    // document title, date, salutation, the student's details, then a closing
    // block signed by the principal. No emoji clutter, no ASCII rule lines.

    function waFormatDate(value) {
        var dt = value ? new Date(value) : new Date();
        if (isNaN(dt.getTime())) dt = new Date();
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return dt.getDate() + ' ' + months[dt.getMonth()] + ' ' + dt.getFullYear();
    }

    // Standard sign-off block shared by every document type.
    // Skips the name line when the school has not set a real principal name
    // (the settings placeholder is literally "PRINCIPAL"), which would otherwise
    // read as "PRINCIPAL / Principal".
    function waSignOff(lines, data) {
        var school = data.schoolName || 'Hindustani Covenant Church High School';
        var principal = (data.principalName || '').toString().trim();
        var isPlaceholder = !principal ||
            principal.toUpperCase() === 'PRINCIPAL' ||
            principal.toUpperCase() === 'THE PRINCIPAL';

        lines.push('');
        lines.push('Regards,');
        if (!isPlaceholder) {
            lines.push('*' + principal + '*');
        }
        lines.push('Principal');
        lines.push('*' + school + '*');
        return lines;
    }

    // Drop empty/undefined lines and collapse repeated blank lines.
    function waClean(lines) {
        var out = [];
        lines.forEach(function (l) {
            if (l === null || l === undefined) return;
            if (l === '' && out.length && out[out.length - 1] === '') return;
            out.push(l);
        });
        while (out.length && out[out.length - 1] === '') out.pop();
        return out.join('\n');
    }

    HCCWhatsApp.templates = {
        reportCard: function (data) {
            var school = data.schoolName || 'Hindustani Covenant Church High School';
            var status = (data.status || '').toString().toUpperCase();
            var lines = [];

            lines.push('*' + school + '*');
            lines.push('');
            lines.push('SEMESTER REPORT CARD');
            lines.push('Date: ' + waFormatDate(data.issueDate));
            lines.push('');
            lines.push('Dear Parent / Guardian,');
            lines.push('');
            lines.push('Please find below the academic performance of your ward' +
                (data.semester ? ' for ' + data.semester : '') +
                (data.academicYear ? ', academic year ' + data.academicYear : '') + '.');
            lines.push('');
            lines.push('Student: *' + (data.studentName || '—') + '*');
            lines.push('Class: *' + (data.className || '—') + '*');
            lines.push('Roll No.: *' + (data.roll || '—') + '*');
            lines.push('');
            lines.push('Marks Obtained: *' + (data.obtained || '0') + ' / ' + (data.maxTotal || '0') + '*');
            lines.push('Percentage: *' + (data.percentage || '0') + '%*');
            lines.push('Grade: *' + (data.grade || '—') + '*');
            lines.push('Result: *' + (status || '—') + '*');
            if (data.attendance) lines.push('Attendance: *' + data.attendance + '*');
            if (data.remark) lines.push('Teacher\'s Remark: *' + data.remark + '*');
            if (data.customNote) {
                lines.push('');
                lines.push('Note: ' + data.customNote);
            }
            lines.push('');
            lines.push('The official Report Card, duly signed and sealed by the school, is attached to this message as a PDF.');
            lines.push('');
            lines.push('Please acknowledge receipt by replying to this message.');
            return waClean(waSignOff(lines, data));
        },

        unitTest: function (data) {
            var school = data.schoolName || 'Hindustani Covenant Church English Medium School, Solapur';
            var testType = data.testType || 'Unit Test';
            var lines = [];

            lines.push('*' + school + '*');
            lines.push('');
            lines.push(testType.toUpperCase() + ' — PROGRESS SLIP');
            lines.push('Date: ' + waFormatDate(data.issueDate));
            lines.push('');
            lines.push('Dear Parent / Guardian,');
            lines.push('');
            lines.push('Please find below the subject-wise performance of your ward in ' + testType + '.');
            lines.push('');
            lines.push('Student: *' + (data.studentName || '—') + '*');
            lines.push('Class: *' + (data.className || '—') + '*');
            lines.push('Roll No.: *' + (data.roll || '—') + '*');

            if (data.subjects && data.subjects.length > 0) {
                lines.push('');
                lines.push('SUBJECT-WISE MARKS');
                data.subjects.forEach(function (s) {
                    var sName = s.name || s.subject_name || 'Subject';
                    var obtVal = (s.obtained !== undefined && s.obtained !== null && s.obtained !== 'undefined') ? s.obtained : ((s.total_obtained !== undefined && s.total_obtained !== null && s.total_obtained !== 'undefined') ? s.total_obtained : '—');
                    var maxVal = (s.max !== undefined && s.max !== null && s.max !== 'undefined') ? s.max : ((s.total_max !== undefined && s.total_max !== null && s.total_max !== 'undefined') ? s.total_max : (s.max_marks || '—'));
                    lines.push('• ' + sName + ': ' + obtVal + ' / ' + maxVal);
                });
            }

            lines.push('');
            lines.push('Total: *' + (data.obtained || '0') + ' / ' + (data.maxTotal || '0') + '*');
            if (data.percentage) lines.push('Percentage: *' + data.percentage + '%*');
            if (data.grade) lines.push('Grade: *' + data.grade + '*');
            if (data.remark) lines.push('Teacher\'s Remark: *' + data.remark + '*');
            if (data.customNote) {
                lines.push('');
                lines.push('Note: ' + data.customNote);
            }
            lines.push('');
            lines.push('The official Progress Slip, signed by the class teacher, is attached to this message as a PDF.');
            lines.push('');
            lines.push('Kindly sign the slip and return it at the next parent-teacher meeting.');
            return waClean(waSignOff(lines, data));
        },

        bonafide: function (data) {
            var school = data.schoolName || 'Hindustani Covenant Church High School, Solapur';
            var lines = [];

            lines.push('*' + school + '*');
            lines.push('');
            lines.push('BONAFIDE CERTIFICATE — ISSUANCE NOTICE');
            lines.push('Date: ' + waFormatDate(data.issueDate));
            lines.push('');
            lines.push('Dear Parent / Guardian,');
            lines.push('');
            lines.push('This is to certify that the student named below is a bonafide student of this school.');
            lines.push('');
            lines.push('Student: *' + (data.studentName || '—') + '*');
            lines.push('Class: *' + (data.className || '—') + '*');
            lines.push('Roll No.: *' + (data.roll || '—') + '*');
            lines.push('G.R. No.: *' + (data.grNo || '—') + '*');
            lines.push('Certificate No.: *' + (data.certSerial || '—') + '*');
            if (data.dob) lines.push('Date of Birth: *' + data.dob + '*');
            lines.push('Academic Year: *' + (data.academicYear || '—') + '*');
            if (data.customNote) {
                lines.push('');
                lines.push('Note: ' + data.customNote);
            }
            lines.push('');
            lines.push('The official Bonafide Certificate, duly signed and sealed by the school, is attached to this message as a PDF.');
            lines.push('');
            lines.push('Please preserve this certificate safely — it may be required for admissions, scholarship applications, and government schemes. For any assistance, kindly contact the school office.');
            return waClean(waSignOff(lines, data));
        },

        classSummary: function (data) {
            var school = data.schoolName || 'Hindustani Covenant Church High School';
            var lines = [];

            lines.push('*' + school + '*');
            lines.push('');
            lines.push('CONSOLIDATED CLASS RESULT');
            lines.push('Date: ' + waFormatDate(data.issueDate));
            lines.push('');
            lines.push('Class: *' + (data.className || '—') + '*' + (data.semester ? '  |  ' + data.semester : ''));
            lines.push('');
            lines.push('Total Students: *' + (data.totalStudents || 0) + '*');
            lines.push('Passed: *' + (data.passed || 0) + '* (' + (data.passPct || 0) + '%)');
            lines.push('Failed: *' + (data.failed || 0) + '*');
            if (data.classAverage) {
                lines.push('Class Average: *' + data.classAverage + '%*');
            }
            if (data.topScorers && data.topScorers.length > 0) {
                lines.push('');
                lines.push('TOP RANKERS');
                data.topScorers.forEach(function (ts, idx) {
                    lines.push((idx + 1) + '. ' + ts.name + ' — ' + ts.percentage + '% (' + ts.grade + ')');
                });
            }
            lines.push('');
            lines.push('The consolidated class marksheet is attached to this message as a PDF.');
            return waClean(waSignOff(lines, data));
        },

        announcement: function (data) {
            var school = data.schoolName || 'Hindustani Covenant Church High School';
            var lines = [];

            lines.push('*' + school + '*');
            lines.push('');
            lines.push('OFFICIAL SCHOOL NOTICE / ANNOUNCEMENT');
            lines.push('Date: ' + waFormatDate(data.issueDate));
            lines.push('');
            lines.push('Dear Parent / Guardian,');
            lines.push('');
            lines.push('Student: *' + (data.studentName || '—') + '*');
            lines.push('Class: *' + (data.className || '—') + '*');
            lines.push('Roll No.: *' + (data.roll || '—') + '*');
            lines.push('');
            if (data.customNote && data.customNote.trim() !== '') {
                lines.push('*Notice:* ' + data.customNote);
            } else {
                lines.push('Please take note of the upcoming school circular and academic schedule.');
            }
            lines.push('');
            lines.push('Kindly contact the school administration or class teacher for any queries.');
            return waClean(waSignOff(lines, data));
        }
    };

    // ── 6. SMART SHARE MODAL (For Individual Contextual Buttons) ──
    HCCWhatsApp.modalEl = null;

    HCCWhatsApp.initModal = function () {
        if (HCCWhatsApp.modalEl) return;
        var html = [
            '<div class="wa-modal-backdrop" id="hccWaModalBackdrop">',
            '  <div class="wa-modal" role="dialog" aria-modal="true" aria-labelledby="hccWaModalTitle">',
            '    <div class="wa-modal-header">',
            '      <h3 id="hccWaModalTitle"><i class="fa-brands fa-whatsapp"></i> Share via WhatsApp</h3>',
            '      <button type="button" class="wa-modal-close" id="hccWaModalClose" aria-label="Close">&times;</button>',
            '    </div>',
            '    <div class="wa-modal-body">',
            '      <div class="wa-meta-box">',
            '        <div class="wa-meta-avatar" id="hccWaAvatar">ST</div>',
            '        <div class="wa-meta-info">',
            '          <div class="wa-meta-title" id="hccWaName">Student Name</div>',
            '          <div class="wa-meta-sub" id="hccWaMeta">Roll 00 &bull; Class</div>',
            '        </div>',
            '      </div>',
            '      <div class="wa-input-group">',
            '        <label for="hccWaPhoneInput">Parent WhatsApp Mobile Number:</label>',
            '        <div class="wa-phone-row">',
            '          <span class="wa-country-prefix">&#127470;&#127475; +91</span>',
            '          <input type="tel" id="hccWaPhoneInput" class="wa-phone-input" placeholder="Enter 10-digit mobile number" maxlength="14" autocomplete="tel">',
            '        </div>',
            '        <div class="wa-input-hint"><i class="fas fa-info-circle"></i> Leave blank to select contact inside WhatsApp.</div>',
            '      </div>',
            '      <div class="wa-preview-card">',
            '        <div class="wa-chat-bubble-view" id="hccWaPreviewBubble">Message preview...</div>',
            '      </div>',
            '    </div>',
            '    <div class="wa-modal-footer">',
            '      <button type="button" class="btn-outline-whatsapp" id="hccWaPdfBtn"><i class="fas fa-file-pdf"></i> Download A4 PDF</button>',
            '      <button type="button" class="btn-outline-whatsapp" id="hccWaCopyBtn"><i class="fas fa-copy"></i> Copy Text</button>',
            '      <a class="btn-whatsapp" id="hccWaSendBtn" href="#" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-whatsapp"></i> Open WhatsApp</a>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('');
        var div = document.createElement('div');
        div.innerHTML = html;
        HCCWhatsApp.modalEl = div.firstElementChild;
        document.body.appendChild(HCCWhatsApp.modalEl);

        // Bind internal close events
        document.getElementById('hccWaModalClose').addEventListener('click', HCCWhatsApp.closeModal);
        HCCWhatsApp.modalEl.addEventListener('click', function (e) {
            if (e.target === HCCWhatsApp.modalEl) HCCWhatsApp.closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && HCCWhatsApp.modalEl.classList.contains('is-open')) {
                HCCWhatsApp.closeModal();
            }
        });
        // Phone-input listener is bound ONCE here (not per openModal) to avoid
        // stacking stale closures; it reads the current message from module state.
        var phoneInputEl = document.getElementById('hccWaPhoneInput');
        var sendBtnEl = document.getElementById('hccWaSendBtn');
        phoneInputEl.addEventListener('input', function () {
            sendBtnEl.href = HCCWhatsApp.buildUrl(phoneInputEl.value.trim(), HCCWhatsApp._currentMessage || '');
        });
    };
    HCCWhatsApp._currentMessage = '';

    HCCWhatsApp.openModal = function (opts) {
        HCCWhatsApp.initModal();
        opts = opts || {};
        var backdrop = HCCWhatsApp.modalEl;
        var nameEl = document.getElementById('hccWaName');
        var metaEl = document.getElementById('hccWaMeta');
        var avatarEl = document.getElementById('hccWaAvatar');
        var phoneInput = document.getElementById('hccWaPhoneInput');
        var previewBubble = document.getElementById('hccWaPreviewBubble');
        var pdfBtn = document.getElementById('hccWaPdfBtn');
        var copyBtn = document.getElementById('hccWaCopyBtn');
        var sendBtn = document.getElementById('hccWaSendBtn');

        var studentName = opts.studentName || 'Student';
        var roll = opts.roll || '';
        var className = opts.className || '';
        var storageKey = 'hcc_wa_phone_' + (opts.classKey || className) + '_' + roll;

        // Auto retrieve saved parent phone if available
        var initialPhone = opts.phone || localStorage.getItem(storageKey) || '';
        phoneInput.value = initialPhone;

        nameEl.textContent = studentName;
        metaEl.textContent = 'Roll ' + roll + (className ? ' • ' + className : '');

        // Avatar initials
        var initials = studentName.split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('') || 'ST';
        avatarEl.textContent = initials;

        // Render message preview
        var messageText = opts.messageText || '';
        HCCWhatsApp._currentMessage = messageText;
        previewBubble.textContent = messageText;

        // Configure PDF button
        if (typeof opts.onDownloadPdf === 'function') {
            pdfBtn.style.display = 'inline-flex';
            pdfBtn.onclick = function () {
                opts.onDownloadPdf();
            };
        } else {
            pdfBtn.style.display = 'none';
        }

        // Bind Copy
        copyBtn.onclick = function () {
            HCCWhatsApp.copyText(messageText, 'Report card text copied!');
        };

        // Automated send mode: the Send button drives the Puppeteer backend
        // (PDF attached automatically) instead of opening a wa.me link.
        var autoSend = (typeof opts.onAutoSend === 'function') ? opts.onAutoSend : null;
        if (autoSend) {
            sendBtn.innerHTML = '<i class="fa-brands fa-whatsapp"></i> Send with PDF';
        } else {
            sendBtn.innerHTML = '<i class="fa-brands fa-whatsapp"></i> Open WhatsApp';
        }

        function updateSendHref() {
            var phone = phoneInput.value.trim();
            sendBtn.href = HCCWhatsApp.buildUrl(phone, messageText);
        }
        updateSendHref();

        // Bind Send
        sendBtn.onclick = function (e) {
            e.preventDefault();
            var phone = phoneInput.value.trim();
            if (phone) {
                localStorage.setItem(storageKey, phone);
            }

            // ── Automated path: backend attaches the PDF and sends ──
            if (autoSend) {
                var cleaned = HCCWhatsApp.cleanPhone(phone);
                if (!cleaned) {
                    alert('Please type the parent mobile number first.');
                    phoneInput.focus();
                    return;
                }
                sendBtn.style.pointerEvents = 'none';
                sendBtn.style.opacity = '0.6';
                var restoreBtn = function () {
                    sendBtn.style.pointerEvents = '';
                    sendBtn.style.opacity = '';
                };
                try {
                    autoSend(phone, messageText, function () {
                        restoreBtn();
                        HCCWhatsApp.closeModal();
                    });
                } catch (err) {
                    console.error(err);
                    restoreBtn();
                }
                return;
            }

            // On mobile devices (iOS / Android), native Web Share API can directly attach files into WhatsApp app
            var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
            if (isMobile && opts.pdfBlob && navigator.canShare && navigator.canShare({ files: [opts.pdfBlob] })) {
                var file = new File([opts.pdfBlob], (opts.fileName || 'Report_Card') + '.pdf', { type: 'application/pdf' });
                navigator.share({
                    title: studentName + ' - Report Card',
                    text: messageText,
                    files: [file]
                }).catch(function (err) {
                    if (err.name !== 'AbortError') {
                        HCCWhatsApp.openChat(phone, messageText);
                    }
                });
            } else {
                // Desktop workflow: auto-download the PDF + copy the message text,
                // then open WhatsApp Web so the user attaches the downloaded file.
                // (Deterministic — no clipboard PDF/text race.)
                if (typeof opts.onDownloadPdf === 'function') {
                    try { opts.onDownloadPdf(); } catch (dlErr) { console.warn(dlErr); }
                }
                HCCWhatsApp.copyText(messageText, 'Message copied! Attach the downloaded PDF in WhatsApp.');

                // Open WhatsApp Web with the chat ready (not wa.me — web.whatsapp.com supports paste)
                var waUrl = 'https://web.whatsapp.com/send' + (HCCWhatsApp.cleanPhone(phone) ? '?phone=' + HCCWhatsApp.cleanPhone(phone) : '');
                try {
                    var win = window.open(waUrl, '_blank', 'width=900,height=700');
                    if (!win || win.closed || typeof win.closed === 'undefined') {
                        var a = document.createElement('a');
                        a.href = waUrl;
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        document.body.appendChild(a);
                        a.click();
                        setTimeout(function () {
                            try { document.body.removeChild(a); } catch (e2) {}
                        }, 100);
                    }
                } catch (err) {
                    window.location.assign(waUrl);
                }

                setTimeout(function () {
                    HCCWhatsApp.showToast('PDF downloaded + message copied! In WhatsApp: attach the PDF file, then Send.', 'fa-paperclip');
                }, 800);
            }

            if (typeof opts.onSent === 'function') {
                opts.onSent(phone);
            }
            setTimeout(function () {
                HCCWhatsApp.closeModal();
            }, 400);
        };

        backdrop.classList.add('is-open');
        phoneInput.focus();
    };

    HCCWhatsApp.closeModal = function () {
        if (HCCWhatsApp.modalEl) {
            HCCWhatsApp.modalEl.classList.remove('is-open');
        }
    };

    window.HCCWhatsApp = HCCWhatsApp;
})(window, document);
