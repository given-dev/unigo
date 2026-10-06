const {JSDOM} = require('jsdom');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const dom = new JSDOM('<form data-confirm="Sign out?"><button type="submit">Sign out</button></form>',
        {runScripts: 'outside-only', url: 'http://localhost/'});
    const win = dom.window;
    win.UNIGO = {pwa: false};
    win.eval(fs.readFileSync('public/assets/js/app.js', 'utf8'));
    await new Promise(resolve => win.document.addEventListener('DOMContentLoaded', resolve, {once: true}));
    const form = win.document.querySelector('form');
    let accepted = 0;
    const submit = () => form.dispatchEvent(new win.Event('submit', {bubbles: true, cancelable: true}));
    form.requestSubmit = submit;
    win.document.addEventListener('submit', event => {
        if (!event.defaultPrevented) accepted++;
        event.preventDefault(); // intercept navigation after application handlers
    });
    win.UniGo.confirm = () => Promise.resolve(false);
    submit();
    await Promise.resolve();
    assert.equal(accepted, 0, 'Cancel must not submit');
    assert.equal(form.dataset.busy, undefined, 'A paused form must remain usable');
    win.UniGo.confirm = () => Promise.resolve(true);
    submit();
    await Promise.resolve();
    assert.equal(accepted, 1, 'Confirm must submit exactly once');
    assert.equal(form.dataset.busy, '1');
    submit();
    assert.equal(accepted, 1, 'A duplicate submission must be blocked');
    dom.window.close();
    console.log('Confirmation regression passed: cancel, confirm, duplicate protection.');
})().catch(error => { console.error(error); process.exitCode = 1; });
