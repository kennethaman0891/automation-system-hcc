/* ─────────────────────────────────────────────────────────────
   Shared clean print toolbar behaviour
   - View All  : fits the whole sheet to the viewport width
   - Download  : prints with a proper document filename (Save as PDF)
   - Print     : same, explicit
   Safe no-op if elements are absent.
   ───────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    function docName(fallback) {
        return (document.body && document.body.dataset.printName) || fallback || 'Document';
    }

    function namedPrint(fallback) {
        var base = docName(fallback);
        var prev = document.title;
        document.title = base;
        window.print();
        setTimeout(function () { document.title = prev; }, 1500);
    }

    /* Fit the widest table inside the scroll container to the viewport. */
    function fitToWidth(container, table) {
        if (!container || !table) return;
        document.body.classList.remove('ptb-fit');
        document.body.style.removeProperty('--ptb-zoom');
        table.style.zoom = '';

        var available = container.clientWidth - 2; // borders/padding slack
        var natural = table.scrollWidth;
        if (!available || !natural) return;

        var ratio = available / natural;
        if (ratio >= 1) return; // already fits — nothing to scale

        // Clamp so text never becomes unreadable.
        var zoom = Math.max(ratio, 0.55);
        document.body.style.setProperty('--ptb-zoom', zoom.toFixed(3));
        document.body.classList.add('ptb-fit');
    }

    function resetFit(container, table) {
        document.body.classList.remove('ptb-fit');
        document.body.style.removeProperty('--ptb-zoom');
        if (table) table.style.zoom = '';
        if (container) container.style.overflowX = '';
    }

    function boot() {
        var bar = document.querySelector('.ptb-bar');
        if (!bar) return;

        var printBtn = bar.querySelector('[data-ptb="print"]');
        var downloadBtn = bar.querySelector('[data-ptb="download"]');
        var viewAllBtn = bar.querySelector('[data-ptb="viewall"]');

        if (printBtn) {
            printBtn.addEventListener('click', function () { namedPrint(); });
        }
        if (downloadBtn) {
            downloadBtn.addEventListener('click', function () { namedPrint(); });
        }

        var scroll = document.querySelector('.ptb-scroll');
        var table = scroll ? scroll.querySelector('table') : null;

        if (viewAllBtn && scroll && table) {
            viewAllBtn.addEventListener('click', function () {
                var on = !document.body.classList.contains('ptb-fit');
                if (on) {
                    fitToWidth(scroll, table);
                    // If the table already fit, still reflect the toggled state.
                    if (!document.body.classList.contains('ptb-fit')) {
                        document.body.classList.add('ptb-fit');
                    }
                } else {
                    resetFit(scroll, table);
                }
                viewAllBtn.classList.toggle('is-active', document.body.classList.contains('ptb-fit'));
                viewAllBtn.setAttribute('aria-pressed', document.body.classList.contains('ptb-fit') ? 'true' : 'false');
            });

            // Keep the fit correct on resize / orientation change.
            var t;
            window.addEventListener('resize', function () {
                if (!document.body.classList.contains('ptb-fit')) return;
                clearTimeout(t);
                t = setTimeout(function () { fitToWidth(scroll, table); }, 120);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
