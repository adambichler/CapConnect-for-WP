(function () {
    'use strict';
    var cfg = window.CAPCONNECT_CONFIG;
    if (!cfg || window.CAPCONNECT_FORMS) return;
    var states = new WeakMap();
    var roots = new Set();
    var loginTokens = new WeakMap();
    var gravityRegistries = new WeakSet();
    var gravityRequests = new Map();
    var boundForminator = new WeakSet();
    var boundNativeForms = new WeakSet();
    var jqueryReady = false;
    var selector = '.capconnect-programmatic-wrapper input[type="hidden"], .tpow-programmatic-wrapper input[type="hidden"]';

    /** Marks lifecycle cancellations so detached or replaced forms do not display errors. */
    function discarded() {
        var error = new Error('Discarded captcha attempt');
        error.capconnectDiscarded = true;
        return error;
    }

    /** Detects replaced challenge fields without treating a DOM move as a new form. */
    function sameFields(root, state) {
        var fields = Array.from(root.querySelectorAll(selector + ', cap-widget'));
        return fields.length === state.fields.length && fields.every(function (field, index) { return field === state.fields[index]; });
    }

    /** Disposes a removed solver and prevents its pending result from reaching another instance. */
    function dispose(root) {
        var state = states.get(root);
        if (!state) return;
        state.generation++;
        state.disposed = true;
        gravityRequests.forEach(function (request, id) {
            if (request && request.form === root) gravityRequests.set(id, null);
        });
        states.delete(root);
        roots.delete(root);
        loginTokens.delete(root);
        if (state.cap) state.cap.widget.remove();
        root.querySelectorAll(selector).forEach(function (field) { field.value = ''; });
        root.dispatchEvent(new CustomEvent('capconnectTokenReset', { bubbles: true }));
    }

    /** Gets an isolated solver for the current form and challenge markup. */
    function stateFor(root) {
        var state = states.get(root);
        if (state && !sameFields(root, state)) {
            dispose(root);
            state = null;
        }
        if (!state) {
            state = { cap: null, promise: null, barrier: null, generation: 0, fields: Array.from(root.querySelectorAll(selector + ', cap-widget')), submitting: false, disposed: false };
            states.set(root, state);
            roots.add(root);
        }
        return state;
    }

    /** Places visible-widget errors before subsequent form controls, keeping floated buttons clear. */
    function showError(root, error) {
        if (!root.isConnected || (error && error.capconnectDiscarded)) return;
        var output = root.querySelector('.capconnect-error-message, .tpow-error-message');
        if (!output) {
            output = document.createElement('div');
            output.className = 'capconnect-error-message';
            output.setAttribute('role', 'alert');
            var widget = root.querySelector('cap-widget');
            var anchor = widget && (widget.closest('.cap-widget-wrapper') || widget);
            if (anchor && anchor !== root && root.contains(anchor)) anchor.after(output);
            else root.appendChild(output);
        }
        output.textContent = cfg.verificationFailedMsg;
        var wrapper = output.closest('[data-capconnect-wc][data-capconnect-mode="programmatic"]');
        if (wrapper) wrapper.classList.add('capconnect-has-error');
        output.style.display = 'block';
        ['capconnectVerificationFailed', 'tpowVerificationFailed'].forEach(function (name) {
            root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: { form: root, message: cfg.verificationFailedMsg, error: error } }));
        });
    }

    /** Clears form-local verification errors after a successful challenge. */
    function clearError(root) {
        root.querySelectorAll('.capconnect-error-message, .tpow-error-message').forEach(function (output) {
            output.style.display = 'none';
            output.textContent = '';
            var wrapper = output.closest('[data-capconnect-wc][data-capconnect-mode="programmatic"]');
            if (wrapper) wrapper.classList.remove('capconnect-has-error');
        });
    }

    /** Applies a form-local token and restores the invisible layout after recovery. */
    function applyToken(root, token) {
        root.querySelectorAll(selector).forEach(function (field) { field.value = token; });
        clearError(root);
    }

    /** Checks that an asynchronous result still belongs to the same live challenge. */
    function assertCurrent(root, state, generation) {
        if (!root.isConnected || state.disposed || state.generation !== generation || !sameFields(root, state)) throw discarded();
    }

    /** Solves once per root, normalizing synchronous initialization and solver failures. */
    function solve(root) {
        if (!root.isConnected) return Promise.reject(discarded());
        var state = stateFor(root);
        var generation = state.generation;
        if (state.barrier) return state.barrier.then(function () {
            assertCurrent(root, state, generation);
            return solve(root);
        });
        if (!state.promise) {
            state.promise = Promise.resolve().then(function () {
                assertCurrent(root, state, generation);
                if (!state.cap) {
                    state.cap = new window.Cap({ apiEndpoint: cfg.apiEndpoint, 'data-cap-disable-prefetch': '' });
                    state.cap.widget.style.setProperty('display', 'none', 'important');
                    state.cap.widget.setAttribute('aria-hidden', 'true');
                    state.cap.addEventListener('reset', function () {
                        if (state.disposed) return;
                        state.promise = null;
                        root.querySelectorAll(selector).forEach(function (field) { field.value = ''; });
                        root.dispatchEvent(new CustomEvent('capconnectTokenReset', { bubbles: true }));
                    });
                }
                return state.cap.solve();
            }).then(function (result) {
                assertCurrent(root, state, generation);
                if (!result || typeof result.token !== 'string' || !result.token) throw new Error('Missing captcha token');
                return result;
            }).catch(function (error) {
                if (state.disposed || state.generation !== generation || !root.isConnected || !sameFields(root, state)) throw discarded();
                if (state.generation === generation) state.promise = null;
                throw error;
            });
        }
        return state.promise.then(function (result) {
            assertCurrent(root, state, generation);
            applyToken(root, result.token);
            return result;
        });
    }

    /** Clears consumed tokens without starting another challenge or overlapping an active solver. */
    function reset(root) {
        var state = states.get(root);
        if (state) {
            state.generation++;
            state.submitting = false;
            state.gravitySubmitted = false;
            state.gravityPending = false;
            var previous = state.barrier || state.promise;
            state.promise = null;
            if (state.cap) {
                var barrier = Promise.resolve(previous).catch(function () {}).then(function () { if (!state.disposed) state.cap.reset(); });
                state.barrier = barrier;
                barrier.then(function () { if (state.barrier === barrier) state.barrier = null; });
            }
        }
        loginTokens.delete(root);
        root.querySelectorAll('cap-widget').forEach(function (widget) { widget.reset(); });
        root.querySelectorAll(selector + ', input[name="cap-token"]').forEach(function (field) { field.value = ''; });
    }

    /** Finds an enabled field while respecting conditional WooCommerce registration. */
    function activeField(form) {
        return Array.from(form.querySelectorAll(selector)).find(function (field) {
            var conditional = field.closest('[data-capconnect-wc="checkout_register"]');
            return !field.disabled && (!conditional || !conditional.hidden);
        });
    }

    /** Finds required visible challenges, excluding inactive pagination and conditional sections. */
    function activeWidgets(form) {
        return Array.from(form.querySelectorAll('cap-widget')).filter(function (widget) {
            return !widget.hasAttribute('disabled') && !widget.closest('[hidden]') && widget.getClientRects().length > 0;
        });
    }

    /** Focuses the widget's keyboard control, including its open shadow root. */
    function focusWidget(widget) {
        var control = widget.shadowRoot && widget.shadowRoot.querySelector('.captcha-trigger');
        if (!control) {
            if (!widget.hasAttribute('tabindex')) widget.setAttribute('tabindex', '-1');
            control = widget;
        }
        control.focus();
    }

    /** Blocks unconfirmed visible widgets without silently solving them. */
    function requireVisibleWidgets(form, event) {
        var widget = activeWidgets(form).find(function (item) { return !(item.tokenValue || item.token); });
        if (!widget) return true;
        if (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
        showError(form, new Error('Captcha verification required'));
        focusWidget(widget);
        return false;
    }

    /** Stops third-party click/Enter handlers before they can bypass the native submit event. */
    function gateVisibleInteraction(event) {
        if (!event.target.closest || event.target.closest('cap-widget')) return;
        var submit = event.type === 'click'
            ? event.target.closest('button:not([type]), button[type="submit"], input[type="submit"], input[type="image"]')
            : (event.key === 'Enter' && event.target.matches('input') ? event.target : null);
        // Forminator renders its pagination buttons without an explicit button type.
        if (submit && submit.matches('.forminator-button-back, .forminator-button-next, .gform_previous_button, .gform_next_button')) return;
        var form = submit && submit.closest('form');
        if (form && isGravityNavigation(form, submit)) return;
        if (form && !form.closest('.wc-block-checkout') && !form.querySelector('[data-capconnect-wc="blocks"]')) requireVisibleWidgets(form, event);
    }
    document.addEventListener('click', gateVisibleInteraction, true);
    document.addEventListener('keydown', gateVisibleInteraction, true);

    /** Keeps native constraint validation out of inactive steps while retaining required widgets. */
    function syncWidgetRequirements() {
        document.querySelectorAll('form cap-widget[required]').forEach(function (widget) {
            var inactive = !!widget.closest('[hidden]') || widget.getClientRects().length === 0;
            if (inactive && !widget.hasAttribute('disabled')) {
                widget.setAttribute('data-capconnect-inactive', '');
                widget.setAttribute('disabled', '');
            } else if (!inactive && widget.hasAttribute('data-capconnect-inactive')) {
                widget.removeAttribute('data-capconnect-inactive');
                widget.removeAttribute('disabled');
            }
        });
    }

    /** Adds a focused, announced error when native validation stops submission before submit fires. */
    document.addEventListener('invalid', function (event) {
        if (!event.target.matches('cap-widget')) return;
        var form = event.target.closest('form');
        if (form) {
            event.preventDefault();
            showError(form, new Error('Captcha verification required'));
            focusWidget(event.target);
        }
    }, true);

    /** Identifies protected plugin forms from their server-rendered integration marker. */
    function integration(form, name) {
        return !!form.querySelector('[data-capconnect-integration="' + name + '"]');
    }

    /** Recognizes Gravity Forms page changes before its previous-button handler updates the target page. */
    function isGravityNavigation(form, submitter, submissionType) {
        if (!integration(form, 'gravityforms')) return false;
        var type = submissionType || (submitter && submitter.dataset.submissionType);
        if (type) return type === 'previous' || type === 'next';
        if (submitter) {
            return submitter.matches('.gform_previous_button, .gform_next_button');
        }
        var id = gravityId(form);
        var target = id && form.querySelector('input[name="gform_target_page_number_' + id + '"]');
        return !!target && /^[1-9][0-9]*$/.test(target.value);
    }

    /** Starts a form-local challenge only after an interaction with that form. */
    function handleInteraction(event) {
        if (!event.isTrusted || !event.target.closest) return;
        if (event.target.closest('button:not([type]), button[type="submit"], input[type="submit"], input[type="image"]')) return;
        var form = event.target.closest('form');
        if (!form || form.closest('.wc-block-checkout') || form.querySelector('[data-capconnect-wc="blocks"]') || !activeField(form)) return;
        if (event.target.closest('.gform_previous_button, .gform_next_button, [data-submission-type="previous"], [data-submission-type="next"]')) return;
        solve(form).catch(function (error) { showError(form, error); });
    }
    ['pointerdown', 'keydown', 'input', 'change'].forEach(function (name) {
        document.addEventListener(name, handleInteraction, true);
    });

    /** Waits once and then resumes the exact original native or plugin submission. */
    function waitForSubmission(form, event, resume) {
        if (!requireVisibleWidgets(form, event)) return;
        var state = stateFor(form);
        if (!state.submitting && (!activeField(form) || activeField(form).value)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (state.submitting) return;
        state.submitting = true;
        var generation = state.generation;
        solve(form).then(function () {
            setTimeout(function () {
                try {
                    assertCurrent(form, state, generation);
                    state.submitting = false;
                    resume();
                } catch (error) { state.submitting = false; showError(form, error); }
            }, 0);
        }).catch(function (error) { state.submitting = false; showError(form, error); });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches('form') || form.closest('.wc-block-checkout') || form.querySelector('[data-capconnect-wc="blocks"]')) return;
        if (isGravityNavigation(form, event.submitter)) {
            clearError(form);
            return;
        }
        if (!requireVisibleWidgets(form, event)) return;
        if (integration(form, 'forminator') && jqueryReady) return;
        if (integration(form, 'gravityforms') && window.gform && gravityRegistries.has(window.gform)) return;
        if (!activeField(form)) return;
        var submitter = event.submitter;
        waitForSubmission(form, event, function () {
            if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            else HTMLFormElement.prototype.submit.call(form);
        });
    }, true);

    /** Preserves Forminator's event namespace and extra arguments across the asynchronous gate. */
    function gateForminator(event) {
        var form = event.target;
        if (!form.matches('form') || !integration(form, 'forminator')) return;
        var args = Array.prototype.slice.call(arguments, 1);
        var name = 'submit' + (event.namespace ? '.' + event.namespace : '');
        var original = event.originalEvent;
        var submitter = event.submitter || (original && original.submitter);
        waitForSubmission(form, event, function () {
            if (original && !form.noValidate && !(submitter && submitter.formNoValidate) && !form.reportValidity()) return;
            var replay = window.jQuery.Event(name, { originalEvent: original, submitter: submitter });
            window.jQuery(form).trigger(replay, args);
        });
    }

    /** Gates native/WooCommerce synthetic submissions and WooCommerce's synchronous pre-order hook. */
    function gateNativeSubmission(event) {
        var form = event.currentTarget;
        waitForSubmission(form, event, function () { window.jQuery(form).trigger('submit'); });
        if (event.isDefaultPrevented()) return false;
    }

    /** Resets only marked forms when their plugin reports a completed request. */
    function completeForminator(event) {
        var form = event.target;
        if (form.matches('form') && integration(form, 'forminator')) reset(form);
    }

    /** Resets a submitted Gravity Form after AJAX/iframe re-rendering, without solving again. */
    function renderedGravity(id) {
        var form = document.getElementById('gform_' + id);
        var state = form && states.get(form);
        if (state && state.gravitySubmitted) reset(form);
    }

    /** Identifies a Gravity form without trusting a later replacement with the same ID. */
    function gravityId(form) {
        return form.dataset.formid || (form.id.match(/^gform_(\d+)$/) || [])[1];
    }

    /** Resets only the exact form and challenge present when a request began. */
    function resetGravityRequest(request) {
        if (!request || !request.form.isConnected || states.get(request.form) !== request.state
            || request.state.generation !== request.generation || !sameFields(request.form, request.state)) return;
        reset(request.form);
    }

    /** Installs one async Gravity Forms gate per loaded API registry. */
    function registerGravity() {
        var gravity = window.gform;
        if (!gravity || gravityRegistries.has(gravity) || !gravity.utils || typeof gravity.utils.addAsyncFilter !== 'function') return;
        gravityRegistries.add(gravity);
        gravity.utils.addAsyncFilter('gform/submission/pre_submission', function (data) {
            var form = data.form;
            if (data.abort || !form || !integration(form, 'gravityforms')) return data;
            if (isGravityNavigation(form, null, data.submissionType)) {
                var previous = states.get(form);
                if (previous && previous.gravitySubmitted) reset(form);
                clearError(form);
                return data;
            }
            var state = stateFor(form);
            if (state.gravityPending) {
                data.abort = true;
                return data;
            }
            if (!activeField(form)) {
                if (state.gravitySubmitted) reset(form);
                if (!requireVisibleWidgets(form)) {
                    data.abort = true;
                    var submission = gravity.submission;
                    if (submission && submission.unlockSubmission) submission.unlockSubmission(form);
                    if (submission && submission.removeSpinner) submission.removeSpinner(form);
                    return data;
                }
                state.gravitySubmitted = true;
                return data;
            }
            // A previous request may have failed without dispatching its completion hook.
            if (state.gravitySubmitted) reset(form);
            state.gravityPending = true;
            return solve(form).then(function () {
                state.gravityPending = false;
                state.gravitySubmitted = true;
                return data;
            }).catch(function (error) {
                state.gravityPending = false;
                data.abort = true;
                var submission = gravity.submission;
                if (submission && submission.unlockSubmission) submission.unlockSubmission(form);
                if (submission && submission.removeSpinner) submission.removeSpinner(form);
                showError(form, error);
                return data;
            });
        });
        gravity.utils.addAsyncFilter('gform/submission/submission_started', function (data) {
            var form = data.form;
            if (!form || data.submissionMethod !== 'ajax' || !integration(form, 'gravityforms')) return data;
            var id = gravityId(form);
            var state = states.get(form);
            if (!id || !state) return data;
            // The error hook supplies only an ID, so concurrent requests cannot be matched safely.
            if (gravityRequests.has(String(id))) gravityRequests.set(String(id), null);
            else gravityRequests.set(String(id), { form: form, state: state, generation: state.generation });
            return data;
        });
        gravity.utils.addAsyncFilter('gform/ajax/post_submission_request', function (data) {
            var form = data.form;
            var id = form && gravityId(form);
            var request = id && gravityRequests.get(String(id));
            if (request && request.form === form) {
                gravityRequests.delete(String(id));
                resetGravityRequest(request);
            }
            return data;
        });
        gravity.utils.addAsyncFilter('gform/ajax/pre_display_error', function (data) {
            var id = String(data.formId);
            var request = gravityRequests.get(id);
            if (request) {
                gravityRequests.delete(id);
                resetGravityRequest(request);
            }
            return data;
        });
    }

    /** Binds existing and future Forminator forms before its delegated submit handler. */
    function syncIntegrations() {
        registerGravity();
        var $ = window.jQuery;
        if (!$ || !document.body) return;
        if (!jqueryReady) {
            jqueryReady = true;
            // Keep a distinct selector: Forminator removes its own delegated submit.frontSubmit handlers.
            $(document.body).on('submit.frontSubmit.capconnect', 'form.forminator-custom-form', gateForminator);
            $(document).on('after.load.forminator.capconnect', syncIntegrations);
            $(document).on('after:forminator:form:submit.capconnect', completeForminator);
            $(document).on('gform_post_render.capconnect', function (event, id) { renderedGravity(id); });
        }
        document.querySelectorAll('form.forminator-custom-form').forEach(function (form) {
            if (!boundForminator.has(form) && integration(form, 'forminator')) {
                boundForminator.add(form);
                $(form).on('submit.frontSubmit.capconnect', gateForminator);
            }
        });
        document.querySelectorAll('form').forEach(function (form) {
            if (!boundNativeForms.has(form) && !integration(form, 'forminator') && !integration(form, 'gravityforms')
                && !form.querySelector('[data-capconnect-wc="blocks"]') && form.querySelector(selector + ', cap-widget')) {
                boundNativeForms.add(form);
                $(form).on('submit.capconnect checkout_place_order.capconnect', gateNativeSubmission);
            }
        });
    }
    document.addEventListener('gform/theme/scripts_loaded', registerGravity);
    document.addEventListener('gform/post_render', function (event) { if (event.detail) renderedGravity(event.detail.formId); });
    document.addEventListener('DOMContentLoaded', syncIntegrations);

    document.addEventListener('solve', function (event) {
        var form = event.target.closest && event.target.closest('form');
        if (form && event.detail && event.detail.token) {
            loginTokens.set(form, event.detail.token);
            clearError(form);
            var state = states.get(form);
            if (state && integration(form, 'gravityforms')) {
                state.gravitySubmitted = false;
                clearError(form);
            }
        }
    });
    document.addEventListener('reset', function (event) {
        var form = event.target.closest && event.target.closest('form');
        if (form) {
            loginTokens.delete(form);
            syncTwoFactor();
            syncWidgetRequirements();
        }
    }, true);

    /** Preserves visible-widget tokens during third-party 2FA prompts in the original login form. */
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

    /** Hides a verified login challenge only for that form's own 2FA continuation. */
    function syncTwoFactor() {
        document.querySelectorAll('#loginform, form.login').forEach(function (form) {
            var verified = loginTokens.get(form) || (activeField(form) && activeField(form).value);
            var hidden = !!verified && !!form.querySelector('#wfls-prompt-overlay, #wfls-prompt-wrapper, #wfls-token, #simba_two_factor_auth, input[name="two_factor_code"]');
            form.querySelectorAll('.cap-widget-wrapper').forEach(function (wrapper) {
                if (wrapper.hidden !== hidden) wrapper.hidden = hidden;
            });
        });
    }

    /** Cleans detached/replaced roots without starting challenges on DOM mutations. */
    function syncLifecycle() {
        roots.forEach(function (root) {
            if (!root.isConnected || !sameFields(root, states.get(root))) dispose(root);
        });
        syncIntegrations();
        syncTwoFactor();
        syncWidgetRequirements();
    }

    window.CAPCONNECT_FORMS = { solve: solve, reset: reset, showError: showError };
    syncLifecycle();
    new MutationObserver(syncLifecycle).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'style', 'class'] });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            roots.forEach(reset);
            document.querySelectorAll('form').forEach(function (form) { if (!roots.has(form)) reset(form); });
        }
    });
})();
