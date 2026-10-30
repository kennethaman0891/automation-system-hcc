/**
 * HCC Automation System — Records Archive & Institutional Vault Controller
 * Handles modal orchestration, academic year lock/unlock dialogs,
 * cohort baseline historical lookups, and multi-year student journey dossiers.
 */
(function (window, document) {
    'use strict';

    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('is-active');
    }

    function openArchiveWizard() {
        const el = document.getElementById('archiveWizardModal');
        if (el) el.classList.add('is-active');
    }

    function openToggleLockModal(year, state) {
        const yInput = document.getElementById('toggleLockYearInput');
        const sInput = document.getElementById('toggleLockStateInput');
        if (yInput) yInput.value = year;
        if (sInput) sInput.value = state;

        const title = document.getElementById('toggleLockTitle');
        const msg = document.getElementById('toggleLockMessage');
        const btn = document.getElementById('toggleLockSubmitBtn');

        if (state === 1) {
            if (title) title.innerHTML = '<i class="fa-solid fa-lock" style="color:#991b1b;"></i> Seal & Lock Academic Year ' + escapeHtml(year);
            if (msg) msg.innerHTML = 'Locking <strong>' + escapeHtml(year) + '</strong> will mark records as read-only to prevent unintended changes. All reports, slips, and certificates remain accessible.';
            if (btn) btn.innerHTML = '<i class="fa-solid fa-lock"></i> Seal & Lock Year';
        } else {
            if (title) title.innerHTML = '<i class="fa-solid fa-unlock" style="color:#15803d;"></i> Unlock Academic Year ' + escapeHtml(year);
            if (msg) msg.innerHTML = 'Unlocking <strong>' + escapeHtml(year) + '</strong> will enable editing, imports, and adjustments for this academic year.';
            if (btn) btn.innerHTML = '<i class="fa-solid fa-unlock"></i> Unlock Year';
        }
        const modal = document.getElementById('toggleLockModal');
        if (modal) modal.classList.add('is-active');
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Open Previous Standard Baseline Modal ──
    function openCohortBaselineModal(targetYear, targetStd, targetDiv, titleText) {
        const titleEl = document.getElementById('baselineModalTitle');
        if (titleEl) titleEl.textContent = titleText;
        const modal = document.getElementById('baselineModal');
        if (modal) modal.classList.add('is-active');

        const tbody = document.getElementById('baselineTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading historical baseline records...</td></tr>';
        }

        fetch('records_archive.php?action=ajax_baseline&target_year=' + encodeURIComponent(targetYear) + '&target_std=' + encodeURIComponent(targetStd) + '&target_div=' + encodeURIComponent(targetDiv))
            .then(res => res.json())
            .then(data => {
                if (!tbody) return;
                const countEl = document.getElementById('baselineStudentCount');
                if (!data.ok || !data.data || data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#64748b;">No baseline records found for this cohort.</td></tr>';
                    if (countEl) countEl.textContent = '0';
                    return;
                }
                if (countEl) countEl.textContent = data.data.length;
                let rowsHtml = '';
                data.data.forEach(st => {
                    const obt = parseFloat(st.prev_obtained) || 0;
                    const max = parseFloat(st.prev_max_total) || 0;
                    const pct = parseFloat(st.prev_percentage) || 0;
                    const grade = escapeHtml(st.prev_grade || '—');
                    const sName = escapeHtml(st.student_name || '—');
                    const roll = escapeHtml(st.roll_no || '—');
                    const pStd = escapeHtml(st.source_std + '-' + st.source_div);
                    const sYear = escapeHtml(st.source_year || '');

                    rowsHtml += '<tr>' +
                        '<td style="padding:10px;font-weight:700;">' + roll + '</td>' +
                        '<td style="padding:10px;font-weight:600;color:var(--brand);">' + sName + '</td>' +
                        '<td style="padding:10px;"><span style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-size:11.5px;font-weight:600;">STD ' + pStd + ' (' + sYear + ')</span></td>' +
                        '<td style="padding:10px;text-align:right;"><strong>' + obt + '</strong> / ' + max + '</td>' +
                        '<td style="padding:10px;text-align:right;"><span style="font-weight:700;color:#166534;">' + pct + '%</span></td>' +
                        '<td style="padding:10px;text-align:center;"><span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:12px;font-weight:700;font-size:11px;">' + grade + '</span></td>' +
                        '<td style="padding:10px;text-align:center;">' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary" style="font-size:11px;padding:3px 8px;" onclick="loadStudentJourney(\'' + encodeURIComponent(st.student_name || '') + '\', \'' + encodeURIComponent(st.roll_no || '') + '\')">' +
                                '<i class="fa-solid fa-route"></i> Journey' +
                            '</button>' +
                        '</td>' +
                    '</tr>';
                });
                tbody.innerHTML = rowsHtml;
            })
            .catch(err => {
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#dc2626;">Error loading baseline: ' + escapeHtml(err.message) + '</td></tr>';
                }
            });
    }

    function filterBaselineTable() {
        const input = document.getElementById('baselineTableFilter');
        if (!input) return;
        const q = input.value.toLowerCase();
        const rows = document.querySelectorAll('#baselineTableBody tr');
        rows.forEach(r => {
            r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    // ── Search & Render Student Multi-Year Journey ──
    function searchStudentJourney() {
        const input = document.getElementById('journeySearchInput');
        const name = input ? input.value.trim() : '';
        if (!name) {
            alert('Please enter a student name or roll number to search.');
            return;
        }
        loadStudentJourney(encodeURIComponent(name), '');
    }

    function loadStudentJourney(studentNameEnc, rollNo) {
        const sName = decodeURIComponent(studentNameEnc);
        const modal = document.getElementById('journeyModal');
        if (modal) modal.classList.add('is-active');

        const body = document.getElementById('journeyModalBody');
        if (body) {
            body.innerHTML = '<div style="text-align:center;padding:30px;"><i class="fa-solid fa-spinner fa-spin"></i> Assembling multi-year academic dossier for <strong>' + escapeHtml(sName) + '</strong>...</div>';
        }

        fetch('records_archive.php?action=ajax_student_journey&student_name=' + encodeURIComponent(sName) + '&roll_no=' + encodeURIComponent(rollNo || ''))
            .then(res => res.json())
            .then(res => {
                if (!body) return;
                if (!res.ok || !res.data) {
                    body.innerHTML = '<div style="text-align:center;padding:30px;color:#dc2626;">No records found for ' + escapeHtml(sName) + '.</div>';
                    return;
                }
                const data = res.data;
                let html = '<div style="margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">' +
                    '<div>' +
                        '<h4 style="margin:0;font-size:18px;font-weight:800;color:var(--brand);">' + escapeHtml(sName) + '</h4>' +
                        '<span style="font-size:12px;color:var(--text-2);">HCC Multi-Year Cumulative Academic Dossier</span>' +
                    '</div>' +
                    '<span style="background:#eff6ff;color:#1e40af;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;"><i class="fa-solid fa-id-card"></i> HCC Student</span>' +
                '</div>';

                html += '<div class="progression-timeline">';

                // 1. Report Cards by year
                if (data.report_cards && data.report_cards.length > 0) {
                    data.report_cards.forEach(rc => {
                        const obt = parseFloat(rc.obtained) || 0;
                        const max = parseFloat(rc.max_total) || 0;
                        const pct = max > 0 ? ((obt / max) * 100).toFixed(1) : 0;
                        html += '<div class="timeline-step">' +
                            '<div class="timeline-bullet"><i class="fa-solid fa-check"></i></div>' +
                            '<div class="timeline-card">' +
                                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">' +
                                    '<strong style="color:var(--brand);font-size:14px;">Academic Year ' + escapeHtml(rc.academic_year) + ' &bull; STD ' + escapeHtml(rc.std + '-' + rc.division) + ' (' + escapeHtml(rc.semester) + ')</strong>' +
                                    '<span style="font-size:12px;font-weight:700;color:#166534;">' + pct + '% (' + obt + '/' + max + ')</span>' +
                                '</div>' +
                                '<div style="font-size:12px;color:var(--text-2);">' +
                                    'Roll No: ' + escapeHtml(rc.roll_no) + (rc.attendance ? ' &bull; Attendance: ' + escapeHtml(rc.attendance) : '') + (rc.remark ? ' &bull; Remark: ' + escapeHtml(rc.remark) : '') +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    });
                }

                // 2. Unit Tests
                if (data.unit_tests && data.unit_tests.length > 0) {
                    data.unit_tests.forEach(ut => {
                        const obt = parseFloat(ut.obtained) || 0;
                        const max = parseFloat(ut.max_total) || 0;
                        const pct = max > 0 ? ((obt / max) * 100).toFixed(1) : 0;
                        html += '<div class="timeline-step">' +
                            '<div class="timeline-bullet" style="background:#4f46e5;"><i class="fa-solid fa-pen"></i></div>' +
                            '<div class="timeline-card" style="background:#f8fafc;">' +
                                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">' +
                                    '<strong style="color:#3730a3;font-size:14px;">' + escapeHtml(ut.academic_year) + ' &bull; STD ' + escapeHtml(ut.std + '-' + ut.division) + ' &bull; ' + escapeHtml(ut.test_type) + '</strong>' +
                                    '<span style="font-size:12px;font-weight:700;color:#3730a3;">' + pct + '% (' + obt + '/' + max + ')</span>' +
                                '</div>' +
                                '<div style="font-size:12px;color:var(--text-2);">' +
                                    'Grade: ' + escapeHtml(ut.grade) + (ut.attendance ? ' &bull; Attendance: ' + escapeHtml(ut.attendance) : '') + (ut.remark ? ' &bull; ' + escapeHtml(ut.remark) : '') +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    });
                }

                // 3. Promotions
                if (data.promotions && data.promotions.length > 0) {
                    data.promotions.forEach(pr => {
                        html += '<div class="timeline-step">' +
                            '<div class="timeline-bullet" style="background:#16a34a;"><i class="fa-solid fa-graduation-cap"></i></div>' +
                            '<div class="timeline-card" style="background:#f0fdf4;border-color:#bbf7d0;">' +
                                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">' +
                                    '<strong style="color:#166534;font-size:14px;">Promoted from STD ' + escapeHtml(pr.source_std + '-' + pr.source_div) + ' (' + escapeHtml(pr.source_year) + ') &rarr; STD ' + escapeHtml(pr.target_std + '-' + pr.target_div) + ' (' + escapeHtml(pr.target_year) + ')</strong>' +
                                    '<span style="font-size:11px;background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:10px;font-weight:700;">' + escapeHtml(pr.prev_status) + '</span>' +
                                '</div>' +
                                '<div style="font-size:12px;color:#166534;">' +
                                    'Prior Baseline Score: ' + escapeHtml(pr.prev_obtained) + ' / ' + escapeHtml(pr.prev_max_total) + ' (' + escapeHtml(pr.prev_percentage) + '%, Grade ' + escapeHtml(pr.prev_grade) + ')' +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    });
                }

                // 4. Issued Bonafides
                if (data.bonafides && data.bonafides.length > 0) {
                    data.bonafides.forEach(bf => {
                        html += '<div class="timeline-step">' +
                            '<div class="timeline-bullet" style="background:#d97706;"><i class="fa-solid fa-stamp"></i></div>' +
                            '<div class="timeline-card" style="background:#fffbeb;border-color:#fde68a;">' +
                                '<strong style="color:#92400e;font-size:13.5px;">Bonafide Issued (Serial #' + escapeHtml(bf.cert_serial) + ', GR: ' + escapeHtml(bf.gr_no) + ')</strong>' +
                                '<div style="font-size:12px;color:#78350f;margin-top:2px;">' +
                                    'Purpose: ' + escapeHtml(bf.purpose || 'Educational Purpose') + ' &bull; Date: ' + escapeHtml(bf.issued_date) +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    });
                }

                html += '</div>';
                body.innerHTML = html;
            })
            .catch(err => {
                if (body) {
                    body.innerHTML = '<div style="text-align:center;padding:30px;color:#dc2626;">Error: ' + escapeHtml(err.message) + '</div>';
                }
            });
    }

    // Bind backdrop listener once DOM is ready
    function initVaultBackdrops() {
        document.querySelectorAll('.vault-modal-overlay').forEach(modal => {
            modal.addEventListener('click', function (e) {
                if (e.target === this) this.classList.remove('is-active');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initVaultBackdrops);
    } else {
        initVaultBackdrops();
    }

    // Expose functions globally for existing onclick handlers
    window.closeModal = closeModal;
    window.openArchiveWizard = openArchiveWizard;
    window.openToggleLockModal = openToggleLockModal;
    window.openCohortBaselineModal = openCohortBaselineModal;
    window.filterBaselineTable = filterBaselineTable;
    window.searchStudentJourney = searchStudentJourney;
    window.loadStudentJourney = loadStudentJourney;

})(window, document);
