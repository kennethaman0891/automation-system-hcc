/* Parent-signature tick / untick.
 * Sends POST to api_parent_sign.php with roll + class_key + signed state.
 * Reverts on failure and shows a brief error state.
 */
(function () {
    'use strict';

    function csrfToken() {
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function setBusy(t, on) {
        t.classList.toggle('is-busy', !!on);
    }

    function flashError(t) {
        t.classList.add('is-error');
        setTimeout(function () { t.classList.remove('is-error'); }, 1200);
    }

    function toggleSign(ev) {
        var t = ev.target;
        if (!t || !t.classList || !t.classList.contains('parent-sign-toggle')) return;

        var roll = t.dataset.roll;
        if (!roll) return;

        var classKey = t.dataset.class || '';
        var newState = t.checked ? 1 : 0;
        var prevState = newState ? 0 : 1;

        setBusy(t, true);

        var body = new URLSearchParams({
            csrf_token: csrfToken(),
            roll: roll,
            sign: String(newState)
        });
        if (classKey) body.append('class_key', classKey);

        var finish = function (ok, message) {
            setBusy(t, false);
            if (!ok) {
                // Revert to the previous state and surface the failure.
                t.checked = !!prevState;
                flashError(t);
                if (window.HCCToast && typeof window.HCCToast.error === 'function') {
                    window.HCCToast.error(message || 'Could not update parent signature');
                } else if (typeof window.showToast === 'function') {
                    window.showToast(message || 'Could not update parent signature', 'error');
                }
            }
        };

        fetch('api_parent_sign.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: body
        })
            .then(function (r) {
                return r.json().catch(function () {
                    return {ok: false, error: 'Server returned HTTP ' + r.status};
                });
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    finish(false, data && data.error);
                    return;
                }
                finish(true);
            })
            .catch(function (e) {
                finish(false, 'Network error: ' + (e && e.message ? e.message : 'unknown'));
            });
    }

    function boot() {
        document.addEventListener('change', toggleSign);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
