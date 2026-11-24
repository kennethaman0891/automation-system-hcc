/* HCC Admin Login — password toggle, caps-lock hint, submit loading state */
(function () {
    /* Drop transient redirect params (?err=, ?notice=, ?oauth_code=, ?google_not_configured)
       from the address bar so a refresh or bookmark never re-shows a stale notice. The
       notice that was just rendered stays on screen — only the URL is cleaned. */
    try {
        var url = new URL(window.location.href);
        ['err', 'notice', 'oauth_code', 'google_not_configured'].forEach(function (p) {
            url.searchParams.delete(p);
        });
        var clean = url.pathname + (url.search || '') + (url.hash || '');
        if (url.search !== window.location.search) {
            window.history.replaceState({}, '', clean);
        }
    } catch (e) { /* history API unavailable — harmless */ }

    var toggle = document.getElementById('pwToggle');
    var input = document.getElementById('password');
    var icon = document.getElementById('pwIcon');
    if (toggle && input) {
        toggle.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            if (icon) icon.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            input.focus();
        });
    }

    /* Caps Lock detection on the password field */
    var caps = document.getElementById('capsNote');
    if (input && caps) {
        var setCaps = function (e) {
            if (typeof e.getModifierState === 'function') {
                caps.hidden = !e.getModifierState('CapsLock');
            }
        };
        input.addEventListener('keydown', setCaps);
        input.addEventListener('keyup', setCaps);
        input.addEventListener('blur', function () { caps.hidden = true; });
    }

    /* Submit → loading state */
    var form = document.getElementById('loginForm');
    var btn = document.getElementById('loginBtn');
    if (form && btn && !btn.disabled) {
        form.addEventListener('submit', function () {
            btn.classList.add('loading');
            btn.disabled = true;
            var label = btn.querySelector('span');
            if (label) label.textContent = 'Signing in…';
        });
    }

    document.querySelectorAll('[data-toggle-password]').forEach(function (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var field = document.getElementById(toggleBtn.getAttribute('data-toggle-password'));
            if (!field) return;
            var show = field.type === 'password';
            field.type = show ? 'text' : 'password';
            var icon = toggleBtn.querySelector('i');
            if (icon) icon.className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            toggleBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            field.focus();
        });
    });

    var neu = document.getElementById('new_password');
    var bar = document.getElementById('strengthBar');
    var strengthLabel = document.getElementById('strengthLabel');
    if (neu && bar && strengthLabel) {
        neu.addEventListener('input', function () {
            var pwd = neu.value || '';
            if (!pwd) {
                bar.style.width = '0%';
                bar.style.backgroundColor = '#e2e8f0';
                strengthLabel.textContent = 'Password strength: Empty';
                strengthLabel.style.color = '#64748b';
                return;
            }
            var score = 0;
            if (pwd.length >= 8) score++;
            if (pwd.length >= 12) score++;
            if (/[0-9]/.test(pwd)) score++;
            if (/[^a-zA-Z0-9]/.test(pwd)) score++;
            if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) score++;
            if (score <= 1) {
                bar.style.width = '25%';
                bar.style.backgroundColor = '#ef4444';
                strengthLabel.textContent = 'Password strength: Weak';
                strengthLabel.style.color = '#ef4444';
            } else if (score <= 3) {
                bar.style.width = '60%';
                bar.style.backgroundColor = '#f59e0b';
                strengthLabel.textContent = 'Password strength: Medium';
                strengthLabel.style.color = '#f59e0b';
            } else {
                bar.style.width = '100%';
                bar.style.backgroundColor = '#10b981';
                strengthLabel.textContent = 'Password strength: Strong';
                strengthLabel.style.color = '#10b981';
            }
        });
    }

    ['forgotForm', 'verifyForm', 'resendForm', 'resetForm'].forEach(function (id) {
        var authForm = document.getElementById(id);
        if (!authForm) return;
        authForm.addEventListener('submit', function () {
            var submitBtn = authForm.querySelector('button[type="submit"]');
            if (!submitBtn || submitBtn.disabled) return;
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        });
    });
})();
