/** Isolated browser regressions. Requires Playwright, Chrome, and CAPCONNECT_TEST_JQUERY pointing to WordPress jQuery. */
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const { execFileSync } = require('node:child_process');
const { resolve } = require('node:path');
const assert = require('node:assert/strict');
const root = resolve(__dirname, '..');

/** Installs deterministic Cap and WooCommerce stores without network or persistent data. */
function installMocks({ mode = 'programmatic', blocks = false, registration = false } = {}) {
    window.CAPCONNECT_CONFIG = { mode, apiEndpoint: '/captcha/', verificationFailedMsg: 'Verification failed' };
    window.CAPCONNECT_WC = { mode, blocks, registration, registrationRequired: false };
    window.solvers = [];
    window.solveCount = 0;
    window.failSolve = false;
    class Widget extends HTMLElement {
        /** Models user-triggered solving and bubbling widget events. */
        async solve() {
            if (window.solveDelay) await new Promise(resolve => setTimeout(resolve, window.solveDelay));
            if (window.failSolve) throw new Error('Unavailable solver');
            this.token = 'token-' + (++window.solveCount);
            this.dispatchEvent(new CustomEvent('solve', { bubbles: true, detail: { token: this.token } }));
            return { token: this.token };
        }
        /** Models token expiry. */
        reset() { this.token = ''; this.dispatchEvent(new CustomEvent('reset', { bubbles: true })); }
    }
    customElements.define('cap-widget', Widget);
    window.Cap = class {
        /** Creates a separate hidden solver for each root. */
        constructor() { this.widget = document.createElement('cap-widget'); document.documentElement.appendChild(this.widget); window.solvers.push(this); }
        /** Delegates mock solving. */
        solve() { return this.widget.solve(); }
        /** Delegates expiry/reset. */
        reset() { this.widget.reset(); }
        /** Delegates lifecycle listeners. */
        addEventListener(...args) { this.widget.addEventListener(...args); }
    };
    const subscribers = [];
    const errors = {};
    const middleware = [];
    window.sent = [];
    window.account = false;
    window.extensionToken = '';
    /** Delivers data store updates synchronously like the WordPress registry. */
    function notify() { subscribers.forEach(fn => fn()); }
    window.wp = { data: {
        select(name) {
            if (name === 'wc/store/checkout') return { getShouldCreateAccount: () => window.account };
            if (name === 'wc/store/validation') return { getValidationError: key => errors[key] };
            return null;
        },
        dispatch(name) {
            if (name === 'wc/store/checkout') return { setExtensionData(namespace, value) { if (namespace !== 'capconnect' || typeof value !== 'object') throw new Error('Wrong extension API'); window.extensionToken = value.token; notify(); } };
            if (name === 'wc/store/validation') return {
                setValidationErrors(values) { Object.assign(errors, values); notify(); },
                clearValidationError(key) { delete errors[key]; notify(); }
            };
            return null;
        },
        subscribe(fn) { subscribers.push(fn); return () => {}; }
    } };
    window.wp.apiFetch = function (options) {
        let next = value => { window.sent.push(JSON.parse(JSON.stringify(value))); return Promise.resolve({ success: true }); };
        middleware.slice().reverse().forEach(fn => { const previous = next; next = value => fn(value, previous); });
        return next(options);
    };
    window.wp.apiFetch.use = fn => middleware.push(fn);
    window.notifyStores = notify;
}

