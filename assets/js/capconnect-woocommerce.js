(function () {
    'use strict';
    var cfg = window.CAPCONNECT_WC;
    var forms = window.CAPCONNECT_FORMS;
    if (!cfg || !forms || window.CAPCONNECT_WC_INITIALIZED) return;
    window.CAPCONNECT_WC_INITIALIZED = true;
    var blockRoot = null;
    var active = false;
    var blockToken = '';
    var lastPublished = null;
    var syncing = false;
    var pending = false;
    var blockGeneration = 0;

    /** Gets a registered WooCommerce data store without assuming Blocks are present. */
    function store(name, dispatch) {
        try { return window.wp.data[dispatch ? 'dispatch' : 'select']('wc/store/' + name); }
        catch (error) { return null; }
    }

    /** Publishes only changed tokens, avoiding recursive data-store subscriptions. */
    function publish(token) {
        blockToken = token;
        var checkout = store('checkout', true);
        var setter = checkout && (checkout.setExtensionData || checkout.__internalSetExtensionData);
        if (setter && lastPublished !== token) {
            lastPublished = token;
            setter.call(checkout, 'capconnect', { token: token });
        }
    }

    /** Reads the account choice from Core's data store, including required registrations. */
    function wantsAccount() {
        var checkout = store('checkout');
        return !!cfg.registrationRequired || !!(checkout && checkout.getShouldCreateAccount && checkout.getShouldCreateAccount());
    }

    /** Solves a Block Checkout challenge on demand and publishes its transient token. */
    function solveBlock() {
        if (cfg.mode !== 'programmatic' || !blockRoot || pending) return;
        pending = true;
        var generation = blockGeneration;
        var root = blockRoot;
        forms.solve(root).then(function (result) {
            if (active && generation === blockGeneration && root === blockRoot) publish(result.token);
        }).catch(function (error) {
            if (generation === blockGeneration && root === blockRoot) forms.showError(root, error);
        }).finally(function () { if (generation === blockGeneration) pending = false; });
    }

    /** Keeps conditional registration challenges and validation state aligned with checkout. */
    function syncBlocks() {
        if (syncing) return;
        syncing = true;
        try {
            var root = document.querySelector('[data-capconnect-wc="blocks"]');
            if (!root) return;
            if (root !== blockRoot) {
                blockRoot = root;
                blockGeneration++;
                pending = false;
                active = false;
                publish('');
            }
            var required = !!cfg.blocks || (!!cfg.registration && wantsAccount());
            blockRoot.hidden = !required;
            blockRoot.style.display = required ? '' : 'none';
            if (active !== required) {
                active = required;
                if (!active) {
                    blockGeneration++;
                    pending = false;
                    publish('');
                    forms.reset(blockRoot);
                }
            }
            var validation = store('validation', true);
            var validationState = store('validation');
            var error = validationState && validationState.getValidationError && validationState.getValidationError('capconnect');
            if (validation) {
                if (active && !blockToken && !error) {
                    validation.setValidationErrors({ capconnect: { message: window.CAPCONNECT_CONFIG.verificationFailedMsg, hidden: true } });
                } else if ((!active || blockToken) && error) {
                    validation.clearValidationError('capconnect');
                }
            }
            if (active && !blockToken) solveBlock();
        } finally { syncing = false; }
    }

    /** Moves inert markup into the React-rendered delayed-registration form. */
    function syncForms() {
        var template = document.getElementById('capconnect-wc-after-register');
        document.querySelectorAll('form.wc-block-order-confirmation-create-account-form').forEach(function (form) {
            if (template && !form.querySelector('[data-capconnect-wc="after_register"]')) {
                form.insertBefore(template.content.cloneNode(true), form.querySelector('button[type="submit"]'));
            }
        });
        document.querySelectorAll('[data-capconnect-wc="checkout_register"]').forEach(function (wrapper) {
            var checkbox = document.getElementById('createaccount');
            wrapper.hidden = !cfg.registrationRequired && !(checkbox && checkbox.checked);
            wrapper.style.display = wrapper.hidden ? 'none' : '';
        });
        syncBlocks();
    }

    document.addEventListener('solve', function (event) {
        if (blockRoot && blockRoot.contains(event.target) && event.detail) {
            publish(event.detail.token || '');
            syncBlocks();
        }
    });
    document.addEventListener('reset', function (event) {
        if (blockRoot && blockRoot.contains(event.target)) {
            publish('');
            syncBlocks();
        }
    }, true);
    document.addEventListener('capconnectTokenReset', function (event) {
        if (event.target === blockRoot) {
            publish('');
            queueMicrotask(syncBlocks);
        }
    });
    document.addEventListener('change', syncForms);

    /** Ends theme-provided submit spinners when Cap cancels a WooCommerce submission. */
    function resetFailedForm(event) {
        var root = event.detail && event.detail.form;
        if (!root || !root.closest) return;
        var form = root.matches('form') ? root : root.closest('form');
        if (!form || !form.querySelector('[data-capconnect-wc]')) return;
        form.querySelectorAll('button[type="submit"].loading, input[type="submit"].loading').forEach(function (button) {
            button.classList.remove('loading');
        });
    }
    document.addEventListener('capconnectVerificationFailed', resetFailedForm);

    /** Adds tokens to the precise request payload, including individual batch operations. */
    function protectRequest(request) {
        var path = request.path || request.url || '';
        if (!/\/wc\/store(?:\/v\d+)?\/checkout\/?(?:\?.*)?$/.test(path) || (request.method || 'GET').toUpperCase() !== 'POST') return Promise.resolve(request);
        var data = request.data || request.body || {};
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch (error) { return Promise.resolve(request); }
        }
        if (!cfg.blocks && !(cfg.registration && (data.create_account || cfg.registrationRequired))) return Promise.resolve(request);
        if (!blockRoot) return Promise.resolve(request); // The server rejects unprotected external/express flows.
        var token = cfg.mode === 'programmatic' ? forms.solve(blockRoot).then(function (result) { return result.token; }) : Promise.resolve(blockToken);
        return token.then(function (value) {
            var extensions = Object.assign({}, data.extensions, { capconnect: { token: value } });
            var payload = Object.assign({}, data, { extensions: extensions });
            var protectedRequest = Object.assign({}, request);
            if ('data' in request) protectedRequest.data = payload;
            else protectedRequest.body = typeof request.body === 'string' ? JSON.stringify(payload) : payload;
            return protectedRequest;
        });
    }

    if (window.wp.apiFetch) {
        window.wp.apiFetch.use(function (options, next) {
            var path = options.path || options.url || '';
            var checkout = /\/wc\/store(?:\/v\d+)?\/checkout\/?(?:\?.*)?$/.test(path) && (options.method || 'GET').toUpperCase() === 'POST';
            var data = options.data;
            var batch = /\/(?:wc\/store\/v\d+\/batch|batch\/v\d+)\/?(?:\?.*)?$/.test(path) && data && Array.isArray(data.requests);
            if (batch) batch = data.requests.some(function (request) {
                return /\/wc\/store(?:\/v\d+)?\/checkout\/?(?:\?.*)?$/.test(request.path || '') && (request.method || 'GET').toUpperCase() === 'POST';
            });
            if (!checkout && !batch) return next(options);
            var prepared = batch ? Promise.all(data.requests.map(protectRequest)).then(function (requests) {
                return Object.assign({}, options, { data: Object.assign({}, data, { requests: requests }) });
            }) : protectRequest(options);
            return prepared.then(function (request) { return next(request); }).finally(function () {
                if (blockRoot && (checkout || batch)) {
                    // Invalidate atomically: store subscriptions must not republish a consumed token.
                    syncing = true;
                    blockGeneration++;
                    pending = false;
                    try {
                        publish('');
                        forms.reset(blockRoot);
                    } finally { syncing = false; }
                    syncBlocks();
                }
            });
        });
    }

    if (window.jQuery) {
        window.jQuery(document.body).on('checkout_error', function () {
            var form = document.querySelector('form.checkout');
            if (form) {
                forms.reset(form);
                if (cfg.mode === 'programmatic') forms.solve(form).catch(function (error) { forms.showError(form, error); });
            }
        }).on('updated_checkout', function () {
            syncForms();
            var form = document.querySelector('form.checkout');
            if (form && cfg.mode === 'programmatic' && form.querySelector('.capconnect-programmatic-wrapper')) {
                forms.solve(form).catch(function (error) { forms.showError(form, error); });
            }
        });
    }
    window.wp.data.subscribe(syncBlocks);
    syncForms();
    new MutationObserver(syncForms).observe(document.documentElement, { childList: true, subtree: true });
})();
