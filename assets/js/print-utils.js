/* Print / Download-PDF helper — reads filename from body[data-print-name]. */
(function () {
    'use strict';
    function namedPrint() {
        var base = document.body.dataset.printName || 'Document';
        var prev = document.title;
        document.title = base;
        window.print();
        setTimeout(function () { document.title = prev; }, 1000);
    }
    document.addEventListener('DOMContentLoaded', function () {
        ['printNowBtn', 'downloadPrintBtn'].forEach(function (id) {
            var b = document.getElementById(id);
            if (b) b.addEventListener('click', namedPrint);
        });
    });
})();
