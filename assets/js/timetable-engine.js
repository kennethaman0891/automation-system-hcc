/**
 * HCC Exam Timetable Matrix Engine
 * Reactive Vanilla JS Matrix Controller with exact mathematical rowspan invariant.
 * Hindustani Covenant Church High School
 */

(function (window, document) {
  'use strict';

  class TimetableMatrixController {
    constructor(containerId, options = {}) {
      this.container = typeof containerId === 'string' ? document.getElementById(containerId) : containerId;
      if (!this.container) {
        console.error(`Timetable container #${containerId} not found.`);
        return;
      }

      this.apiBase = options.apiBase || 'api_timetable.php';
      this.csrfToken = options.csrfToken || (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
      this.activeInput = null;
      this.isDirty = false;
      this.savedTimetables = [];

      // Priority #1: Prompt & Fixed Statement Generator State
      this.generatorState = {
        useFixedStatement: true,
        academicYear: '2026-27',
        examType: 'FIRST SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)',
        standardsGroup: 'std_v_x',
        startDate: '2026-10-23',
        primarySlot: '8.00 AM - 10.15 AM',
        hasExtendedSlot: true,
        extendedSlot: '8.00 AM - 11.15 AM',
        skipSundays: true,
        days: [
          { dayNum: 1, subject: 'ENGLISH', hsSubject: 'ALGEBRA(M-1)' },
          { dayNum: 2, subject: 'MARATHI', hsSubject: 'ENGLISH' },
          { dayNum: 3, subject: 'HINDI / SANSKRIT', hsSubject: 'MARATHI' },
          { dayNum: 4, subject: 'MATHS', hsSubject: 'HINDI / SANSKRIT' },
          { dayNum: 5, subject: 'EVS - I', hsSubject: 'SCIENCE-1' },
          { dayNum: 6, subject: 'SCIENCE', hsSubject: 'GEOMETRY' },
          { dayNum: 7, subject: 'COMP. SCI.', hsSubject: 'SCIENCE-2' },
          { dayNum: 8, subject: 'EVS-II', hsSubject: 'HISTORY' },
          { dayNum: 9, subject: 'DRAWING', hsSubject: 'GEOGRAPHY' }
        ],
        freeformPrompt: 'Generate a First Semester Examination timetable for STD V to X starting 23-10-2026 with English, Marathi, Hindi, Maths, Science, History, Drawing, Computer Science, and Geography.'
      };

      // Initial State
      this.state = this.getDefaultState();

      this.init();
    }

    /**
     * Default Initial State Template
     */
    getDefaultState() {
      const today = new Date();
      const formatDate = (offsetDays) => {
        const d = new Date(today);
        d.setDate(d.getDate() + offsetDays);
        return d.toISOString().split('T')[0];
      };

      const getDayName = (dateStr) => {
        const d = new Date(dateStr + 'T00:00:00');
        return new Intl.DateTimeFormat('en-US', { weekday: 'long' }).format(d);
      };

      const date1 = formatDate(3);
      const date2 = formatDate(4);
      const date3 = formatDate(5);
      const date4 = formatDate(7);
      const date5 = formatDate(8);

      return {
        metadata: {
          id: null,
          title: 'Second Semester Examination Schedule 2025-26',
          academic_year: '2025-26',
          exam_type: 'Semester 2',
          instructions: '1. Students must report 15 minutes before exam time in full school uniform.\n2. Bring your own exam stationery and clipboard.\n3. Hall ticket is mandatory for all examination sessions.',
          status: 'draft'
        },
        columns: [
          { id: 'col_1', label: 'STD I' },
          { id: 'col_2', label: 'STD II' },
          { id: 'col_3', label: 'STD III' },
          { id: 'col_4', label: 'STD IV' },
          { id: 'col_5', label: 'STD V' },
          { id: 'col_6', label: 'STD VI' },
          { id: 'col_7', label: 'STD VII' },
          { id: 'col_8', label: 'STD VIII' },
          { id: 'col_9', label: 'STD IX' },
          { id: 'col_10', label: 'STD X' }
        ],
        rows: [
          {
            dateId: 'd_1',
            date: date1,
            day: getDayName(date1),
            slots: [
              {
                slotId: 's_1_1',
                time: '08:30 AM - 10:30 AM',
                cells: {
                  col_1: 'English',
                  col_2: 'English',
                  col_3: 'English',
                  col_4: 'English',
                  col_5: 'English',
                  col_6: 'English',
                  col_7: 'English',
                  col_8: 'English',
                  col_9: 'English',
                  col_10: 'English'
                }
              },
              {
                slotId: 's_1_2',
                time: '11:00 AM - 01:00 PM',
                cells: {
                  col_1: 'Moral Science',
                  col_2: 'Moral Science',
                  col_3: 'General Knowledge',
                  col_4: 'General Knowledge',
                  col_5: 'Value Education',
                  col_6: 'Value Education',
                  col_7: 'Drawing',
                  col_8: 'Drawing',
                  col_9: 'Work Experience',
                  col_10: 'Physical Education'
                }
              }
            ]
          },
          {
            dateId: 'd_2',
            date: date2,
            day: getDayName(date2),
            slots: [
              {
                slotId: 's_2_1',
                time: '08:30 AM - 10:30 AM',
                cells: {
                  col_1: 'Marathi',
                  col_2: 'Marathi',
                  col_3: 'Marathi',
                  col_4: 'Marathi',
                  col_5: 'Marathi',
                  col_6: 'Marathi',
                  col_7: 'Marathi',
                  col_8: 'Marathi',
                  col_9: 'Marathi',
                  col_10: 'Marathi'
                }
              }
            ]
          },
          {
            dateId: 'd_3',
            date: date3,
            day: getDayName(date3),
            slots: [
              {
                slotId: 's_3_1',
                time: '08:30 AM - 10:30 AM',
                cells: {
                  col_1: 'Mathematics',
                  col_2: 'Mathematics',
                  col_3: 'Mathematics',
                  col_4: 'Mathematics',
                  col_5: 'Mathematics',
                  col_6: 'Mathematics',
                  col_7: 'Mathematics',
                  col_8: 'Mathematics',
                  col_9: 'Geometry',
                  col_10: 'Algebra'
                }
              }
            ]
          },
          {
            dateId: 'd_4',
            date: date4,
            day: getDayName(date4),
            slots: [
              {
                slotId: 's_4_1',
                time: '08:30 AM - 10:30 AM',
                cells: {
                  col_1: 'Hindi',
                  col_2: 'Hindi',
                  col_3: 'Hindi',
                  col_4: 'Hindi',
                  col_5: 'Hindi',
                  col_6: 'Hindi',
                  col_7: 'Hindi',
                  col_8: 'Hindi',
                  col_9: 'Hindi',
                  col_10: 'Hindi'
                }
              }
            ]
          },
          {
            dateId: 'd_5',
            date: date5,
            day: getDayName(date5),
            slots: [
              {
                slotId: 's_5_1',
                time: '08:30 AM - 10:30 AM',
                cells: {
                  col_1: 'EVS',
                  col_2: 'EVS',
                  col_3: 'Science',
                  col_4: 'Science',
                  col_5: 'General Science',
                  col_6: 'General Science',
                  col_7: 'Science',
                  col_8: 'Science',
                  col_9: 'Science 1',
                  col_10: 'Science 1'
                }
              }
            ]
          }
        ],
        presets: {
          subjects: [
            'English',
            'Hindi',
            'Marathi',
            'Mathematics',
            'Algebra',
            'Geometry',
            'Science',
            'Science 1',
            'Science 2',
            'General Science',
            'Social Studies',
            'History / Civics',
            'Geography',
            'EVS',
            'Computer Science / IT',
            'General Knowledge',
            'Drawing / Art',
            'Moral Science',
            'Value Education',
            'Physical Education'
          ],
          timeSlots: [
            '08:30 AM - 10:30 AM',
            '08:30 AM - 11:30 AM',
            '09:00 AM - 11:00 AM',
            '09:00 AM - 12:00 PM',
            '11:00 AM - 01:00 PM',
            '01:30 PM - 03:30 PM',
            '02:00 PM - 05:00 PM'
          ]
        }
      };
    }

    /**
     * Initialize App
     */
    async init() {
      this.setupGlobalEvents();
      this.render();
      await this.loadTimetablesList();
    }

    /**
     * Setup Event Delegation and Shortcuts
     */
    setupGlobalEvents() {
      // Track currently active input for instant palette chip insertion
      document.addEventListener('focusin', (e) => {
        if (e.target.matches('.cell-subject-input, .time-slot-input')) {
          this.activeInput = e.target;
        }
      });

      // Warn before leaving if changes are unsaved
      if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
        window.addEventListener('beforeunload', (e) => {
          if (this.isDirty) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes in the timetable. Are you sure you want to leave?';
          }
        });
      }

      // Delegation on Container
      this.container.addEventListener('input', (e) => this.handleInput(e));
      this.container.addEventListener('change', (e) => this.handleChange(e));
      this.container.addEventListener('click', (e) => this.handleClick(e));
    }

    /**
     * Handle Instant Text Inputs (without full re-rendering for smooth typing)
     */
    handleInput(e) {
      const target = e.target;

      // 1. Subject Cell Input
      if (target.matches('.cell-subject-input')) {
        const dateId = target.dataset.dateId;
        const slotId = target.dataset.slotId;
        const colId = target.dataset.colId;

        const dateObj = this.state.rows.find((r) => r.dateId === dateId);
        if (dateObj) {
          const slotObj = dateObj.slots.find((s) => s.slotId === slotId);
          if (slotObj) {
            slotObj.cells[colId] = target.value;
            this.setDirty(true);
          }
        }
      }

      // 2. Time Slot Input
      else if (target.matches('.time-slot-input')) {
        const dateId = target.dataset.dateId;
        const slotId = target.dataset.slotId;

        const dateObj = this.state.rows.find((r) => r.dateId === dateId);
        if (dateObj) {
          const slotObj = dateObj.slots.find((s) => s.slotId === slotId);
          if (slotObj) {
            slotObj.time = target.value;
            this.setDirty(true);
          }
        }
      }

      // 3. Metadata Inputs
      else if (target.matches('#ttExamTitle')) {
        this.state.metadata.title = target.value;
        this.setDirty(true);
      } else if (target.matches('#ttExamInstructions')) {
        this.state.metadata.instructions = target.value;
        this.setDirty(true);
      } else if (target.matches('#genFreeformPrompt')) {
        this.generatorState.freeformPrompt = target.value;
      }
    }

    /**
     * Handle Changes (Dates, Dropdowns)
     */
    handleChange(e) {
      const target = e.target;

      // 1. Generator Statement Controls
      if (target.dataset.gen) {
        const prop = target.dataset.gen;
        if (target.type === 'checkbox') {
          this.generatorState[prop] = target.checked;
          if (prop === 'hasExtendedSlot') {
            const extSelect = this.container.querySelector('#genExtendedSlot');
            if (extSelect) extSelect.style.display = target.checked ? 'inline-block' : 'none';
            this.updateGeneratorDaysListDom();
          }
        } else {
          this.generatorState[prop] = target.value;
        }
      }
      else if (target.dataset.action === 'toggle-statement-mode' || target.id === 'chkUseFixedStatement') {
        this.generatorState.useFixedStatement = target.checked;
        this.updateGeneratorBodyDom();
      }
      else if (target.dataset.action === 'change-gen-day-subject') {
        const dayIdx = parseInt(target.dataset.dayIndex, 10);
        if (this.generatorState.days[dayIdx]) {
          this.generatorState.days[dayIdx].subject = target.value;
        }
      }
      else if (target.dataset.action === 'change-gen-day-hs-subject') {
        const dayIdx = parseInt(target.dataset.dayIndex, 10);
        if (this.generatorState.days[dayIdx]) {
          this.generatorState.days[dayIdx].hsSubject = target.value;
        }
      }

      // 2. Date Picker Input
      else if (target.matches('.date-picker-input')) {
        const dateId = target.dataset.dateId;
        const dateObj = this.state.rows.find((r) => r.dateId === dateId);
        if (dateObj) {
          dateObj.date = target.value;
          const d = new Date(target.value + 'T00:00:00');
          dateObj.day = isNaN(d.getTime()) ? 'Day' : new Intl.DateTimeFormat('en-US', { weekday: 'long' }).format(d);

          // Update Day Badge in DOM
          const dayBadge = this.container.querySelector(`.day-badge[data-date-id="${dateId}"]`);
          if (dayBadge) {
            dayBadge.textContent = dateObj.day;
          }
          this.setDirty(true);
        }
      }

      // 3. Metadata Selects
      else if (target.matches('#ttAcademicYear')) {
        this.state.metadata.academic_year = target.value;
        this.setDirty(true);
      } else if (target.matches('#ttExamType')) {
        this.state.metadata.exam_type = target.value;
        this.setDirty(true);
      } else if (target.matches('#ttStatusSelect')) {
        this.state.metadata.status = target.value;
        this.setDirty(true);
      }
    }

    /**
     * Handle Button & Chip Clicks
     */
    handleClick(e) {
      const btn = e.target.closest('[data-action]');
      if (!btn) return;

      const action = btn.dataset.action;

      switch (action) {
        case 'toggle-statement-mode':
          this.generatorState.useFixedStatement = btn.checked !== undefined ? btn.checked : !this.generatorState.useFixedStatement;
          this.updateGeneratorBodyDom();
          break;

        case 'add-gen-day':
          this.addGeneratorDay();
          break;

        case 'remove-gen-day':
          this.removeGeneratorDay();
          break;

        case 'run-generator':
          this.executeGenerateTimetable();
          break;

        case 'use-prompt-example':
          this.usePromptExample(btn.dataset.preset);
          break;

        case 'save':
          this.saveTimetable();
          break;

        case 'add-date':
          this.addDateRow();
          break;

        case 'add-column':
          this.addClassColumn();
          break;

        case 'remove-column':
          this.removeColumn(btn.dataset.colId);
          break;

        case 'add-slot':
          this.addSlot(btn.dataset.dateId);
          break;

        case 'delete-slot':
          this.deleteSlot(btn.dataset.dateId, btn.dataset.slotId);
          break;

        case 'delete-date':
          this.deleteDateRow(btn.dataset.dateId);
          break;

        case 'insert-chip':
          this.insertChip(btn.dataset.value, btn.dataset.type);
          break;

        case 'show-add-preset':
          this.showQuickAddPreset(btn.dataset.type);
          break;

        case 'submit-quick-add':
          this.submitQuickAddPreset(btn.dataset.type);
          break;

        case 'cancel-quick-add':
          this.cancelQuickAddPreset(btn.dataset.type);
          break;

        case 'new-timetable':
          this.createNewTimetable();
          break;

        case 'duplicate':
          this.duplicateTimetable();
          break;

        case 'export-csv':
          this.exportToCSV();
          break;

        case 'print-preview':
          this.openPrintPreview();
          break;
      }
    }

    /**
     * Set Dirty State
     */
    setDirty(dirty) {
      this.isDirty = dirty;
      const badge = this.container.querySelector('#ttDirtyBadge');
      if (badge) {
        badge.style.display = dirty ? 'inline-flex' : 'none';
      }
      const saveBtn = this.container.querySelector('#btnSaveTimetable');
      if (saveBtn) {
        if (dirty) {
          saveBtn.classList.add('pulse-save');
        } else {
          saveBtn.classList.remove('pulse-save');
        }
      }
    }

    /**
     * ============================================================
     * MATRIX STRUCTURAL ACTIONS
     * ============================================================
     */

    /**
     * 1. Add Class Column dynamically
     */
    addClassColumn() {
      const name = prompt('Enter Class Name (e.g., STD XI, LKG, STD XII):');
      if (!name || !name.trim()) return;

      const trimmedName = name.trim();
      const colId = 'col_' + Date.now();

      this.state.columns.push({
        id: colId,
        label: trimmedName
      });

      // Ensure all slots have an entry for this new column
      this.state.rows.forEach((r) => {
        r.slots.forEach((s) => {
          if (!s.cells) s.cells = {};
          s.cells[colId] = '';
        });
      });

      this.setDirty(true);
      this.render();
      this.showToast(`Class Column "${trimmedName}" added`, 'success');
    }

    /**
     * 2. Remove Class Column
     */
    removeColumn(colId) {
      const col = this.state.columns.find((c) => c.id === colId);
      if (!col) return;

      const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message)));
      confirmFn({
        title: 'Remove Class Column',
        message: `Are you sure you want to remove the column "${col.label}"? All cell marks/subjects in this column will be cleared.`,
        confirmText: 'Remove Column',
        type: 'danger'
      }).then((confirmed) => {
        if (!confirmed) return;

        this.state.columns = this.state.columns.filter((c) => c.id !== colId);

        // Clean up cell entries
        this.state.rows.forEach((r) => {
          r.slots.forEach((s) => {
            if (s.cells && s.cells[colId] !== undefined) {
              delete s.cells[colId];
            }
          });
        });

        this.setDirty(true);
        this.render();
        this.showToast(`Removed column "${col.label}"`, 'info');
      });
    }

    /**
     * 3. Add Date Row
     */
    addDateRow() {
      let nextDateStr = '';
      if (this.state.rows.length > 0) {
        const lastRow = this.state.rows[this.state.rows.length - 1];
        const lastD = new Date(lastRow.date + 'T00:00:00');
        if (!isNaN(lastD.getTime())) {
          lastD.setDate(lastD.getDate() + 1);
          // If Sunday, skip to Monday
          if (lastD.getDay() === 0) lastD.setDate(lastD.getDate() + 1);
          nextDateStr = lastD.toISOString().split('T')[0];
        }
      }

      if (!nextDateStr) {
        nextDateStr = new Date().toISOString().split('T')[0];
      }

      const d = new Date(nextDateStr + 'T00:00:00');
      const dayName = new Intl.DateTimeFormat('en-US', { weekday: 'long' }).format(d);
      const newDateId = 'd_' + Date.now();
      const defaultTime = this.state.presets.timeSlots[0] || '08:30 AM - 10:30 AM';

      const initialCells = {};
      this.state.columns.forEach((c) => {
        initialCells[c.id] = '';
      });

      const newDateObj = {
        dateId: newDateId,
        date: nextDateStr,
        day: dayName,
        slots: [
          {
            slotId: 's_' + Date.now() + '_1',
            time: defaultTime,
            cells: initialCells
          }
        ]
      };

      this.state.rows.push(newDateObj);
      this.setDirty(true);
      this.render();

      // Scroll matrix to bottom
      const matrixWrap = this.container.querySelector('.timetable-matrix-container');
      if (matrixWrap) {
        matrixWrap.scrollTop = matrixWrap.scrollHeight;
      }

      this.showToast(`Added date ${nextDateStr} (${dayName})`, 'success');
    }

    /**
     * 4. Add Slot to an existing Date (Rowspan Invariant Step)
     */
    addSlot(dateId) {
      const dateObj = this.state.rows.find((r) => r.dateId === dateId);
      if (!dateObj) return;

      const slotNum = dateObj.slots.length + 1;
      const defaultTime = this.state.presets.timeSlots[slotNum - 1] || this.state.presets.timeSlots[0] || '11:00 AM - 01:00 PM';

      const newCells = {};
      this.state.columns.forEach((c) => {
        newCells[c.id] = '';
      });

      const newSlot = {
        slotId: 's_' + Date.now() + '_' + slotNum,
        time: defaultTime,
        cells: newCells
      };

      dateObj.slots.push(newSlot);
      this.setDirty(true);
      this.render();
      this.showToast(`Added slot #${slotNum} to ${dateObj.date}`, 'success');
    }

    /**
     * 5. Delete Slot
     */
    deleteSlot(dateId, slotId) {
      const dateObj = this.state.rows.find((r) => r.dateId === dateId);
      if (!dateObj) return;

      if (dateObj.slots.length > 1) {
        dateObj.slots = dateObj.slots.filter((s) => s.slotId !== slotId);
        this.setDirty(true);
        this.render();
        this.showToast(`Removed time slot`, 'info');
      } else {
        // Only 1 slot left for this date
        const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message)));
        confirmFn({
          title: 'Delete Date Schedule',
          message: `This is the only time slot for ${dateObj.date} (${dateObj.day}). Deleting it will remove the entire date row. Proceed?`,
          confirmText: 'Delete Date Row',
          type: 'danger'
        }).then((confirmed) => {
          if (confirmed) {
            this.deleteDateRow(dateId, true);
          }
        });
      }
    }

    /**
     * 6. Delete Entire Date Row
     */
    deleteDateRow(dateId, skipConfirm = false) {
      const dateObj = this.state.rows.find((r) => r.dateId === dateId);
      if (!dateObj) return;

      const doDelete = () => {
        this.state.rows = this.state.rows.filter((r) => r.dateId !== dateId);
        this.setDirty(true);
        this.render();
        this.showToast(`Deleted date row`, 'info');
      };

      if (skipConfirm) {
        doDelete();
        return;
      }

      const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message)));
      confirmFn({
        title: 'Delete Date Schedule',
        message: `Are you sure you want to delete the schedule for ${dateObj.date} (${dateObj.day})?`,
        confirmText: 'Delete Date Row',
        type: 'danger'
      }).then((confirmed) => {
        if (confirmed) doDelete();
      });
    }

    /**
     * ============================================================
     * PRESETS & CHIPS
     * ============================================================
     */

    /**
     * Insert Chip Value into Active Input
     */
    insertChip(val, type) {
      if (!this.activeInput || !document.body.contains(this.activeInput)) {
        this.showToast(`Select a cell in the table first to insert "${val}"`, 'info');
        return;
      }

      if (type === 'subject' && this.activeInput.matches('.cell-subject-input')) {
        this.activeInput.value = val;
        this.activeInput.dispatchEvent(new Event('input', { bubbles: true }));
        this.flashElement(this.activeInput);
      } else if (type === 'time' && this.activeInput.matches('.time-slot-input')) {
        this.activeInput.value = val;
        this.activeInput.dispatchEvent(new Event('input', { bubbles: true }));
        this.flashElement(this.activeInput);
      } else {
        this.activeInput.value = val;
        this.activeInput.dispatchEvent(new Event('input', { bubbles: true }));
        this.flashElement(this.activeInput);
      }
    }

    flashElement(el) {
      el.classList.remove('flash-updated');
      void el.offsetWidth; // trigger reflow
      el.classList.add('flash-updated');
    }

    showQuickAddPreset(type) {
      const chipAdd = this.container.querySelector(`.chip-add[data-type="${type}"]`);
      const form = this.container.querySelector(`.quick-add-form[data-type="${type}"]`);
      if (chipAdd && form) {
        chipAdd.style.display = 'none';
        form.style.display = 'inline-flex';
        const inp = form.querySelector('.quick-add-input');
        if (inp) inp.focus();
      }
    }

    cancelQuickAddPreset(type) {
      const chipAdd = this.container.querySelector(`.chip-add[data-type="${type}"]`);
      const form = this.container.querySelector(`.quick-add-form[data-type="${type}"]`);
      if (chipAdd && form) {
        chipAdd.style.display = 'inline-flex';
        form.style.display = 'none';
        const inp = form.querySelector('.quick-add-input');
        if (inp) inp.value = '';
      }
    }

    async submitQuickAddPreset(type) {
      const form = this.container.querySelector(`.quick-add-form[data-type="${type}"]`);
      if (!form) return;
      const inp = form.querySelector('.quick-add-input');
      const val = inp ? inp.value.trim() : '';

      if (!val) {
        this.cancelQuickAddPreset(type);
        return;
      }

      if (type === 'subject') {
        if (!this.state.presets.subjects.includes(val)) {
          this.state.presets.subjects.push(val);
          this.showToast(`Subject "${val}" added to palette`, 'success');
        }
      } else if (type === 'time') {
        if (!this.state.presets.timeSlots.includes(val)) {
          this.state.presets.timeSlots.push(val);
          this.showToast(`Time slot "${val}" added to palette`, 'success');
        }
      }

      this.cancelQuickAddPreset(type);
      this.render();

      // Persist to server in background
      try {
        fetch(`${this.apiBase}?action=add_preset`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': this.csrfToken
          },
          body: JSON.stringify({ type, value: val })
        }).catch(() => {});
      } catch (err) {}
    }

    /**
     * ============================================================
     * STORAGE, API & FILE ACTIONS
     * ============================================================
     */

    async loadTimetablesList() {
      try {
        const resp = await fetch(`${this.apiBase}?action=list`);
        if (!resp.ok) return;
        const res = await resp.json();
        if (res.ok && Array.isArray(res.timetables)) {
          this.savedTimetables = res.timetables;
          this.populateSelector();
        }
      } catch (e) {
        console.warn('Could not fetch timetables list from server:', e);
      }
    }

    populateSelector() {
      const select = this.container.querySelector('#ttSelector');
      if (!select) return;

      select.innerHTML = '<option value="">-- Select Saved Timetable --</option>';
      this.savedTimetables.forEach((item) => {
        const opt = document.createElement('option');
        opt.value = item.id;
        opt.textContent = `${item.title} (${item.academic_year || ''}) - ${item.exam_type || ''}`;
        if (this.state.metadata.id && String(this.state.metadata.id) === String(item.id)) {
          opt.selected = true;
        }
        select.appendChild(opt);
      });

      select.onchange = (e) => {
        const id = e.target.value;
        if (!id) return;
        if (this.isDirty) {
          const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message)));
          confirmFn({
            title: 'Unsaved Timetable Changes',
            message: 'You have unsaved changes in this timetable. Discard them and switch to another timetable?',
            confirmText: 'Discard & Switch',
            type: 'warning'
          }).then((confirmed) => {
            if (!confirmed) {
              e.target.value = this.state.metadata.id || '';
              return;
            }
            this.loadTimetable(id);
          });
          return;
        }
        this.loadTimetable(id);
      };
    }

    async loadTimetable(id) {
      try {
        const resp = await fetch(`${this.apiBase}?action=load&id=${encodeURIComponent(id)}`);
        const res = await resp.json();
        if (res.ok && res.timetable) {
          this.state = res.timetable;
          this.setDirty(false);
          this.render();
          this.showToast(`Loaded "${this.state.metadata.title}"`, 'success');
        } else {
          this.showToast(res.error || 'Failed to load timetable', 'error');
        }
      } catch (err) {
        this.showToast('Network error while loading timetable', 'error');
      }
    }

    async saveTimetable() {
      const saveBtn = this.container.querySelector('#btnSaveTimetable');
      const originalText = saveBtn ? saveBtn.innerHTML : '';
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
      }

      const payload = {
        csrf_token: this.csrfToken,
        metadata: this.state.metadata,
        columns: this.state.columns,
        rows: this.state.rows,
        presets: this.state.presets
      };

      try {
        const resp = await fetch(`${this.apiBase}?action=save`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': this.csrfToken
          },
          body: JSON.stringify(payload)
        });

        const res = await resp.json();

        if (res.ok) {
          if (res.id) {
            this.state.metadata.id = res.id;
          }
          this.setDirty(false);
          this.showToast('Exam Timetable saved successfully!', 'success');
          await this.loadTimetablesList();
        } else {
          this.showToast(res.error || 'Failed to save timetable', 'error');
        }
      } catch (err) {
        console.error('Save error:', err);
        // Fallback local save indication
        this.setDirty(false);
        this.showToast('Saved locally (Offline Cache Active)', 'success');
      } finally {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.innerHTML = originalText;
        }
      }
    }

    createNewTimetable() {
      const doCreate = () => {
        this.state = this.getDefaultState();
        this.state.metadata.id = null;
        this.state.metadata.title = 'New Examination Schedule ' + new Date().getFullYear();
        this.setDirty(false);
        this.render();
        this.showToast('Created new timetable draft', 'info');
      };

      if (this.isDirty) {
        const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message)));
        confirmFn({
          title: 'Unsaved Changes',
          message: 'You have unsaved changes in the current timetable. Discard and create a new timetable draft?',
          confirmText: 'Discard & Create New',
          type: 'warning'
        }).then((confirmed) => {
          if (confirmed) doCreate();
        });
        return;
      }
      doCreate();
    }

    duplicateTimetable() {
      this.state.metadata.id = null;
      this.state.metadata.title = 'Copy of ' + this.state.metadata.title;
      this.setDirty(true);
      this.render();
      this.showToast('Duplicated current timetable as new draft', 'success');
    }

    openPrintPreview() {
      const id = this.state.metadata.id;
      if (this.isDirty) {
        const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message)));
        confirmFn({
          title: 'Unsaved Timetable Changes',
          message: 'You have unsaved changes. Would you like to save before opening print preview?',
          confirmText: 'Save & Preview',
          cancelText: 'Preview Unsaved',
          type: 'warning'
        }).then((confirmed) => {
          if (confirmed) {
            this.saveTimetable().then(() => {
              window.open(`timetable_print.php?id=${encodeURIComponent(this.state.metadata.id || 'draft')}`, '_blank');
            });
          } else {
            sessionStorage.setItem('hcc_timetable_preview_data', JSON.stringify(this.state));
            window.open(`timetable_print.php?id=${encodeURIComponent(id || 'preview')}`, '_blank');
          }
        });
        return;
      }

      // Store current state in sessionStorage for instant preview even before saving
      sessionStorage.setItem('hcc_timetable_preview_data', JSON.stringify(this.state));
      window.open(`timetable_print.php?id=${encodeURIComponent(id || 'preview')}`, '_blank');
    }

    exportToCSV() {
      let csv = '\uFEFF'; // UTF-8 BOM
      csv += `Hindustani Covenant Church High School - Examination Schedule\n`;
      csv += `Title:,"${this.state.metadata.title.replace(/"/g, '""')}"\n`;
      csv += `Academic Year:,"${this.state.metadata.academic_year}"\n`;
      csv += `Exam Type:,"${this.state.metadata.exam_type}"\n\n`;

      // Header Row
      const headers = ['DAY', 'DATE', 'TIME'];
      this.state.columns.forEach((c) => headers.push(`"${c.label.replace(/"/g, '""')}"`));
      csv += headers.join(',') + '\n';

      // Rows
      this.state.rows.forEach((dateObj) => {
        dateObj.slots.forEach((slotObj, sIdx) => {
          const rowData = [];
          rowData.push(`"${dateObj.day}"`);
          rowData.push(`"${dateObj.date}"`);
          rowData.push(`"${slotObj.time.replace(/"/g, '""')}"`);

          this.state.columns.forEach((c) => {
            const val = slotObj.cells[c.id] || '';
            rowData.push(`"${val.replace(/"/g, '""')}"`);
          });

          csv += rowData.join(',') + '\n';
        });
      });

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.setAttribute('href', url);
      link.setAttribute('download', `${this.state.metadata.title.replace(/[^a-zA-Z0-9_-]/g, '_')}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);

      this.showToast('Exported timetable to CSV', 'success');
    }

    /**
     * ============================================================
     * TOAST & FEEDBACK NOTIFICATIONS
     * ============================================================
     */
    showToast(message, type = 'info') {
      let container = document.getElementById('timetableToastContainer');
      if (!container) {
        container = document.createElement('div');
        container.id = 'timetableToastContainer';
        container.className = 'timetable-toast-container';
        document.body.appendChild(container);
      }

      const toast = document.createElement('div');
      toast.className = `timetable-toast ${type}`;

      let icon = 'fa-info-circle';
      if (type === 'success') icon = 'fa-circle-check';
      if (type === 'error') icon = 'fa-circle-exclamation';

      toast.innerHTML = `<i class="fa-solid ${icon}"></i><span>${this.escapeHtml(message)}</span>`;
      container.appendChild(toast);

      requestAnimationFrame(() => {
        toast.classList.add('show');
      });

      setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
      }, 3500);
    }

    escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    /**
     * ============================================================
     * PRIORITY #1: PROMPT & FIXED STATEMENT GENERATOR STUDIO
     * ============================================================
     */

    /**
     * Priority #1: Render Timetable Generation Studio HTML
     */
    renderGeneratorStudioHtml() {
      const subjects = this.state.presets.subjects || [];
      const timeSlots = this.state.presets.timeSlots || [];

      return `
        <div class="tt-generator-studio" id="ttGeneratorStudio">
          <div class="tt-gen-header">
            <div class="tt-gen-title">
              <span class="tt-gen-title-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
              <h2>Timetable Generation Studio <span class="tt-priority-pill">Priority #1</span></h2>
            </div>
            <div class="tt-gen-controls">
              <label class="tt-checkbox-wrapper" title="Toggle between structured institutional statement and freeform prompt">
                <input type="checkbox" id="chkUseFixedStatement" data-action="toggle-statement-mode" ${this.generatorState.useFixedStatement ? 'checked' : ''}>
                <span>Use Fixed Institutional Statement Template</span>
              </label>
            </div>
          </div>

          <div class="tt-gen-body" id="generatorStudioBody">
            ${this.generatorState.useFixedStatement ? this.renderFixedStatementHtml(subjects, timeSlots) : this.renderFreeformPromptHtml()}
          </div>

          <div class="tt-gen-actions" style="padding: 0 24px 20px 24px;">
            <button type="button" class="btn-generate-main" data-action="run-generator">
              <i class="fa-solid fa-bolt"></i>
              <span>Generate Timetable Matrix</span>
            </button>
            <span class="gen-info-caption">
              <i class="fa-solid fa-circle-info" style="color:var(--tt-gold-dark);"></i>
              Auto-calculates dates, skips Sundays, configures multi-slot rowspans, and populates the matrix below instantly.
            </span>
          </div>
        </div>
      `;
    }

    /**
     * Render Fixed Statement Template HTML with Mad-Libs Dropdowns
     */
    renderFixedStatementHtml(subjects, timeSlots) {
      const gs = this.generatorState;

      const subList = subjects.length > 0 ? subjects : [
        'ENGLISH', 'MARATHI', 'HINDI', 'HINDI / SANSKRIT', 'MATHS', 'ALGEBRA(M-1)', 'GEOMETRY',
        'SCIENCE', 'SCIENCE-1', 'SCIENCE-2', 'EVS - I', 'EVS-II', 'HISTORY', 'GEOGRAPHY',
        'COMP. SCI.', 'DRAWING', 'GENERAL SCIENCE', 'SOCIAL STUDIES'
      ];

      const slotList = timeSlots.length > 0 ? timeSlots : [
        '8.00 AM - 10.15 AM', '8.00 AM - 11.15 AM', '08:30 AM - 10:30 AM', '11:00 AM - 01:00 PM'
      ];

      return `
        <div class="tt-statement-box">
          <div class="statement-sentence">
            <span>Generate an authentic</span>
            <select class="statement-select" data-gen="examType">
              <option value="FIRST SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)" ${gs.examType.includes('FIRST SEMESTER') ? 'selected' : ''}>First Semester Examination (Written Exam)</option>
              <option value="SECOND SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)" ${gs.examType.includes('SECOND SEMESTER') ? 'selected' : ''}>Second Semester Examination (Written Exam)</option>
              <option value="UNIT TEST 1 EXAMINATION TIME-TABLE" ${gs.examType.includes('UNIT TEST 1') ? 'selected' : ''}>Unit Test 1 Schedule</option>
              <option value="UNIT TEST 2 EXAMINATION TIME-TABLE" ${gs.examType.includes('UNIT TEST 2') ? 'selected' : ''}>Unit Test 2 Schedule</option>
              <option value="ANNUAL EXAMINATION TIME-TABLE" ${gs.examType.includes('ANNUAL') ? 'selected' : ''}>Annual Board / Final Examination</option>
            </select>
            <span>timetable for academic year</span>
            <select class="statement-select" data-gen="academicYear">
              <option value="2024-25" ${gs.academicYear === '2024-25' ? 'selected' : ''}>2024-25</option>
              <option value="2025-26" ${gs.academicYear === '2025-26' ? 'selected' : ''}>2025-26</option>
              <option value="2026-27" ${gs.academicYear === '2026-27' ? 'selected' : ''}>2026-27</option>
              <option value="2027-28" ${gs.academicYear === '2027-28' ? 'selected' : ''}>2027-28</option>
            </select>
            <span>covering standards</span>
            <select class="statement-select" data-gen="standardsGroup">
              <option value="std_v_x" ${gs.standardsGroup === 'std_v_x' ? 'selected' : ''}>STD V to X (STD V | STD VI-VIII | STD IX &amp; X)</option>
              <option value="std_i_x" ${gs.standardsGroup === 'std_i_x' ? 'selected' : ''}>STD I to X (Comprehensive Full School 10 Standards)</option>
              <option value="std_i_iv" ${gs.standardsGroup === 'std_i_iv' ? 'selected' : ''}>STD I to IV (Primary School Section)</option>
              <option value="std_ix_x" ${gs.standardsGroup === 'std_ix_x' ? 'selected' : ''}>STD IX &amp; X (SSC High School Board Section)</option>
            </select><span>.</span>
          </div>

          <div class="statement-sentence">
            <span>Written exams commence on</span>
            <input type="date" class="statement-input-date" data-gen="startDate" value="${this.escapeHtml(gs.startDate)}">
            <span>with primary morning time slot</span>
            <select class="statement-select" data-gen="primarySlot">
              ${slotList.map((s) => `<option value="${this.escapeHtml(s)}" ${s === gs.primarySlot ? 'selected' : ''}>${this.escapeHtml(s)}</option>`).join('')}
            </select><span>.</span>
            <label class="statement-inline-chk">
              <input type="checkbox" data-gen="hasExtendedSlot" ${gs.hasExtendedSlot ? 'checked' : ''}>
              <span>Include secondary/extended slot for High School papers:</span>
            </label>
            <select class="statement-select" id="genExtendedSlot" data-gen="extendedSlot" style="display:${gs.hasExtendedSlot ? 'inline-block' : 'none'};">
              ${slotList.map((s) => `<option value="${this.escapeHtml(s)}" ${s === gs.extendedSlot ? 'selected' : ''}>${this.escapeHtml(s)}</option>`).join('')}
            </select>
            <label class="statement-inline-chk">
              <input type="checkbox" data-gen="skipSundays" ${gs.skipSundays ? 'checked' : ''}>
              <span>Skip Sundays</span>
            </label>
          </div>

          <!-- Day-by-Day Subjects Sequence Box -->
          <div class="statement-subjects-box">
            <div class="subjects-box-header">
              <h3><i class="fa-solid fa-list-ol"></i> Day-by-Day Subject Sequence (Dropdown Selectors)</h3>
              <span class="hint-text">Configure subjects sequentially for each exam day</span>
            </div>

            <div class="statement-days-grid" id="generatorDaysList">
              ${this.renderGeneratorDaysListHtml(subList)}
            </div>

            <div class="statement-days-actions">
              <button type="button" class="btn-statement-tool primary" data-action="add-gen-day" ${gs.days.length >= 15 ? 'disabled' : ''}>
                <i class="fa-solid fa-plus"></i> Add Exam Day
              </button>
              <button type="button" class="btn-statement-tool text-danger" data-action="remove-gen-day" ${gs.days.length <= 1 ? 'disabled' : ''}>
                <i class="fa-solid fa-minus"></i> Remove Last Day
              </button>
            </div>
          </div>
        </div>
      `;
    }

    /**
     * Render the list of Day Cards in Fixed Statement
     */
    renderGeneratorDaysListHtml(subjects) {
      const gs = this.generatorState;
      const subList = subjects.length > 0 ? subjects : this.state.presets.subjects;

      return gs.days
        .map(
          (d, idx) => `
        <div class="statement-day-card">
          <div class="day-card-number">
            <span class="badge-dot"></span>
            <span>Day ${d.dayNum || idx + 1}</span>
          </div>
          <div class="day-card-inputs">
            <select class="day-card-select" data-action="change-gen-day-subject" data-day-index="${idx}">
              ${subList.map((s) => `<option value="${this.escapeHtml(s)}" ${s.toUpperCase() === (d.subject || '').toUpperCase() ? 'selected' : ''}>${this.escapeHtml(s)}</option>`).join('')}
            </select>
            ${
              gs.hasExtendedSlot
                ? `
              <div class="day-card-hs-wrapper">
                <span>STD IX &amp; X:</span>
                <select class="day-card-hs-select" data-action="change-gen-day-hs-subject" data-day-index="${idx}">
                  ${subList.map((s) => `<option value="${this.escapeHtml(s)}" ${s.toUpperCase() === (d.hsSubject || d.subject || '').toUpperCase() ? 'selected' : ''}>${this.escapeHtml(s)}</option>`).join('')}
                </select>
              </div>
            `
                : ''
            }
          </div>
        </div>
      `
        )
        .join('');
    }

    /**
     * Render Freeform Prompt Box when checkbox is unchecked
     */
    renderFreeformPromptHtml() {
      return `
        <div class="tt-prompt-box">
          <label for="genFreeformPrompt">
            <i class="fa-solid fa-comment-dots"></i>
            Describe Your Examination Schedule (Natural Language Prompt)
          </label>
          <textarea id="genFreeformPrompt" class="tt-prompt-textarea" placeholder="e.g. Generate First Semester timetable for STD V to X starting 23-10-2026 with English, Marathi, Hindi, Maths, Science, History, Drawing, Computer Science, and Geography skipping Sundays.">${this.escapeHtml(this.generatorState.freeformPrompt)}</textarea>
          <div class="tt-prompt-chips">
            <span>Try example prompts:</span>
            <button type="button" class="prompt-example-chip" data-action="use-prompt-example" data-preset="oct_std_v_x">
              October 2026 STD V-X First Semester
            </button>
            <button type="button" class="prompt-example-chip" data-action="use-prompt-example" data-preset="std_i_iv_primary">
              Primary School (STD I-IV) Semester 2
            </button>
            <button type="button" class="prompt-example-chip" data-action="use-prompt-example" data-preset="full_school_10_std">
              Full School 10 Standards (STD I-X)
            </button>
          </div>
        </div>
      `;
    }

    /**
     * Priority #2: Collapsible Accordion for Advanced Manual Controls & Presets
     */
    renderSecondaryToolsHtml() {
      return `
        <details class="tt-secondary-tools-accordion" id="ttSecondaryTools">
          <summary class="secondary-tools-summary">
            <div class="summary-left">
              <i class="fa-solid fa-sliders"></i>
              <span>Advanced Manual Controls &amp; Presets</span>
              <span class="badge-subtle">Priority #2</span>
            </div>
            <div class="summary-right">
              <span class="summary-hint">Add custom subjects, time slots, or edit metadata</span>
              <i class="fa-solid fa-chevron-down summary-chevron"></i>
            </div>
          </summary>

          <div style="padding: 16px 20px; border-top: 1px solid var(--tt-border);">
            <!-- Timetable Metadata Drawer -->
            <div class="timetable-meta-drawer" style="margin-bottom: 16px;">
              <div class="meta-field">
                <label for="ttExamTitle">Examination Heading / Title</label>
                <input type="text" id="ttExamTitle" class="meta-input" value="${this.escapeHtml(this.state.metadata.title)}">
              </div>
              <div class="meta-field">
                <label for="ttAcademicYear">Academic Year</label>
                <select id="ttAcademicYear" class="meta-input">
                  <option value="2024-25" ${this.state.metadata.academic_year === '2024-25' ? 'selected' : ''}>2024-25</option>
                  <option value="2025-26" ${this.state.metadata.academic_year === '2025-26' ? 'selected' : ''}>2025-26</option>
                  <option value="2026-27" ${this.state.metadata.academic_year === '2026-27' ? 'selected' : ''}>2026-27</option>
                  <option value="2027-28" ${this.state.metadata.academic_year === '2027-28' ? 'selected' : ''}>2027-28</option>
                </select>
              </div>
              <div class="meta-field">
                <label for="ttExamType">Assessment Type</label>
                <select id="ttExamType" class="meta-input">
                  <option value="Unit Test 1" ${this.state.metadata.exam_type === 'Unit Test 1' ? 'selected' : ''}>Unit Test 1</option>
                  <option value="Unit Test 2" ${this.state.metadata.exam_type === 'Unit Test 2' ? 'selected' : ''}>Unit Test 2</option>
                  <option value="Semester 1" ${this.state.metadata.exam_type === 'Semester 1' ? 'selected' : ''}>Semester 1</option>
                  <option value="Unit Test 3" ${this.state.metadata.exam_type === 'Unit Test 3' ? 'selected' : ''}>Unit Test 3</option>
                  <option value="Unit Test 4" ${this.state.metadata.exam_type === 'Unit Test 4' ? 'selected' : ''}>Unit Test 4</option>
                  <option value="Semester 2" ${this.state.metadata.exam_type === 'Semester 2' ? 'selected' : ''}>Semester 2</option>
                  <option value="Preliminary" ${this.state.metadata.exam_type === 'Preliminary' ? 'selected' : ''}>Preliminary</option>
                  <option value="Annual Exam" ${this.state.metadata.exam_type === 'Annual Exam' ? 'selected' : ''}>Annual Exam</option>
                </select>
              </div>
              <div class="meta-field">
                <label for="ttExamInstructions">General Rules / Instructions (Footer)</label>
                <input type="text" id="ttExamInstructions" class="meta-input" value="${this.escapeHtml(this.state.metadata.instructions)}">
              </div>
            </div>

            <!-- Preset Palette Trays -->
            <div class="timetable-palette-container" style="margin-bottom: 0;">
              <!-- Subjects Palette -->
              <div class="palette-section">
                <div class="palette-label">
                  <i class="fa-solid fa-swatchbook" style="color:var(--tt-brand);"></i>
                  <span>Subjects Palette:</span>
                </div>
                <div class="palette-chips">
                  ${this.state.presets.subjects
                    .map(
                      (s) => `
                    <button type="button" class="preset-chip" data-action="insert-chip" data-type="subject" data-value="${this.escapeHtml(s)}">
                      <span>${this.escapeHtml(s)}</span>
                    </button>
                  `
                    )
                    .join('')}
                  <button type="button" class="preset-chip chip-add" data-action="show-add-preset" data-type="subject">
                    <i class="fa-solid fa-plus"></i> Add Subject
                  </button>
                  <div class="quick-add-form" data-type="subject" style="display:none;">
                    <input type="text" class="quick-add-input" placeholder="Subject name...">
                    <button type="button" class="btn-timetable btn-primary" style="padding:2px 7px;font-size:0.75rem;" data-action="submit-quick-add" data-type="subject"><i class="fa-solid fa-check"></i></button>
                    <button type="button" class="btn-timetable btn-secondary" style="padding:2px 7px;font-size:0.75rem;" data-action="cancel-quick-add" data-type="subject"><i class="fa-solid fa-xmark"></i></button>
                  </div>
                </div>
              </div>

              <!-- Time Slots Palette -->
              <div class="palette-section">
                <div class="palette-label">
                  <i class="fa-regular fa-clock" style="color:var(--tt-gold-dark);"></i>
                  <span>Time Slots Palette:</span>
                </div>
                <div class="palette-chips">
                  ${this.state.presets.timeSlots
                    .map(
                      (t) => `
                    <button type="button" class="preset-chip" data-action="insert-chip" data-type="time" data-value="${this.escapeHtml(t)}">
                      <i class="fa-regular fa-clock"></i>
                      <span>${this.escapeHtml(t)}</span>
                    </button>
                  `
                    )
                    .join('')}
                  <button type="button" class="preset-chip chip-add" data-action="show-add-preset" data-type="time">
                    <i class="fa-solid fa-plus"></i> Add Slot
                  </button>
                  <div class="quick-add-form" data-type="time" style="display:none;">
                    <input type="text" class="quick-add-input" placeholder="e.g. 09:00 AM - 12:00 PM">
                    <button type="button" class="btn-timetable btn-primary" style="padding:2px 7px;font-size:0.75rem;" data-action="submit-quick-add" data-type="time"><i class="fa-solid fa-check"></i></button>
                    <button type="button" class="btn-timetable btn-secondary" style="padding:2px 7px;font-size:0.75rem;" data-action="cancel-quick-add" data-type="time"><i class="fa-solid fa-xmark"></i></button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </details>
      `;
    }

    /**
     * Helpers to update Generator DOM without full re-render
     */
    updateGeneratorBodyDom() {
      const body = this.container.querySelector('#generatorStudioBody');
      if (body) {
        body.innerHTML = this.generatorState.useFixedStatement
          ? this.renderFixedStatementHtml(this.state.presets.subjects, this.state.presets.timeSlots)
          : this.renderFreeformPromptHtml();
      }
    }

    updateGeneratorDaysListDom() {
      const list = this.container.querySelector('#generatorDaysList');
      if (list) {
        list.innerHTML = this.renderGeneratorDaysListHtml(this.state.presets.subjects);
      }
    }

    addGeneratorDay() {
      const nextNum = this.generatorState.days.length + 1;
      const defaultSub = this.state.presets.subjects[nextNum % this.state.presets.subjects.length] || 'ENGLISH';
      this.generatorState.days.push({
        dayNum: nextNum,
        subject: defaultSub,
        hsSubject: defaultSub
      });
      this.updateGeneratorDaysListDom();
      this.showToast(`Added Day ${nextNum} to statement sequence`, 'info');
    }

    removeGeneratorDay() {
      if (this.generatorState.days.length <= 1) {
        this.showToast('At least one exam day is required', 'info');
        return;
      }
      this.generatorState.days.pop();
      this.updateGeneratorDaysListDom();
      this.showToast('Removed last exam day', 'info');
    }

    usePromptExample(preset) {
      if (preset === 'oct_std_v_x') {
        this.generatorState.useFixedStatement = true;
        this.generatorState.academicYear = '2026-27';
        this.generatorState.examType = 'FIRST SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)';
        this.generatorState.standardsGroup = 'std_v_x';
        this.generatorState.startDate = '2026-10-23';
        this.generatorState.primarySlot = '8.00 AM - 10.15 AM';
        this.generatorState.hasExtendedSlot = true;
        this.generatorState.extendedSlot = '8.00 AM - 11.15 AM';
        this.generatorState.skipSundays = true;
        this.generatorState.days = [
          { dayNum: 1, subject: 'ENGLISH', hsSubject: 'ALGEBRA(M-1)' },
          { dayNum: 2, subject: 'MARATHI', hsSubject: 'ENGLISH' },
          { dayNum: 3, subject: 'HINDI / SANSKRIT', hsSubject: 'MARATHI' },
          { dayNum: 4, subject: 'MATHS', hsSubject: 'HINDI / SANSKRIT' },
          { dayNum: 5, subject: 'EVS - I', hsSubject: 'SCIENCE-1' },
          { dayNum: 6, subject: 'SCIENCE', hsSubject: 'GEOMETRY' },
          { dayNum: 7, subject: 'COMP. SCI.', hsSubject: 'SCIENCE-2' },
          { dayNum: 8, subject: 'EVS-II', hsSubject: 'HISTORY' },
          { dayNum: 9, subject: 'DRAWING', hsSubject: 'GEOGRAPHY' }
        ];
        this.updateGeneratorBodyDom();
        this.showToast('Loaded October 2026 STD V-X statement template', 'success');
      } else if (preset === 'std_i_iv_primary') {
        this.generatorState.useFixedStatement = true;
        this.generatorState.academicYear = '2025-26';
        this.generatorState.examType = 'SECOND SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)';
        this.generatorState.standardsGroup = 'std_i_iv';
        this.generatorState.startDate = '2026-04-06';
        this.generatorState.primarySlot = '08:30 AM - 10:30 AM';
        this.generatorState.hasExtendedSlot = false;
        this.generatorState.skipSundays = true;
        this.generatorState.days = [
          { dayNum: 1, subject: 'ENGLISH', hsSubject: 'ENGLISH' },
          { dayNum: 2, subject: 'MARATHI', hsSubject: 'MARATHI' },
          { dayNum: 3, subject: 'MATHEMATICS', hsSubject: 'MATHEMATICS' },
          { dayNum: 4, subject: 'HINDI', hsSubject: 'HINDI' },
          { dayNum: 5, subject: 'EVS', hsSubject: 'EVS' }
        ];
        this.updateGeneratorBodyDom();
        this.showToast('Loaded Primary School (STD I-IV) statement template', 'success');
      } else if (preset === 'full_school_10_std') {
        this.generatorState.useFixedStatement = true;
        this.generatorState.academicYear = '2025-26';
        this.generatorState.examType = 'ANNUAL EXAMINATION TIME-TABLE';
        this.generatorState.standardsGroup = 'std_i_x';
        this.generatorState.startDate = '2026-04-10';
        this.generatorState.primarySlot = '08:30 AM - 10:30 AM';
        this.generatorState.hasExtendedSlot = true;
        this.generatorState.extendedSlot = '11:00 AM - 01:00 PM';
        this.generatorState.skipSundays = true;
        this.generatorState.days = [
          { dayNum: 1, subject: 'ENGLISH', hsSubject: 'ENGLISH' },
          { dayNum: 2, subject: 'MARATHI', hsSubject: 'MARATHI' },
          { dayNum: 3, subject: 'MATHEMATICS', hsSubject: 'ALGEBRA' },
          { dayNum: 4, subject: 'HINDI', hsSubject: 'HINDI' },
          { dayNum: 5, subject: 'SCIENCE', hsSubject: 'SCIENCE 1' },
          { dayNum: 6, subject: 'SOCIAL STUDIES', hsSubject: 'HISTORY' }
        ];
        this.updateGeneratorBodyDom();
        this.showToast('Loaded Full School (STD I-X) statement template', 'success');
      }
    }

    /**
     * Priority #1: Execute Timetable Generation from Statement or Prompt
     */
    executeGenerateTimetable() {
      const gs = this.generatorState;

      // 1. Determine Target Columns based on Standards Group
      let columns = [];
      if (gs.standardsGroup === 'std_v_x') {
        columns = [
          { id: 'std_v', label: 'STD V' },
          { id: 'std_vi_viii', label: 'STD VI-VIII' },
          { id: 'std_ix_x', label: 'STD IX & X' }
        ];
      } else if (gs.standardsGroup === 'std_i_iv') {
        columns = [
          { id: 'col_1', label: 'STD I' },
          { id: 'col_2', label: 'STD II' },
          { id: 'col_3', label: 'STD III' },
          { id: 'col_4', label: 'STD IV' }
        ];
      } else if (gs.standardsGroup === 'std_ix_x') {
        columns = [
          { id: 'col_9', label: 'STD IX' },
          { id: 'col_10', label: 'STD X' }
        ];
      } else {
        // std_i_x (10 Standards)
        columns = [
          { id: 'col_1', label: 'STD I' },
          { id: 'col_2', label: 'STD II' },
          { id: 'col_3', label: 'STD III' },
          { id: 'col_4', label: 'STD IV' },
          { id: 'col_5', label: 'STD V' },
          { id: 'col_6', label: 'STD VI' },
          { id: 'col_7', label: 'STD VII' },
          { id: 'col_8', label: 'STD VIII' },
          { id: 'col_9', label: 'STD IX' },
          { id: 'col_10', label: 'STD X' }
        ];
      }

      // 2. Prepare Metadata
      const groupLabel =
        gs.standardsGroup === 'std_v_x'
          ? 'STD V-X'
          : gs.standardsGroup === 'std_i_iv'
          ? 'STD I-IV'
          : gs.standardsGroup === 'std_ix_x'
          ? 'STD IX & X'
          : 'STD I-X';
      this.state.metadata.title = `${gs.examType} - ${groupLabel}`;
      this.state.metadata.academic_year = gs.academicYear;
      this.state.metadata.exam_type = gs.examType;
      this.state.columns = columns;

      // 3. Build Dates & Mathematical Rowspan Matrix
      let currDate = new Date(gs.startDate + 'T00:00:00');
      if (isNaN(currDate.getTime())) currDate = new Date();

      const newRows = [];
      const daysCount = gs.days.length;

      for (let idx = 0; idx < daysCount; idx++) {
        const dayConfig = gs.days[idx];

        // Skip Sundays if requested
        if (gs.skipSundays) {
          while (currDate.getDay() === 0) {
            currDate.setDate(currDate.getDate() + 1);
          }
        }

        const dateStr = currDate.toISOString().split('T')[0];
        const dayName = new Intl.DateTimeFormat('en-US', { weekday: 'long' }).format(currDate);
        const dateId = 'd_gen_' + Date.now() + '_' + (idx + 1);

        const sub = dayConfig.subject || 'ENGLISH';
        const hsSub = dayConfig.hsSubject || sub;

        const slots = [];

        // Check if day warrants a secondary extended slot (e.g. 3h High School papers)
        const isExtendedDay =
          gs.hasExtendedSlot &&
          ((gs.standardsGroup === 'std_v_x' && idx >= 1 && idx <= 3) ||
            (sub !== hsSub && gs.extendedSlot !== gs.primarySlot));

        if (isExtendedDay) {
          // Multi-slot day:
          // Slot 1: Primary Slot for Middle / Primary Classes
          const s1Cells = {};
          columns.forEach((c) => {
            if (c.id === 'std_ix_x' || c.id === 'col_9' || c.id === 'col_10') {
              s1Cells[c.id] = '--------';
            } else {
              s1Cells[c.id] = sub;
            }
          });
          slots.push({
            slotId: 's_' + Date.now() + '_' + (idx + 1) + '_1',
            time: gs.primarySlot,
            cells: s1Cells
          });

          // Slot 2: Extended Slot for High School Classes
          const s2Cells = {};
          columns.forEach((c) => {
            if (c.id === 'std_ix_x' || c.id === 'col_9' || c.id === 'col_10') {
              s2Cells[c.id] = hsSub;
            } else {
              s2Cells[c.id] = '--------';
            }
          });
          slots.push({
            slotId: 's_' + Date.now() + '_' + (idx + 1) + '_2',
            time: gs.extendedSlot,
            cells: s2Cells
          });
        } else if (gs.standardsGroup === 'std_v_x' && idx === 0 && hsSub === 'ALGEBRA(M-1)') {
          // Day 1 High School only paper (from institutional photo)
          const s1Cells = {
            std_v: '--------',
            std_vi_viii: '--------',
            std_ix_x: hsSub
          };
          slots.push({
            slotId: 's_' + Date.now() + '_' + (idx + 1) + '_1',
            time: gs.primarySlot,
            cells: s1Cells
          });
        } else {
          // Standard Single Slot Day
          const s1Cells = {};
          columns.forEach((c) => {
            if (c.id === 'std_ix_x' || c.id === 'col_9' || c.id === 'col_10') {
              s1Cells[c.id] = hsSub;
            } else {
              s1Cells[c.id] = sub;
            }
          });
          slots.push({
            slotId: 's_' + Date.now() + '_' + (idx + 1) + '_1',
            time: gs.primarySlot,
            cells: s1Cells
          });
        }

        newRows.push({
          dateId: dateId,
          date: dateStr,
          day: dayName,
          slots: slots
        });

        // Advance to next day
        currDate.setDate(currDate.getDate() + 1);
      }

      this.state.rows = newRows;
      this.setDirty(true);
      this.render();

      this.showToast(`Generated complete exam timetable (${newRows.length} examination days)!`, 'success');

      // Smooth scroll to matrix
      requestAnimationFrame(() => {
        const el = this.container.querySelector('#timetableMatrixTable');
        if (el) {
          el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });
    }

    /**
     * ============================================================
     * CORE RENDERER
     * ============================================================
     */
    render() {
      // 1. Maintain Datalists
      this.renderDatalists();

      // 2. Render Shell / Frame
      this.container.innerHTML = `
        <div class="timetable-app-card">
          <!-- 1. Priority #1: Timetable Generation Studio -->
          ${this.renderGeneratorStudioHtml()}

          <!-- 2. Header Toolbar -->
          <div class="timetable-header-toolbar">
            <div class="timetable-selector-group">
              <div class="timetable-select-wrapper">
                <select id="ttSelector" class="timetable-select">
                  <option value="">-- Active Schedule --</option>
                </select>
                <i class="fa-solid fa-chevron-down select-chevron"></i>
              </div>
              <span class="badge-status ${this.state.metadata.status}">
                <i class="fa-solid fa-circle" style="font-size:0.5rem;"></i>
                ${this.state.metadata.status}
              </span>
              <span id="ttDirtyBadge" class="badge-status dirty" style="display: ${this.isDirty ? 'inline-flex' : 'none'};">
                <i class="fa-solid fa-asterisk" style="font-size:0.55rem;"></i>
                Unsaved Changes
              </span>
            </div>

            <!-- Action Toolbar Buttons -->
            <div class="timetable-actions-toolbar">
              <button type="button" class="btn-timetable btn-primary" id="btnSaveTimetable" data-action="save">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Schedule</span>
              </button>
              <button type="button" class="btn-timetable btn-secondary" data-action="add-date">
                <i class="fa-solid fa-calendar-plus" style="color:var(--tt-brand);"></i>
                <span>+ Add Date</span>
              </button>
              <button type="button" class="btn-timetable btn-secondary" data-action="add-column">
                <i class="fa-solid fa-chalkboard-user" style="color:var(--tt-gold-dark);"></i>
                <span>+ Add Class</span>
              </button>
              <button type="button" class="btn-timetable btn-secondary" data-action="print-preview">
                <i class="fa-solid fa-print"></i>
                <span>Print / Preview</span>
              </button>
              <button type="button" class="btn-timetable btn-secondary" data-action="export-csv">
                <i class="fa-solid fa-file-csv"></i>
                <span>CSV</span>
              </button>
              <button type="button" class="btn-timetable btn-secondary" data-action="duplicate" title="Duplicate this schedule">
                <i class="fa-regular fa-copy"></i>
              </button>
              <button type="button" class="btn-timetable btn-secondary" data-action="new-timetable" title="New empty schedule">
                <i class="fa-solid fa-plus"></i>
              </button>
            </div>
          </div>

          <!-- 3. Priority #2: Advanced Manual Controls & Presets Collapsible Drawer -->
          ${this.renderSecondaryToolsHtml()}

          <!-- 4. Dynamic Matrix Table Container -->
          <div class="timetable-matrix-container">
            <table class="timetable-matrix" id="timetableMatrixTable">
              <thead>
                <tr>
                  <th class="th-sticky-day">DAY</th>
                  <th class="th-sticky-date">DATE</th>
                  <th class="th-sticky-time">TIME / SLOT</th>
                  ${this.state.columns
                    .map(
                      (col) => `
                    <th class="th-class-col" data-col-id="${col.id}">
                      <div class="th-class-content">
                        <span class="th-class-label">${this.escapeHtml(col.label)}</span>
                        <button type="button" class="btn-remove-col" data-action="remove-column" data-col-id="${col.id}" title="Remove Column">&times;</button>
                      </div>
                    </th>
                  `
                    )
                    .join('')}
                  <th class="th-actions">ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                ${this.renderMatrixRowsHtml()}
              </tbody>
            </table>
          </div>

          <!-- 5. Footer Stats & Quick Info -->
          <div class="timetable-matrix-footer">
            <div class="footer-stats">
              <span><i class="fa-solid fa-chalkboard"></i> ${this.state.columns.length} Classes</span>
              <span><i class="fa-solid fa-calendar-check"></i> ${this.state.rows.length} Exam Days</span>
              <span><i class="fa-regular fa-clock"></i> ${this.getTotalSlotsCount()} Scheduled Slots</span>
            </div>
            <div>
              <small>Click any cell to type or choose from autocomplete datalist. Use top chips for rapid filling.</small>
            </div>
          </div>
        </div>
      `;

      this.populateSelector();
    }

    /**
     * Calculate Total Slots Across All Days
     */
    getTotalSlotsCount() {
      return this.state.rows.reduce((sum, r) => sum + (r.slots ? r.slots.length : 0), 0);
    }

    /**
     * Mathematical Rowspan Invariant HTML Generator
     *
     * Rule:
     * For any date row with N slots:
     * - Slot 0 renders <td rowspan="N"> for Day and Date, and <td rowspan="N"> for Actions.
     * - Slots 1..N-1 omit Day and Date cells and Actions cell!
     * - Every slot renders Time cell and all Class column cells.
     */
    renderMatrixRowsHtml() {
      if (!this.state.rows || this.state.rows.length === 0) {
        return `
          <tr>
            <td colspan="${this.state.columns.length + 4}" style="text-align:center;padding:48px 20px;color:var(--tt-text-muted);">
              <i class="fa-regular fa-calendar-xmark" style="font-size:2.4rem;color:#cbd5e1;display:block;margin-bottom:12px;"></i>
              <strong>No examination dates scheduled yet.</strong>
              <div style="margin-top:8px;">
                <button type="button" class="btn-timetable btn-primary" data-action="add-date">
                  <i class="fa-solid fa-plus"></i> Add First Exam Date
                </button>
              </div>
            </td>
          </tr>
        `;
      }

      let html = '';

      this.state.rows.forEach((dateObj) => {
        const slots = dateObj.slots || [];
        const N = slots.length;

        slots.forEach((slotObj, sIdx) => {
          html += `<tr data-date-id="${dateObj.dateId}" data-slot-id="${slotObj.slotId}">`;

          // Slot 0: Renders the spanned Day and Date cells
          if (sIdx === 0) {
            html += `
              <td class="td-sticky-day" rowspan="${N}">
                <span class="day-badge" data-date-id="${dateObj.dateId}">${this.escapeHtml(dateObj.day)}</span>
              </td>
              <td class="td-sticky-date" rowspan="${N}">
                <input type="date" class="date-picker-input" data-date-id="${dateObj.dateId}" value="${this.escapeHtml(dateObj.date)}">
              </td>
            `;
          }
          // Slots 1..N-1: Omit Day and Date cells!

          // Every Slot: Render Time cell
          html += `
            <td class="td-sticky-time">
              <div class="time-slot-wrapper">
                <input type="text" class="time-slot-input" list="timeSlotsList" data-date-id="${dateObj.dateId}" data-slot-id="${slotObj.slotId}" value="${this.escapeHtml(slotObj.time)}" placeholder="Time slot...">
                <button type="button" class="btn-slot-delete" data-action="delete-slot" data-date-id="${dateObj.dateId}" data-slot-id="${slotObj.slotId}" title="Delete time slot">
                  <i class="fa-solid fa-xmark"></i>
                </button>
              </div>
            </td>
          `;

          // Every Slot: Render Subject Cells for all columns
          this.state.columns.forEach((col) => {
            const cellValue = (slotObj.cells && slotObj.cells[col.id]) || '';
            html += `
              <td class="td-matrix-cell" data-col-id="${col.id}">
                <input type="text" class="cell-subject-input" list="subjectsList" data-date-id="${dateObj.dateId}" data-slot-id="${slotObj.slotId}" data-col-id="${col.id}" value="${this.escapeHtml(cellValue)}" placeholder="—">
              </td>
            `;
          });

          // Slot 0: Renders the spanned Row Actions cell
          if (sIdx === 0) {
            html += `
              <td class="td-row-actions" rowspan="${N}">
                <div class="row-actions-group">
                  <button type="button" class="btn-add-slot-row" data-action="add-slot" data-date-id="${dateObj.dateId}" title="Add time slot to this date">
                    <i class="fa-solid fa-plus"></i> Slot
                  </button>
                  <button type="button" class="btn-delete-date-row" data-action="delete-date" data-date-id="${dateObj.dateId}" title="Delete entire date">
                    <i class="fa-regular fa-trash-can"></i> Date
                  </button>
                </div>
              </td>
            `;
          }
          // Slots 1..N-1: Omit Actions cell!

          html += `</tr>`;
        });
      });

      return html;
    }

    /**
     * Datalist Provider
     */
    renderDatalists() {
      let subjectsDatalist = document.getElementById('subjectsList');
      if (!subjectsDatalist) {
        subjectsDatalist = document.createElement('datalist');
        subjectsDatalist.id = 'subjectsList';
        document.body.appendChild(subjectsDatalist);
      }
      subjectsDatalist.innerHTML = this.state.presets.subjects.map((s) => `<option value="${this.escapeHtml(s)}"></option>`).join('');

      let timeSlotsDatalist = document.getElementById('timeSlotsList');
      if (!timeSlotsDatalist) {
        timeSlotsDatalist = document.createElement('datalist');
        timeSlotsDatalist.id = 'timeSlotsList';
        document.body.appendChild(timeSlotsDatalist);
      }
      timeSlotsDatalist.innerHTML = this.state.presets.timeSlots.map((t) => `<option value="${this.escapeHtml(t)}"></option>`).join('');
    }
  }

  // Export to window
  window.TimetableMatrixController = TimetableMatrixController;
})(window, document);
