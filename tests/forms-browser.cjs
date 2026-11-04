/** Integration regressions; external plugin files are read-only fixtures supplied through environment variables. */
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const { installMocks } = require('./woocommerce-browser.cjs');
const root = resolve(__dirname, '..');
const programmatic = '<div class="capconnect-programmatic-wrapper"><input type="hidden" name="cap-token"><div class="capconnect-error-message" role="alert" style="display:none"></div></div>';

/** Builds valid isolated form markup with the same integration markers as PHP. */
function formMarkup(id = 'f', integration = '') {
    return '<form id="' + id + '" class="forminator-custom-form forminator_ajax"><div class="forminator-response-message"></div><input name="email" value="adam@example.test"><div data-capconnect-integration="' + integration + '">' + programmatic + '</div><button type="submit" class="forminator-button-submit" name="send" value="yes">Senden</button></form>';
}

/** Models the public Gravity Forms hook contract, including request completion and form locking. */
function installGravity() {
    window.gravityFilters = {};
    window.unlocked = 0;
    window.spinnerRemoved = 0;
    window.gform = {
        utils: { addAsyncFilter(name, callback) { (window.gravityFilters[name] ||= []).push(callback); } },
        submission: {
            unlockSubmission() { window.unlocked++; },
            removeSpinner() { window.spinnerRemoved++; }
        }
    };
    window.runGravity = async (name, data) => {
        for (const callback of window.gravityFilters[name] || []) data = await callback(data);
        return data;
    };
}

