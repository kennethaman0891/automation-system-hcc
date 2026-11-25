/* Unit Test importer — file validation banner, drag & drop, submit guards.
   Mirrors import-excel.js patterns; element IDs are page-specific. */
(function () {
    'use strict';

    var uploadForm = document.getElementById('uploadForm');
    var MAX_BYTES = parseInt((uploadForm && uploadForm.getAttribute('data-max-bytes')) || '', 10) || (40 * 1024 * 1024);
    var OK_EXT = ['xlsx', 'xls', 'csv'];

    var fileInput = document.getElementById('excelFileInput');
    var parseBtn = document.getElementById('parseBtn');
    var fileDrop = document.getElementById('fileDrop');
    var fileStatus = document.getElementById('fileStatus');

    function fmtSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    /* DOM-built banner — the filename is never injected via innerHTML. */
    function renderBanner(ok, name, size, message) {
        if (!fileStatus) return;
        fileStatus.textContent = '';
        fileStatus.hidden = false;

        var box = document.createElement('div');
        box.className = 'imp-file' + (ok ? '' : ' imp-file--error');

        var icon = document.createElement('i');
        icon.className = ok ? 'fas fa-file-excel' : 'fas fa-circle-exclamation';
        box.appendChild(icon);

        var body = document.createElement('div');
        var label = document.createElement('div');
        label.className = 'imp-file-name';
        label.textContent = ok ? name : message;
        body.appendChild(label);

        if (ok) {
            var meta = document.createElement('div');
            meta.className = 'imp-file-meta';
            meta.textContent = size + ' · ready to preview';
            body.appendChild(meta);
        }
        box.appendChild(body);

        if (ok) {
            var clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'btn btn-outline btn-sm imp-file__actions';
            clear.innerHTML = '<i class="fas fa-xmark"></i> Remove';
            clear.addEventListener('click', function (ev) {
                ev.preventDefault();
                fileInput.value = '';
                fileStatus.hidden = true;
                fileStatus.textContent = '';
                if (parseBtn) parseBtn.disabled = true;
            });
            box.appendChild(clear);
        }

        fileStatus.appendChild(box);
    }

    function validateFile(file) {
        var name = file.name || '';
        var ext = (name.split('.').pop() || '').toLowerCase();
        if (OK_EXT.indexOf(ext) === -1) {
            renderBanner(false, '', 0, '\u201C' + name + '\u201D is not a supported spreadsheet. Choose a .xlsx, .xls, or .csv file.');
            return false;
        }
        if (file.size === 0) {
            renderBanner(false, '', 0, 'That file is empty. Choose a file that contains your data.');
            return false;
        }
        if (file.size > MAX_BYTES) {
            renderBanner(false, '', 0, 'That file is ' + fmtSize(file.size) + ' — the limit is ' + fmtSize(MAX_BYTES) + '. Split it and try again.');
            return false;
        }
        renderBanner(true, name, fmtSize(file.size));
        return true;
    }

    function onFileSelected() {
        if (!fileInput.files || !fileInput.files.length) {
            if (fileStatus) { fileStatus.hidden = true; fileStatus.textContent = ''; }
            if (parseBtn) parseBtn.disabled = true;
            return;
        }
        if (parseBtn) parseBtn.disabled = !validateFile(fileInput.files[0]);
    }

    if (fileInput) {
        fileInput.addEventListener('change', onFileSelected);

        if (fileDrop) {
            ['dragenter', 'dragover'].forEach(function (ev) {
                fileDrop.addEventListener(ev, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    fileDrop.classList.add('dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                fileDrop.addEventListener(ev, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (ev === 'dragleave' && fileDrop.contains(e.relatedTarget)) return;
                    fileDrop.classList.remove('dragover');
                });
            });
            fileDrop.addEventListener('drop', function (e) {
                var dt = e.dataTransfer;
                if (!dt || !dt.files || !dt.files.length) return;
                if (dt.files.length > 1) {
                    renderBanner(false, '', 0, 'Multiple files were dropped. Import one file at a time.');
                    if (parseBtn) parseBtn.disabled = true;
                    return;
                }
                try {
                    fileInput.files = dt.files;
                } catch (err) {
                    fileInput.click();
                    return;
                }
                onFileSelected();
            });
        }

        var uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function (e) {
                if (!fileInput.files || !fileInput.files.length) {
                    e.preventDefault();
                    renderBanner(false, '', 0, 'Choose an Excel file before continuing.');
                    if (parseBtn) parseBtn.disabled = true;
                    return;
                }
                if (parseBtn) {
                    parseBtn.classList.add('is-busy');
                    parseBtn.innerHTML = '<i class="fas fa-spinner"></i> Reading workbook…';
                }
            });
        }
    }

    /* ── Confirm guards (single + batch) ── */
    function armConfirm(formId, btnId, busyLabel, confirmText) {
        var form = document.getElementById(formId);
        var btn = document.getElementById(btnId);
        if (!form || !btn) return;
        form.addEventListener('submit', function (e) {
            if (form.dataset.hccConfirmed === 'true') {
                delete form.dataset.hccConfirmed;
                return;
            }
            e.preventDefault();
            var count = btn.getAttribute('data-count') || '';
            var msg = confirmText(count);
            var confirmFn = window.hccConfirm || function (opts) {
                return Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message));
            };
            confirmFn({
                title: 'Confirm Unit Test Import',
                message: msg,
                confirmText: 'Import Now',
                type: 'primary'
            }).then(function (confirmed) {
                if (confirmed) {
                    form.dataset.hccConfirmed = 'true';
                    btn.classList.add('is-busy');
                    btn.innerHTML = '<i class="fas fa-spinner"></i> ' + busyLabel;
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            });
        });
    }

    armConfirm('confirmForm', 'confirmBtn', 'Importing…', function (count) {
        return 'Import ' + (count || 'the previewed') + ' student(s) into Unit Test results?';
    });
    armConfirm('batchForm', 'batchBtn', 'Importing…', function () {
        return 'Run the batch import for every detected class? Existing records will be overwritten where checked.';
    });

})();
