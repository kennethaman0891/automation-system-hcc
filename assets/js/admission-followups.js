/**
 * HCC Admission Follow-up Management Interactive Engine
 * Handles Dashboard sections, 6-Step WhatsApp manual dispatch workflow,
 * Rust engine triggers, audit histories, and modal actions.
 */

(function () {
    'use strict';

    let currentSection = 'today';
    let currentEnquiryId = null;
    let csrfToken = '';

    function getCsrfToken() {
        if (csrfToken) return csrfToken;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) {
            csrfToken = meta.content;
            return csrfToken;
        }
        const input = document.querySelector('input[name="csrf_token"]');
        if (input && input.value) {
            csrfToken = input.value;
            return csrfToken;
        }
        if (window.HCC_CSRF_TOKEN) {
            csrfToken = window.HCC_CSRF_TOKEN;
            return csrfToken;
        }
        return '';
    }

    // ── Helper: Format Date & Time ──
    function formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function formatTime(timeStr) {
        if (!timeStr) return '';
        const parts = timeStr.split(':');
        let hours = parseInt(parts[0], 10);
        const mins = parts[1] || '00';
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        return `${hours}:${mins} ${ampm}`;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── 1. Fetch & Render Dashboard Counts ──
    async function loadDashboardCounts() {
        try {
            const res = await fetch('api_admission_followup.php?action=get_dashboard_counts');
            const data = await res.json();
            if (!data.success) return;

            const c = data.counts;
            // Update KPI cards and tab badges
            updateCountEl('kpiCountToday', c.today);
            updateCountEl('kpiCountOverdue', c.overdue);
            updateCountEl('kpiCountUpcoming', c.upcoming);
            updateCountEl('kpiCountHigh', c.high_priority);
            updateCountEl('kpiCountAwaiting', c.awaiting_responses);
            updateCountEl('kpiCountInterested', c.interested);
            updateCountEl('kpiCountCompleted', c.completed);

            updateCountEl('tabBadgeToday', c.today);
            updateCountEl('tabBadgeOverdue', c.overdue);
            updateCountEl('tabBadgeUpcoming', c.upcoming);
            updateCountEl('tabBadgeHigh', c.high_priority);
            updateCountEl('tabBadgeAwaiting', c.awaiting_responses);
            updateCountEl('tabBadgeInterested', c.interested);
            updateCountEl('tabBadgeCompleted', c.completed);

            // Update queue pill
            const qPill = document.getElementById('engineQueueBadge');
            if (qPill) {
                qPill.textContent = (c.pending_jobs || 0) + ' Queued';
            }
        } catch (e) {
            console.error('Failed to load dashboard counts', e);
        }
    }

    function updateCountEl(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val !== undefined ? val : 0;
    }

    // ── 2. Load Follow-up List for Active Section ──
    async function loadSection(sectionName, searchVal = '') {
        currentSection = sectionName || currentSection;
        const listContainer = document.getElementById('followupListContainer');
        if (!listContainer) return;

        listContainer.innerHTML = `
            <tr>
                <td colspan="7" style="text-align:center;padding:40px;color:#64748b;">
                    <i class="fas fa-spinner fa-spin" style="font-size:1.5rem;color:#00a884;margin-bottom:10px;"></i>
                    <p style="margin:0;">Loading follow-ups...</p>
                </td>
            </tr>
        `;

        try {
            let url = `api_admission_followup.php?action=list&section=${encodeURIComponent(currentSection)}`;
            if (searchVal) {
                url += `&search=${encodeURIComponent(searchVal)}`;
            }

            const res = await fetch(url);
            const data = await res.json();

            if (!data.success || !data.enquiries || data.enquiries.length === 0) {
                listContainer.innerHTML = `
                    <tr>
                        <td colspan="7" style="text-align:center;padding:48px 20px;color:#64748b;">
                            <div style="width:48px;height:48px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:1.2rem;color:#94a3b8;">
                                <i class="fas fa-inbox"></i>
                            </div>
                            <h4 style="margin:0 0 6px 0;color:#1e293b;font-weight:700;">No follow-up enquiries found</h4>
                            <p style="margin:0;font-size:0.84rem;">There are no enquiries in "${getSectionTitle(currentSection)}" matching your criteria.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            const today = new Date().toISOString().split('T')[0];

            let html = '';
            data.enquiries.forEach(enq => {
                const isOverdue = enq.next_followup_date < today && enq.is_completed == 0;
                const isToday = enq.next_followup_date === today && enq.is_completed == 0;

                let dateBadge = '';
                if (enq.is_completed == 1) {
                    dateBadge = `<span style="color:#10b981;font-weight:700;"><i class="fas fa-check-circle"></i> Completed</span>`;
                } else if (isOverdue) {
                    dateBadge = `<span class="badge-date-overdue"><i class="fas fa-triangle-exclamation"></i> Overdue: ${formatDate(enq.next_followup_date)}</span>`;
                } else if (isToday) {
                    dateBadge = `<span class="badge-date-today"><i class="fas fa-calendar-day"></i> Today at ${formatTime(enq.next_followup_time)}</span>`;
                } else {
                    dateBadge = `<span>${formatDate(enq.next_followup_date)} (${formatTime(enq.next_followup_time)})</span>`;
                }

                const priorityClass = (enq.priority || 'medium').toLowerCase();

                html += `
                    <tr data-enquiry-id="${enq.id}">
                        <td>
                            <strong style="font-size:0.92rem;color:#0f172a;display:block;">${escapeHtml(enq.parent_name)}</strong>
                            <span style="color:#64748b;font-size:0.78rem;">Child: <strong>${escapeHtml(enq.student_name)}</strong> (${escapeHtml(enq.interested_class)})</span>
                        </td>
                        <td>
                            <a href="tel:${escapeHtml(enq.phone_number)}" style="color:#2563eb;text-decoration:none;font-weight:600;">
                                <i class="fas fa-phone-alt" style="font-size:0.75rem;"></i> ${escapeHtml(enq.phone_number)}
                            </a>
                        </td>
                        <td>
                            <span class="badge-category">${escapeHtml(enq.followup_category)}</span>
                        </td>
                        <td>
                            ${dateBadge}
                            <div style="font-size:0.75rem;color:#64748b;margin-top:2px;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtml(enq.followup_reason)}">
                                ${escapeHtml(enq.followup_reason)}
                            </div>
                        </td>
                        <td>
                            <span class="badge-priority ${priorityClass}">
                                <i class="fas fa-flag"></i> ${escapeHtml(enq.priority)}
                            </span>
                        </td>
                        <td>
                            <span style="font-size:0.8rem;color:#334155;font-weight:600;">
                                <i class="fas fa-user-tie" style="font-size:0.75rem;color:#94a3b8;"></i> ${escapeHtml(enq.assigned_staff_name || 'Desk')}
                            </span>
                            ${enq.is_completed == 1 && enq.manual_sent_confirmed == 1 ? `
                                <div class="badge-unverified-delivery" title="Staff manually confirmed sending via WhatsApp Web. This is unverified delivery.">
                                    <i class="fas fa-info-circle"></i> Manual Sent · Unverified
                                </div>
                            ` : ''}
                        </td>
                        <td style="text-align:right;">
                            <button type="button" class="btn btn-sm btn-primary js-open-followup" data-id="${enq.id}" style="padding:6px 12px;font-size:0.78rem;font-weight:700;border-radius:6px;background:#00a884;border:none;color:#fff;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                                <i class="fa-brands fa-whatsapp"></i> Review &amp; Dispatch
                            </button>
                        </td>
                    </tr>
                `;
            });

            listContainer.innerHTML = html;
        } catch (e) {
            console.error('Failed to load follow-up list', e);
            listContainer.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align:center;padding:30px;color:#b91c1c;">
                        <i class="fas fa-circle-exclamation"></i> Failed to load follow-up records. Please try again.
                    </td>
                </tr>
            `;
        }
    }

    function getSectionTitle(sec) {
        switch (sec) {
            case 'today': return "Today's Follow-ups";
            case 'overdue': return "Overdue Follow-ups";
            case 'upcoming': return "Upcoming Follow-ups";
            case 'high_priority': return "High-Priority Enquiries";
            case 'awaiting':
            case 'awaiting_responses': return "Parents Awaiting Responses";
            case 'interested': return "Interested Parents";
            case 'completed': return "Completed Admissions";
            default: return "All Active Follow-ups";
        }
    }

    // ── 3. Open Follow-up Record (6-Step Manual Dispatch Workflow) ──
    async function openFollowupRecord(id) {
        currentEnquiryId = id;
        const modal = document.getElementById('followupDetailModal');
        if (!modal) return;

        // Reset fields
        document.getElementById('modalParentName').textContent = 'Loading...';
        document.getElementById('modalChildMeta').textContent = '';
        document.getElementById('modalConversationContainer').innerHTML = '<div style="text-align:center;padding:20px;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading conversation...</div>';
        document.getElementById('modalSuggestedMessage').value = '';
        document.getElementById('modalHistoryContainer').innerHTML = '';
        document.getElementById('modalStatusBanner').style.display = 'none';

        modal.style.display = 'flex';

        try {
            const res = await fetch(`api_admission_followup.php?action=get&id=${id}`);
            const data = await res.json();
            if (!data.success || !data.enquiry) {
                alert('Failed to load enquiry record.');
                modal.style.display = 'none';
                return;
            }

            const enq = data.enquiry;
            document.getElementById('modalParentName').textContent = enq.parent_name;
            document.getElementById('modalChildMeta').textContent = `Child: ${enq.student_name} (${enq.interested_class}) · Phone: ${enq.phone_number} · Category: ${enq.followup_category}`;

            document.getElementById('modalFollowupReason').textContent = enq.followup_reason || 'General admission follow-up';
            document.getElementById('modalStaffAssigned').textContent = enq.assigned_staff_name || 'Admissions Desk';
            document.getElementById('modalNextAction').textContent = enq.next_recommended_action || 'Review and connect via WhatsApp';
            document.getElementById('modalPriorityBadge').textContent = enq.priority.toUpperCase();
            document.getElementById('modalPriorityBadge').className = `badge-priority ${enq.priority.toLowerCase()}`;

            // Populate Suggested WhatsApp Message
            document.getElementById('modalSuggestedMessage').value = enq.suggested_message || '';

            // Render Conversation Thread
            renderConversationThread(data.messages, enq);

            // Render History Timeline
            renderHistoryTimeline(data.history);

            // Show Completed state if already completed
            const btnMarkCompleted = document.getElementById('btnModalMarkCompleted');
            if (enq.is_completed == 1) {
                document.getElementById('modalStatusBanner').style.display = 'block';
                document.getElementById('modalStatusBannerText').innerHTML = `
                    <strong><i class="fas fa-check-circle"></i> Follow-up Completed on ${formatDate(enq.completed_at)} by ${escapeHtml(enq.completed_by || 'Staff')}</strong>
                    ${enq.manual_sent_confirmed == 1 ? '<span class="badge-unverified-delivery"><i class="fas fa-info-circle"></i> Manual confirmation · Not verified WhatsApp delivery receipt</span>' : ''}
                `;
                if (btnMarkCompleted) btnMarkCompleted.style.display = 'none';
            } else {
                if (btnMarkCompleted) btnMarkCompleted.style.display = 'inline-flex';
            }

        } catch (e) {
            console.error('Failed to get enquiry detail', e);
            alert('An error occurred while loading follow-up detail.');
            modal.style.display = 'none';
        }
    }

    function renderConversationThread(messages, enquiry) {
        const box = document.getElementById('modalConversationContainer');
        if (!box) return;

        if (!messages || messages.length === 0) {
            box.innerHTML = `
                <div style="text-align:center;padding:24px 16px;color:#64748b;">
                    <i class="fa-brands fa-whatsapp" style="font-size:1.8rem;color:#25d366;margin-bottom:8px;"></i>
                    <p style="margin:0 0 4px 0;font-weight:600;color:#1e293b;">No previous WhatsApp messages found in database for this contact.</p>
                    <p style="margin:0;font-size:0.78rem;">Initial enquiry message: "<em>${escapeHtml(enquiry.original_message || 'Inbound admission enquiry')}</em>"</p>
                </div>
            `;
            return;
        }

        let html = '';
        messages.forEach(m => {
            const isOut = m.from_me == 1;
            const bubbleClass = isOut ? 'outbound' : 'inbound';
            html += `
                <div class="chat-bubble ${bubbleClass}">
                    <div class="bubble-sender" style="font-size:0.72rem;font-weight:700;color:${isOut ? '#0f766e' : '#4338ca'};margin-bottom:2px;">
                        ${escapeHtml(m.sender_name || (isOut ? 'HCC School' : enquiry.parent_name))}
                    </div>
                    <div class="bubble-body">${escapeHtml(m.body)}</div>
                    <div class="bubble-meta">${formatTime(m.received_at ? m.received_at.split(' ')[1] : '')}</div>
                </div>
            `;
        });

        box.innerHTML = html;
        box.scrollTop = box.scrollHeight;
    }

    function renderHistoryTimeline(history) {
        const box = document.getElementById('modalHistoryContainer');
        if (!box) return;

        if (!history || history.length === 0) {
            box.innerHTML = '<p style="color:#94a3b8;font-size:0.8rem;margin:0;">No previous follow-up history logged.</p>';
            return;
        }

        let html = '<div class="history-timeline">';
        history.forEach(h => {
            html += `
                <div class="history-item">
                    <div class="history-dot"></div>
                    <div class="history-title">${escapeHtml(h.title)}</div>
                    <div class="history-meta">By <strong>${escapeHtml(h.performed_by || 'System')}</strong> · ${formatDate(h.created_at ? h.created_at.split(' ')[0] : '')} ${formatTime(h.created_at ? h.created_at.split(' ')[1] : '')}</div>
                    <div class="history-details">${escapeHtml(h.details)}</div>
                    ${h.manual_sent_confirmed == 1 ? `
                        <div class="badge-unverified-delivery"><i class="fas fa-shield-halved"></i> Unverified manual send confirmation</div>
                    ` : ''}
                </div>
            `;
        });
        html += '</div>';

        box.innerHTML = html;
    }

    // ── 4. Open WhatsApp Action (Step 4 of Sending Restrictions) ──
    async function handleOpenWhatsApp() {
        if (!currentEnquiryId) return;

        const messageText = document.getElementById('modalSuggestedMessage').value.trim();
        const btn = document.getElementById('btnModalOpenWhatsApp');
        if (btn) btn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('id', currentEnquiryId);
            formData.append('message', messageText);
            formData.append('csrf_token', getCsrfToken());

            const res = await fetch('api_admission_followup.php?action=open_whatsapp', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (!data.success) {
                alert('Error opening WhatsApp: ' + (data.error || 'Failed'));
                return;
            }

            // Launch WhatsApp Web or App in new window
            window.open(data.whatsapp_web_url, '_blank', 'noopener,noreferrer');

            // Show Step 5 & 6 Guidance Banner
            document.getElementById('modalStepNotice').style.display = 'block';
            document.getElementById('modalStepNotice').scrollIntoView({ behavior: 'smooth' });

            // Refresh history in background
            const getRes = await fetch(`api_admission_followup.php?action=get&id=${currentEnquiryId}`);
            const getData = await getRes.json();
            if (getData.success && getData.history) {
                renderHistoryTimeline(getData.history);
            }

        } catch (e) {
            console.error('Failed to open WhatsApp', e);
            alert('Failed to connect to WhatsApp API.');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // ── 5. Mark Follow-up as Completed (Step 6 of Sending Restrictions) ──
    async function handleMarkCompleted() {
        if (!currentEnquiryId) return;

        const notes = prompt("Enter completion notes for this admission follow-up (e.g. Parent confirmed campus visit for Friday / Fee paid):", "");
        if (notes === null) return; // User cancelled

        const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : (opts.message || 'Confirm?'))));
        const confirmedManual = await confirmFn({
            title: 'Confirm Manual WhatsApp Dispatch',
            message: 'Did you manually press Send in WhatsApp?\n\nIn compliance with school policy, this records an UNVERIFIED staff confirmation and is NOT a verified WhatsApp delivery receipt.',
            confirmText: 'Yes, Confirm Sent',
            cancelText: 'No / Skip Confirmation',
            type: 'warning',
            icon: 'fa-circle-check'
        });

        try {
            const formData = new FormData();
            formData.append('id', currentEnquiryId);
            formData.append('completion_notes', notes);
            formData.append('manual_sent_confirmed', confirmedManual ? '1' : '0');
            formData.append('csrf_token', getCsrfToken());

            const res = await fetch('api_admission_followup.php?action=mark_completed', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (!data.success) {
                alert('Error marking completed: ' + (data.error || 'Unknown error'));
                return;
            }

            alert("Follow-up marked as COMPLETED.\nManual confirmation logged as unverified delivery.");
            document.getElementById('followupDetailModal').style.display = 'none';

            // Refresh counts and list
            loadDashboardCounts();
            loadSection(currentSection);

        } catch (e) {
            console.error('Failed to complete follow-up', e);
            alert('Failed to mark follow-up as completed.');
        }
    }

    // ── 6. Trigger Rust Automation Engine ──
    async function triggerRustEngine() {
        const btn = document.getElementById('btnRunEngineNow');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running Engine...';
        }

        try {
            const formData = new FormData();
            formData.append('csrf_token', getCsrfToken());

            const res = await fetch('api_admission_followup.php?action=run_engine', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                alert('Rust Automation Engine executed successfully.\n\nOutput:\n' + data.output);
                loadDashboardCounts();
                loadSection(currentSection);
            } else {
                alert('Engine run reported: ' + (data.error || data.output || 'Check logs'));
            }
        } catch (e) {
            console.error('Engine run failure', e);
            alert('Failed to trigger Rust Automation Engine.');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-play"></i> Run Engine Now';
            }
        }
    }

    // ── 7. DOM Ready & Event Listeners ──
    document.addEventListener('DOMContentLoaded', function () {
        // Initial data load
        loadDashboardCounts();
        loadSection(currentSection);

        // Tab Switcher Buttons
        document.querySelectorAll('.js-followup-tab').forEach(btn => {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.js-followup-tab').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                // Also update KPI active state if matches
                const sec = this.dataset.section;
                document.querySelectorAll('.followup-kpi-card').forEach(card => {
                    card.classList.toggle('active', card.dataset.section === sec);
                });

                loadSection(sec);
            });
        });

        // KPI Card Clicks
        document.querySelectorAll('.followup-kpi-card').forEach(card => {
            card.addEventListener('click', function () {
                const sec = this.dataset.section;
                if (!sec) return;

                document.querySelectorAll('.followup-kpi-card').forEach(c => c.classList.remove('active'));
                this.classList.add('active');

                document.querySelectorAll('.js-followup-tab').forEach(tab => {
                    tab.classList.toggle('active', tab.dataset.section === sec);
                });

                loadSection(sec);
            });
        });

        // Search Input
        const searchInput = document.getElementById('followupSearchInput');
        if (searchInput) {
            let debounceTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    loadSection(currentSection, this.value.trim());
                }, 300);
            });
        }

        // Delegate Click on "Review & Dispatch" table buttons
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.js-open-followup');
            if (btn) {
                const id = btn.dataset.id;
                if (id) openFollowupRecord(id);
            }
        });

        // Open WhatsApp Button
        const btnOpenWa = document.getElementById('btnModalOpenWhatsApp');
        if (btnOpenWa) {
            btnOpenWa.addEventListener('click', handleOpenWhatsApp);
        }

        // Mark Completed Button
        const btnComplete = document.getElementById('btnModalMarkCompleted');
        if (btnComplete) {
            btnComplete.addEventListener('click', handleMarkCompleted);
        }

        // Close Detail Modal
        const btnCloseDetail = document.getElementById('btnFollowupModalClose');
        if (btnCloseDetail) {
            btnCloseDetail.addEventListener('click', function () {
                document.getElementById('followupDetailModal').style.display = 'none';
            });
        }

        // Run Engine Button
        const btnEngine = document.getElementById('btnRunEngineNow');
        if (btnEngine) {
            btnEngine.addEventListener('click', triggerRustEngine);
        }

        // Open New Enquiry Modal
        const btnNew = document.getElementById('btnNewEnquiryModal');
        if (btnNew) {
            btnNew.addEventListener('click', function () {
                const modal = document.getElementById('newEnquiryModal');
                if (modal) modal.style.display = 'flex';
            });
        }

        // Close New Enquiry Modal
        const btnCloseNew = document.getElementById('btnNewEnquiryModalClose');
        if (btnCloseNew) {
            btnCloseNew.addEventListener('click', function () {
                const modal = document.getElementById('newEnquiryModal');
                if (modal) modal.style.display = 'none';
            });
        }

        // Handle New Enquiry Form Submit
        const newForm = document.getElementById('newEnquiryForm');
        if (newForm) {
            newForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;

                try {
                    const formData = new FormData(this);
                    formData.append('csrf_token', getCsrfToken());

                    const res = await fetch('api_admission_followup.php?action=create', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await res.json();
                    if (data.success) {
                        alert('Admission enquiry created successfully.');
                        document.getElementById('newEnquiryModal').style.display = 'none';
                        newForm.reset();
                        loadDashboardCounts();
                        loadSection(currentSection);
                    } else {
                        alert('Error creating enquiry: ' + (data.error || 'Failed'));
                    }
                } catch (err) {
                    console.error('Failed to submit new enquiry', err);
                    alert('An error occurred while creating admission enquiry.');
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        }
    });

    // Expose helpers to global scope
    window.HCCAdmissionFollowups = {
        loadDashboardCounts,
        loadSection,
        openFollowupRecord,
        triggerRustEngine
    };

})();
