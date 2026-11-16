/* HCC Automation System — Enhanced Manual Marks Entry & Student Profile Script
 * - Mirrors CBSE (STD 1-8) and Maharashtra State Board (STD 9-10) grading scales.
 * - Real-time calculation of subject subtotals, grand totals, %, grade, division, and status.
 * - Visual over-max validation (.rm-over).
 * - Live Date of Birth to words converter.
 * - Manage subjects dynamic row additions.
 */
(function () {
    'use strict';

    function num(v) {
        var n = parseFloat(v);
        return isFinite(n) ? n : 0;
    }

    function fmt(n) {
        var r = Math.round(n * 100) / 100;
        return String(r);
    }

    function getStd() {
        var el = document.getElementById('stdInput') || document.querySelector('[name="std"]');
        if (!el) return 1;
        var val = el.value ? parseInt(el.value, 10) : 1;
        return isNaN(val) ? 1 : val;
    }

    // Mirror official grading scale from results.php & functions.php
    function gradeOf(p, std) {
        if (std >= 9) {
            if (p >= 75.0) return 'Distinction';
            if (p >= 60.0) return 'Grade I';
            if (p >= 45.0) return 'Grade II';
            if (p >= 35.0) return 'Pass';
            return 'Fail';
        }
        if (p >= 90.5) return 'A1';
        if (p >= 80.5) return 'A2';
        if (p >= 70.5) return 'B1';
        if (p >= 60.5) return 'B2';
        if (p >= 50.5) return 'C1';
        if (p >= 40.5) return 'C2';
        if (p >= 33.0) return 'D';
        if (p >= 21.0) return 'E1';
        return 'E2';
    }

    function divisionOf(p, std) {
        if (std >= 9) {
            if (p >= 75) return 'Distinction';
            if (p >= 60) return 'First Class';
            if (p >= 45) return 'Second Class';
            if (p >= 35) return 'Pass Class';
            return 'Fail';
        }
        if (p >= 90) return 'Distinction';
        if (p >= 75) return 'First Class';
        if (p >= 60) return 'Second Class';
        if (p >= 45) return 'Third Class';
        if (p >= 33) return 'Pass Class';
        return 'Needs Improvement';
    }

    function gradeBadgeClass(grade) {
        var clean = grade.replace(/[^a-zA-Z0-9_-]/g, '');
        var first = grade.charAt(0);
        var cls = 'grade-badge grade-' + clean;
        if (['A', 'B', 'C', 'D'].indexOf(first) !== -1) {
            cls += ' grade-' + first;
        } else if (grade === 'Distinction' || grade === 'Grade I' || grade === 'Pass') {
            cls += ' grade-A';
        } else {
            cls += ' grade-F';
        }
        return cls;
    }

    function setText(id, txt) {
        var el = document.getElementById(id);
        if (el) el.textContent = txt;
    }

    // Convert date string YYYY-MM-DD or DD/MM/YYYY to words
    function convertDobToWords(dateStr) {
        if (!dateStr) return '';
        var parts = [];
        if (dateStr.indexOf('-') !== -1) {
            parts = dateStr.split('-');
            if (parts.length === 3) {
                return formatDobComponents(parseInt(parts[2], 10), parseInt(parts[1], 10), parseInt(parts[0], 10));
            }
        } else if (dateStr.indexOf('/') !== -1) {
            parts = dateStr.split('/');
            if (parts.length === 3) {
                return formatDobComponents(parseInt(parts[0], 10), parseInt(parts[1], 10), parseInt(parts[2], 10));
            }
        }
        return dateStr;
    }

    function formatDobComponents(d, m, y) {
        if (isNaN(d) || isNaN(m) || isNaN(y) || d < 1 || d > 31 || m < 1 || m > 12) return '';
        var days = ['','First','Second','Third','Fourth','Fifth','Sixth','Seventh','Eighth','Ninth','Tenth',
            'Eleventh','Twelfth','Thirteenth','Fourteenth','Fifteenth','Sixteenth','Seventeenth','Eighteenth','Nineteenth','Twentieth',
            'Twenty First','Twenty Second','Twenty Third','Twenty Fourth','Twenty Fifth','Twenty Sixth','Twenty Seventh','Twenty Eighth','Twenty Ninth','Thirtieth','Thirty First'];
        var months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

        function numToWords(n) {
            var ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
            var tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
            var th = Math.floor(n / 1000);
            var h = Math.floor((n % 1000) / 100);
            var rem = n % 100;
            var res = '';
            if (th > 0) res += ones[th] + ' Thousand';
            if (h > 0) res += (res ? ' ' : '') + ones[h] + ' Hundred';
            if (rem > 0) {
                if (rem < 20) {
                    res += (res ? ' ' : '') + ones[rem];
                } else {
                    var t = Math.floor(rem / 10);
                    var o = rem % 10;
                    res += (res ? ' ' : '') + tens[t] + (o > 0 ? ' ' + ones[o] : '');
                }
            }
            return res.trim();
        }

        return (days[d] || d) + ' ' + (months[m] || m) + ' ' + numToWords(y);
    }

    function recalcLive() {
        var std = getStd();
        var sums = { fa_max: 0, fa_obtained: 0, sa_max: 0, sa_obtained: 0 };
        var maxCol = 0;

        // Process all mark inputs
        var inputs = document.querySelectorAll('.mk-input');
        inputs.forEach(function (inp) {
            var mark = inp.getAttribute('data-mark');
            var raw = inp.value.trim();
            var v = raw === '' ? 0 : num(raw);
            var cap = num(inp.getAttribute('data-cap') || inp.getAttribute('max') || 0);

            // Over-max validation
            if (cap > 0 && v > cap) {
                inp.classList.add('rm-over');
                inp.title = 'Entered mark (' + v + ') exceeds max limit (' + cap + ')';
            } else {
                inp.classList.remove('rm-over');
                inp.title = '';
            }

            if (sums[mark] !== undefined) {
                sums[mark] += v;
            }

            var c = parseInt(inp.getAttribute('data-col'), 10);
            if (isFinite(c) && c + 1 > maxCol) maxCol = c + 1;
        });

        // Row/Category totals
        setText('tot-fa_max', fmt(sums.fa_max));
        setText('tot-fa_obtained', fmt(sums.fa_obtained));
        setText('tot-sa_max', fmt(sums.sa_max));
        setText('tot-sa_obtained', fmt(sums.sa_obtained));

        // Per-subject totals in each column
        for (var j = 0; j < maxCol; j++) {
            var famEl = document.querySelector('.mk-input[data-mark="fa_max"][data-col="' + j + '"]');
            var faoEl = document.querySelector('.mk-input[data-mark="fa_obtained"][data-col="' + j + '"]');
            var samEl = document.querySelector('.mk-input[data-mark="sa_max"][data-col="' + j + '"]');
            var saoEl = document.querySelector('.mk-input[data-mark="sa_obtained"][data-col="' + j + '"]');

            var fam = famEl ? num(famEl.value) : 0;
            var fao = faoEl ? num(faoEl.value) : 0;
            var sam = samEl ? num(samEl.value) : 0;
            var sao = saoEl ? num(saoEl.value) : 0;

            var mx = fam + sam;
            var ob = fao + sao;
            var pctSub = mx > 0 ? ((ob / mx) * 100).toFixed(1) : '0';

            var cell = document.querySelector('.subj-total-cell[data-col="' + j + '"]');
            if (cell) {
                cell.textContent = mx > 0 ? (fmt(ob) + ' / ' + fmt(mx) + ' (' + pctSub + '%)') : '—';
            }
        }

        // Grand totals
        var grand = sums.fa_max + sums.sa_max;
        var obt = sums.fa_obtained + sums.sa_obtained;
        var pct = grand > 0 ? Math.round((obt / grand) * 10000) / 100 : 0;
        var grade = gradeOf(pct, std);
        var division = divisionOf(pct, std);
        var passThreshold = std >= 9 ? 35 : 33;
        var passed = pct >= passThreshold;

        setText('tot-grand', grand > 0 ? (fmt(obt) + ' / ' + fmt(grand)) : '—');
        setText('sumGrand', fmt(obt) + ' / ' + fmt(grand));
        setText('sumPct', pct.toFixed(2) + '%');
        setText('sumGrade', grade);
        setText('sumDivision', division);

        var gradeEl = document.getElementById('sumGrade');
        if (gradeEl) {
            gradeEl.className = 'summary-grade-val ' + gradeBadgeClass(grade);
        }

        var passEl = document.getElementById('sumPass');
        if (passEl) {
            passEl.textContent = passed ? 'PASS' : 'FAIL';
            passEl.className = 'result-badge ' + (passed ? 'badge-pass' : 'badge-fail');
        }

        var live = document.getElementById('liveTotal');
        if (live) {
            live.textContent = 'Grand Total: ' + fmt(obt) + ' / ' + fmt(grand)
                + '  •  ' + pct.toFixed(2) + '%'
                + '  •  Grade: ' + grade
                + '  •  ' + division
                + '  •  ' + (passed ? 'PASS' : 'FAIL');
        }
    }

    function boot() {
        var data = { rows: [], subjects: [], classKey: '', roll: '' };
        var el = document.getElementById('entryData');
        if (el) {
            try {
                data = JSON.parse(el.textContent || '{}') || {};
            } catch (e) {
                data = { rows: [], subjects: [], classKey: '', roll: '' };
            }
        }

        // Live recalc on matrix input change
        document.addEventListener('input', function (ev) {
            var t = ev.target;
            if (t && t.classList && t.classList.contains('mk-input')) {
                recalcLive();
            }
            if (t && t.id === 'stdInput') {
                recalcLive();
            }
            if (t && t.id === 'dobInput') {
                var w = convertDobToWords(t.value);
                setText('dobWordsDisplay', w ? '(' + w + ')' : '');
            }
        }, true);

        // Date of birth initial trigger
        var dobInp = document.getElementById('dobInput');
        if (dobInp && dobInp.value) {
            var w = convertDobToWords(dobInp.value);
            setText('dobWordsDisplay', w ? '(' + w + ')' : '');
        }

        // Preset remark clicks
        document.querySelectorAll('.remark-preset-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var remInp = document.getElementById('remarkInput');
                if (remInp) {
                    remInp.value = this.dataset.text || this.textContent.trim();
                    remInp.focus();
                }
            });
        });

        // Manage subjects add row
        var addBtn = document.getElementById('msAddRow');
        if (addBtn) {
            addBtn.addEventListener('click', function () { addManageRow(); });
        }
        var mbox = document.getElementById('manageSubjectRows');
        if (mbox) {
            mbox.addEventListener('click', function (ev) {
                var btn = ev.target && ev.target.closest ? ev.target.closest('.ms-remove') : null;
                if (btn) removeManageRow(btn);
            });
        }

        // Delete confirm modal
        var delForm = document.getElementById('deleteEntryForm');
        var delModal = document.getElementById('deleteModal');
        window.confirmDel = function () {
            if (!delModal) return;
            var rollEl = document.getElementById('deleteModalRoll');
            if (rollEl) rollEl.textContent = data.roll || '';
            delModal.hidden = false;
            document.body.style.overflow = 'hidden';
            var cancel = document.getElementById('deleteCancelBtn');
            if (cancel) cancel.focus();
        };
        function closeDelModal() {
            if (!delModal) return;
            delModal.hidden = true;
            document.body.style.overflow = '';
        }
        if (delModal) {
            var delCancel = document.getElementById('deleteCancelBtn');
            if (delCancel) delCancel.addEventListener('click', closeDelModal);
            var delBackdrop = delModal.querySelector('[data-close]');
            if (delBackdrop) delBackdrop.addEventListener('click', closeDelModal);
            var delConfirm = document.getElementById('deleteConfirmBtn');
            if (delConfirm) delConfirm.addEventListener('click', function () {
                var ck = document.getElementById('delCk');
                var rl = document.getElementById('delRoll');
                if (ck) ck.value = data.classKey || '';
                if (rl) rl.value = data.roll || '';
                if (delForm) delForm.submit();
            });
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && !delModal.hidden) closeDelModal();
            });
        }

        recalcLive();
        initDashboardModal();
    }

    function addManageRow(name, faMax, saMax) {
        var box = document.getElementById('manageSubjectRows');
        if (!box) return;
        var row = document.createElement('div');
        row.className = 'ms-row';

        var nm = document.createElement('input');
        nm.type = 'text';
        nm.name = 'subject_name[]';
        nm.placeholder = 'Subject name';
        nm.required = true;
        nm.value = (name === undefined || name === null) ? '' : String(name);

        var fm = document.createElement('input');
        fm.type = 'number';
        fm.name = 'fa_max[]';
        fm.step = '0.5';
        fm.min = '0';
        fm.value = (faMax === undefined || faMax === null || faMax === '') ? 70 : faMax;

        var sm = document.createElement('input');
        sm.type = 'number';
        sm.name = 'sa_max[]';
        sm.step = '0.5';
        sm.min = '0';
        sm.value = (saMax === undefined || saMax === null || saMax === '') ? 30 : saMax;

        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'btn-rem-subj ms-remove';
        rm.title = 'Remove subject';
        var ic = document.createElement('i');
        ic.className = 'fas fa-times';
        rm.appendChild(ic);

        row.appendChild(nm);
        row.appendChild(fm);
        row.appendChild(sm);
        row.appendChild(rm);
        box.appendChild(row);
        nm.focus();
    }

    function removeManageRow(btn) {
        var row = btn && btn.closest ? btn.closest('.ms-row') : null;
        if (row) row.remove();
    }

    function escHtml(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function initDashboardModal() {
        var modal = document.getElementById('dashboardModal');
        if (!modal) return;

        function closeDashboardModal() {
            modal.style.display = 'none';
        }
        window.closeDashboardModal = closeDashboardModal;

        var backdrop = modal.querySelector('.modal-backdrop');
        if (backdrop) backdrop.addEventListener('click', closeDashboardModal);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                closeDashboardModal();
            }
        });

        // Tab switching (Overview / Subjects)
        modal.querySelectorAll('.dashboard-tabs [data-tab]').forEach(function (tabBtn) {
            tabBtn.addEventListener('click', function () {
                modal.querySelectorAll('.dashboard-tabs [data-tab]').forEach(function (b) { b.classList.remove('active'); });
                tabBtn.classList.add('active');
                var showSubjects = tabBtn.getAttribute('data-tab') === 'subjects';
                var over = document.getElementById('dashTabOverview');
                var subs = document.getElementById('dashTabSubjects');
                if (over) over.style.display = showSubjects ? 'none' : 'block';
                if (subs) subs.style.display = showSubjects ? 'block' : 'none';
            });
        });

        document.querySelectorAll('.dashboard-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var classKey = this.dataset.class;
                var roll = this.dataset.roll;
                var name = this.dataset.name;
                if (!classKey || !roll) return;

                modal.style.display = 'flex';
                var nameEl = modal.querySelector('.student-name');
                if (nameEl) nameEl.textContent = name;
                var loadEl = document.getElementById('dashLoading');
                var contEl = document.getElementById('dashContent');
                if (loadEl) {
                    loadEl.style.display = 'block';
                    loadEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading student analytics...';
                }
                if (contEl) contEl.style.display = 'none';

                modal.querySelectorAll('.dashboard-tabs [data-tab]').forEach(function (b) {
                    b.classList.toggle('active', b.getAttribute('data-tab') === 'overview');
                });
                var over = document.getElementById('dashTabOverview');
                var subsTab = document.getElementById('dashTabSubjects');
                if (over) over.style.display = 'block';
                if (subsTab) subsTab.style.display = 'none';

                fetch('api_student_dashboard.php?class=' + encodeURIComponent(classKey) + '&roll=' + encodeURIComponent(roll))
                    .then(function (res) {
                        if (!res.ok) throw new Error('Server returned ' + res.status);
                        return res.json();
                    })
                    .then(function (data) {
                        if (loadEl) loadEl.style.display = 'none';
                        if (contEl) contEl.style.display = 'block';
                        if (!data || data.error) {
                            if (loadEl) {
                                loadEl.style.display = 'block';
                                loadEl.innerHTML = '<span style="color:#dc2626;">' + escHtml((data && data.error) || 'No analytics data available.') + '</span>';
                            }
                            if (contEl) contEl.style.display = 'none';
                            return;
                        }

                        var s = data.summary || {};
                        var pctNum = isFinite(Number(s.percentage)) ? Number(s.percentage) : 0;
                        var kpiGrid = document.getElementById('kpiGrid');
                        if (kpiGrid) {
                            kpiGrid.innerHTML =
                                '<div class="kpi-card"><div class="kpi-value">' + pctNum.toFixed(1) + '%</div><div class="kpi-label">Overall Percentage</div></div>' +
                                '<div class="kpi-card"><div class="kpi-value" style="color:var(--brand-main);">' + escHtml(s.grade || '—') + '</div><div class="kpi-label">Grade</div></div>' +
                                '<div class="kpi-card"><div class="kpi-value">' + escHtml(s.division || '—') + '</div><div class="kpi-label">Division</div></div>' +
                                '<div class="kpi-card"><div class="kpi-value" style="color:' + (s.passed ? '#16a34a' : '#dc2626') + '">' + (s.passed ? 'PASS' : 'FAIL') + '</div><div class="kpi-label">Status</div></div>';
                        }

                        var subs = Array.isArray(data.subjects) ? data.subjects : [];
                        var labels = subs.map(function (x) { return x.subject || 'Subject'; });
                        var pcts = subs.map(function (x) { return isFinite(Number(x.total_pct)) ? Number(x.total_pct) : 0; });

                        if (window.entryChart) { window.entryChart.destroy(); window.entryChart = null; }
                        var chartEmpty = document.getElementById('dashChartEmpty');
                        var canvasEl = document.getElementById('subjectChartCanvas');
                        var canvasWrap = canvasEl ? canvasEl.parentElement : null;

                        if (!subs.length) {
                            if (canvasWrap) canvasWrap.style.display = 'none';
                            if (chartEmpty) chartEmpty.style.display = 'block';
                        } else {
                            if (canvasWrap) canvasWrap.style.display = 'block';
                            if (chartEmpty) chartEmpty.style.display = 'none';
                            if (typeof Chart === 'undefined') {
                                if (chartEmpty) {
                                    chartEmpty.style.display = 'block';
                                    chartEmpty.textContent = 'Chart library failed to load. Please refresh the page.';
                                }
                            } else if (canvasEl) {
                                var ctx = canvasEl.getContext('2d');
                                window.entryChart = new Chart(ctx, {
                                    type: 'bar',
                                    data: {
                                        labels: labels,
                                        datasets: [{ label: 'Subject %', data: pcts, backgroundColor: '#7a2238', borderRadius: 6 }]
                                    },
                                    options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 0, max: 100 } } }
                                });
                            }
                        }

                        // Subjects tab table
                        var body = document.getElementById('dashSubjectsBody');
                        if (body) {
                            if (!subs.length) {
                                body.innerHTML = '<tr><td colspan="6" style="padding:18px;text-align:center;color:var(--text-3);">No subject marks recorded yet.</td></tr>';
                            } else {
                                body.innerHTML = subs.map(function (x) {
                                    return '<tr>' +
                                        '<td style="padding:9px 12px;border-bottom:1px solid var(--border);font-weight:600;">' + escHtml(x.subject || '—') + '</td>' +
                                        '<td style="padding:9px 12px;border-bottom:1px solid var(--border);text-align:center;">' + escHtml(x.fa_obtained) + ' / ' + escHtml(x.fa_max) + '</td>' +
                                        '<td style="padding:9px 12px;border-bottom:1px solid var(--border);text-align:center;">' + escHtml(x.sa_obtained) + ' / ' + escHtml(x.sa_max) + '</td>' +
                                        '<td style="padding:9px 12px;border-bottom:1px solid var(--border);text-align:center;font-weight:700;">' + escHtml(x.total_obtained) + ' / ' + escHtml(x.total_max) + '</td>' +
                                        '<td style="padding:9px 12px;border-bottom:1px solid var(--border);text-align:center;font-weight:700;">' + escHtml(x.total_pct) + '%</td>' +
                                        '<td style="padding:9px 12px;border-bottom:1px solid var(--border);text-align:center;font-weight:700;">' + escHtml(x.grade || '—') + '</td>' +
                                        '</tr>';
                                }).join('');
                            }
                        }
                    })
                    .catch(function (err) {
                        if (loadEl) loadEl.innerHTML = '<span style="color:#dc2626;">Failed to load analytics: ' + escHtml(err.message) + '</span>';
                    });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