/** Exercises actual Forminator dispatch and deterministic checkout/form lifecycles without persistent data. */
async function main() {
    const jqueryPath = process.env.CAPCONNECT_TEST_JQUERY;
    const forminatorPath = process.env.CAPCONNECT_TEST_FORMINATOR;
    if (!jqueryPath || !forminatorPath) throw new Error('CAPCONNECT_TEST_JQUERY und CAPCONNECT_TEST_FORMINATOR (front.submit.js) müssen gesetzt sein.');
    const jquery = readFileSync(jqueryPath, 'utf8');
    if (jquery.length < 1000 || !jquery.includes('jQuery')) throw new Error('Die jQuery-Testdatei ist leer oder unvollständig.');
    if (!readFileSync(forminatorPath, 'utf8').includes('forminatorFrontSubmit')) throw new Error('Das Originalskript von Forminator fehlt oder ist unvollständig.');
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('http://capconnect.test/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head></head><body></body></html>' }));

        /** Preloads the shared runtime on a page without an initial form. */
        async function setup(options = {}, gravityEarly = false) {
            await page.goto('http://capconnect.test/');
            await page.evaluate(installMocks, options);
            await page.addScriptTag({ path: jqueryPath });
            if (gravityEarly) await page.evaluate(installGravity);
            await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-forms.js') });
        }

        /** Installs only a fake transport; all submit handling comes from the original Forminator script. */
        async function loadForminator() {
            await page.evaluate(() => {
                window.ForminatorFront = { ajaxUrl: '/mock-forminator', cform: { error: 'Fehler' } };
                window.pluginRequests = [];
                window.submitArguments = [];
                window.nativeSubmitters = [];
                jQuery(document.body).on('submit.frontSubmit.audit', 'form.forminator-custom-form', function (event, extra) {
                    window.submitArguments.push({ namespace: event.namespace, extra: extra || null });
                    const submitter = event.submitter || (event.originalEvent && event.originalEvent.submitter);
                    if (submitter) window.nativeSubmitters.push(submitter.name);
                });
                jQuery.ajax = options => {
                    const deferred = jQuery.Deferred();
                    if (options.beforeSend) options.beforeSend();
                    window.pluginRequests.push(Object.fromEntries(options.data));
                    // A failed transport still executes Forminator's original .always cleanup and completion event.
                    setTimeout(() => deferred.reject({ status: 422 }), 10);
                    return deferred.promise();
                };
            });
            await page.addScriptTag({ path: forminatorPath });
        }

        for (const method of ['native', 'submit', 'submit.frontSubmit']) {
            await setup();
            await loadForminator();
            assert.equal(await page.evaluate(() => solveAttempts), 0);
            await page.evaluate(({ markup, method }) => {
                document.body.insertAdjacentHTML('beforeend', markup);
                jQuery('#f').forminatorFrontSubmit({ forminator_selector: '#f', form_type: 'custom-form' });
                window.solveDelay = 40;
                if (method === 'native') document.getElementById('f').requestSubmit(document.querySelector('#f button'));
                else {
                    jQuery('#f').trigger(method, ['forminator:submit:paypal']);
                    jQuery('#f').trigger(method, ['forminator:submit:paypal']);
                }
            }, { markup: formMarkup('f', 'forminator'), method });
            await page.waitForFunction(() => pluginRequests.length === 1 && !document.querySelector('#f button').disabled);
            assert.match(await page.evaluate(() => pluginRequests[0]['cap-token']), /^token-/);
            assert.equal(await page.evaluate(() => solveAttempts), 1);
            assert.equal(await page.locator('#f input[name="cap-token"]').inputValue(), '');
            if (method !== 'native') {
                assert.deepEqual(await page.evaluate(() => submitArguments), [{ namespace: method.includes('.') ? 'frontSubmit' : '', extra: 'forminator:submit:paypal' }]);
            }
            else assert.deepEqual(await page.evaluate(() => nativeSubmitters), ['send']);
            const first = await page.evaluate(() => pluginRequests[0]['cap-token']);
            await page.evaluate(() => { window.solveDelay = 0; jQuery('#f').trigger('submit.frontSubmit', ['onSubmitAuthenticationBox']); });
            await page.waitForFunction(() => pluginRequests.length === 2 && !document.querySelector('#f button').disabled);
            assert.notEqual(await page.evaluate(() => pluginRequests[1]['cap-token']), first);
            assert.equal(await page.evaluate(() => submitArguments.at(-1).extra), 'onSubmitAuthenticationBox');
        }
        console.log('Passed original Forminator native/synthetic/namespaced dispatch, same-stack insertion, duplicate blocking, preserved arguments and fresh retry tokens.');

        await setup();
        await loadForminator();
        await page.evaluate(markup => {
            document.body.insertAdjacentHTML('beforeend', markup);
            jQuery('#f').forminatorFrontSubmit({ forminator_selector: '#f' });
            window.failConstructor = true;
            jQuery('#f').trigger('submit.frontSubmit');
        }, formMarkup('f', 'forminator'));
        await page.waitForFunction(() => document.querySelector('#f [role="alert"]').textContent);
        assert.equal(await page.evaluate(() => pluginRequests.length), 0);
        assert.equal(await page.locator('#f button').isEnabled(), true);
        await page.evaluate(() => { window.failConstructor = false; window.failSolve = true; jQuery('#f').trigger('submit.frontSubmit'); });
        await page.waitForFunction(() => solveAttempts === 1);
        await page.waitForTimeout(30);
        assert.equal(await page.evaluate(() => pluginRequests.length), 0);
        await page.evaluate(() => { window.failSolve = false; jQuery('#f').trigger('submit.frontSubmit'); });
        await page.waitForFunction(() => pluginRequests.length === 1 && !document.querySelector('#f button').disabled);
        assert.equal(await page.locator('#f [role="alert"]').isVisible(), false);
        console.log('Passed synchronous initialization errors, solver failure, no unsolicited retry, unlocked Forminator and successful recovery.');

        await setup();
        await loadForminator();
        await page.evaluate(markup => {
            document.body.insertAdjacentHTML('beforeend', markup);
            jQuery('#f').forminatorFrontSubmit({ forminator_selector: '#f' });
            const email = document.querySelector('#f input[name="email"]');
            email.type = 'email'; email.required = true;
            window.solveDelay = 30;
            document.getElementById('f').requestSubmit(document.querySelector('#f button'));
            email.value = 'ungültig';
        }, formMarkup('f', 'forminator'));
        await page.waitForFunction(() => document.querySelector('#f input[name="cap-token"]').value);
        await page.waitForTimeout(50);
        assert.equal(await page.evaluate(() => pluginRequests.length), 0);
        await page.evaluate(() => {
            document.querySelector('#f input[name="email"]').value = 'adam@example.test';
            document.getElementById('f').requestSubmit(document.querySelector('#f button'));
        });
        await page.waitForFunction(() => pluginRequests.length === 1);
        assert.equal(await page.evaluate(() => solveAttempts), 1);
        console.log('Passed native validation after fields change during the asynchronous Forminator gate.');

        await setup();
        await loadForminator();
        let postbackPayload;
        await page.route('http://capconnect.test/forminator-postback', route => {
            postbackPayload = Object.fromEntries(new URLSearchParams(route.request().postData()));
            return route.fulfill({ contentType: 'text/html', body: '<!doctype html><p>Empfangen</p>' });
        });
        await page.evaluate(markup => {
            document.body.insertAdjacentHTML('beforeend', markup);
            const form = document.getElementById('f');
            form.classList.remove('forminator_ajax');
            form.method = 'post'; form.action = '/forminator-postback';
            jQuery(form).forminatorFrontSubmit({ forminator_selector: '#f' });
        }, formMarkup('f', 'forminator'));
        await Promise.all([page.waitForURL('http://capconnect.test/forminator-postback'), page.locator('#f button').click()]);
        assert.match(postbackPayload['cap-token'], /^token-/);
        assert.equal(postbackPayload.email, 'adam@example.test');

        await setup({ mode: 'widget' });
        await loadForminator();
        await page.evaluate(markup => {
            document.body.insertAdjacentHTML('beforeend', markup);
            jQuery('#f').forminatorFrontSubmit({ forminator_selector: '#f' });
            document.querySelector('#f cap-widget').addEventListener('solve', event => {
                document.querySelector('#f input[name="cap-token"]').value = event.detail.token;
            });
        }, formMarkup('f', 'forminator').replace(programmatic, '<cap-widget></cap-widget><input type="hidden" name="cap-token">'));
        assert.equal(await page.evaluate(() => solveAttempts), 0);
        for (const name of ['submit', 'submit.frontSubmit']) {
            await page.evaluate(name => jQuery('#f').trigger(name), name);
            assert.equal(await page.evaluate(() => pluginRequests.length), 0, 'Unconfirmed Forminator widgets cannot send synthetic submissions');
            assert.equal(await page.evaluate(() => solveAttempts), 0, 'Visible submission never automatically solves');
        }
        await page.evaluate(async () => { await document.querySelector('#f cap-widget').solve(); jQuery('#f').trigger('submit.frontSubmit'); });
        await page.waitForFunction(() => pluginRequests.length === 1 && !document.querySelector('#f button').disabled);
        assert.match(await page.evaluate(() => pluginRequests[0]['cap-token']), /^token-/);
        assert.equal(await page.locator('#f cap-widget').evaluate(widget => widget.token), '');
        assert.equal(await page.evaluate(() => solveAttempts), 1);
        console.log('Passed original Forminator postback and visible-widget AJAX reset without automatic solving.');

        for (const mode of ['widget', 'programmatic']) {
            await setup({ mode });
            await loadForminator();
            const args = [resolve(__dirname, 'woocommerce.php'), '--forminator-pagination-html'];
            if (mode === 'programmatic') args.push('--programmatic');
            const challenge = execFileSync('php', args, { encoding: 'utf8' });
            await page.addScriptTag({ path: resolve(forminatorPath, '..', 'front.pagination.js') });
            await page.addStyleTag({ content: 'cap-widget { display: block; width: 100%; height: 58px; }' });
            await page.evaluate(({ challenge, mode }) => {
                ForminatorFront.cform.pagination_next = 'Weiter';
                ForminatorFront.cform.pagination_prev = 'Zurück';
                window.Forminator_Cform_Paginations = { 1: { 'pagination-labels': 'default', 'has-paypal': false } };
                document.body.insertAdjacentHTML('beforeend', '<form id="f" class="forminator-custom-form forminator_ajax"><div class="forminator-response-message"></div><input name="form_id" value="1" type="hidden"><div class="forminator-pagination" data-step="0"><div class="forminator-pagination--content"><input name="email" value="test@example.test"></div></div><div class="forminator-pagination" data-step="1" hidden><div class="forminator-pagination--content"><input name="message" value="Test"></div>' + challenge + '</div></form>');
                if (mode === 'widget') {
                    const widget = document.querySelector('#f cap-widget');
                    const token = document.createElement('input');
                    token.type = 'hidden'; token.name = 'cap-token';
                    widget.after(token);
                    widget.addEventListener('solve', event => { token.value = event.detail.token; });
                    widget.addEventListener('reset', () => { token.value = ''; });
                }
                jQuery('#f').forminatorFrontSubmit({ forminator_selector: '#f', form_type: 'custom-form' });
                jQuery('#f').forminatorFrontPagination({ totalSteps: 2, step: 0 });
            }, { challenge, mode });
            assert.equal(await page.locator('#f [data-step="1"]').isVisible(), false);
            await page.locator('#f .forminator-button-next').click();
            await page.waitForFunction(() => !document.querySelector('#f [data-step="1"]').hidden && !!document.querySelector('#f .forminator-pagination-footer .forminator-button-submit'));
            assert.equal(await page.locator('#f [data-step="1"]').isVisible(), true);
            if (mode === 'widget') assert.equal(await page.locator('#f cap-widget').isVisible(), true);
            await page.locator('#f .forminator-button-back').click();
            await page.waitForFunction(() => !document.querySelector('#f [data-step="0"]').hidden);
            assert.equal(await page.locator('#f [data-step="1"]').isVisible(), false);
            await page.locator('#f .forminator-button-next').click();
            await page.waitForFunction(() => !document.querySelector('#f [data-step="1"]').hidden && !!document.querySelector('#f .forminator-pagination-footer .forminator-button-submit'));
            assert.equal(await page.evaluate(() => pluginRequests.length), 0);
            if (mode === 'widget') await page.evaluate(() => document.querySelector('#f cap-widget').solve());
            await page.locator('#f .forminator-pagination-footer .forminator-button-submit').click();
            await page.waitForFunction(() => pluginRequests.length === 1);
            assert.match(await page.evaluate(() => pluginRequests[0]['cap-token']), /^token-/);
            await page.waitForFunction(() => !document.querySelector('#f input[name="cap-token"]').value);
            assert.equal(await page.evaluate(() => solveAttempts), 1);
        }
        console.log('Passed original Forminator pagination, final-page widget, back/next and protected submission in both modes.');

        for (const mode of ['widget', 'programmatic']) {
            await setup({ mode }, true);
            await page.addStyleTag({ path: resolve(root, 'assets/css/capconnect-widget.css') });
            const args = [resolve(__dirname, 'woocommerce.php'), '--gravity-html'];
            if (mode === 'programmatic') args.push('--programmatic');
            const challenge = execFileSync('php', args, { encoding: 'utf8' });
            await page.evaluate(challenge => {
                document.body.innerHTML = '<div class="gform-theme--framework"><form id="gform_1" data-formid="1" novalidate><input type="hidden" name="gform_source_page_number_1" value="2"><input type="hidden" name="gform_target_page_number_1" value="0"><button type="button" class="gform_previous_button" data-submission-type="previous">Zurück</button>' + challenge + '</form></div>';
                window.navigations = 0;
                document.querySelector('.gform_previous_button').addEventListener('click', async () => {
                    const data = await runGravity('gform/submission/pre_submission', { form: document.getElementById('gform_1'), submissionType: 'previous' });
                    if (!data.abort) window.navigations++;
                });
            }, challenge);
            // Load the reset after CAP styles, matching Gravity Forms' actual frontend asset order.
            await page.addStyleTag(process.env.CAPCONNECT_TEST_GRAVITYFORMS
                ? { path: resolve(process.env.CAPCONNECT_TEST_GRAVITYFORMS, 'assets/css/dist/gravity-forms-theme-reset.min.css') }
                : { content: '.gform-theme--framework :where(:not(.gform-theme__no-reset--el):not(.gform-theme__no-reset--children *)){all:unset;display:revert}' });
            await page.locator('.gform_previous_button').click();
            await page.waitForFunction(() => navigations === 1);
            await page.locator('.gform_previous_button').press('Enter');
            await page.waitForFunction(() => navigations === 2);
            assert.equal(await page.evaluate(() => solveAttempts), 0, 'Back navigation cannot start a hidden challenge');
            const next = await page.evaluate(async () => runGravity('gform/submission/pre_submission', { form: document.getElementById('gform_1'), submissionType: 'next' }));
            assert.equal(!!next.abort, false);
            assert.equal(await page.evaluate(() => solveAttempts), 0, 'Forward navigation cannot start a hidden challenge');
            const blocked = await page.evaluate(async () => {
                window.failSolve = true;
                return !!(await runGravity('gform/submission/pre_submission', { form: document.getElementById('gform_1'), submissionType: 'submit' })).abort;
            });
            assert.equal(blocked, true, 'Final submission remains protected');
            const error = page.locator('[data-capconnect-integration="gravityforms"] .capconnect-error-message');
            assert.equal(await error.isVisible(), true);
            assert.deepEqual(await error.evaluate(node => ({ border: getComputedStyle(node).borderTopWidth, color: getComputedStyle(node).color, background: getComputedStyle(node).backgroundColor })), { border: '1px', color: 'rgb(185, 28, 28)', background: 'rgb(254, 242, 242)' });
            const before = await page.evaluate(() => solveAttempts);
            await page.locator('.gform_previous_button').click();
            await page.waitForFunction(() => navigations === 3);
            assert.equal(await error.isVisible(), false, 'Navigation clears the obsolete CAP error');
            assert.equal(await page.evaluate(() => solveAttempts), before);
            const legacy = await page.evaluate(() => {
                const form = document.getElementById('gform_1');
                form.querySelector('[name="gform_target_page_number_1"]').value = '1';
                let received = false;
                form.addEventListener('submit', event => { event.preventDefault(); received = true; }, { once: true });
                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                return received;
            });
            assert.equal(legacy, true, 'Legacy page-target submissions bypass CAP navigation checks');
        }
        console.log('Passed Gravity previous/next navigation by mouse/keyboard, legacy targets, styled PHP-rendered errors and protected final submissions in both modes.');

        for (const early of [false, true]) {
            await setup({}, early);
            if (!early) {
                await page.evaluate(installGravity);
                await page.evaluate(() => document.dispatchEvent(new Event('gform/theme/scripts_loaded')));
            }
            await page.evaluate(() => document.dispatchEvent(new Event('gform/theme/scripts_loaded')));
            assert.equal(await page.evaluate(() => gravityFilters['gform/submission/pre_submission'].length), 1);
            await page.evaluate(markup => document.body.insertAdjacentHTML('beforeend', markup), formMarkup('gform_1', 'gravityforms'));
            if (early) await page.evaluate(() => { document.getElementById('gform_1').dataset.formid = '1'; });
            for (const method of ['postback', 'iframe', 'ajax']) {
                const result = await page.evaluate(async method => {
                    const form = document.getElementById('gform_1');
                    window.solveDelay = 25;
                    const before = window.solveAttempts;
                    const data = await runGravity('gform/submission/pre_submission', { form, submissionMethod: method, submissionType: 'submit' });
                    const token = form.querySelector('input[name="cap-token"]').value;
                    if (method === 'ajax') {
                        await runGravity('gform/submission/submission_started', { form, submissionMethod: 'ajax' });
                        await runGravity('gform/ajax/post_submission_request', { form, submissionResult: { success: true } });
                    }
                    else if (method === 'iframe') jQuery(document).trigger('gform_post_render', [1, 2]);
                    else document.dispatchEvent(new CustomEvent('gform/post_render', { detail: { formId: 1, currentPage: 2 } }));
                    return { token, abort: !!data.abort, attempts: solveAttempts - before, cleared: !form.querySelector('input[name="cap-token"]').value };
                }, method);
                assert.match(result.token, /^token-/);
                assert.deepEqual({ ...result, token: undefined }, { token: undefined, abort: false, attempts: 1, cleared: true });
            }
            await page.evaluate(() => { window.failSolve = true; });
            const failed = await page.evaluate(async () => {
                const data = await runGravity('gform/submission/pre_submission', { form: document.getElementById('gform_1') });
                return { abort: data.abort, unlocked, spinnerRemoved };
            });
            assert.deepEqual(failed, { abort: true, unlocked: 1, spinnerRemoved: 1 });
            await page.evaluate(() => { window.failSolve = false; });
            assert.equal(await page.evaluate(async () => !!(await runGravity('gform/submission/pre_submission', { form: document.getElementById('gform_1'), submissionType: 'submit' })).abort), false);
        }
        console.log('Passed early/late Gravity Forms registries, single registration, postback/iframe/AJAX contracts, pagination reset, abort/unlock and retry.');

        await setup({}, true);
        await page.evaluate(markup => document.body.insertAdjacentHTML('beforeend', markup), formMarkup('gform_1', 'gravityforms'));
        const duplicates = await page.evaluate(async () => {
            window.solveDelay = 40;
            const form = document.getElementById('gform_1');
            const data = await Promise.all([runGravity('gform/submission/pre_submission', { form }), runGravity('gform/submission/pre_submission', { form })]);
            return { allowed: data.filter(result => !result.abort).length, attempts: solveAttempts, unlocked };
        });
        assert.deepEqual(duplicates, { allowed: 1, attempts: 1, unlocked: 0 });
        await setup({ mode: 'widget' }, true);
        await page.evaluate(() => document.body.insertAdjacentHTML('beforeend', '<form id="gform_1"><div data-capconnect-integration="gravityforms"><cap-widget></cap-widget></div></form>'));
        assert.equal(await page.evaluate(async () => (await runGravity('gform/submission/pre_submission', { form: document.getElementById('gform_1') })).abort), true, 'Unconfirmed Gravity widgets abort before transport');
        assert.equal(await page.evaluate(() => solveAttempts), 0, 'Gravity visible verification remains manual');
        await page.evaluate(async () => {
            const form = document.getElementById('gform_1');
            await form.querySelector('cap-widget').solve();
            await runGravity('gform/submission/pre_submission', { form });
            document.dispatchEvent(new CustomEvent('gform/post_render', { detail: { formId: 1 } }));
        });
        assert.equal(await page.locator('cap-widget').evaluate(widget => widget.token), '');
        assert.equal(await page.evaluate(() => solveAttempts), 1);
        console.log('Passed duplicate Gravity preparation and visible-widget reset without automatic solving.');

        for (const hasFormId of [false, true]) {
            await setup({}, true);
            await page.evaluate(({ markup, hasFormId }) => {
                document.body.insertAdjacentHTML('beforeend', markup);
                if (hasFormId) document.getElementById('gform_1').dataset.formid = '1';
            }, { markup: formMarkup('gform_1', 'gravityforms'), hasFormId });
            let gravityRequests = 0;
            await page.route('http://capconnect.test/gravity-request', route => {
                gravityRequests++;
                return route.abort('connectionreset');
            });
            const gravityFailure = await page.evaluate(async () => {
                const form = document.getElementById('gform_1');
                await runGravity('gform/submission/pre_submission', { form, submissionMethod: 'ajax' });
                await runGravity('gform/submission/submission_started', { form, submissionMethod: 'ajax' });
                const first = form.querySelector('input[name="cap-token"]').value;
                try { await fetch('/gravity-request', { method: 'POST', body: new FormData(form) }); }
                catch (error) {
                    await runGravity('gform/ajax/pre_display_error', { formId: 1, submissionResult: { success: false } });
                }
                const cleared = !form.querySelector('input[name="cap-token"]').value;
                const before = solveAttempts;
                await runGravity('gform/submission/pre_submission', { form, submissionMethod: 'ajax' });
                return { first, cleared, before, after: solveAttempts, retry: form.querySelector('input[name="cap-token"]').value };
            });
            assert.equal(gravityRequests, 1);
            assert.equal(gravityFailure.cleared, true);
            assert.equal(gravityFailure.before, 1);
            assert.equal(gravityFailure.after, 2);
            assert.notEqual(gravityFailure.first, gravityFailure.retry);
            await page.unroute('http://capconnect.test/gravity-request');
        }

        await setup({}, true);
        await page.evaluate(markup => document.body.insertAdjacentHTML('beforeend', markup), formMarkup('gform_1', 'gravityforms'));
        const replacedGravity = await page.evaluate(async markup => {
            let form = document.getElementById('gform_1');
            await runGravity('gform/submission/pre_submission', { form, submissionMethod: 'ajax' });
            await runGravity('gform/submission/submission_started', { form, submissionMethod: 'ajax' });
            form.remove();
            document.body.insertAdjacentHTML('beforeend', markup);
            form = document.getElementById('gform_1');
            await runGravity('gform/submission/pre_submission', { form, submissionMethod: 'ajax' });
            await runGravity('gform/submission/submission_started', { form, submissionMethod: 'ajax' });
            const token = form.querySelector('input[name="cap-token"]').value;
            await runGravity('gform/ajax/pre_display_error', { formId: 1, submissionResult: { success: false } });
            const afterOldError = form.querySelector('input[name="cap-token"]').value;
            await runGravity('gform/ajax/pre_display_error', { formId: 1, submissionResult: { success: false } });
            const beforeRetry = solveAttempts;
            await runGravity('gform/submission/pre_submission', { form, submissionMethod: 'ajax' });
            return { token, afterOldError, beforeRetry, afterRetry: solveAttempts, retry: form.querySelector('input[name="cap-token"]').value };
        }, formMarkup('gform_1', 'gravityforms'));
        assert.equal(replacedGravity.afterOldError, replacedGravity.token);
        assert.equal(replacedGravity.afterRetry, replacedGravity.beforeRetry + 1);
        assert.notEqual(replacedGravity.retry, replacedGravity.token);

        await setup({ mode: 'widget' }, true);
        await page.evaluate(() => document.body.insertAdjacentHTML('beforeend', '<form id="gform_1"><div data-capconnect-integration="gravityforms"><cap-widget></cap-widget></div></form>'));
        const visibleRetry = await page.evaluate(async () => {
            const form = document.getElementById('gform_1');
            const widget = form.querySelector('cap-widget');
            await widget.solve();
            await runGravity('gform/submission/pre_submission', { form });
            const aborted = await runGravity('gform/submission/pre_submission', { form });
            const cleared = !widget.token;
            await widget.solve();
            const retry = await runGravity('gform/submission/pre_submission', { form });
            return { aborted: !!aborted.abort, cleared, retryAborted: !!retry.abort, attempts: solveAttempts, error: form.querySelector('.capconnect-error-message').textContent };
        });
        assert.deepEqual(visibleRetry, { aborted: true, cleared: true, retryAborted: false, attempts: 2, error: '' });
        console.log('Passed Gravity failed transport, retry and ambiguous late errors without resetting a replacement.');

        for (const outcome of ['success', 'error', 'unknown']) {
            await setup();
            let submittedToken = '';
            await page.route('http://capconnect.test/custom-request', route => {
                submittedToken = route.request().postData() || '';
                return outcome === 'unknown' ? route.abort('connectionreset')
                    : route.fulfill({ status: outcome === 'success' ? 200 : 500, body: outcome });
            });
            await page.evaluate(markup => document.body.insertAdjacentHTML('beforeend', markup + markup.replaceAll('id="first"', 'id="second"')), formMarkup('first'));
            const result = await page.evaluate(async () => {
                const first = document.getElementById('first');
                const second = document.getElementById('second');
                await CAPCONNECT_FORMS.solve(first);
                await CAPCONNECT_FORMS.solve(second);
                const firstToken = first.querySelector('input[name="cap-token"]').value;
                const secondToken = second.querySelector('input[name="cap-token"]').value;
                const attempts = solveAttempts;
                try {
                    const response = await fetch('/custom-request', { method: 'POST', body: new FormData(first) });
                    if (!response.ok) throw new Error('Request failed');
                } catch (error) {} finally { CAPCONNECT_FORMS.reset(first); }
                const cleared = first.querySelector('input[name="cap-token"]').value;
                const afterReset = solveAttempts;
                await CAPCONNECT_FORMS.solve(first);
                return { firstToken, cleared, retry: first.querySelector('input[name="cap-token"]').value, second: second.querySelector('input[name="cap-token"]').value, secondToken, attempts, afterReset };
            });
            assert.ok(submittedToken.includes(result.firstToken));
            assert.equal(result.cleared, '');
            assert.notEqual(result.retry, result.firstToken);
            assert.equal(result.second, result.secondToken);
            assert.equal(result.attempts, result.afterReset);
            await page.unroute('http://capconnect.test/custom-request');
        }
        console.log('Passed custom AJAX completion resets without solving or affecting another form.');

        await setup();
        await page.evaluate(markup => {
            document.body.insertAdjacentHTML('beforeend', markup);
            document.querySelector('#dynamic input[name="email"]').dispatchEvent(new Event('input', { bubbles: true }));
            document.querySelector('#dynamic input[name="email"]').dispatchEvent(new Event('change', { bubbles: true }));
        }, formMarkup('dynamic'));
        assert.equal(await page.evaluate(() => solveAttempts), 0);
        await page.evaluate(() => {
            const form = document.getElementById('dynamic');
            window.solveDelay = 80;
            window.oldResult = CAPCONNECT_FORMS.solve(form).then(() => 'accepted', error => error.capconnectDiscarded ? 'discarded' : error.message);
        });
        await page.waitForFunction(() => solveAttempts === 1);
        await page.evaluate(markup => {
            document.getElementById('dynamic').remove();
            document.body.insertAdjacentHTML('beforeend', markup);
        }, formMarkup('dynamic'));
        await page.waitForFunction(() => document.querySelectorAll('cap-widget').length === 0);
        assert.equal(await page.evaluate(() => oldResult), 'discarded');
        assert.equal(await page.locator('#dynamic input[name="cap-token"]').inputValue(), '');
        await page.evaluate(() => { window.solveDelay = 0; return CAPCONNECT_FORMS.solve(document.getElementById('dynamic')); });
        const token = await page.locator('#dynamic input[name="cap-token"]').inputValue();
        await page.evaluate(() => {
            const container = document.createElement('section'); document.body.appendChild(container);
            container.appendChild(document.getElementById('dynamic'));
        });
        assert.equal(await page.locator('#dynamic input[name="cap-token"]').inputValue(), token);
        assert.equal(await page.locator('cap-widget').count(), 1);
        await page.evaluate(markup => { document.getElementById('dynamic').innerHTML = markup; }, '<input name="email">' + programmatic + '<button>Senden</button>');
        await page.waitForFunction(() => document.querySelectorAll('cap-widget').length === 0);
        assert.equal(await page.evaluate(() => solveAttempts), 2);
        await page.evaluate(() => CAPCONNECT_FORMS.solve(document.getElementById('dynamic')));
        assert.notEqual(await page.locator('#dynamic input[name="cap-token"]').inputValue(), token);
        await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
        assert.equal(await page.locator('#dynamic input[name="cap-token"]').inputValue(), '');
        assert.equal(await page.evaluate(() => solveAttempts), 3);
        console.log('Passed detached pending forms, same-ID replacement, solver disposal, DOM moves, replaced challenge fields and BFCache without eager solving.');

        await setup({ mode: 'widget' });
        await page.evaluate(() => {
            document.body.insertAdjacentHTML('beforeend', '<form id="shortcode"><div class="tpow-programmatic-wrapper"><input type="hidden" name="custom-proof" id="custom-proof-id"></div><button type="submit" name="send" value="yes">Senden</button></form>');
            const form = document.getElementById('shortcode');
            form.addEventListener('submit', event => { event.preventDefault(); window.shortcodePayload = Object.fromEntries(new FormData(form, event.submitter)); });
            form.requestSubmit(form.querySelector('button'));
        });
        await page.waitForFunction(() => window.shortcodePayload);
        assert.match(await page.evaluate(() => shortcodePayload['custom-proof']), /^token-/);
        assert.equal(await page.evaluate(() => shortcodePayload.send), 'yes');
        console.log('Passed dynamic legacy programmatic shortcode with a custom field in widget mode.');

        await setup({ blocks: true });
        await page.evaluate(markup => document.body.insertAdjacentHTML('beforeend', '<div class="wc-block-checkout"><input aria-label="E-Mail"><div data-capconnect-wc="blocks">' + markup + '</div></div>'), programmatic);
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-woocommerce.js') });
        assert.equal(await page.evaluate(() => solveAttempts), 0);
        assert.equal(await page.evaluate(() => checkoutValidators.length), 1);
        await page.evaluate(async () => {
            window.solveDelay = 25;
            const response = await checkoutValidators[0]();
            if (response.type !== 'success') throw new Error('Checkout rejected');
            lifecycle.push('validated'); lifecycle.push('payment');
            await wp.apiFetch({ path: '/wc/store/v1/checkout', method: 'POST', data: {} });
        });
        assert.deepEqual(await page.evaluate(() => lifecycle), ['challenge', 'validated', 'payment', 'request']);
        await page.evaluate(() => { window.failSolve = true; });
        const failure = await page.evaluate(async () => {
            const response = await checkoutValidators[0]();
            if (response.type === 'success') { lifecycle.push('payment'); await wp.apiFetch({ path: '/wc/store/v1/checkout', method: 'POST', data: {} }); }
            return response;
        });
        assert.equal(failure.type, 'error');
        assert.equal(await page.evaluate(() => sent.length), 1);
        assert.equal(await page.evaluate(() => lifecycle.filter(value => value === 'payment').length), 1);
        await page.evaluate(() => { window.failSolve = false; });
        await page.getByLabel('E-Mail').fill('adam@example.test');
        await page.waitForFunction(() => extensionToken);
        await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
        assert.equal(await page.evaluate(() => extensionToken), '');
        const beforeRemoval = await page.evaluate(() => solveAttempts);
        await page.evaluate(() => document.querySelector('[data-capconnect-wc="blocks"]').remove());
        await page.waitForFunction(() => document.querySelectorAll('cap-widget').length === 0);
        assert.equal(await page.evaluate(() => solveAttempts), beforeRemoval);
        assert.equal(await page.evaluate(async () => (await checkoutValidators[0]()).type), 'error');
        assert.equal(await page.evaluate(() => checkoutValidators.length), 1);
        console.log('Passed Checkout validation before payment, failed-solver abort, detached Blocks roots and BFCache invalidation.');

        await setup({ blocks: true });
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-woocommerce.js') });
        const sameStack = await page.evaluate(async markup => {
            document.body.insertAdjacentHTML('beforeend', '<div class="wc-block-checkout"><input aria-label="Dynamische E-Mail"><div data-capconnect-wc="blocks">' + markup + '</div></div>');
            return (await checkoutValidators[0]()).type;
        }, programmatic);
        assert.equal(sameStack, 'success');
        await page.evaluate(() => {
            window.holdRequests = true;
            window.pendingCheckout = wp.apiFetch({ path: '/wc/store/v1/checkout', method: 'POST', data: {} });
        });
        await page.waitForFunction(() => sent.length === 1);
        await page.evaluate(markup => { document.querySelector('[data-capconnect-wc="blocks"]').innerHTML = markup; }, programmatic);
        await page.getByLabel('Dynamische E-Mail').fill('neu@example.test');
        await page.waitForFunction(() => extensionToken && extensionToken !== sent[0].data.extensions.capconnect.token);
        const replacementToken = await page.evaluate(() => extensionToken);
        await page.evaluate(async () => { releaseRequest(); await pendingCheckout; });
        assert.equal(await page.evaluate(() => extensionToken), replacementToken);
        assert.equal(await page.locator('[data-capconnect-wc="blocks"] input').inputValue(), replacementToken);
        console.log('Passed same-stack Block insertion and late request completion without resetting replacement markup.');

        for (const mode of ['programmatic', 'widget']) {
            await setup({ mode, blocks: true });
            await page.evaluate(markup => {
                window.wc = {};
                document.body.insertAdjacentHTML('beforeend', '<div class="wc-block-checkout"><div data-capconnect-wc="blocks">' + markup + '</div></div>');
            }, mode === 'programmatic' ? programmatic : '<cap-widget></cap-widget>');
            await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-woocommerce.js') });
            assert.match(await page.locator('[data-capconnect-wc="blocks"] [role="alert"]').textContent(), /aktualisiere/);
            assert.equal(await page.evaluate(() => wp.data.select('wc/store/validation').getValidationError('capconnect').hidden), false);
            assert.equal(await page.evaluate(async () => { try { await wp.apiFetch({ path: '/wc/store/v1/checkout', method: 'POST', data: {} }); return false; } catch { return true; } }), true);
            assert.equal(await page.evaluate(() => sent.length), 0);
            assert.equal(await page.evaluate(() => solveAttempts), 0);
            await page.evaluate(markup => {
                document.body.insertAdjacentHTML('beforeend', markup);
                document.getElementById('classic').addEventListener('submit', event => { event.preventDefault(); window.classicSent = true; });
                document.getElementById('classic').requestSubmit();
            }, formMarkup('classic'));
            await page.waitForFunction(() => window.classicSent);
        }
        console.log('Passed fail-closed missing Checkout API in both modes with classic forms remaining usable.');

        await page.goto('http://capconnect.test/');
        await page.route('https://**', route => route.abort());
        await page.evaluate(() => {
            window.CAPCONNECT_CONFIG = { mode: 'widget', apiEndpoint: '/captcha/', verificationFailedMsg: 'Prüfung fehlgeschlagen' };
            window.CAP_SILENT = true;
            window.challengeRequests = [];
            window.CAP_CUSTOM_FETCH = async url => {
                window.challengeRequests.push(url);
                return new Response(JSON.stringify({ error: 'Isolierter Testfehler' }), { headers: { 'Content-Type': 'application/json' } });
            };
        });
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-widget.js') });
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-forms.js') });
        await page.addStyleTag({ path: resolve(root, 'assets/css/capconnect-widget.css') });
        for (const width of [800, 260]) {
            const layout = await page.evaluate(width => {
                document.body.innerHTML = '<form style="width:' + width + 'px"><div class="gform_footer" style="display:flex;flex-wrap:wrap"><div data-capconnect-integration="gravityforms"><div class="cap-widget-wrapper"><cap-widget data-cap-disable-prefetch></cap-widget></div></div><button type="submit">Absenden</button></div></form>';
                const footer = document.querySelector('.gform_footer').getBoundingClientRect();
                const widget = document.querySelector('cap-widget').getBoundingClientRect();
                const button = document.querySelector('button').getBoundingClientRect();
                return { footer: footer.width, widget: widget.width, widgetBottom: widget.bottom, buttonTop: button.top };
            }, width);
            assert.equal(layout.widget, layout.footer);
            assert.ok(layout.buttonTop >= layout.widgetBottom);
        }
        console.log('Passed Gravity Forms footer layout at wide and narrow widths.');
        await page.evaluate(() => {
            document.body.innerHTML = '';
            document.body.insertAdjacentHTML('beforeend', '<button id="outside">Außerhalb</button><form><cap-widget data-cap-api-endpoint="/captcha/" data-cap-disable-prefetch></cap-widget></form>');
            window.dispatchEvent(new MouseEvent('mousemove'));
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab' }));
        });
        await page.waitForTimeout(2800);
        assert.deepEqual(await page.evaluate(() => challengeRequests), []);
        await page.evaluate(async () => { try { await document.querySelector('cap-widget').solve(); } catch {} });
        assert.equal(await page.evaluate(() => challengeRequests.length), 1);
        await page.evaluate(() => {
            document.querySelector('cap-widget').reset();
            window.dispatchEvent(new MouseEvent('mousemove'));
        });
        await page.waitForTimeout(2800);
        assert.equal(await page.evaluate(() => challengeRequests.length), 1);
        console.log('Passed bundled Cap widget without speculative requests before use or after reset.');
        assert.deepEqual(errors, []);
        console.log('No browser JavaScript errors.');
    } finally { await browser.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
