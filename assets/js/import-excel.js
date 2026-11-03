(function () {
    'use strict';

    var MAX_BYTES = 5 * 1024 * 1024;
    var OK_EXT   = ['xlsx', 'xls'];

    var fileInput  = document.getElementById('excelFile');
    var parseBtn   = document.getElementById('parseBtn');
    var fileDrop   = document.getElementById('fileDrop');
    var fileStatus = document.getElementById('fileStatus');
    var classSelect = document.getElementById('classSelect');
    var confirmForm = document.getElementById('confirmForm');
    var confirmBtn  = document.getElementById('confirmBtn');
    var uploadForm  = document.getElementById('uploadForm');

    /* ── Confirm guard (preview screen) ──
       Runs independently: the preview screen has no file input,
       so nothing below may early-return before this. */
    if (confirmForm) {
        confirmForm.addEventListener('submit', function (e) {
            if (confirmForm.dataset.hccConfirmed === 'true') {
                delete confirmForm.dataset.hccConfirmed;
                return;
            }
            e.preventDefault();
            var count = confirmBtn ? (confirmBtn.getAttribute('data-count') || '0') : '0';
            var overwrite = document.getElementById('overwriteChk');
            var isOverwrite = overwrite && overwrite.checked;
            var msg = 'Import ' + count + ' student(s) into the database?';
            msg += isOverwrite
                ? ' Existing entries with matching roll numbers WILL be overwritten.'
                : ' Existing entries are kept; only new roll numbers are added.';

            var confirmFn = window.hccConfirm || function (opts) {
                return Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message));
            };

            confirmFn({
                title: 'Confirm Student Import',
                message: msg,
                confirmText: 'Import Students',
                type: isOverwrite ? 'warning' : 'primary'
            }).then(function (confirmed) {
                if (confirmed) {
                    confirmForm.dataset.hccConfirmed = 'true';
                    if (confirmBtn) {
                        confirmBtn.classList.add('is-busy');
                        confirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Importing…';
                    }
                    if (typeof confirmForm.requestSubmit === 'function') {
                        confirmForm.requestSubmit();
                    } else {
                        confirmForm.submit();
                    }
                }
            });
        });
    }

    /* Everything below is upload-screen only. */
    if (!fileInput || !parseBtn || !fileDrop || !fileStatus) return;

    /* ── Helpers ── */
    function fmtSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    /* Build the file banner with DOM APIs only — never inject the
       filename via innerHTML (a crafted name could inject markup). */
    function renderBanner(ok, name, size, message) {
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
                parseBtn.disabled = true;
            });
            box.appendChild(clear);
        }

        fileStatus.appendChild(box);
    }

    /* ── Validate + render the chosen file ── */
    function validateFile(file) {
        var name = file.name || '';
        var ext  = (name.split('.').pop() || '').toLowerCase();

        if (OK_EXT.indexOf(ext) === -1) {
            renderBanner(false, '', 0, '“' + name + '” is not an Excel file. Choose a .xlsx or .xls file.');
            return false;
        }
        if (file.size === 0) {
            renderBanner(false, '', 0, 'That file is empty. Choose a file that contains your data.');
            return false;
        }
        if (file.size > MAX_BYTES) {
            renderBanner(false, '', 0, 'That file is ' + fmtSize(file.size) + ' — the limit is 5 MB. Split it and try again.');
            return false;
        }
        renderBanner(true, name, fmtSize(file.size));
        return true;
    }

    function onFileSelected() {
        if (!fileInput.files || !fileInput.files.length) {
            fileStatus.hidden = true;
            fileStatus.textContent = '';
            parseBtn.disabled = true;
            return;
        }
        parseBtn.disabled = !validateFile(fileInput.files[0]);
    }

    fileInput.addEventListener('change', onFileSelected);

    /* ── Drag & drop ── */
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
            parseBtn.disabled = true;
            return;
        }
        try {
            fileInput.files = dt.files;
        } catch (err) {
            /* Older browsers block assigning FileList — fall back to a picker. */
            fileInput.click();
            return;
        }
        onFileSelected();
    });

    /* ── Class quick-fill ──
       Option values are raw "year|std|div|sem" (previously urlencoded,
       which broke the split and pre-filled a single garbled field). */
    if (classSelect) {
        classSelect.addEventListener('change', function () {
            var val = this.value;
            if (!val) return;
            var parts = val.split('|');
            var map = [['fYear', 0], ['fStd', 1], ['fDiv', 2], ['fSem', 3]];
            map.forEach(function (pair) {
                var el = document.getElementById(pair[0]);
                if (el) el.value = parts[pair[1]] || '';
            });
        });
    }

    /* ── Template download (Native direct GET download) ── */
    var templateLink = document.getElementById('templateLink');
    if (templateLink) {
        // Let native GET download link work without blocking or form injection
    }

    /* ── Guard the parse submit ── */
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (e) {
            if (!fileInput.files || !fileInput.files.length) {
                e.preventDefault();
                renderBanner(false, '', 0, 'Choose an Excel file before continuing.');
                parseBtn.disabled = true;
                return;
            }
            parseBtn.classList.add('is-busy');
            parseBtn.innerHTML = '<i class="fas fa-spinner"></i> Reading file…';
        });
    }

})();