/** Runs UI and token lifecycle regressions against real plugin scripts and mocked external services. */
async function main() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const failures = [];
        page.on('pageerror', error => failures.push(error.message));
        const settings = execFileSync('php', [resolve(__dirname, 'woocommerce.php'), '--settings-html'], { encoding: 'utf8' });
        const jquery = readFileSync(process.env.CAPCONNECT_TEST_JQUERY, 'utf8');
        await page.route('http://capconnect.test/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><script>' + jquery + '</script></head><body>' + settings + '</body></html>' }));
        await page.goto('http://capconnect.test/#woocommerce');
        await page.waitForFunction(() => document.querySelector('[data-tab="forms"]').getAttribute('aria-selected') === 'true');
        assert.equal(await page.locator('[data-tab="forms"]').textContent(), 'Integrations');
        assert.equal(await page.locator('[data-tab="woocommerce"]').isVisible(), false);
        await page.locator('input[type="checkbox"][name="capconnect_woocommerce_enabled"]').check();
        assert.equal(await page.locator('[data-tab="woocommerce"]').isVisible(), true);
        await page.locator('[data-tab="woocommerce"]').click();
        assert.equal(await page.locator('#capconnect-tab-woocommerce input[type="checkbox"]').count(), 9);
        assert.equal(await page.locator('#capconnect-tab-woocommerce input[type="checkbox"]:checked').count(), 9);
        await page.locator('input[type="checkbox"][name="capconnect_protect_woocommerce_blocks"]').uncheck();
        await page.locator('[data-tab="forms"]').click();
        await page.locator('input[type="checkbox"][name="capconnect_woocommerce_enabled"]').uncheck();
        assert.equal(await page.locator('[data-tab="woocommerce"]').isVisible(), false);
        assert.equal(await page.locator('input[type="checkbox"][name="capconnect_protect_woocommerce_blocks"]').isChecked(), false);
        assert.deepEqual(await page.evaluate(() => new FormData(document.getElementById('capconnect-settings-form')).getAll('capconnect_protect_woocommerce_blocks')), ['0']);
        await page.locator('[data-tab="forms"]').focus();
        await page.keyboard.press('ArrowRight');
        assert.equal(await page.locator('[data-tab="styling"]').getAttribute('aria-selected'), 'true');
        console.log('Passed settings tabs, immediate visibility, nine independent controls, retained values and keyboard navigation.');

        await page.unroute('http://capconnect.test/**');
        const inactive = execFileSync('php', [resolve(__dirname, 'woocommerce.php'), '--inactive-settings-html'], { encoding: 'utf8' });
        await page.route('http://capconnect.test/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><script>' + jquery + '</script>' + inactive }));
        await page.goto('http://capconnect.test/inactive#woocommerce');
        await page.waitForFunction(() => document.querySelector('[data-tab="forms"]').getAttribute('aria-selected') === 'true');
        assert.equal(await page.locator('[data-tab="woocommerce"]').count(), 0);
        assert.equal(await page.getByLabel('Protect WooCommerce', { exact: true }).isVisible(), true);
        assert.equal(await page.getByLabel('Protect WooCommerce', { exact: true }).isDisabled(), true);
        assert.equal(await page.locator('input[name="capconnect_woocommerce_enabled"]').count(), 1);
        assert.equal(await page.locator('input[name="capconnect_protect_woocommerce_blocks"]').inputValue(), '1');
        assert.equal(await page.locator('input[name="capconnect_woocommerce_enabled"]').inputValue(), '1');
        console.log('Passed inactive WooCommerce visibility, fallback and option preservation.');

        await page.unroute('http://capconnect.test/**');
        await page.route('http://capconnect.test/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><body></body>' }));
        await page.goto('http://capconnect.test/');
        await page.evaluate(installMocks, {});
        await page.setContent('<form id="first"><div class="capconnect-programmatic-wrapper"><input type="hidden" name="cap-token"></div><button id="submit-first" name="login" value="yes">Login</button></form><form id="second"><div class="capconnect-programmatic-wrapper"><input type="hidden" name="cap-token"></div><button>Register</button></form>');
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-forms.js') });
        await page.waitForFunction(() => document.querySelector('#first input').value && document.querySelector('#second input').value);
        const tokens = await page.locator('form input').evaluateAll(inputs => inputs.map(input => input.value));
        assert.notEqual(tokens[0], tokens[1]);
        await page.evaluate(() => {
            const form = document.getElementById('first');
            window.CAPCONNECT_FORMS.reset(form);
            form.addEventListener('submit', event => { event.preventDefault(); window.lastSubmitter = event.submitter.id; });
            form.requestSubmit(document.getElementById('submit-first'));
        });
        await page.waitForFunction(() => window.lastSubmitter === 'submit-first');
        assert.notEqual(await page.locator('#first input').inputValue(), tokens[0]);
        assert.equal(await page.locator('#second input').inputValue(), tokens[1]);
        await page.evaluate(() => { window.failSolve = true; document.body.insertAdjacentHTML('beforeend', '<form id="failed"><div class="capconnect-programmatic-wrapper"><input type="hidden" name="cap-token"></div><button>Retry</button></form>'); });
        await page.waitForFunction(() => document.querySelector('#failed [role="alert"]'));
        await page.evaluate(() => {
            window.failSolve = false;
            const form = document.getElementById('failed');
            form.addEventListener('submit', event => event.preventDefault());
            form.requestSubmit();
        });
        await page.waitForFunction(() => document.querySelector('#failed input').value);
        assert.equal(await page.locator('#failed [role="alert"]').isVisible(), false);
        const racing = await page.evaluate(async () => {
            const form = document.getElementById('first');
            window.solveDelay = 20;
            window.CAPCONNECT_FORMS.reset(form);
            const first = window.CAPCONNECT_FORMS.solve(form).catch(() => null);
            window.CAPCONNECT_FORMS.reset(form);
            const second = window.CAPCONNECT_FORMS.solve(form);
            const result = await second;
            await first;
            window.solveDelay = 0;
            return { result: result.token, field: form.querySelector('input').value };
        });
        assert.equal(racing.result, racing.field);
        console.log('Passed isolated form tokens, dynamic forms, submitter preservation, accessible failure and retry.');

        await page.goto('http://capconnect.test/');
        await page.evaluate(installMocks, { mode: 'widget' });
        await page.setContent('<form id="loginform"><div class="cap-widget-wrapper"><cap-widget></cap-widget></div><button>Login</button></form><form id="other" class="login"><button>Other</button></form><form class="register"><div class="cap-widget-wrapper">Registration</div></form>');
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-forms.js') });
        await page.evaluate(async () => {
            document.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => event.preventDefault()));
            await document.querySelector('cap-widget').solve();
            document.getElementById('other').requestSubmit();
        });
        assert.equal(await page.locator('#other input[name="cap-token"]').count(), 0);
        await page.evaluate(() => {
            document.querySelector('cap-widget').remove();
            document.getElementById('loginform').requestSubmit();
            document.body.insertAdjacentHTML('beforeend', '<div id="wfls-prompt-overlay">Two-factor</div>');
        });
        assert.match(await page.locator('#loginform input[name="cap-token"]').inputValue(), /^token-/);
        await page.waitForFunction(() => document.querySelector('#loginform .cap-widget-wrapper').hidden);
        assert.equal(await page.locator('form.register .cap-widget-wrapper').isVisible(), true);
        console.log('Passed form-local 2FA token preservation and widget visibility.');

        for (const mode of ['programmatic', 'widget']) {
            await page.goto('http://capconnect.test/');
            await page.evaluate(installMocks, { mode, blocks: true, registration: true });
            const widget = mode === 'widget' ? '<cap-widget></cap-widget>' : '<div class="capconnect-programmatic-wrapper"><input type="hidden" name="cap-token"></div>';
            await page.setContent('<div class="wc-block-checkout"><div data-capconnect-wc="blocks">' + widget + '</div></div><template id="capconnect-wc-after-register"><div data-capconnect-wc="after_register">' + widget + '</div></template><form class="wc-block-order-confirmation-create-account-form"><button type="submit">Create account</button></form>');
            await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-forms.js') });
            await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-woocommerce.js') });
            if (mode === 'widget') await page.evaluate(() => document.querySelector('[data-capconnect-wc="blocks"] cap-widget').solve());
            await page.waitForFunction(() => window.extensionToken);
            assert.equal(await page.locator('form [data-capconnect-wc="after_register"]').count(), 1);
            const first = await page.evaluate(async () => {
                await wp.apiFetch({ path: '/wc/store/v1/checkout', method: 'POST', data: { create_account: true, extensions: { other: { retained: true } } } });
                return window.sent.at(-1);
            });
            assert.match(first.data.extensions.capconnect.token, /^token-/);
            assert.equal(first.data.extensions.other.retained, true);
            if (mode === 'widget') await page.evaluate(() => document.querySelector('[data-capconnect-wc="blocks"] cap-widget').solve());
            await page.waitForFunction(() => window.extensionToken);
            const second = await page.evaluate(async () => {
                await wp.apiFetch({ path: '/wc/store/v1/batch', method: 'POST', data: { requests: [{ path: '/wc/store/v1/cart', method: 'GET' }, { path: '/wc/store/v1/checkout', method: 'POST', body: { create_account: true } }] } });
                return window.sent.at(-1);
            });
            const batchToken = second.data.requests[1].body.extensions.capconnect.token;
            assert.match(batchToken, /^token-/);
            assert.notEqual(batchToken, first.data.extensions.capconnect.token);
            assert.equal(second.data.requests[0].path, '/wc/store/v1/cart');
            if (mode === 'programmatic') {
                await page.waitForFunction(() => window.extensionToken);
                const before = await page.evaluate(() => window.extensionToken);
                await page.evaluate(() => window.solvers.find(solver => solver.widget.token === window.extensionToken).reset());
                await page.waitForFunction(token => window.extensionToken && window.extensionToken !== token, before);
            }
            console.log('Passed ' + mode + ' Block Checkout token transport, retry, batch payloads and delayed registration insertion.');
        }
        for (const mode of ['programmatic', 'widget']) {
            await page.goto('http://capconnect.test/');
            await page.evaluate(installMocks, { mode, blocks: false, registration: true });
            const markup = mode === 'widget' ? '<cap-widget></cap-widget>' : '<div class="capconnect-programmatic-wrapper"><input type="hidden" name="cap-token"></div>';
            await page.setContent('<div class="wc-block-checkout"><div data-capconnect-wc="blocks">' + markup + '</div></div>');
            await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-forms.js') });
            await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-woocommerce.js') });
            assert.equal(await page.locator('[data-capconnect-wc="blocks"]').isVisible(), false);
            await page.evaluate(() => { window.account = true; window.notifyStores(); });
            assert.equal(await page.locator('[data-capconnect-wc="blocks"]').evaluate(element => !element.hidden && element.style.display !== 'none'), true);
            if (mode === 'widget') await page.evaluate(() => document.querySelector('[data-capconnect-wc="blocks"] cap-widget').solve());
            await page.waitForFunction(() => window.extensionToken);
            const original = await page.evaluate(() => window.extensionToken);
            await page.evaluate(() => { window.account = false; window.notifyStores(); });
            assert.equal(await page.locator('[data-capconnect-wc="blocks"]').isVisible(), false);
            assert.equal(await page.evaluate(() => window.extensionToken), '');
            await page.evaluate(() => { window.account = true; window.notifyStores(); });
            if (mode === 'widget') await page.evaluate(() => document.querySelector('[data-capconnect-wc="blocks"] cap-widget').solve());
            await page.waitForFunction(() => window.extensionToken);
            assert.notEqual(await page.evaluate(() => window.extensionToken), original);
            console.log('Passed ' + mode + ' independent checkout registration and account-choice toggling.');
        }
        await page.goto('http://capconnect.test/');
        await page.evaluate(installMocks, { mode: 'programmatic' });
        await page.evaluate(() => { window.failSolve = true; });
        await page.setContent('<form class="woocommerce-form-login login"><div data-capconnect-wc="login" data-capconnect-mode="programmatic"><div class="capconnect-programmatic-wrapper"><input type="hidden" name="cap-token"><div class="capconnect-error-message" role="alert" style="display:none"></div></div></div><button type="submit" class="loader">Login</button></form><form id="unrelated"><button type="submit" class="loading">Unrelated</button></form>');
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-forms.js') });
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-woocommerce.js') });
        await page.evaluate(() => {
            document.querySelector('form.login button').addEventListener('click', function () { this.classList.add('loading'); });
            window.loginSubmissions = 0;
            document.querySelector('form.login').addEventListener('submit', event => { event.preventDefault(); window.loginSubmissions++; });
        });
        await page.locator('form.login button').click();
        await page.waitForFunction(() => !document.querySelector('form.login button').classList.contains('loading'));
        assert.equal(await page.locator('form.login [role="alert"]').isVisible(), true);
        assert.equal(await page.evaluate(() => window.loginSubmissions), 0);
        assert.equal(await page.locator('#unrelated button').getAttribute('class'), 'loading');
        await page.evaluate(() => { window.failSolve = false; });
        await page.locator('form.login button').click();
        await page.waitForFunction(() => window.loginSubmissions === 1);
        assert.equal(await page.locator('form.login [role="alert"]').isVisible(), false);
        assert.equal(await page.locator('form.login button').getAttribute('class'), 'loader loading');
        console.log('Passed rejected-domain solver failure, theme spinner recovery, unrelated form isolation and successful retry.');

        const layoutFixture = JSON.parse(execFileSync('php', [resolve(__dirname, 'woocommerce.php'), '--layout-fixture'], { encoding: 'utf8' }));
        for (const layout of ['flow', 'flex', 'grid']) {
            for (const area of ['login', 'checkout', 'blocks', 'after_register']) {
                await page.goto('http://capconnect.test/');
                await page.evaluate(installMocks, { mode: 'programmatic' });
                await page.setContent('<form class="' + layout + '"><div class="field first">Before</div><div class="field last">After</div></form><div id="outside" class="cap-widget-wrapper">Unrelated widget</div>');
                await page.addStyleTag({ content: readFileSync(resolve(root, 'assets/css/capconnect-widget.css'), 'utf8') + layoutFixture.css + `
                    form { position: relative; width: 500px; }
                    .field { display: block; width: 150px; height: 24px; }
                    .flex { display: flex; flex-direction: column; gap: 20px; }
                    .grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
                    form .form-row { margin: 12px !important; padding: 10px !important; min-width: 60px; min-height: 20px; border: 2px solid black; }
                    .capconnect-error-message { overflow-wrap: anywhere; }
                    cap-widget { height: 58px; width: 200px; }
                ` });
                const baseline = await page.evaluate(() => {
                    const form = document.querySelector('form');
                    const last = form.querySelector('.last').getBoundingClientRect();
                    return { height: form.getBoundingClientRect().height, lastTop: last.top, scroll: form.scrollHeight };
                });
                await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-forms.js') });
                await page.evaluate(markup => {
                    const form = document.querySelector('form');
                    form.querySelector('.last').insertAdjacentHTML('beforebegin', markup);
                }, layoutFixture.programmatic[area]);
                if (area === 'blocks') await page.evaluate(() => window.CAPCONNECT_FORMS.solve(document.querySelector('[data-capconnect-wc="blocks"]')));
                await page.waitForFunction(() => document.querySelector('form input[name="cap-token"]').value);
                const collapsed = await page.evaluate(() => {
                    const form = document.querySelector('form');
                    const wrapper = form.querySelector('[data-capconnect-wc]');
                    return { height: form.getBoundingClientRect().height, lastTop: form.querySelector('.last').getBoundingClientRect().top,
                        scroll: form.scrollHeight, position: getComputedStyle(wrapper).position,
                        width: wrapper.getBoundingClientRect().width, wrapperHeight: wrapper.getBoundingClientRect().height,
                        outside: getComputedStyle(document.getElementById('outside')).position };
                });
                assert.equal(collapsed.height, baseline.height, layout + ' ' + area + ': no height change');
                assert.equal(collapsed.lastTop, baseline.lastTop, layout + ' ' + area + ': no added gap');
                assert.equal(collapsed.scroll, baseline.scroll, layout + ' ' + area + ': no scroll overflow');
                assert.equal(collapsed.position, 'absolute');
                assert.equal(collapsed.width, 0);
                assert.equal(collapsed.wrapperHeight, 0);
                assert.equal(collapsed.outside, 'static');
                await page.evaluate(() => {
                    const form = document.querySelector('form');
                    const root = form.querySelector('[data-capconnect-wc="blocks"]') || form;
                    window.CAPCONNECT_FORMS.showError(root, new Error('Test failure'));
                });
                assert.equal(await page.locator('[data-capconnect-wc]').evaluate(element => getComputedStyle(element).position), 'relative');
                assert.equal(await page.locator('[role="alert"]').isVisible(), true);
                const errorBounds = await page.locator('[role="alert"]').boundingBox();
                const lastBounds = await page.locator('.last').boundingBox();
                assert.ok(lastBounds.y >= errorBounds.y + errorBounds.height, 'Error must not overlap the following field');
                await page.evaluate(async () => {
                    const form = document.querySelector('form');
                    const root = form.querySelector('[data-capconnect-wc="blocks"]') || form;
                    await window.CAPCONNECT_FORMS.solve(root);
                });
                assert.equal(await page.locator('[role="alert"]').isVisible(), false);
                assert.equal(await page.locator('[data-capconnect-wc]').evaluate(element => getComputedStyle(element).position), 'absolute');
                assert.equal(await page.locator('form').evaluate(element => element.getBoundingClientRect().height), baseline.height);
                await page.evaluate(markup => {
                    document.querySelector('[data-capconnect-wc]').remove();
                    document.querySelector('.last').insertAdjacentHTML('beforebegin', markup);
                }, layoutFixture.widget[area]);
                assert.equal(await page.locator('[data-capconnect-wc]').evaluate(element => getComputedStyle(element).position), 'relative');
                assert.ok(await page.locator('form').evaluate(element => element.getBoundingClientRect().height) > baseline.height, 'Visible widget occupies layout space');
            }
            console.log('Passed ' + layout + ' WooCommerce layout isolation, visible widgets and error recovery across account, classic, Block and delayed forms.');
        }
        assert.deepEqual(failures, []);
        console.log('No browser JavaScript errors.');
    } finally { await browser.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
