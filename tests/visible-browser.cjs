/** Visible CAP regressions using the bundled custom element and isolated transports. */
const { chromium } = require('playwright');
const { resolve } = require('node:path');
const { readFileSync } = require('node:fs');
const assert = require('node:assert/strict');
const root = resolve(__dirname, '..');

/** Installs deterministic challenge/redeem responses without contacting a CAP server. */
function installService() {
    window.CAPCONNECT_CONFIG = { mode: 'widget', apiEndpoint: '/captcha/', verificationFailedMsg: 'Bitte bestätige die CAP-Prüfung.' };
    window.CAP_SILENT = true;
    window.requests = [];
    window.serial = 0;
    window.expiry = 60000;
    window.CAP_CUSTOM_FETCH = async url => {
        window.requests.push(url);
        return new Response(JSON.stringify(url.endsWith('challenge')
            ? { challenge: [], token: 'challenge' }
            : { success: true, token: 'valid-' + (++window.serial), expires: new Date(Date.now() + window.expiry).toISOString() }),
        { headers: { 'Content-Type': 'application/json' } });
    };
}

/** Tests native validation, synthetic plugin gates, keyboard focus, expiry and inactive sections. */
async function main() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('http://capconnect.test/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="de"><head><title>CAP-Test</title></head><body></body></html>' }));
        await page.route('https://**', route => route.abort());
        await page.goto('http://capconnect.test/');
        await page.evaluate(installService);
        await page.addScriptTag({ path: process.env.CAPCONNECT_TEST_JQUERY });
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-widget.js') });
        await page.addScriptTag({ path: resolve(root, 'assets/js/capconnect-forms.js') });
        await page.addStyleTag({ path: resolve(root, 'assets/css/capconnect-widget.css') });
        for (const id of ['loginform', 'lostpasswordform', 'resetpassform', 'registerform', 'commentform', 'woocommerce-lost', 'woocommerce-login', 'woocommerce-register', 'woocommerce-reset', 'woocommerce-checkout', 'woocommerce-after-register']) {
            await page.evaluate(id => {
                const woo = id.startsWith('woocommerce');
                document.body.innerHTML = '<form id="' + id + '"' + (woo ? ' class="login"' : '') + '><label>Benutzername<input name="user_login" value="adam"></label><div' + (woo ? ' data-capconnect-wc="login"' : '') + '><cap-widget required data-cap-disable-prefetch data-cap-api-endpoint="/captcha/"></cap-widget></div><button type="submit">Absenden</button></form>';
                window.submissions = [];
                document.querySelector('form').addEventListener('submit', event => { event.preventDefault(); submissions.push(new FormData(event.target).get('cap-token')); });
            }, id);
            const start = await page.evaluate(() => requests.length);
            await page.locator('button[type="submit"]').click();
            assert.deepEqual(await page.evaluate(() => submissions), [], id);
            assert.equal(await page.evaluate(() => requests.length), start, 'Submit must not automatically solve');
            assert.equal(await page.locator('[role="alert"]').isVisible(), true);
            assert.equal(await page.evaluate(() => document.activeElement.shadowRoot.activeElement.className), 'captcha-trigger');
            await page.locator('input[name="user_login"]').press('Enter');
            assert.deepEqual(await page.evaluate(() => submissions), []);
            // Synthetic/novalidate dispatch must also be rejected when native validation is bypassed.
            assert.equal(await page.evaluate(() => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))), false);
            assert.deepEqual(await page.evaluate(() => submissions), []);
            await page.evaluate(() => jQuery('form').trigger('submit'));
            assert.deepEqual(await page.evaluate(() => submissions), [], 'Synthetic WordPress submits must also be blocked');
            await page.locator('cap-widget .captcha-trigger').press('Space');
            await page.waitForFunction(() => !!document.querySelector('cap-widget').tokenValue);
            await page.locator('button[type="submit"]').click();
            assert.match(await page.evaluate(() => submissions[0]), /^valid-/);
            assert.equal(await page.evaluate(() => submissions.length), 1);
            await page.evaluate(() => CAPCONNECT_FORMS.reset(document.querySelector('form')));
            await page.locator('button[type="submit"]').click();
            assert.equal(await page.evaluate(() => submissions.length), 1);
        }
        console.log('Passed real visible widget: click, Enter, bypassed native validation, keyboard solve, one submission and reset across native/WooCommerce forms.');

        await page.evaluate(() => {
            document.body.innerHTML = '<form class="checkout"><div data-capconnect-wc="checkout_register" hidden><cap-widget required data-cap-disable-prefetch></cap-widget></div><button type="submit">Bestellen</button></form>';
        });
        await page.waitForFunction(() => document.querySelector('cap-widget').hasAttribute('disabled'));
        assert.equal(await page.evaluate(() => document.querySelector('form').checkValidity()), true);
        await page.evaluate(() => { document.querySelector('[hidden]').hidden = false; });
        await page.waitForFunction(() => !document.querySelector('cap-widget').hasAttribute('disabled'));
        assert.equal(await page.evaluate(() => document.querySelector('form').checkValidity()), false);
        assert.equal(await page.evaluate(() => jQuery('form').triggerHandler('checkout_place_order')), false);
        assert.equal(await page.evaluate(() => jQuery.Event('submit').isDefaultPrevented()), false);
        const before = await page.evaluate(() => requests.length);
        await page.evaluate(() => jQuery('form').trigger('submit'));
        assert.equal(await page.evaluate(() => requests.length), before);
        console.log('Passed conditional checkout native/synthetic/pre-order blocking without automatic solving.');

        await page.evaluate(() => { window.expiry = 250; });
        await page.locator('cap-widget .captcha-trigger').click();
        await page.waitForFunction(() => !!document.querySelector('cap-widget').tokenValue);
        await page.waitForFunction(() => !document.querySelector('cap-widget').tokenValue);
        assert.equal(await page.evaluate(() => document.querySelector('form').checkValidity()), false);
        console.log('Passed actual token expiry and restored native invalidity.');
        await page.evaluate(() => { window.expiry = 60000; });
        for (const snapshot of process.argv.slice(2)) {
            const count = await page.evaluate(html => {
                const source = new DOMParser().parseFromString(html, 'text/html');
                const forms = Array.from(source.querySelectorAll('form')).filter(form => form.querySelector('cap-widget'));
                forms.forEach(form => form.querySelectorAll('script').forEach(script => script.remove()));
                window.realMarkup = forms.map(form => form.outerHTML);
                return forms.length;
            }, readFileSync(snapshot, 'utf8'));
            assert.ok(count > 0, 'Snapshot must contain actual protected forms');
            for (let index = 0; index < count; index++) {
                await page.evaluate(index => {
                    document.body.innerHTML = realMarkup[index];
                    document.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="checkbox"])').forEach(input => { input.value = input.type === 'email' ? 'adam@example.test' : 'adam-test'; });
                    window.submissions = [];
                    document.querySelector('form').addEventListener('submit', event => { event.preventDefault(); submissions.push(new FormData(event.target).get('cap-token')); });
                }, index);
                await page.locator('[type="submit"]').first().click();
                assert.deepEqual(await page.evaluate(() => submissions), [], snapshot);
                assert.equal(await page.locator('[role="alert"]').isVisible(), true);
                await page.locator('cap-widget .captcha-trigger').press('Tab');
                await page.locator('cap-widget .captcha-trigger').focus();
                await page.screenshot({ path: '/private/tmp/capconnect-focus.png' });
                const focus = await page.locator('cap-widget .captcha-trigger').evaluate(control => ({ width: getComputedStyle(control).outlineWidth, style: getComputedStyle(control).outlineStyle, color: getComputedStyle(control).outlineColor }));
                assert.ok(parseFloat(focus.width) >= 2 && focus.style !== 'none');
                console.log('Visible keyboard focus: ' + JSON.stringify(focus));
                await page.locator('cap-widget .captcha-trigger').press('Space');
                await page.waitForFunction(() => !!document.querySelector('cap-widget').tokenValue);
                await page.locator('[type="submit"]').first().click();
                assert.equal(await page.evaluate(() => submissions.length), 1);
            }
            console.log('Passed actual form markup from ' + snapshot + ' with isolated scripts/transports.');
        }
        assert.deepEqual(errors, []);
        console.log('No browser JavaScript errors.');
    } finally { await browser.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
