{{--
    CORE-H-Z1-REMEDIATION-R3, ER-9 (WCAG 2.2.1 Timing Adjustable; data safety). The admin session ends after
    config('session.lifetime') minutes without a request (120 by default). Before R3 nothing warned the user, and
    a Save after the end was silent: Filament's Livewire error interceptor prevented Livewire's own "page expired"
    prompt and tried to show its error toast through another Livewire request, which failed with 419 as well.
    AdminPanelProvider now hides Filament's 419 toast (public hiddenErrorNotification(419) API) and this script
    takes over that one status. It never extends the session by itself and stores no form data.

      - Idle clock. The server renews the session on every request, so the page counts from its own last known
        server contact: page load and every Livewire response. Tabs share that moment through one timestamp in
        localStorage (tn-admin-session-activity, a number only), so activity in another tab postpones the warning
        here. If storage is unavailable, each tab counts on its own (it can then only warn too early, never late).
      - Warning. One timer (no polling) opens a modal <dialog role="alertdialog"> min(5 min, half the lifetime)
        before the end: what will happen and when, "Stay signed in" (focused) and "Close". "Stay signed in" is a
        user-initiated authenticated request to cms-session, which renews the session like any admin request.
        An open warning is closed only by the user, or by activity in another tab; a request of this page that
        succeeds meanwhile (e.g. a field's deferred update sent when focus moved into the warning) only updates
        the end time shown.
      - Ended / 419. At the end, or when any Livewire request returns 419 (expired, signed out or token rotated in
        another tab), the dialog says the session has ended and that the changes are still on this page. "Sign in
        in a new tab" opens the panel's sign-in page in a new tab; "Continue" asks cms-session whether this same
        account is signed in again and, if so, puts the new CSRF token where Livewire reads it, closes the dialog
        and returns focus, so the user can press Save again with the typed data intact. A different account, or
        no sign-in yet, gets a message and no token.
      - Focus. The dialog is modal (the page is inert), Escape closes it like "Close", and focus returns to the
        element that had it (or last had it, when its own loading state had disabled it, e.g. Save).
    Signed-in admin pages only (PanelsRenderHook::BODY_END).
--}}
@php
    $tnSession = [
        'lifetime' => max(1, (int) config('session.lifetime')) * 60,
        'statusUrl' => route('filament.admin.cms-session'),
        'loginUrl' => filament()->getLoginUrl(),
        'account' => (string) auth()->id(),
        'text' => [
            'warnTitle' => tn_trans('Your session is about to end'),
            'warnBody' => tn_trans('For your security, you will be signed out at :time after :minutes minutes without activity. Changes on this page that are not saved by then cannot be saved without signing in again.'),
            'stay' => tn_trans('Stay signed in'),
            'close' => tn_trans('Close'),
            'endTitle' => tn_trans('Your session has ended'),
            'endBody' => tn_trans('Your changes are still on this page but were not saved. Sign in again in a new tab, then come back here and select Continue. After that, save again.'),
            'signIn' => tn_trans('Sign in in a new tab'),
            'continue' => tn_trans('Continue'),
            'notYet' => tn_trans('You are not signed in yet. Sign in in the new tab, then select Continue.'),
            'otherAccount' => tn_trans('A different account is signed in. These changes cannot be saved with it. Sign out and sign in again with the original account, then select Continue.'),
            'failed' => tn_trans('The session could not be checked. Try again.'),
        ],
    ];
@endphp
<dialog id="tn-session-dialog" role="alertdialog" aria-labelledby="tn-session-title" aria-describedby="tn-session-body tn-session-status"
        style="max-width:32rem;width:calc(100% - 2rem);border-radius:0.75rem;padding:1.25rem;">
    <h2 id="tn-session-title" style="margin:0 0 .5rem;font-size:1.125rem;font-weight:600;line-height:1.5rem;"></h2>
    <p id="tn-session-body" style="margin:0 0 .75rem;font-size:.875rem;line-height:1.5rem;"></p>
    <p id="tn-session-status" role="status" style="margin:0 0 .75rem;font-size:.875rem;line-height:1.5rem;font-weight:500;"></p>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;justify-content:flex-end;">
        <a id="tn-session-signin" target="_blank" rel="noopener" style="align-self:center;margin-inline-end:auto;"></a>
        <button type="button" id="tn-session-close" class="tn-session-btn"></button>
        <button type="button" id="tn-session-primary" class="tn-session-btn tn-session-btn-primary"></button>
    </div>
</dialog>
<style>
    .tn-session-btn { border:1px solid var(--gray-500); border-radius:.5rem; padding:.375rem .75rem; font-size:.875rem; font-weight:500; line-height:1.25rem; background:transparent; color:inherit; cursor:pointer; }
    .tn-session-btn-primary { background:var(--primary-700); border-color:var(--primary-700); color:#fff; }
    /* The sign-in link: same AA colour and underline as Core's .tn-admin-link (A5), kept separate from page links. */
    #tn-session-signin { color:var(--primary-700); font-weight:500; text-decoration-line:underline; text-underline-offset:2px; font-size:.875rem; }
    .dark #tn-session-signin { color:var(--primary-400); }
    .tn-session-btn:focus-visible, #tn-session-signin:focus-visible { outline:2px solid var(--primary-700); outline-offset:2px; }
    /* Colours live here, not in the style attribute, so that the .dark rule below can replace them (an inline style would win). */
    #tn-session-dialog { border:1px solid var(--gray-500); background:var(--color-white,#fff); color:var(--gray-950,#030712); }
    #tn-session-dialog::backdrop { background:rgba(3,7,18,.5); }
    .dark #tn-session-dialog { background:var(--gray-900); color:#fff; border-color:var(--gray-400); }
    .dark .tn-session-btn { border-color:var(--gray-400); }
    .dark .tn-session-btn-primary { background:var(--primary-400); border-color:var(--primary-400); color:var(--gray-950); }
    .dark .tn-session-btn:focus-visible, .dark #tn-session-signin:focus-visible { outline-color:var(--primary-400); }
</style>
<script data-tn-admin-session-timeout>
(function () {
    if (window.__tnAdminSessionTimeout) {
        return;
    }
    window.__tnAdminSessionTimeout = true;

    var config = @js($tnSession);
    var KEY = 'tn-admin-session-activity';
    var dialog = document.getElementById('tn-session-dialog');
    if (! dialog || typeof dialog.showModal !== 'function') {
        return;
    }
    var title = document.getElementById('tn-session-title');
    var body = document.getElementById('tn-session-body');
    var status = document.getElementById('tn-session-status');
    var signIn = document.getElementById('tn-session-signin');
    var closeButton = document.getElementById('tn-session-close');
    var primary = document.getElementById('tn-session-primary');
    var lifetimeMs = config.lifetime * 1000;
    var warnMs = Math.min(300000, Math.floor(lifetimeMs / 2));
    var lastActivity = Date.now();
    var mode = null;
    var timer = null;
    var returnFocus = null;
    var busy = false;
    var dismissedAt = null;
    // The control that started a failing request is often disabled while it loads (Filament's
    // wire:loading), which moves focus to <body> before the 419 arrives; remember the last focused
    // page element so focus can still return to it.
    var lastFocused = null;
    document.addEventListener('focusin', function (event) {
        if (! dialog.contains(event.target)) {
            lastFocused = event.target;
        }
    }, true);

    var readShared = function () {
        try {
            var value = parseInt(window.localStorage.getItem(KEY) || '', 10);
            return isFinite(value) ? value : 0;
        } catch (e) {
            return 0;
        }
    };

    var schedule = function () {
        window.clearTimeout(timer);
        var now = Date.now();
        var ends = lastActivity + lifetimeMs;
        if (mode === 'ended') {
            return;
        }
        if (now >= ends) {
            open('ended');
            return;
        }
        var dismissed = dismissedAt === lastActivity;
        if (mode !== 'warn' && ! dismissed && now >= ends - warnMs) {
            open('warn');
        }
        var next = mode === 'warn' || dismissed ? ends : ends - warnMs;
        timer = window.setTimeout(function () {
            lastActivity = Math.max(lastActivity, readShared());
            schedule();
        }, Math.max(1000, next - now));
    };

    var touch = function (at) {
        lastActivity = Math.max(lastActivity, at);
        try {
            window.localStorage.setItem(KEY, String(lastActivity));
        } catch (e) {
            // Storage unavailable: this tab keeps its own clock.
        }
        if (mode === 'warn') {
            // This page's own request succeeded while the warning was open, e.g. a field's deferred update sent when
            // focus moved into the warning. The session was renewed, but the warning stays until the user answers;
            // it now shows the new end time.
            render();
        }
        schedule();
    };

    var render = function () {
        status.textContent = '';
        closeButton.textContent = config.text.close;
        if (mode === 'warn') {
            var at = new Date(lastActivity + lifetimeMs);
            title.textContent = config.text.warnTitle;
            body.textContent = config.text.warnBody
                .replace(':time', at.toLocaleTimeString(document.documentElement.lang || undefined, { hour: '2-digit', minute: '2-digit' }))
                .replace(':minutes', String(Math.round(config.lifetime / 60)));
            primary.textContent = config.text.stay;
            signIn.hidden = true;
        } else {
            title.textContent = config.text.endTitle;
            body.textContent = config.text.endBody;
            primary.textContent = config.text.continue;
            signIn.textContent = config.text.signIn;
            signIn.href = config.loginUrl;
            signIn.hidden = false;
        }
    };

    var open = function (next) {
        if (mode === 'ended' && next === 'warn') {
            return;
        }
        mode = next;
        render();
        // Moving focus into the dialog blurs the page field that had it. A field with an on-blur update (Filament
        // live(onBlur)) would then send a request, which renews the session: every warning would extend it and the
        // session would never end (§12 / §14). During this synchronous move only, that field's blur / focusout is
        // kept from the page; it keeps its value and sends it on the next real blur or with Save.
        var from = document.activeElement && document.activeElement !== document.body && ! dialog.contains(document.activeElement) ? document.activeElement : null;
        var keepFromPage = function (event) {
            if (event.target === from) {
                event.stopImmediatePropagation();
            }
        };
        window.addEventListener('blur', keepFromPage, true);
        window.addEventListener('focusout', keepFromPage, true);
        try {
            if (! dialog.open) {
                returnFocus = from || lastFocused;
                dialog.showModal();
            }
            primary.focus();
        } finally {
            window.removeEventListener('blur', keepFromPage, true);
            window.removeEventListener('focusout', keepFromPage, true);
        }
    };

    var hide = function () {
        if (dialog.open) {
            dialog.close();
        }
        var target = returnFocus;
        returnFocus = null;
        if (target && target.isConnected && typeof target.focus === 'function' && target !== document.body) {
            target.focus();
        }
    };

    var close = function () {
        mode = null;
        hide();
        schedule();
    };

    var setToken = function (token) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            meta.setAttribute('content', token);
        }
        var holders = document.querySelectorAll('[data-csrf]');
        for (var i = 0; i < holders.length; i++) {
            holders[i].setAttribute('data-csrf', token);
        }
        if (window.livewireScriptConfig && typeof window.livewireScriptConfig === 'object') {
            window.livewireScriptConfig.csrf = token;
        }
    };

    var check = function () {
        if (busy) {
            return;
        }
        busy = true;
        // Cleared first, so the same message is announced again on a repeated attempt.
        status.textContent = '';
        var url = config.statusUrl + (config.statusUrl.indexOf('?') === -1 ? '?' : '&') + 'for=' + encodeURIComponent(config.account);
        window.fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (data) {
                    return { response: response, data: data };
                });
            })
            .then(function (result) {
                busy = false;
                var data = result.data || {};
                if (result.response.ok && data.sameAccount === true && typeof data.token === 'string' && data.token !== '') {
                    setToken(data.token);
                    if (typeof data.lifetime === 'number' && data.lifetime > 0) {
                        lifetimeMs = data.lifetime * 1000;
                        warnMs = Math.min(300000, Math.floor(lifetimeMs / 2));
                    }
                    mode = null;
                    touch(Date.now());
                    close();
                    return;
                }
                if (mode !== 'ended') {
                    open('ended');
                }
                status.textContent = result.response.status === 409 ? config.text.otherAccount : (result.response.status === 401 || result.response.status === 419 ? config.text.notYet : config.text.failed);
            })
            .catch(function () {
                busy = false;
                status.textContent = config.text.failed;
            });
    };

    primary.addEventListener('click', check);
    // Close keeps the state: a dismissed warning is not shown again until there is new activity (the end is
    // still announced), and a dismissed "ended" dialog comes back on the next 419 (e.g. pressing Save again).
    closeButton.addEventListener('click', function () {
        if (mode === 'warn') {
            dismissedAt = lastActivity;
            close();
            return;
        }
        hide();
    });
    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
        closeButton.click();
    });

    window.addEventListener('storage', function (event) {
        if (event.key === KEY && event.newValue) {
            var value = parseInt(event.newValue, 10);
            if (isFinite(value) && value > lastActivity) {
                lastActivity = value;
                if (mode === 'warn') {
                    close();
                }
                schedule();
            }
        }
    });

    var hook = function (Livewire) {
        if (! Livewire || typeof Livewire.interceptRequest !== 'function') {
            return false;
        }
        Livewire.interceptRequest(function (request) {
            request.onSuccess(function () {
                touch(Date.now());
            });
            request.onError(function (error) {
                if (error && error.response && error.response.status === 419) {
                    error.preventDefault();
                    open('ended');
                }
            });
        });
        return true;
    };
    if (! hook(window.Livewire)) {
        document.addEventListener('livewire:init', function () {
            hook(window.Livewire);
        }, { once: true });
    }

    touch(Date.now());
})();
</script>
