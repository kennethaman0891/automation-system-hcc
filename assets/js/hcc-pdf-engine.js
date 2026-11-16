/**
 * HCC Automation System — A4 PDF Engine
 * Generates official high-resolution A4 PDFs (210mm x 297mm) for Semester Report Cards
 * and Unit Test Progress Slips featuring the official school logo, academic tables,
 * and signature blocks.
 *
 * Supports:
 * - Single Student A4 PDF Download
 * - Whole-Class Consolidated Multi-page A4 PDF Download
 * - Whole-Class ZIP Archive Download (containing all individual student A4 PDFs)
 * - WhatsApp sharing with PDF attachment / assisted desktop workflow
 */
(function (window, document) {
    'use strict';

    var HCCPdfEngine = {};

    // Retrieve embedded Base64 logo
    function getLogoSrc() {
        if (window.HCC_LOGO_DATA_URL && window.HCC_LOGO_DATA_URL.startsWith('data:image')) {
            return window.HCC_LOGO_DATA_URL;
        }
        return '../assets/images/hcclogo.jpg';
    }

    // Sanitize string for HTML
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Clean filename
    function cleanFilename(str) {
        return String(str || '').replace(/[^a-zA-Z0-9_\-]/g, '_').replace(/_+/g, '_');
    }

    // ── 1. PROGRESS MODAL UI ──
    var progressEl = null;

    HCCPdfEngine.showProgress = function (title, total) {
        if (!progressEl) {
            var div = document.createElement('div');
            div.id = 'hccPdfProgressModal';
            div.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(3px);';
            div.innerHTML = [
                '<div style="background:#fff;border-radius:12px;padding:26px 32px;width:90%;max-width:440px;box-shadow:0 20px 40px rgba(0,0,0,0.25);text-align:center;">',
                '  <div style="width:48px;height:48px;margin:0 auto 14px;background:#e0f2fe;color:#0284c7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">',
                '    <i class="fas fa-file-pdf"></i>',
                '  </div>',
                '  <h3 id="hccPdfProgTitle" style="font-size:1.15rem;font-weight:800;color:#0f172a;margin-bottom:6px;">Generating A4 PDFs...</h3>',
                '  <p id="hccPdfProgStatus" style="font-size:0.85rem;color:#64748b;margin-bottom:18px;">Please wait while documents are rendered.</p>',
                '  <div style="background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;margin-bottom:14px;">',
                '    <div id="hccPdfProgBar" style="background:linear-gradient(90deg,#2563eb,#0284c7);width:0%;height:100%;transition:width 0.2s ease;"></div>',
                '  </div>',
                '  <div id="hccPdfProgCount" style="font-size:0.8rem;font-weight:700;color:#334155;">0 / 0 Completed</div>',
                '</div>'
            ].join('');
            document.body.appendChild(div);
            progressEl = div;
        }
        document.getElementById('hccPdfProgTitle').textContent = title || 'Generating A4 PDFs...';
        document.getElementById('hccPdfProgStatus').textContent = 'Starting document rendering...';
        document.getElementById('hccPdfProgBar').style.width = '0%';
        document.getElementById('hccPdfProgCount').textContent = '0 / ' + (total || 0) + ' Completed';
        progressEl.style.display = 'flex';
    };

    HCCPdfEngine.updateProgress = function (current, total, statusText) {
        if (!progressEl) return;
        var pct = total > 0 ? Math.round((current / total) * 100) : 0;
        var bar = document.getElementById('hccPdfProgBar');
        var count = document.getElementById('hccPdfProgCount');
        var status = document.getElementById('hccPdfProgStatus');
        if (bar) bar.style.width = pct + '%';
        if (count) count.textContent = current + ' / ' + total + ' Completed (' + pct + '%)';
        if (status && statusText) status.textContent = statusText;
    };

    HCCPdfEngine.hideProgress = function () {
        if (progressEl) {
            progressEl.style.display = 'none';
        }
    };

    // ── 2. A4 HTML TEMPLATE RENDERERS ──

    // Display remark helper matching PHP rcDisplayRemark()
    function getDisplayRemark(remark, percentage) {
        var str = String(remark || '').trim();
        if (str.startsWith("'")) str = str.substring(1).trim();
        if (str === '' || !str.startsWith('=')) {
            return str || '—';
        }
        var p = parseFloat(percentage) || 0;
        if (p < 21) return 'Needs major improvement';
        if (p > 90) return 'Excellent';
        if (p > 80) return 'V.Good';
        if (p > 70) return 'Good';
        if (p > 60) return 'Satisfactory';
        if (p > 50) return 'Fair';
        if (p > 40) return 'Average';
        if (p > 30) return 'Can do better';
        return 'Needs to improve';
    }

    // Format grade pill badge matching view_report_card.php
    function getGradePillHtml(grade) {
        var g = String(grade || '—').trim();
        var badgeCls = g.replace(/[^a-zA-Z0-9_-]/g, '');
        var bg = '#eef2ff', bc = '#c7d2fe', col = '#1a237e';
        var up = g.toUpperCase();
        if (up.startsWith('A') || up.includes('DISTINCTION')) {
            bg = '#dcfce7'; bc = '#86efac'; col = '#166534';
        } else if (up.startsWith('B') || up.includes('GRADE I') || up.includes('GRADE-I')) {
            bg = '#dbeafe'; bc = '#93c5fd'; col = '#1d4ed8';
        } else if (up.startsWith('C') || up.includes('GRADE II') || up.includes('GRADE-II')) {
            bg = '#fef3c7'; bc = '#fde68a'; col = '#92400e';
        } else if (up.startsWith('D')) {
            bg = '#fed7aa'; bc = '#fdba74'; col = '#9a3412';
        } else if (up === 'PASS') {
            bg = '#dcfce7'; bc = '#86efac'; col = '#166534';
        } else if (up.startsWith('F') || up.startsWith('E') || up.includes('FAIL')) {
            bg = '#fee2e2'; bc = '#fecaca'; col = '#991b1b';
        }
        return '<span class="grade-pill grade-' + esc(badgeCls) + '" style="display:inline-block;padding:2px 12px;border-radius:12px;font-weight:800;font-size:0.8rem;border:1px solid ' + bc + ';background:' + bg + ';color:' + col + ';">' + esc(g) + '</span>';
    }

    // Format current date as dd-mm-yyyy
    function getFormattedToday() {
        var d = new Date();
        var dd = String(d.getDate()).padStart(2, '0');
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var yyyy = d.getFullYear();
        return dd + '-' + mm + '-' + yyyy;
    }

    /**
     * Renders an Official A4 Semester Report Card
     * Exactly matches admin/view_report_card.php (.print-sheet)
     */
    HCCPdfEngine.renderReportCardHtml = function (st, meta) {
        meta = meta || {};
        var schoolName = meta.schoolName || 'Hindustani Covenant Church High School';
        var schoolTagline = meta.schoolTagline || 'Excellence in Academic & Moral Education';
        var className = meta.className || ('STD ' + (st.std || '') + ' ' + (st.division || '') + (st.semester ? ' — ' + st.semester : ''));
        var ay = meta.academicYear || st.academic_year || '2024-25';
        var logoSrc = getLogoSrc();
        var today = getFormattedToday();

        var subjects = st.subjects || [];
        var rowsHtml = '';
        var hasSa = false;

        subjects.forEach(function (sub) {
            if (parseFloat(sub.sa_max) > 0) hasSa = true;
        });

        if (subjects.length === 0) {
            rowsHtml = '<tr><td colspan="' + (hasSa ? 8 : 5) + '" style="text-align:center;padding:30px;color:#94a3b8;">No subject records found.</td></tr>';
        } else {
            subjects.forEach(function (sub, idx) {
                var sName = esc(sub.name);
                if (hasSa) {
                    var faMax = parseFloat(sub.fa_max) || 0;
                    var faObt = parseFloat(sub.fa_obtained) || 0;
                    var saMax = parseFloat(sub.sa_max) || 0;
                    var saObt = parseFloat(sub.sa_obtained) || 0;
                    var sTotalMax = faMax + saMax;
                    var sTotalObt = faObt + saObt;
                    var sPct = sTotalMax > 0 ? ((sTotalObt / sTotalMax) * 100).toFixed(2) : '0.00';

                    rowsHtml += [
                        '<tr style="background:' + (idx % 2 === 1 ? '#f8fafc' : '#ffffff') + ';">',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:left;width:6%;">' + (idx + 1) + '</td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:left;width:28%;"><strong>' + sName + '</strong></td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:12%;">' + faMax.toFixed(1) + '</td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:12%;">' + faObt.toFixed(1) + '</td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:12%;">' + saMax.toFixed(1) + '</td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:12%;">' + saObt.toFixed(1) + '</td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:14%;"><strong>' + sTotalObt.toFixed(1) + ' / ' + sTotalMax.toFixed(1) + '</strong></td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:14%;">' + sPct + '%</td>',
                        '</tr>'
                    ].join('');
                } else {
                    var maxM = (parseFloat(sub.fa_max) || 0) + (parseFloat(sub.sa_max) || 0) || (parseFloat(sub.max) || 100);
                    var obtM = (parseFloat(sub.fa_obtained) || 0) + (parseFloat(sub.sa_obtained) || 0) || (parseFloat(sub.obtained) || 0);
                    var sPct = maxM > 0 ? ((obtM / maxM) * 100).toFixed(2) : '0.00';

                    rowsHtml += [
                        '<tr style="background:' + (idx % 2 === 1 ? '#f8fafc' : '#ffffff') + ';">',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:left;width:8%;">' + (idx + 1) + '</td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:left;width:44%;"><strong>' + sName + '</strong></td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:16%;">' + maxM.toFixed(1) + '</td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:18%;"><strong>' + obtM.toFixed(1) + '</strong></td>',
                        '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:14%;">' + sPct + '%</td>',
                        '</tr>'
                    ].join('');
                }
            });
        }

        var isPass = (st.status === 'PASS' || st.passed);
        var statusHtml = isPass
            ? '<span style="color:#16a34a;font-weight:700;">PASS</span>'
            : '<span style="color:#dc2626;font-weight:700;">FAIL</span>';

        var grandObt = parseFloat(st.obtained) || 0;
        var grandMax = parseFloat(st.max_total) || 0;
        var pctVal = st.pct ? parseFloat(st.pct).toFixed(2) : (grandMax > 0 ? ((grandObt / grandMax) * 100).toFixed(2) : '0.00');
        var divisionLabel = esc(st.division_label || '—');
        var displayRemarkText = esc(getDisplayRemark(st.remark, pctVal));
        var parentSignText = st.parents_sign ? 'Confirmed' : 'Not Signed';

        return [
            '<div class="print-sheet" style="width:800px;min-height:1132px;box-sizing:border-box;margin:0 auto;background:#ffffff;border:2px solid #1a237e;position:relative;display:flex;flex-direction:column;font-family:\'Inter\',system-ui,-apple-system,sans-serif;color:#0f172a;padding:0;">',

            '  <!-- TOP HEADER WITH LOGO & SCHOOL TITLES -->',
            '  <div class="print-hdr" style="text-align:center;padding:24px 24px 14px;position:relative;">',
            '    <div style="position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#1a237e,#c9943e,#1a237e);"></div>',
            '    <img src="' + logoSrc + '" alt="Logo" style="height:64px;width:auto;object-fit:contain;margin-bottom:6px;display:inline-block;">',
            '    <h1 style="font-family:\'Merriweather\',Georgia,serif;font-size:1.35rem;font-weight:900;color:#1a237e;margin:0;line-height:1.2;">' + esc(schoolName) + '</h1>',
            (schoolTagline ? '    <h2 style="font-family:\'Merriweather\',Georgia,serif;font-size:0.95rem;font-weight:700;color:#1a237e;margin:2px 0 0;letter-spacing:1px;line-height:1.2;">' + esc(schoolTagline) + '</h2>' : ''),
            '  </div>',

            '  <!-- TITLE BAR -->',
            '  <div class="print-title" style="margin:0;padding:10px 24px;border-top:2px solid #1a237e;border-bottom:2px solid #1a237e;background:#ffffff;">',
            '    <h3 style="margin:0;font-size:1rem;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:3px;text-align:center;">Report Card</h3>',
            '  </div>',

            '  <!-- 4-COLUMN META BAR -->',
            '  <div class="print-meta" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:16px 24px;font-size:0.85rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">',
            '    <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Student Name</strong><span style="font-weight:700;color:#1a237e;word-break:break-word;">' + esc(st.name) + '</span></div>',
            '    <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Roll No.</strong><span style="font-weight:700;color:#1a237e;">' + esc(st.roll) + '</span></div>',
            '    <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Class</strong><span style="font-weight:700;color:#1a237e;">' + esc(className) + '</span></div>',
            '    <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Academic Year</strong><span style="font-weight:700;color:#1a237e;">' + esc(ay) + '</span></div>',
            '  </div>',

            '  <!-- PRINT BODY: MARKS TABLE & EVALUATION -->',
            '  <div class="print-body" style="padding:16px 24px 20px;flex:1;">',

            '    <table class="print-table" style="width:100%;border-collapse:collapse;border:2px solid #1a237e;font-size:0.82rem;">',
            '      <thead>',
            '        <tr style="background:#1a237e;color:#ffffff;">',
            (hasSa ?
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:6%;">#</th>' +
            '          <th style="padding:8px 10px;text-align:left;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:28%;">Subject</th>' +
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:12%;">FA Max</th>' +
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:12%;">FA Obtained</th>' +
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:12%;">SA Max</th>' +
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:12%;">SA Obtained</th>' +
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:14%;">Total</th>' +
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:14%;">%</th>'
            :
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:8%;">#</th>' +
            '          <th style="padding:8px 10px;text-align:left;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:44%;">Subject</th>' +
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:16%;">Max Marks</th>' +
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:18%;">Marks Obtained</th>' +
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:14%;">%</th>'
            ),
            '        </tr>',
            '      </thead>',
            '      <tbody>' + rowsHtml + '</tbody>',
            '    </table>',

            '    <!-- 5-COLUMN PERFORMANCE SUMMARY -->',
            '    <div class="print-summary" style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-top:16px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Grand Total</strong><span style="font-weight:800;color:#1a237e;font-size:1.05rem;">' + grandObt.toFixed(1) + ' / ' + grandMax.toFixed(1) + '</span></div>',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Percentage</strong><span style="font-weight:800;color:#1a237e;font-size:1.05rem;">' + pctVal + '%</span></div>',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Grade</strong><span>' + getGradePillHtml(st.grade) + '</span></div>',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Division</strong><span style="font-weight:800;color:#1a237e;font-size:1.05rem;">' + divisionLabel + '</span></div>',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Status</strong><span style="font-weight:800;font-size:1.05rem;">' + statusHtml + '</span></div>',
            '    </div>',

            '    <!-- ADDITIONAL INFORMATION -->',
            '    <div class="print-details" style="margin-top:20px;padding:16px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;">',
            '      <h4 style="margin:0 0 12px;font-size:0.88rem;font-weight:700;color:#1a237e;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">Additional Information</h4>',
            '      <div class="details-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:0.85rem;">',
            '        <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Remark</strong><span style="font-weight:600;color:#334155;">' + displayRemarkText + '</span></div>',
            '        <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Attendance</strong><span style="font-weight:600;color:#334155;">' + esc(st.attendance || '—') + '</span></div>',
            '        <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Parent Signature</strong><span style="font-weight:600;color:#334155;">' + parentSignText + '</span></div>',
            '        <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Date Issued</strong><span style="font-weight:600;color:#334155;">' + today + '</span></div>',
            '        <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Class Teacher</strong><span style="font-weight:600;color:#334155;"></span></div>',
            '        <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Principal</strong><span style="font-weight:600;color:#334155;"></span></div>',
            '      </div>',
            '    </div>',

            '  </div>',

            '  <!-- 3-COLUMN OFFICIAL SIGNATURE BLOCK -->',
            '  <div class="print-foot" style="display:flex;justify-content:space-between;gap:16px;padding:26px 24px 20px;font-size:0.82rem;color:#475569;margin-top:auto;">',
            '    <div class="sig" style="text-align:center;flex:1;"><div class="sig-line" style="width:180px;border-bottom:1.5px solid #94a3b8;height:34px;margin:0 auto 5px;"></div>Class Teacher</div>',
            '    <div class="sig" style="text-align:center;flex:1;"><div class="sig-line" style="width:180px;border-bottom:1.5px solid #94a3b8;height:34px;margin:0 auto 5px;"></div>Coordinator</div>',
            '    <div class="sig" style="text-align:center;flex:1;"><div class="sig-line" style="width:180px;border-bottom:1.5px solid #94a3b8;height:34px;margin:0 auto 5px;"></div>Principal</div>',
            '  </div>',

            '</div>'
        ].join('');
    };

    /**
     * Renders an Official A4 Unit Test Report Card
     * Exactly matches admin/unit_test_report_card.php (.print-sheet)
     */
    HCCPdfEngine.renderUnitTestHtml = function (st, meta) {
        meta = meta || {};
        var schoolName = meta.schoolName || 'Hindustani Covenant Church High School';
        var schoolTagline = meta.schoolTagline || 'Excellence in Academic & Moral Education';
        var className = meta.className || ('STD ' + (st.std || '') + ' ' + (st.division || ''));
        var testType = meta.testType || st.testType || 'Unit Test 2';
        var ay = meta.academicYear || st.academic_year || '2025-26';
        var logoSrc = getLogoSrc();
        var today = getFormattedToday();

        var subjects = st.subjects || [];
        var rowsHtml = '';

        if (subjects.length === 0) {
            rowsHtml = '<tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">No subject records found.</td></tr>';
        } else {
            subjects.forEach(function (sub, idx) {
                var sName = esc(sub.name);
                var maxM = parseFloat(sub.max) || 25;
                var obtStr = (sub.obtained !== undefined && sub.obtained !== null) ? String(sub.obtained) : '0';
                var isAb = (obtStr === 'Absent' || obtStr === '-' || obtStr === '–' || sub.is_absent);
                var obtVal = (!isAb && !isNaN(parseFloat(obtStr))) ? parseFloat(obtStr) : null;
                var subjPct = (!isAb && obtVal !== null && maxM > 0) ? ((obtVal / maxM) * 100).toFixed(2) : null;

                rowsHtml += [
                    '<tr style="background:' + (idx % 2 === 1 ? '#f8fafc' : '#ffffff') + ';">',
                    '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:8%;">' + (idx + 1) + '</td>',
                    '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:left;width:40%;"><strong>' + sName + '</strong></td>',
                    '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:16%;">' + maxM.toFixed(1) + '</td>',
                    '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:18%;">' + (isAb ? '<strong>–</strong>' : (obtVal !== null ? '<strong>' + obtVal.toFixed(1) + '</strong>' : '–')) + '</td>',
                    '  <td style="padding:7px 10px;border-bottom:1px solid #e2e8f0;color:#334155;text-align:center;width:18%;">' + (subjPct !== null ? subjPct + '%' : '–') + '</td>',
                    '</tr>'
                ].join('');
            });
        }

        var grandObt = parseFloat(st.obtained) || 0;
        var grandMax = parseFloat(st.max_total) || 0;
        var pctVal = st.pct ? parseFloat(st.pct).toFixed(2) : (grandMax > 0 ? ((grandObt / grandMax) * 100).toFixed(2) : null);
        var isPass = (st.status === 'PASS' || st.passed || (pctVal !== null && parseFloat(pctVal) >= 35));
        var statusHtml = isPass
            ? '<span style="color:#16a34a;font-weight:700;">PASS</span>'
            : '<span style="color:#dc2626;font-weight:700;">FAIL</span>';
        var gradeText = esc(st.grade || '–');
        var parentSignText = st.parents_sign ? 'Confirmed' : 'Not Signed';

        return [
            '<div class="print-sheet" style="width:800px;min-height:1132px;box-sizing:border-box;margin:0 auto;background:#ffffff;border:2px solid #1a237e;position:relative;display:flex;flex-direction:column;font-family:\'Inter\',system-ui,-apple-system,sans-serif;color:#0f172a;padding:0;">',

            '  <!-- TOP HEADER WITH LOGO & SCHOOL TITLES -->',
            '  <div class="print-hdr" style="text-align:center;padding:24px 24px 14px;position:relative;">',
            '    <div style="position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#1a237e,#c9943e,#1a237e);"></div>',
            '    <img src="' + logoSrc + '" alt="Logo" style="height:64px;width:auto;object-fit:contain;margin-bottom:6px;display:inline-block;">',
            '    <h1 style="font-family:\'Merriweather\',Georgia,serif;font-size:1.35rem;font-weight:900;color:#1a237e;margin:0;line-height:1.2;">' + esc(schoolName) + '</h1>',
            (schoolTagline ? '    <h2 style="font-family:\'Merriweather\',Georgia,serif;font-size:0.95rem;font-weight:700;color:#1a237e;margin:2px 0 0;letter-spacing:1px;line-height:1.2;">' + esc(schoolTagline) + '</h2>' : ''),
            '  </div>',

            '  <!-- TITLE BAR -->',
            '  <div class="print-title" style="margin:0;padding:10px 24px;border-top:2px solid #1a237e;border-bottom:2px solid #1a237e;background:#ffffff;">',
            '    <h3 style="margin:0;font-size:1rem;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:3px;text-align:center;">Unit Test Report Card</h3>',
            '  </div>',

            '  <!-- 4-COLUMN META BAR -->',
            '  <div class="print-meta" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:16px 24px;font-size:0.85rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">',
            '    <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Student Name</strong><span style="font-weight:700;color:#1a237e;word-break:break-word;">' + esc(st.name) + '</span></div>',
            '    <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Roll No.</strong><span style="font-weight:700;color:#1a237e;">' + esc(st.roll) + '</span></div>',
            '    <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Class</strong><span style="font-weight:700;color:#1a237e;">' + esc(className) + '</span></div>',
            '    <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Exam</strong><span style="font-weight:700;color:#1a237e;">' + esc(testType) + ' (' + esc(ay) + ')</span></div>',
            '  </div>',

            '  <!-- PRINT BODY: MARKS TABLE & EVALUATION -->',
            '  <div class="print-body" style="padding:16px 24px 20px;flex:1;">',

            '    <table class="print-table" style="width:100%;border-collapse:collapse;border:2px solid #1a237e;font-size:0.82rem;">',
            '      <thead>',
            '        <tr style="background:#1a237e;color:#ffffff;">',
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:8%;">#</th>',
            '          <th style="padding:8px 10px;text-align:left;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:40%;">Subject</th>',
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:16%;">Max Marks</th>',
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:18%;">Marks Obtained</th>',
            '          <th style="padding:8px 10px;text-align:center;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border:1px solid #1a237e;width:18%;">%</th>',
            '        </tr>',
            '      </thead>',
            '      <tbody>' + rowsHtml + '</tbody>',
            '    </table>',

            '    <!-- 5-COLUMN PERFORMANCE SUMMARY -->',
            '    <div class="print-summary" style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-top:16px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Grand Total</strong><span style="font-weight:800;color:#1a237e;font-size:1.05rem;">' + grandObt.toFixed(1) + ' / ' + grandMax.toFixed(1) + '</span></div>',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Percentage</strong><span style="font-weight:800;color:#1a237e;font-size:1.05rem;">' + (pctVal !== null ? pctVal + '%' : '–') + '</span></div>',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Grade</strong><span style="font-weight:800;color:#1a237e;font-size:1.05rem;">' + gradeText + '</span></div>',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Status</strong><span style="font-weight:800;font-size:1.05rem;">' + statusHtml + '</span></div>',
            '      <div style="display:flex;flex-direction:column;gap:4px;text-align:center;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Attendance</strong><span style="font-weight:800;color:#1a237e;font-size:1.05rem;">' + esc(st.attendance || '—') + '</span></div>',
            '    </div>',

            '    <!-- ADDITIONAL INFORMATION -->',
            '    <div class="print-details" style="margin-top:20px;padding:16px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;">',
            '      <h4 style="margin:0 0 12px;font-size:0.88rem;font-weight:700;color:#1a237e;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">Additional Information</h4>',
            '      <div class="details-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:0.85rem;">',
            '        <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Remark</strong><span style="font-weight:600;color:#334155;">' + esc(st.remark || '—') + '</span></div>',
            '        <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Date Issued</strong><span style="font-weight:600;color:#334155;">' + today + '</span></div>',
            '        <div style="display:flex;flex-direction:column;gap:2px;"><strong style="color:#64748b;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Parents Sign</strong><span style="font-weight:600;color:#334155;">' + parentSignText + '</span></div>',
            '      </div>',
            '    </div>',

            '  </div>',

            '  <!-- 3-COLUMN OFFICIAL SIGNATURE BLOCK -->',
            '  <div class="print-foot" style="display:flex;justify-content:space-between;gap:16px;padding:26px 24px 20px;font-size:0.82rem;color:#475569;margin-top:auto;">',
            '    <div class="sig" style="text-align:center;flex:1;"><div class="sig-line" style="width:180px;border-bottom:1.5px solid #94a3b8;height:34px;margin:0 auto 5px;"></div>Teacher Signature</div>',
            '    <div class="sig" style="text-align:center;flex:1;"><div class="sig-line" style="width:180px;border-bottom:1.5px solid #94a3b8;height:34px;margin:0 auto 5px;"></div>Coordinator Signature</div>',
            '    <div class="sig" style="text-align:center;flex:1;"><div class="sig-line" style="width:180px;border-bottom:1.5px solid #94a3b8;height:34px;margin:0 auto 5px;"></div>Principal Signature</div>',
            '  </div>',

            '</div>'
        ].join('');
    };

    /**
     * Renders an Official Bonafide Certificate
     * Exactly matches admin/bonafide_certificate.php (.cert-sheet)
     */
    HCCPdfEngine.renderBonafideHtml = function (st, meta) {
        meta = meta || {};
        var schoolName = meta.schoolName || 'Hindustani Covenant Church High School';
        var address = '166, Railway Lines, Near Rang Bhawan, Church Street, Solapur-413 001';
        var grNo = esc(st.gr_no || '3875');
        var certSerial = esc(st.cert_serial || st.roll || '01');
        var principalName = esc(meta.principalName || 'PRINCIPAL');
        var studentName = esc(st.name || '____');
        var stdDiv = esc(st.std_div || meta.className || 'STD');
        var dobDigits = esc(st.dob_digits || '____');
        var dobWords = esc(st.dob_words || '____');
        var academicYear = esc(st.academic_year || meta.academicYear || '2025-2026');
        var caste = esc(st.caste || '_______');
        var issueDate = esc(st.issue_date || getFormattedToday());
        var logoSrc = getLogoSrc();

        return [
            '<div class="cert-sheet" style="width:800px;min-height:1132px;box-sizing:border-box;margin:0 auto;background:#ffffff;border:2px solid #1a237e;padding:38px 46px;position:relative;display:flex;flex-direction:column;font-family:\'Inter\',system-ui,-apple-system,sans-serif;color:#0f1e3d;">',

            '  <!-- SCHOOL HEADER WITH LOGO -->',
            '  <div class="cert-top" style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">',
            '    <div class="cert-school" style="display:flex;gap:12px;align-items:center;text-align:left;flex:1;">',
            '      <img src="' + logoSrc + '" alt="Logo" style="height:54px;width:54px;object-fit:contain;border-radius:10px;border:1px solid #e5eaf1;background:#fff;padding:4px;flex-shrink:0;">',
            '      <div class="cert-school-text">',
            '        <h1 style="font-family:\'Merriweather\',Georgia,serif;font-size:1.15rem;font-weight:900;color:#1a237e;margin:0;line-height:1.35;">' + esc(schoolName) + '</h1>',
            '        <p style="font-size:0.72rem;color:#475569;margin:4px 0 0;font-weight:600;">' + address + '</p>',
            '      </div>',
            '    </div>',
            '  </div>',

            '  <!-- REFERENCE NUMBERS (G.R & SERIAL) -->',
            '  <div class="cert-ref" style="margin:4px 0 8px;">',
            '    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;font-weight:600;color:#334155;">',
            '      <tr>',
            '        <td style="text-align:left;">G.R No:<span style="margin-left:6px;text-decoration:underline;text-underline-offset:3px;font-weight:700;">' + grNo + '</span></td>',
            '        <td style="text-align:right;">Sr. No:<span style="margin-left:6px;text-decoration:underline;text-underline-offset:3px;font-weight:700;">' + certSerial + '</span></td>',
            '      </tr>',
            '    </table>',
            '  </div>',

            '  <!-- CERTIFICATE TITLE -->',
            '  <div class="cert-title" style="text-align:center;margin:16px 0 24px;">',
            '    <h2 style="font-family:\'Merriweather\',Georgia,serif;font-size:1.25rem;font-weight:900;color:#b91c1c;letter-spacing:0.2em;text-transform:uppercase;margin:0;">Bonafide Certificate</h2>',
            '    <div style="width:72px;height:3px;background:#c9a227;border-radius:999px;margin:9px auto 0;"></div>',
            '  </div>',

            '  <!-- CERTIFICATE CERTIFICATION BODY -->',
            '  <div class="cert-body" style="flex:1;font-size:0.96rem;line-height:2.1;text-align:justify;padding:0 4px;">',
            '    <p style="margin:0 0 16px;">',
            '      This is to certify that <span style="text-decoration:underline;text-underline-offset:3px;font-weight:700;color:#0f172a;">' + studentName + '</span>',
            '      is a <strong>Bonafide Student</strong> of High School / Jr. College.',
            '      He/She is studying in <span style="text-decoration:underline;text-underline-offset:3px;font-weight:700;color:#0f172a;">' + stdDiv + '</span>.',
            '      His/Her date of birth according to the record is',
            '      <span style="text-decoration:underline;text-underline-offset:3px;font-weight:700;color:#0f172a;">' + dobDigits + '</span>.',
            '      (In words: <span style="text-decoration:underline;text-underline-offset:3px;font-weight:700;color:#0f172a;">' + dobWords + '</span>.)',
            '      For <span style="text-decoration:underline;text-underline-offset:3px;font-weight:700;color:#0f172a;">' + academicYear + '</span>.',
            '      <br>Caste :- <span style="text-decoration:underline;text-underline-offset:3px;font-weight:700;color:#0f172a;">' + caste + '</span>',
            '    </p>',
            '  </div>',

            '  <!-- CERTIFICATE FOOTER -->',
            '  <div class="cert-foot" style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:auto;padding-top:24px;font-size:0.85rem;">',
            '    <div class="cert-date" style="font-weight:600;color:#334155;">',
            '      Date : <span style="font-family:\'Merriweather\',Georgia,serif;font-size:0.95rem;font-weight:700;color:#1a237e;text-decoration:underline;text-underline-offset:3px;padding:0 6px;">' + issueDate + '</span>',
            '    </div>',
            '    <div class="cert-photo" style="width:112px;height:136px;border:1.5px dashed #94a3b8;border-radius:6px;background:#f8fafc;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#94a3b8;font-size:0.68rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;gap:6px;">',
            '      <i class="fas fa-camera" style="font-size:1.1rem;"></i>Photo',
            '    </div>',
            '    <div class="cert-principal" style="text-align:center;font-size:0.85rem;font-weight:800;color:#1a237e;">',
            '      <div class="sig-line" style="width:190px;height:32px;margin:0 auto 6px;border-bottom:1.5px solid #1a237e;"></div>',
            '      <span>' + principalName + '</span>',
            '    </div>',
            '  </div>',

            '</div>'
        ].join('');
    };

    // ── 3. CORE PDF GENERATOR (Single Document) ──

    /**
     * Renders off-screen HTML and returns Promise<{ pdf, blob, dataUrl, fileName }>
     */
    HCCPdfEngine.generateSinglePdf = function (st, docType, meta) {
        return new Promise(function (resolve, reject) {
            try {
                if (!window.html2canvas) {
                    return reject(new Error('html2canvas library is not loaded.'));
                }
                if (!window.jspdf || !window.jspdf.jsPDF) {
                    return reject(new Error('jsPDF library is not loaded.'));
                }

                var html;
                var docPrefix;
                if (docType === 'unit_test') {
                    html = HCCPdfEngine.renderUnitTestHtml(st, meta);
                    docPrefix = cleanFilename(meta.testType || 'UnitTest');
                } else if (docType === 'bonafide') {
                    html = HCCPdfEngine.renderBonafideHtml(st, meta);
                    docPrefix = 'Bonafide_Certificate';
                } else {
                    html = HCCPdfEngine.renderReportCardHtml(st, meta);
                    docPrefix = 'ReportCard';
                }

                var container = document.createElement('div');
                container.style.position = 'fixed';
                container.style.left = '0';
                container.style.top = '0';
                container.style.width = '800px';
                container.style.minHeight = '1132px';
                container.style.background = '#ffffff';
                container.style.zIndex = '-99999';
                container.style.pointerEvents = 'none';
                container.innerHTML = html;
                document.body.appendChild(container);

                var targetNode = container.firstElementChild || container;

                window.html2canvas(targetNode, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    logging: false,
                    width: 800,
                    windowWidth: 800
                }).then(function (canvas) {
                    var imgData = canvas.toDataURL('image/jpeg', 0.95);
                    var jsPDF = window.jspdf.jsPDF;
                    var pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

                    // Standard A4: 210mm x 297mm (preserve canvas aspect ratio)
                    var imgProps = pdf.getImageProperties(imgData);
                    var pageW = 210, pageH = 297;
                    var pdfW = pageW;
                    var pdfH = (imgProps.height * pageW) / imgProps.width;
                    if (pdfH > pageH) {
                        pdfH = pageH;
                        pdfW = (imgProps.width * pageH) / imgProps.height;
                    }
                    var offsetX = (pageW - pdfW) / 2;
                    pdf.addImage(imgData, 'JPEG', offsetX, 0, pdfW, pdfH, undefined, 'FAST');

                    var blob = pdf.output('blob');
                    var rollStr = String(st.roll || '0').padStart(2, '0');
                    var safeName = cleanFilename(st.name || 'Student');
                    var fileName = docPrefix + '_Roll_' + rollStr + '_' + safeName + '.pdf';

                    document.body.removeChild(container);

                    resolve({
                        pdf: pdf,
                        blob: blob,
                        dataUrl: imgData,
                        fileName: fileName
                    });
                }).catch(function (err) {
                    if (container.parentNode) document.body.removeChild(container);
                    reject(err);
                });

            } catch (err) {
                reject(err);
            }
        });
    };

    /**
     * Download A4 PDF for an individual student
     */
    HCCPdfEngine.downloadSinglePdf = function (st, docType, meta) {
        if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
            window.HCCWhatsApp.showToast('Generating A4 PDF for ' + (st.name || 'Student') + '...', 'fa-spinner fa-spin');
        }
        return HCCPdfEngine.generateSinglePdf(st, docType, meta).then(function (res) {
            res.pdf.save(res.fileName);
            if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                window.HCCWhatsApp.showToast('Downloaded ' + res.fileName, 'fa-file-pdf');
            }
            return res;
        }).catch(function (err) {
            console.error('Error generating single PDF:', err);
            alert('Failed to generate PDF: ' + err.message);
        });
    };

    // ── 4. BATCH GENERATOR: ALL-IN-ONE CONSOLIDATED A4 PDF ──

    /**
     * Compiles all students into a single multi-page A4 PDF (1 student per A4 page)
     */
    HCCPdfEngine.downloadClassBatchPdf = function (students, docType, meta, onProgress) {
        return new Promise(function (resolve, reject) {
            if (!students || students.length === 0) {
                alert('No students to export.');
                return resolve();
            }

            var total = students.length;
            HCCPdfEngine.showProgress('Compiling Class A4 PDF...', total);

            var jsPDF = window.jspdf.jsPDF;
            var masterPdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
            var currentIndex = 0;

            function processNext() {
                if (currentIndex >= total) {
                    HCCPdfEngine.updateProgress(total, total, 'Saving master PDF file...');
                    setTimeout(function () {
                        var classLabel = cleanFilename(meta.className || 'Class');
                        var docLabel = (docType === 'unit_test') ? cleanFilename(meta.testType || 'UnitTest') : (docType === 'bonafide' ? 'Bonafide_Certificates' : 'ReportCards');
                        var masterFilename = classLabel + '_' + docLabel + '_Complete_Class_A4.pdf';
                        masterPdf.save(masterFilename);
                        HCCPdfEngine.hideProgress();
                        if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                            window.HCCWhatsApp.showToast('Downloaded ' + masterFilename, 'fa-check');
                        }
                        resolve(masterPdf);
                    }, 400);
                    return;
                }

                var st = students[currentIndex];
                HCCPdfEngine.updateProgress(currentIndex + 1, total, 'Rendering student ' + (currentIndex + 1) + ' of ' + total + ' (' + st.name + ')...');

                HCCPdfEngine.generateSinglePdf(st, docType, meta).then(function (res) {
                    if (currentIndex > 0) {
                        masterPdf.addPage();
                    }
                    var imgProps = masterPdf.getImageProperties(res.dataUrl);
                    var pageW = 210, pageH = 297;
                    var pdfW = pageW;
                    var pdfH = (imgProps.height * pageW) / imgProps.width;
                    if (pdfH > pageH) {
                        pdfH = pageH;
                        pdfW = (imgProps.width * pageH) / imgProps.height;
                    }
                    var offsetX = (pageW - pdfW) / 2;
                    masterPdf.addImage(res.dataUrl, 'JPEG', offsetX, 0, pdfW, pdfH, undefined, 'FAST');
                    currentIndex++;
                    setTimeout(processNext, 50);
                }).catch(function (err) {
                    console.error('Error on student index ' + currentIndex, err);
                    currentIndex++;
                    setTimeout(processNext, 50);
                });
            }

            processNext();
        });
    };

    // ── 5. BATCH GENERATOR: ZIP ARCHIVE OF INDIVIDUAL A4 PDFS ──

    /**
     * Generates all individual A4 PDFs and bundles them into a ZIP archive
     */
    HCCPdfEngine.downloadClassBatchZip = function (students, docType, meta, onProgress) {
        return new Promise(function (resolve, reject) {
            if (!students || students.length === 0) {
                alert('No students to export.');
                return resolve();
            }
            if (!window.JSZip) {
                alert('JSZip library is not loaded. Please refresh the page.');
                return reject(new Error('JSZip not found'));
            }

            var total = students.length;
            HCCPdfEngine.showProgress('Packaging Class PDFs into ZIP...', total);

            var zip = new window.JSZip();
            var currentIndex = 0;

            function processNext() {
                if (currentIndex >= total) {
                    HCCPdfEngine.updateProgress(total, total, 'Compressing ZIP archive...');
                    zip.generateAsync({ type: 'blob' }).then(function (blobContent) {
                        var classLabel = cleanFilename(meta.className || 'Class');
                        var docLabel = (docType === 'unit_test') ? cleanFilename(meta.testType || 'UnitTest') : (docType === 'bonafide' ? 'Bonafide_Certificates' : 'ReportCards');
                        var zipName = classLabel + '_' + docLabel + '_A4_PDFs.zip';

                        // Trigger download
                        var link = document.createElement('a');
                        link.href = URL.createObjectURL(blobContent);
                        link.download = zipName;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        URL.revokeObjectURL(link.href);

                        HCCPdfEngine.hideProgress();
                        if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
                            window.HCCWhatsApp.showToast('Downloaded ' + zipName, 'fa-file-zipper');
                        }
                        resolve(zipName);
                    }).catch(function (err) {
                        HCCPdfEngine.hideProgress();
                        reject(err);
                    });
                    return;
                }

                var st = students[currentIndex];
                HCCPdfEngine.updateProgress(currentIndex + 1, total, 'Generating PDF for Roll ' + st.roll + ' (' + st.name + ')...');

                HCCPdfEngine.generateSinglePdf(st, docType, meta).then(function (res) {
                    zip.file(res.fileName, res.blob);
                    currentIndex++;
                    setTimeout(processNext, 50);
                }).catch(function (err) {
                    console.error('Error generating PDF for student ' + currentIndex, err);
                    currentIndex++;
                    setTimeout(processNext, 50);
                });
            }

            processNext();
        });
    };

    // ── 6. WHATSAPP SHARING WITH PDF ATTACHMENT ──

    /**
     * Prepares PDF and launches WhatsApp sharing:
     * - Web Share API with attached File on mobile / Safari
     * - Assisted auto-download + copy message + open WhatsApp Web on Desktop
     */
    HCCPdfEngine.shareWhatsAppWithPdf = function (st, docType, meta, phone, customNote) {
        var note = customNote || '';
        var msg = '';
        if (docType === 'unit_test') {
            msg = window.HCCWhatsApp.templates.unitTest({
                schoolName: meta.schoolName,
                studentName: st.name,
                roll: st.roll,
                className: meta.className,
                testType: meta.testType,
                subjects: st.subjects,
                obtained: st.obtained,
                maxTotal: st.max_total,
                percentage: st.pct,
                grade: st.grade,
                remark: st.remark,
                customNote: note
            });
        } else {
            msg = window.HCCWhatsApp.templates.reportCard({
                schoolName: meta.schoolName,
                studentName: st.name,
                roll: st.roll,
                className: meta.className,
                semester: meta.semester,
                obtained: st.obtained,
                maxTotal: st.max_total,
                percentage: st.pct,
                grade: st.grade,
                status: st.status,
                attendance: st.attendance,
                remark: st.remark,
                customNote: note
            });
        }

        if (window.HCCWhatsApp && window.HCCWhatsApp.showToast) {
            window.HCCWhatsApp.showToast('Preparing A4 PDF for WhatsApp...', 'fa-spinner fa-spin');
        }

        return HCCPdfEngine.generateSinglePdf(st, docType, meta).then(function (res) {
            window.HCCWhatsApp.openModal({
                studentName: st.name,
                roll: st.roll,
                className: meta.className,
                classKey: meta.classKey,
                phone: phone,
                messageText: msg,
                pdfBlob: res.blob,
                fileName: res.fileName,
                onDownloadPdf: function () {
                    res.pdf.save(res.fileName);
                }
            });
        }).catch(function (err) {
            console.error('Error preparing PDF for WhatsApp:', err);
            // Fallback to text modal
            window.HCCWhatsApp.openModal({
                studentName: st.name,
                roll: st.roll,
                className: meta.className,
                classKey: meta.classKey,
                phone: phone,
                messageText: msg
            });
        });
    };

    // ── 7. BASE64 PDF GENERATOR (For API / Automation) ──

    /**
     * Generates a single PDF and returns it as base64 data URL + blob
     */
    HCCPdfEngine.generateSinglePdfBase64 = function (st, docType, meta) {
        return HCCPdfEngine.generateSinglePdf(st, docType, meta).then(function (res) {
            return {
                base64: res.pdf.output('dataurlonly'),
                fileName: res.fileName,
                blob: res.blob
            };
        });
    };

    // ── 8. AUTOMATED WHATSAPP SEND (Puppeteer Backend) ──

    /**
     * Sends PDF via Puppeteer browser automation through the PHP API endpoint.
     * Generates PDF, encodes as base64, USED TO call api_automated_send.php (now 410 — send from WhatsApp hub instead),
     * which triggers the Node.js Puppeteer script.
     */
    HCCPdfEngine.shareWhatsAppAutomated = function (st, docType, meta, phone, customNote, messageOverride) {
        // REMOVED: automated WhatsApp sending. Use the WhatsApp hub Send button.
        return Promise.reject(new Error('Automated WhatsApp sending has been removed. Open the WhatsApp hub, attach the PDF or paste the message, and press Send.'));
    };

    window.HCCPdfEngine = HCCPdfEngine;

})(window, document);
