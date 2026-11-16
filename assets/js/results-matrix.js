/* Bulk Results inline subject-marks matrix.
 * - Rendered server-side in admin/results.php (one column per subject, one row per student).
 * - This script only recalculates Total / % / Grade / Status live per row.
 * - Mirrors PHP grading scales:
 *     - Standard 1-8: CBSE 9-point scale (A1, A2, B1, B2, C1, C2, D, E1, E2; pass >= 33%)
 *     - Standard 9-10: Maharashtra Board scale (Distinction >=75%, Grade I >=60%, Grade II >=45%, Pass >=35%, Fail <35%)
 * - Server clamps obtained to max on save (see clampMark()), so live totals clamp too.
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
        var form = document.getElementById('bulkMarksForm');
        if (!form) return 0;
        var s = form.getAttribute('data-std');
        return s ? parseInt(s, 10) : 0;
    }

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

    function gradeClass(grade) {
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

    function setCell(row, role, txt) {
        var el = row.querySelector('[data-rm="' + role + '"]');
        if (el) el.textContent = txt;
    }

    function recalcRow(row, std) {
        var obt = 0, max = 0;
        var inputs = row.querySelectorAll('.rm-input');
        var subjTotals = {};
        var subjHasValue = {};

        inputs.forEach(function (inp) {
            var kind = inp.getAttribute('data-kind');
            var subj = inp.getAttribute('data-subj') || '';
            var cap = kind === 'sa' ? num(inp.getAttribute('data-samax')) : num(inp.getAttribute('data-famax'));
            var raw = inp.value.trim();
            var v = raw === '' ? 0 : num(raw);
            if (v < 0) v = 0;
            if (cap > 0 && v > cap) {
                inp.classList.add('rm-over');
                v = cap; // mirror server-side clampMark()
            } else {
                inp.classList.remove('rm-over');
            }
            if (cap > 0) max += cap;
            obt += v;

            if (subj) {
                if (raw !== '') subjHasValue[subj] = true;
                if (!subjTotals[subj]) subjTotals[subj] = 0;
                subjTotals[subj] += v;
            }
        });

        // Update each subject subtotal column in this row live
        for (var s in subjTotals) {
            var subEl = row.querySelector('[data-rm-subtot="' + s + '"]');
            if (subEl) {
                subEl.textContent = subjHasValue[s] ? fmt(subjTotals[s]) : '-';
            }
        }

        var pct = max > 0 ? Math.round((obt / max) * 10000) / 100 : 0;
        var grade = gradeOf(pct, std);
        var passThreshold = std >= 9 ? 35 : 33;
        var passed = pct >= passThreshold;

        setCell(row, 'total', fmt(obt) + ' / ' + fmt(max));
        setCell(row, 'pct', pct.toFixed(2) + '%');
        var gEl = row.querySelector('[data-rm="grade"]');
        if (gEl) {
            gEl.textContent = grade;
            gEl.className = gradeClass(grade);
        }
        var sEl = row.querySelector('[data-rm="status"]');
        if (sEl) {
            sEl.textContent = passed ? 'PASS' : 'FAIL';
            sEl.className = passed ? 'pass-tag' : 'fail-tag';
        }
    }

    function markDirty(row) {
        if (row) row.classList.add('rm-dirty');
        var btn = document.getElementById('bulkSaveBtn');
        if (btn) btn.disabled = false;
    }

    var cachedStd = 0;

    function cleanRemarkText(txt, pct) {
        if (!txt) return '';
        var t = txt.trim();
        if (t.charAt(0) !== '=' && !/^#(VALUE|REF|N\/A|NAME\?|DIV\/0|NUM|NULL)!/i.test(t)) return t;
        var p = parseFloat(pct) || 0;
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

    function onInput(ev) {
        var t = ev.target;
        if (!t || !t.classList) return;
        var row = t.closest ? t.closest('tr[data-roll]') : null;
        if (!row) return;
        // Remark edits: clean any accidental formula/error and dirty row
        if (t.classList.contains('rm-remark')) {
            var rawVal = t.value.trim();
            if (rawVal.charAt(0) === '=' || /^#(VALUE|REF|N\/A|NAME\?|DIV\/0|NUM|NULL)!/i.test(rawVal)) {
                var pctEl = row ? row.querySelector('[data-rm="pct"]') : null;
                var pctVal = pctEl ? parseFloat(pctEl.textContent) : 0;
                t.value = cleanRemarkText(rawVal, pctVal);
            }
            markDirty(row);
            return;
        }
        if (!t.classList.contains('rm-input')) return;
        recalcRow(row, cachedStd);
        markDirty(row);
    }

    function boot() {
        var form = document.getElementById('bulkMarksForm');
        if (!form) return; // read-only mode (All Classes) — nothing to wire
        cachedStd = getStd();
        document.addEventListener('input', onInput, true);
        document.addEventListener('change', onInput, true);

        // Sanitize any formula strings in remark fields on page load
        form.querySelectorAll('.rm-remark').forEach(function (inp) {
            var v = inp.value.trim();
            if (v.charAt(0) === '=' || /^#(VALUE|REF|N\/A|NAME\?|DIV\/0|NUM|NULL)!/i.test(v)) {
                var r = inp.closest('tr[data-roll]');
                var pe = r ? r.querySelector('[data-rm="pct"]') : null;
                var pv = pe ? parseFloat(pe.textContent) : 0;
                inp.value = cleanRemarkText(v, pv);
            }
        });

        // Normalize server-rendered totals through the same code path.
        form.querySelectorAll('tr[data-roll]').forEach(function (r) {
            recalcRow(r, cachedStd);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
