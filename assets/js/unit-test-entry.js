/**
 * HCC Automation System — Unit Test Dedicated Entry & Profile Script
 * Handles real-time recalculation of Unit Test totals, %, official grades (A1-E),
 * over-max boundary checks, absent toggles, preset pills, and subject config.
 */
(function () {
    'use strict';

    function num(v) {
        var n = parseFloat(v);
        return isFinite(n) ? n : 0;
    }

    function calculateGrade(pct) {
        if (pct === null || isNaN(pct)) {
            return { grade: '-', remark: 'Absent', cssClass: 'grade-dash' };
        }
        if (pct >= 91.0) {
            return { grade: 'A1', remark: 'Excellent', cssClass: 'grade-a1' };
        } else if (pct >= 81.0) {
            return { grade: 'A2', remark: 'V.Good', cssClass: 'grade-a2' };
        } else if (pct >= 71.0) {
            return { grade: 'B1', remark: 'Good', cssClass: 'grade-b1' };
        } else if (pct >= 61.0) {
            return { grade: 'B2', remark: 'Satisfactory', cssClass: 'grade-b2' };
        } else if (pct >= 51.0) {
            return { grade: 'C1', remark: 'Fair', cssClass: 'grade-c1' };
        } else if (pct >= 41.0) {
            return { grade: 'C2', remark: 'Average', cssClass: 'grade-c2' };
        } else if (pct >= 35.0) {
            return { grade: 'D', remark: 'Needs Improvement', cssClass: 'grade-d' };
        } else {
            return { grade: 'E', remark: 'Needs Major Improvement', cssClass: 'grade-e' };
        }
    }

    function recalcLive() {
        var rows = document.querySelectorAll('.ut-subj-row');
        var totObt = 0;
        var totMax = 0;
        var allAbsent = true;
        var hasOverMax = false;

        rows.forEach(function (row) {
            var maxInp = row.querySelector('.ut-max-inp');
            var obtInp = row.querySelector('.ut-obt-inp');
            var absChk = row.querySelector('.ut-abs-chk');
            var valBadge = row.querySelector('.ut-row-status');

            var maxM = maxInp ? num(maxInp.value) : 20;
            if (maxM <= 0) maxM = 20;
            totMax += maxM;

            var isAbsent = absChk && absChk.checked;

            if (isAbsent) {
                if (obtInp) {
                    obtInp.disabled = true;
                    obtInp.value = '-';
                    obtInp.classList.remove('rm-over');
                }
                if (valBadge) {
                    valBadge.innerHTML = '<span class="badge-absent-text">Absent</span>';
                }
            } else {
                if (obtInp) {
                    obtInp.disabled = false;
                    var rawVal = obtInp.value.trim();
                    if (rawVal === '-' || rawVal.toUpperCase() === 'AB') {
                        rawVal = '0';
                        obtInp.value = '0';
                    }
                    var obtM = rawVal === '' ? 0 : num(rawVal);

                    // Over-max validation
                    if (obtM > maxM) {
                        obtInp.classList.add('rm-over');
                        obtInp.title = 'Exceeds maximum marks of ' + maxM;
                        hasOverMax = true;
                        if (valBadge) {
                            valBadge.innerHTML = '<span class="badge-over-text"><i class="fas fa-exclamation-triangle"></i> > ' + maxM + '</span>';
                        }
                    } else {
                        obtInp.classList.remove('rm-over');
                        obtInp.title = '';
                        if (valBadge) {
                            valBadge.innerHTML = '<i class="fas fa-check-circle" style="color:#10b981;font-size:0.9rem;"></i>';
                        }
                    }

                    allAbsent = false;
                    totObt += (obtM > maxM) ? maxM : obtM;
                }
            }
        });

        // Computed values
        var pct = null;
        if (!allAbsent && totMax > 0) {
            pct = Math.round((totObt / totMax) * 10000) / 100;
        }

        var grInfo = calculateGrade(pct);

        // Update Summary Chips
        var sumObtEl = document.getElementById('sumTotObt');
        var sumMaxEl = document.getElementById('sumTotMax');
        var sumPctEl = document.getElementById('sumPct');
        var sumGrdEl = document.getElementById('sumGrade');
        var sumResEl = document.getElementById('sumResult');

        if (sumObtEl) sumObtEl.textContent = allAbsent ? '-' : (Math.round(totObt * 10) / 10);
        if (sumMaxEl) sumMaxEl.textContent = totMax;
        if (sumPctEl) sumPctEl.textContent = (pct !== null) ? pct.toFixed(1) + '%' : '-';

        if (sumGrdEl) {
            sumGrdEl.textContent = grInfo.grade;
            sumGrdEl.className = 'ut-summary-grade ' + grInfo.cssClass;
        }

        if (sumResEl) {
            if (allAbsent) {
                sumResEl.textContent = 'ABSENT';
                sumResEl.className = 'result-badge badge-absent';
            } else if (pct !== null && pct >= 35.0) {
                sumResEl.textContent = 'PASS';
                sumResEl.className = 'result-badge badge-pass';
            } else {
                sumResEl.textContent = 'FAIL';
                sumResEl.className = 'result-badge badge-fail';
            }
        }

        // Form submit guard warning
        var saveBtns = document.querySelectorAll('.js-btn-save');
        saveBtns.forEach(function (btn) {
            if (hasOverMax) {
                btn.setAttribute('data-has-error', '1');
            } else {
                btn.removeAttribute('data-has-error');
            }
        });
    }

    // Remark preset pills
    function initPresetPills() {
        var pills = document.querySelectorAll('.ut-preset-pill');
        var rmkInput = document.getElementById('teacherRemarkInput');
        if (!rmkInput) return;

        pills.forEach(function (p) {
            p.addEventListener('click', function () {
                var text = p.getAttribute('data-remark') || p.textContent.trim();
                rmkInput.value = text;
                rmkInput.focus();

                // Subtle animation
                rmkInput.style.transition = 'background-color 0.3s ease';
                rmkInput.style.backgroundColor = '#ecfdf5';
                setTimeout(function () {
                    rmkInput.style.backgroundColor = '';
                }, 400);
            });
        });
    }

    // Absent checkboxes
    function initAbsentHandlers() {
        var chks = document.querySelectorAll('.ut-abs-chk');
        chks.forEach(function (chk) {
            chk.addEventListener('change', function () {
                recalcLive();
            });
        });
    }

    // Mark inputs input event
    function initMarkInputHandlers() {
        var inputs = document.querySelectorAll('.ut-obt-inp, .ut-max-inp');
        inputs.forEach(function (inp) {
            inp.addEventListener('input', recalcLive);
            inp.addEventListener('change', recalcLive);

            // Keyboard navigation: Enter / Down Arrow -> Next input; Up Arrow -> Prev input
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === 'ArrowDown') {
                    if (inp.classList.contains('ut-obt-inp')) {
                        e.preventDefault();
                        var allObt = Array.from(document.querySelectorAll('.ut-obt-inp:not([disabled])'));
                        var idx = allObt.indexOf(inp);
                        if (idx !== -1 && idx < allObt.length - 1) {
                            allObt[idx + 1].focus();
                            allObt[idx + 1].select();
                        }
                    }
                } else if (e.key === 'ArrowUp') {
                    if (inp.classList.contains('ut-obt-inp')) {
                        e.preventDefault();
                        var allObtPrev = Array.from(document.querySelectorAll('.ut-obt-inp:not([disabled])'));
                        var idxPrev = allObtPrev.indexOf(inp);
                        if (idxPrev > 0) {
                            allObtPrev[idxPrev - 1].focus();
                            allObtPrev[idxPrev - 1].select();
                        }
                    }
                }
            });
        });
    }

    // Form submission validation
    function initFormValidation() {
        var form = document.getElementById('unitTestEntryForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            var overInps = form.querySelectorAll('.rm-over');
            if (overInps.length > 0) {
                e.preventDefault();
                alert('Warning: One or more entered marks exceed maximum marks! Please correct the highlighted entries before saving.');
                if (overInps[0]) {
                    overInps[0].focus();
                    overInps[0].select();
                }
                return false;
            }

            var nameInp = document.getElementById('studentNameInput');
            if (nameInp && nameInp.value.trim() === '') {
                e.preventDefault();
                alert('Student Name is required.');
                nameInp.focus();
                return false;
            }

            var rollInp = document.getElementById('studentRollInput');
            if (rollInp && rollInp.value.trim() === '') {
                e.preventDefault();
                alert('Roll Number is required.');
                rollInp.focus();
                return false;
            }

            return true;
        });
    }

    // Dynamic Subject rows in modal
    function initManageSubjects() {
        var addBtn = document.getElementById('btnAddUnitTestSubject');
        var list = document.getElementById('unitTestSubjectRows');
        if (!addBtn || !list) return;

        addBtn.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'ms-row';
            row.innerHTML = 
                '<input type="text" name="cfg_subject_name[]" placeholder="Subject Name (e.g. Science)" required>' +
                '<input type="number" name="cfg_max_marks[]" value="20" min="1" max="100" step="1" required>' +
                '<button type="button" class="btn-rem-subj" title="Remove Subject" onclick="this.closest(\'.ms-row\').remove()"><i class="fas fa-trash"></i></button>';
            list.appendChild(row);
            var firstInp = row.querySelector('input');
            if (firstInp) firstInp.focus();
        });
    }

    // Keyboard navigation between students: Alt + Left / Alt + Right
    function initStudentNavHotkeys() {
        document.addEventListener('keydown', function (e) {
            if (e.altKey && (e.key === 'ArrowLeft' || e.key === 'Left')) {
                var prevBtn = document.getElementById('btnPrevStudent');
                if (prevBtn && prevBtn.href && !prevBtn.classList.contains('is-disabled')) {
                    e.preventDefault();
                    location.href = prevBtn.href;
                }
            } else if (e.altKey && (e.key === 'ArrowRight' || e.key === 'Right')) {
                var nextBtn = document.getElementById('btnNextStudent');
                if (nextBtn && nextBtn.href && !nextBtn.classList.contains('is-disabled')) {
                    e.preventDefault();
                    location.href = nextBtn.href;
                }
            }
        });
    }

    // Document Ready
    document.addEventListener('DOMContentLoaded', function () {
        initPresetPills();
        initAbsentHandlers();
        initMarkInputHandlers();
        initFormValidation();
        initManageSubjects();
        initStudentNavHotkeys();
        recalcLive();
    });

})();
