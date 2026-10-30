/**
 * HCC Automation System — Dedicated Custom Dispatch Hub Controller
 * Manages recipient list selection, dynamic message formatting, speech bubble editing,
 * inline phone auto-saving, sequential dispatch stepper, and batch automation.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const dataEl = document.getElementById('customHubData');
        if (!dataEl) return;

        let cfg = {};
        try {
            cfg = JSON.parse(dataEl.textContent || '{}');
        } catch (e) {
            console.error('Failed to parse customHubData JSON:', e);
            return;
        }

        const rawRecipients = cfg.recipients || [];
        const schoolName    = cfg.schoolName || '';
        const categoryKey   = cfg.categoryKey || '';
        const groupLabel    = cfg.groupLabel || '';
        const docType       = cfg.docType || 'notice';
        const principalName = cfg.principalName || 'PRINCIPAL';

        let activeIdx = 0;
        let seqMode = false;
        let sentSet = new Set();
        let attachedFile = null;
        let attachedUrl  = null;
        let attachedId   = null;
        let messageOverrides = {};

        // ── 1. PERSISTENT STORAGE RESTORATION ──
        const sentStorageKey = 'hcc_custom_sent_' + encodeURIComponent(categoryKey) + '_' + docType;
        try {
            const saved = JSON.parse(localStorage.getItem(sentStorageKey) || '[]');
            if (Array.isArray(saved)) sentSet = new Set(saved);
        } catch (e) {}

        const msgStorageKey = 'hcc_custom_msg_' + encodeURIComponent(categoryKey) + '_' + docType;
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

        // ── 2. DOM REFERENCES ──
        const customNoteInput     = document.getElementById('customNoteInput');
        const mockRecipientName   = document.getElementById('mockRecipientName');
        const mockRecipientSub    = document.getElementById('mockRecipientSubtitle');
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
        const prevRecipientBtn    = document.getElementById('prevRecipientBtn');
        const sendAndNextBtn      = document.getElementById('sendAndNextBtn');
        const bubbleResetBtn      = document.getElementById('bubbleResetBtn');
        const bubbleEditHint      = document.getElementById('bubbleEditHint');

        const customWaApp         = document.getElementById('customWaApp');
        const waEmptyPane         = document.getElementById('waEmptyPane');
        const chatBackBtn         = document.getElementById('chatBackBtn');
        const paneSendBtn         = document.getElementById('paneSendBtn');
        const panePhoneInput      = document.getElementById('panePhoneInput');
        const batchMenuBtn        = document.getElementById('batchMenuBtn');
        const batchMenu           = document.getElementById('batchMenu');

        const totalCountEl        = document.getElementById('totalCountEl');
        const sentCountEl         = document.getElementById('sentCountEl');
        const pendingCountEl      = document.getElementById('pendingCountEl');
        const progressPctEl       = document.getElementById('progressPctEl');

        const seqBar              = document.getElementById('seqBar');
        const seqCountPill        = document.getElementById('seqCountPill');
        const seqRecipientName    = document.getElementById('seqRecipientName');
        const seqSendNextBtn      = document.getElementById('seqSendNextBtn');
        const seqStopBtn          = document.getElementById('seqStopBtn');
        const csrfTokenEl         = document.getElementById('csrfToken');
        const csrfToken           = csrfTokenEl ? csrfTokenEl.value : '';

        // ── 3. PHONE NUMBER NORMALIZATION ──
        function cleanPhone(raw) {
            if (!raw) return '';
            var digits = String(raw).replace(/\D+/g, '');
            if (!digits) return '';
            if (digits.length === 10) return '91' + digits;
            if (digits.length === 11 && digits.charAt(0) === '0') return '91' + digits.substring(1);
            return digits;
        }

        function formatPhoneDisplay(cleaned) {
            if (!cleaned) return '';
            if (cleaned.length === 12 && cleaned.startsWith('91')) {
                return '+91 ' + cleaned.substring(2, 7) + ' ' + cleaned.substring(7);
            }
            return cleaned;
        }

        // Live phone cache by item ID
        const phoneCache = {};
        rawRecipients.forEach(item => {
            phoneCache[String(item.id)] = item.phone || '';
        });

        function getRecipientPhone(id) {
            return phoneCache[String(id)] || '';
        }

        // ── 4. INLINE PHONE AUTO-SAVING VIA AJAX ──
        function saveRecipientPhone(id, rawVal, inputEl) {
            const phone = cleanPhone(rawVal);
            phoneCache[String(id)] = phone;

            if (inputEl) {
                inputEl.classList.remove('is-saved');
                inputEl.classList.add('is-saving');
            }

            const fd = new FormData();
            fd.append('action', 'update_phone');
            fd.append('csrf_token', csrfToken);
            fd.append('item_id', id);
            fd.append('phone', phone);
            fd.append('category', categoryKey);

            fetch('custom_dispatcher.php', {
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
                updateStats();
                updateActivePhoneHeader(id);
            })
            .catch(err => {
                console.error('Phone save failed:', err);
                if (inputEl) inputEl.classList.remove('is-saving');
            });
        }

        function updateActivePhoneHeader(id) {
            const p = getRecipientPhone(id);
            if (mockPhoneStatus) {
                if (p) {
                    mockPhoneStatus.innerHTML = '<i class="fa-brands fa-whatsapp" style="color:#25D366;"></i> ' + formatPhoneDisplay(p);
                } else {
                    mockPhoneStatus.innerHTML = '<i class="fas fa-phone-slash" style="color:#ef4444;"></i> <span style="color:#ef4444;font-weight:600;">No mobile number</span>';
                }
            }
        }

        // ── 5. MESSAGE TEMPLATE FORMATTER ──
        // (Modify this template function to match your exact content!)
        function buildMessageForRecipient(item) {
            const note = customNoteInput ? customNoteInput.value.trim() : '';
            const fields = item.custom_fields || {};
            const lines = [];

            lines.push('*' + (schoolName || 'Hindustani Covenant Church') + '*');
            lines.push('');
            lines.push('OFFICIAL COMMUNICATION / NOTICE');
            lines.push('Date: ' + new Date().toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }));
            lines.push('');
            lines.push('Dear Parent / Guardian,');
            lines.push('');
            lines.push('Recipient: *' + (item.name || '—') + '*');
            lines.push('Group: *' + (groupLabel || '—') + '*');
            if (item.id) lines.push('Reference ID: *' + item.id + '*');

            if (fields.score && fields.score !== '0 / 0') {
                lines.push('Score: *' + fields.score + '*');
            }
            if (fields.percentage) {
                lines.push('Percentage: *' + fields.percentage + '%*');
            }
            if (fields.grade) {
                lines.push('Grade: *' + fields.grade + '*');
            }
            if (fields.remark) {
                lines.push('Remark: *' + fields.remark + '*');
            }

            if (note) {
                lines.push('');
                lines.push('*Important Note:* ' + note);
            }

            if (item.public_url) {
                lines.push('');
                lines.push('Online Verification / Digital Copy: ' + item.public_url);
            }

            lines.push('');
            lines.push('Please acknowledge receipt of this message.');
            lines.push('');
            lines.push('Warm regards,');
            lines.push('*' + principalName + '*');
            lines.push(schoolName);

            return lines.join('\n');
        }

        function getActiveMessage(item) {
            if (!item) return '';
            const ov = messageOverrides[String(item.id)];
            if (typeof ov === 'string' && ov.trim() !== '') {
                return ov;
            }
            return buildMessageForRecipient(item);
        }

        // ── 6. UI PREVIEW & SYNCHRONIZATION ──
        function updateStats() {
            let total = rawRecipients.length;
            let sent = 0;
            let missing = 0;

            rawRecipients.forEach(item => {
                const isSent = sentSet.has(item.id);
                const hasPhone = !!getRecipientPhone(item.id);
                if (isSent) sent++;
                if (!hasPhone) missing++;

                const pill = document.getElementById('statusPill_' + item.id);
                if (pill) {
                    if (isSent) {
                        pill.className = 'wa-status-pill wa-status-sent';
                        pill.textContent = '✓ Sent';
                    } else if (!hasPhone) {
                        pill.className = 'wa-status-pill wa-status-missing';
                        pill.textContent = '✕ No phone';
                    } else {
                        pill.className = 'wa-status-pill wa-status-pending';
                        pill.textContent = '⚪ Pending';
                    }
                }
            });

            const pending = Math.max(0, total - sent);
            const pct = total > 0 ? Math.round((sent / total) * 100) : 0;

            if (totalCountEl) totalCountEl.textContent = total;
            if (sentCountEl) sentCountEl.textContent = sent;
            if (pendingCountEl) pendingCountEl.textContent = pending;
            if (progressPctEl) progressPctEl.textContent = pct + '%';

            const pAll = document.getElementById('pillCountAll');
            const pPending = document.getElementById('pillCountPending');
            const pSent = document.getElementById('pillCountSent');
            const pMissing = document.getElementById('pillCountMissing');
            if (pAll) pAll.textContent = total;
            if (pPending) pPending.textContent = pending;
            if (pSent) pSent.textContent = sent;
            if (pMissing) pMissing.textContent = missing;
        }

        function updatePreview(idx) {
            if (idx < 0 || idx >= rawRecipients.length) return;
            activeIdx = idx;
            const item = rawRecipients[idx];

            if (waEmptyPane) waEmptyPane.style.display = 'none';
            if (mockRecipientName) mockRecipientName.textContent = item.name;
            if (mockRecipientSub) mockRecipientSub.textContent = item.subtitle || ('ID ' + item.id);

            // Avatar initials
            if (mockAvatar) {
                const parts = (item.name || '').trim().split(/\s+/);
                let inits = '';
                parts.slice(0, 2).forEach(p => { if (p) inits += p.charAt(0); });
                mockAvatar.textContent = inits.toUpperCase() || 'CT';
            }

            // Message text
            const text = getActiveMessage(item);
            if (mockBubbleText) mockBubbleText.textContent = text;

            // Check if overridden
            const hasOverride = typeof messageOverrides[String(item.id)] === 'string' && messageOverrides[String(item.id)].trim() !== '';
            if (bubbleResetBtn) bubbleResetBtn.style.display = hasOverride ? 'inline-flex' : 'none';

            // Phone input
            if (panePhoneInput) {
                panePhoneInput.value = getRecipientPhone(item.id);
            }
            updateActivePhoneHeader(item.id);

            // Attachment tile
            if (mockPdfFilename) {
                mockPdfFilename.textContent = item.doc_name || ('Document_' + item.id + '.pdf');
            }

            // Highlight active sidebar item
            document.querySelectorAll('.wa-chat-item').forEach(el => el.classList.remove('is-active-student'));
            const activeRow = document.querySelector(`.wa-chat-item[data-idx="${idx}"]`);
            if (activeRow) activeRow.classList.add('is-active-student');

            // Sequential dispatch bar sync
            if (seqBar && !seqBar.classList.contains('is-hidden')) {
                if (seqCountPill) seqCountPill.textContent = (idx + 1) + ' / ' + rawRecipients.length;
                if (seqRecipientName) seqRecipientName.textContent = item.name;
            }
        }

        // ── 7. DISPATCH ACTIONS (1-Click, Stepper, Web Companion) ──
        function dispatchActiveRecipient(advanceNext) {
            if (activeIdx < 0 || activeIdx >= rawRecipients.length) return;
            const item = rawRecipients[activeIdx];
            const phone = getRecipientPhone(item.id);
            const msg = getActiveMessage(item);
            const enc = encodeURIComponent(msg || '');

            let url = '';
            if (phone) {
                url = 'https://web.whatsapp.com/send?phone=' + phone + '&text=' + enc;
            } else {
                url = 'https://web.whatsapp.com';
            }

            // Open in dedicated companion popup
            const winWidth = 1040;
            const winHeight = 820;
            const left = Math.max(0, Math.round((screen.width - winWidth) / 2));
            const top = Math.max(0, Math.round((screen.height - winHeight) / 2));
            const features = 'width=' + winWidth + ',height=' + winHeight + ',left=' + left + ',top=' + top + ',menubar=no,toolbar=no,location=no,status=no,resizable=yes,scrollbars=yes';
            window.open(url, 'HCC_Custom_WhatsApp', features);

            // Mark as sent
            sentSet.add(item.id);
            try {
                localStorage.setItem(sentStorageKey, JSON.stringify(Array.from(sentSet)));
            } catch (e) {}
            updateStats();

            // Advance if stepper requested
            if (advanceNext && activeIdx < rawRecipients.length - 1) {
                setTimeout(() => updatePreview(activeIdx + 1), 400);
            }
        }

        // ── 8. EVENT LISTENERS SETUP ──
        // Recipient row click
        document.querySelectorAll('.wa-chat-item').forEach(el => {
            el.addEventListener('click', () => {
                const idx = parseInt(el.getAttribute('data-idx'), 10);
                updatePreview(idx);
                if (customWaApp) customWaApp.classList.add('show-chat');
            });
        });

        // Mobile back button
        if (chatBackBtn) {
            chatBackBtn.addEventListener('click', () => {
                if (customWaApp) customWaApp.classList.remove('show-chat');
            });
        }

        // Send buttons
        if (paneSendBtn) paneSendBtn.addEventListener('click', () => dispatchActiveRecipient(false));
        if (openCompanionBtn) openCompanionBtn.addEventListener('click', () => dispatchActiveRecipient(false));
        if (sendAndNextBtn) sendAndNextBtn.addEventListener('click', () => dispatchActiveRecipient(true));

        if (prevRecipientBtn) {
            prevRecipientBtn.addEventListener('click', () => {
                if (activeIdx > 0) updatePreview(activeIdx - 1);
            });
        }

        // Copy message
        if (mockCopyBtn) {
            mockCopyBtn.addEventListener('click', () => {
                const item = rawRecipients[activeIdx];
                const msg = getActiveMessage(item);
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(msg).then(() => {
                        const orig = mockCopyBtn.innerHTML;
                        mockCopyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                        setTimeout(() => mockCopyBtn.innerHTML = orig, 1800);
                    });
                }
            });
        }

        // Phone input auto-saving
        if (panePhoneInput) {
            const handlePhoneInput = () => {
                const item = rawRecipients[activeIdx];
                if (item) saveRecipientPhone(item.id, panePhoneInput.value, panePhoneInput);
            };
            panePhoneInput.addEventListener('change', handlePhoneInput);
            panePhoneInput.addEventListener('blur', handlePhoneInput);
        }

        // Live bubble edits
        if (mockBubbleText) {
            mockBubbleText.addEventListener('input', () => {
                const item = rawRecipients[activeIdx];
                if (item) {
                    messageOverrides[String(item.id)] = mockBubbleText.innerText;
                    persistMessageOverrides();
                    if (bubbleResetBtn) bubbleResetBtn.style.display = 'inline-flex';
                }
            });
        }

        if (bubbleResetBtn) {
            bubbleResetBtn.addEventListener('click', () => {
                const item = rawRecipients[activeIdx];
                if (item) {
                    delete messageOverrides[String(item.id)];
                    persistMessageOverrides();
                    mockBubbleText.textContent = buildMessageForRecipient(item);
                    bubbleResetBtn.style.display = 'none';
                }
            });
        }

        // Custom note input live preview update
        if (customNoteInput) {
            customNoteInput.addEventListener('input', () => {
                const item = rawRecipients[activeIdx];
                if (item && !messageOverrides[String(item.id)]) {
                    mockBubbleText.textContent = buildMessageForRecipient(item);
                }
            });
        }

        // Sequential dispatch banner
        const startSeqBtn = document.getElementById('startSeqBtn');
        if (startSeqBtn && seqBar) {
            startSeqBtn.addEventListener('click', () => {
                seqBar.classList.remove('is-hidden');
                updatePreview(activeIdx);
            });
        }

        if (seqStopBtn && seqBar) {
            seqStopBtn.addEventListener('click', () => {
                seqBar.classList.add('is-hidden');
            });
        }

        if (seqSendNextBtn) {
            seqSendNextBtn.addEventListener('click', () => {
                dispatchActiveRecipient(true);
            });
        }

        // Keyboard Shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.altKey && e.key === 'Enter') {
                e.preventDefault();
                dispatchActiveRecipient(true);
            } else if (e.altKey && e.key === 'ArrowRight') {
                e.preventDefault();
                if (activeIdx < rawRecipients.length - 1) updatePreview(activeIdx + 1);
            } else if (e.altKey && e.key === 'ArrowLeft') {
                e.preventDefault();
                if (activeIdx > 0) updatePreview(activeIdx - 1);
            }
        });

        // 3-Dots Batch Menu toggle
        if (batchMenuBtn && batchMenu) {
            batchMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isHidden = batchMenu.hasAttribute('hidden');
                if (isHidden) batchMenu.removeAttribute('hidden');
                else batchMenu.setAttribute('hidden', '');
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.wa-batch-wrap')) {
                    batchMenu.setAttribute('hidden', '');
                }
            });
        }

        // Copy All Phones
        const copyAllPhonesBtn = document.getElementById('copyAllPhonesBtn');
        if (copyAllPhonesBtn) {
            copyAllPhonesBtn.addEventListener('click', () => {
                const phones = rawRecipients
                    .map(it => getRecipientPhone(it.id))
                    .filter(p => !!p);
                if (phones.length && navigator.clipboard) {
                    navigator.clipboard.writeText(phones.join('\n')).then(() => {
                        alert('Copied ' + phones.length + ' phone numbers to clipboard!');
                    });
                } else {
                    alert('No valid phone numbers found.');
                }
            });
        }

        // Reset Sent Status
        const resetSentStatusBtn = document.getElementById('resetSentStatusBtn');
        if (resetSentStatusBtn) {
            resetSentStatusBtn.addEventListener('click', () => {
                if (confirm('Reset sent status for this entire group?')) {
                    sentSet.clear();
                    localStorage.removeItem(sentStorageKey);
                    updateStats();
                }
            });
        }

        // Live Filter Pills (All / Pending / Sent / Missing Phone)
        document.querySelectorAll('#rosterFilterPills .wa-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('#rosterFilterPills .wa-pill').forEach(b => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                const filter = btn.getAttribute('data-filter');

                document.querySelectorAll('.wa-chat-item').forEach(itemEl => {
                    const id = itemEl.getAttribute('data-id');
                    const isSent = sentSet.has(id);
                    const hasPhone = !!getRecipientPhone(id);

                    let visible = true;
                    if (filter === 'pending') visible = !isSent;
                    else if (filter === 'sent') visible = isSent;
                    else if (filter === 'missing') visible = !hasPhone;

                    itemEl.style.display = visible ? 'flex' : 'none';
                });
            });
        });

        // Initial preview render
        if (rawRecipients.length > 0) {
            updateStats();
            updatePreview(0);
        }
    });
})();
