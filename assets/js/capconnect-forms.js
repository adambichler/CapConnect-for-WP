(function () {
    'use strict';
    var cfg = window.CAPCONNECT_CONFIG;
    if (!cfg || window.CAPCONNECT_FORMS) return;
    var states = new WeakMap();
    var loginTokens = new WeakMap();
    var selector = '.capconnect-programmatic-wrapper input[type="hidden"], .tpow-programmatic-wrapper input[type="hidden"]';

    /** Gets an isolated solver for the supplied form or checkout wrapper. */
    function stateFor(root) {
        var state = states.get(root);
        if (!state) {
            state = { cap: null, promise: null, barrier: null, generation: 0 };
            states.set(root, state);
        }
        return state;
    }

    /** Displays an accessible error inside the affected form only. */
    function showError(root, error) {
        var output = root.querySelector('.capconnect-error-message, .tpow-error-message');
        if (!output) {
            output = document.createElement('div');
            output.className = 'capconnect-error-message';
            output.setAttribute('role', 'alert');
            root.appendChild(output);
        }
        output.textContent = cfg.verificationFailedMsg;
        var wrapper = output.closest('[data-capconnect-wc][data-capconnect-mode="programmatic"]');
        if (wrapper) wrapper.classList.add('capconnect-has-error');
        output.style.display = 'block';
        ['capconnectVerificationFailed', 'tpowVerificationFailed'].forEach(function (name) {
            root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: { form: root, message: cfg.verificationFailedMsg, error: error } }));
        });
    }

    /** Applies a form-local token and returns recovered WooCommerce wrappers to invisible layout. */
    function applyToken(root, token) {
        root.querySelectorAll(selector).forEach(function (field) { field.value = token; });
        root.querySelectorAll('.capconnect-error-message, .tpow-error-message').forEach(function (output) {
            output.style.display = 'none';
            output.textContent = '';
            var wrapper = output.closest('[data-capconnect-wc][data-capconnect-mode="programmatic"]');
            if (wrapper) wrapper.classList.remove('capconnect-has-error');
        });
    }

    /** Solves once per root and leaves token expiry to the Cap widget's lifecycle. */
    function solve(root) {
        var state = stateFor(root);
        if (state.barrier) return state.barrier.then(function () { return solve(root); });
        var currentGeneration = state.generation;
        if (state.promise) return state.promise.then(function (result) {
            if (currentGeneration !== state.generation) throw new Error('Discarded captcha attempt');
            applyToken(root, result.token);
            return result;
        });
        if (!state.cap) {
            state.cap = new window.Cap({ apiEndpoint: cfg.apiEndpoint });
            state.cap.widget.style.setProperty('display', 'none', 'important');
            state.cap.widget.setAttribute('aria-hidden', 'true');
            state.cap.addEventListener('reset', function () {
                state.promise = null;
                root.querySelectorAll(selector).forEach(function (field) { field.value = ''; });
                root.dispatchEvent(new CustomEvent('capconnectTokenReset', { bubbles: true }));
            });
        }
        var generation = state.generation;
        state.promise = state.cap.solve().then(function (result) {
            if (generation !== state.generation) throw new Error('Discarded captcha attempt');
            applyToken(root, result.token);
            return result;
        }).catch(function (error) {
            state.promise = null;
            throw error;
        });
        return state.promise;
    }

    /** Clears consumed tokens before a subsequent attempt without sharing them across forms. */
    function reset(root) {
        var state = states.get(root);
        if (state) {
            state.generation++;
            var previous = state.barrier || state.promise;
            state.promise = null;
            if (state.cap) {
                // Cap does not start a second solve while one is running; reset only after it settles.
                var barrier = Promise.resolve(previous).catch(function () {}).then(function () { state.cap.reset(); });
                state.barrier = barrier;
                barrier.then(function () { if (state.barrier === barrier) state.barrier = null; });
            }
        }
        loginTokens.delete(root);
        root.querySelectorAll('cap-widget').forEach(function (widget) { widget.reset(); });
        root.querySelectorAll(selector + ', input[name="cap-token"]').forEach(function (field) { field.value = ''; });
    }

    /** Initializes new programmatic forms while leaving Block Checkout to its own lifecycle. */
    function initialize() {
        if (cfg.mode !== 'programmatic') return;
        document.querySelectorAll(selector).forEach(function (field) {
            var form = field.form || field.closest('form');
            if (!form || form.closest('.wc-block-checkout') || form.querySelector('[data-capconnect-wc="blocks"]') || states.has(form)) return;
            solve(form).catch(function (error) { showError(form, error); });
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches('form') || form.closest('.wc-block-checkout') || form.querySelector('[data-capconnect-wc="blocks"]')) return;
        var field = form.querySelector(selector);
        if (!field || field.value) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        var submitter = event.submitter;
        solve(form).then(function () {
            // A cached solution may settle before the browser finishes the original submit event.
            setTimeout(function () {
                if (!form.isConnected) return;
                if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                else form.submit();
            }, 0);
        }).catch(function (error) { showError(form, error); });
    }, true);

    document.addEventListener('solve', function (event) {
        var form = event.target.closest && event.target.closest('form');
        if (form && event.detail && event.detail.token) loginTokens.set(form, event.detail.token);
    });
    document.addEventListener('reset', function (event) {
        var form = event.target.closest && event.target.closest('form');
        if (form) loginTokens.delete(form);
    }, true);

    /** Preserves tokens within the original login form during third-party 2FA prompts. */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var token = loginTokens.get(form);
        if (!token || !form.matches('#loginform, form.login') || form.querySelector('input[name="cap-token"]')) return;
        var field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'cap-token';
        field.value = token;
        form.appendChild(field);
    }, true);

    /** Keeps solved widgets from covering a supported 2FA prompt. */
    function syncTwoFactor() {
        var hidden = !!document.querySelector('#wfls-prompt-overlay, #wfls-prompt-wrapper, #wfls-token, #simba_two_factor_auth, input[name="two_factor_code"]');
        document.querySelectorAll('#loginform .cap-widget-wrapper, form.login .cap-widget-wrapper').forEach(function (wrapper) {
            wrapper.hidden = hidden;
        });
    }

    window.CAPCONNECT_FORMS = { solve: solve, reset: reset, showError: showError };
    initialize();
    syncTwoFactor();
    new MutationObserver(function () { initialize(); syncTwoFactor(); }).observe(document.documentElement, { childList: true, subtree: true });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            document.querySelectorAll('form').forEach(reset);
            initialize();
        }
    });
})();
