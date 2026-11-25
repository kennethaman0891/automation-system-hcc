/**
 * HCC Unit Test Results Matrix — Client-Side Real-Time Calculation Engine
 * Replicates the school's official Unit Test formulas and grading scales.
 */
(function () {
    'use strict';

    // Official Maharashtra School Grading & Remark Scale
    function computeGradeAndRemark(pct) {
        if (pct === null || isNaN(pct)) {
            return { grade: '-', remark: '' };
        }
        if (pct >= 91.0) return { grade: 'A1', remark: 'Excellent' };
        if (pct >= 81.0) return { grade: 'A2', remark: 'V.Good' };
        if (pct >= 71.0) return { grade: 'B1', remark: 'Good' };
        if (pct >= 61.0) return { grade: 'B2', remark: 'Satisfactory' };
        if (pct >= 51.0) return { grade: 'C1', remark: 'Fair' };
        if (pct >= 41.0) return { grade: 'C2', remark: 'Average' };
        if (pct >= 35.0) return { grade: 'D',  remark: 'Needs Improvement' };
        return { grade: 'E', remark: 'Needs Major Improvement' };
    }

    function recalcRow(row) {
        const markInputs = row.querySelectorAll('.ut-mark-input');
        let totalObtained = 0.0;
        let totalMax = 0.0;
        let allAbsent = true;

        markInputs.forEach(input => {
            const maxM = parseFloat(input.dataset.max) || 20.0;
            totalMax += maxM;

            const val = input.value.trim();
            if (val === '-' || val.toUpperCase() === 'AB' || val === '') {
                input.classList.remove('is-invalid');
                if (val === '-' || val.toUpperCase() === 'AB') {
                    input.style.color = '#dc2626';
                    input.style.fontWeight = '700';
                } else {
                    input.style.color = '';
                    input.style.fontWeight = '';
                }
            } else {
                const num = parseFloat(val);
                if (!isNaN(num)) {
                    if (num < 0 || num > maxM) {
                        input.classList.add('is-invalid');
                        input.style.borderColor = '#ef4444';
                        input.style.backgroundColor = '#fef2f2';
                    } else {
                        input.classList.remove('is-invalid');
                        input.style.borderColor = '';
                        input.style.backgroundColor = '';
                        input.style.color = '';
                        input.style.fontWeight = '';
                        totalObtained += num;
                        allAbsent = false;
                    }
                }
            }
        });

        // Total cell
        const totCell = row.querySelector('.ut-cell-total');
        const pctCell = row.querySelector('.ut-cell-pct');
        const gradeCell = row.querySelector('.ut-cell-grade');
        const remarkCell = row.querySelector('.ut-cell-remark');

        if (allAbsent || totalMax <= 0) {
            if (totCell) totCell.textContent = '-';
            if (pctCell) pctCell.textContent = '-';
            if (gradeCell) {
                gradeCell.textContent = '-';
                gradeCell.className = 'ut-cell-grade grade-dash';
            }
            if (remarkCell) remarkCell.value = 'Absent';
        } else {
            const roundedTot = Math.round(totalObtained * 10) / 10;
            const pct = Math.round((totalObtained / totalMax) * 10000) / 100;

            if (totCell) totCell.textContent = roundedTot.toFixed(roundedTot % 1 === 0 ? 0 : 1);
            if (pctCell) pctCell.textContent = pct.toFixed(2) + '%';

            const evalResult = computeGradeAndRemark(pct);
            if (gradeCell) {
                gradeCell.textContent = evalResult.grade;
                gradeCell.className = 'ut-cell-grade grade-' + evalResult.grade.toLowerCase();
            }
            if (remarkCell && (!remarkCell.dataset.userEdited || remarkCell.dataset.userEdited === '0')) {
                remarkCell.value = evalResult.remark;
            }
        }
    }

    function recalcClassKPIs() {
        const rows = document.querySelectorAll('.ut-student-row');
        if (!rows.length) return;

        let totalStudents = rows.length;
        let sumPct = 0;
        let countScored = 0;
        let highestTot = 0;
        let passCount = 0;

        rows.forEach(r => {
            const pctCell = r.querySelector('.ut-cell-pct');
            const totCell = r.querySelector('.ut-cell-total');
            if (pctCell && pctCell.textContent !== '-') {
                const pct = parseFloat(pctCell.textContent);
                if (!isNaN(pct)) {
                    sumPct += pct;
                    countScored++;
                    if (pct >= 35.0) passCount++;
                }
            }
            if (totCell && totCell.textContent !== '-') {
                const tot = parseFloat(totCell.textContent);
                if (!isNaN(tot) && tot > highestTot) highestTot = tot;
            }
        });

        const avgPctEl = document.getElementById('kpiClassAvg');
        const highestEl = document.getElementById('kpiHighest');
        const passPctEl = document.getElementById('kpiPassPct');

        if (avgPctEl) {
            avgPctEl.textContent = countScored > 0 ? (sumPct / countScored).toFixed(1) + '%' : '0.0%';
        }
        if (highestEl) {
            highestEl.textContent = highestTot > 0 ? highestTot.toFixed(highestTot % 1 === 0 ? 0 : 1) : '-';
        }
        if (passPctEl) {
            passPctEl.textContent = countScored > 0 ? ((passCount / countScored) * 100).toFixed(1) + '%' : '0.0%';
        }
    }

    // Debounced Auto-Save (delegates to common HCCAutoSave engine when loaded)
    let saveTimeout = null;
    function triggerAutoSave() {
        if (window.HCCAutoSave && typeof window.HCCAutoSave.triggerForm === 'function') {
            window.HCCAutoSave.triggerForm('unitTestMatrixForm');
            return;
        }
        const statusPill = document.getElementById('autoSaveStatus');
        if (statusPill) {
            statusPill.textContent = 'Saving changes...';
            statusPill.style.color = '#d97706';
        }

        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(() => {
            const form = document.getElementById('unitTestMatrixForm');
            if (!form) return;

            const fd = new FormData(form);
            fd.append('is_ajax', '1');

            fetch(window.location.href, {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if (statusPill) {
                    if (data.ok) {
                        statusPill.textContent = 'All changes saved';
                        statusPill.style.color = '#166534';
                        setTimeout(() => { statusPill.textContent = ''; }, 3000);
                    } else {
                        statusPill.textContent = 'Save failed: ' + (data.error || 'Server error');
                        statusPill.style.color = '#dc2626';
                    }
                }
            })
            .catch(() => {
                if (statusPill) {
                    statusPill.textContent = 'Network error saving marks';
                    statusPill.style.color = '#dc2626';
                }
            });
        }, 1200);
    }

    // Initialize Event Listeners
    document.addEventListener('DOMContentLoaded', () => {
        const matrix = document.getElementById('unitTestTable');
        if (!matrix) return;

        // Recalculate all rows on initial load
        const rows = matrix.querySelectorAll('.ut-student-row');
        rows.forEach(r => recalcRow(r));
        recalcClassKPIs();

        // Listen for user input on marks
        matrix.addEventListener('input', (e) => {
            if (e.target.classList.contains('ut-mark-input')) {
                const row = e.target.closest('.ut-student-row');
                if (row) {
                    recalcRow(row);
                    recalcClassKPIs();
                    triggerAutoSave();
                }
            } else if (e.target.classList.contains('ut-cell-remark') || e.target.classList.contains('ut-attn-input')) {
                if (e.target.classList.contains('ut-cell-remark')) {
                    e.target.dataset.userEdited = '1';
                }
                triggerAutoSave();
            }
        });

        // Arrow navigation between cells
        matrix.addEventListener('keydown', (e) => {
            if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter'].includes(e.key)) {
                const active = document.activeElement;
                if (!active || !active.classList.contains('ut-mark-input')) return;

                const td = active.closest('td');
                const tr = active.closest('tr');
                if (!td || !tr) return;

                const colIdx = Array.from(tr.children).indexOf(td);

                if (e.key === 'ArrowDown' || e.key === 'Enter') {
                    e.preventDefault();
                    let nextTr = tr.nextElementSibling;
                    while (nextTr && !nextTr.classList.contains('ut-student-row')) nextTr = nextTr.nextElementSibling;
                    if (nextTr && nextTr.children[colIdx]) {
                        const targetInput = nextTr.children[colIdx].querySelector('.ut-mark-input');
                        if (targetInput) { targetInput.focus(); targetInput.select(); }
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    let prevTr = tr.previousElementSibling;
                    while (prevTr && !prevTr.classList.contains('ut-student-row')) prevTr = prevTr.previousElementSibling;
                    if (prevTr && prevTr.children[colIdx]) {
                        const targetInput = prevTr.children[colIdx].querySelector('.ut-mark-input');
                        if (targetInput) { targetInput.focus(); targetInput.select(); }
                    }
                }
            }
        });
    });
})();
