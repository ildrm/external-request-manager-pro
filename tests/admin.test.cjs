const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const jquery = require('jquery');

const labels = Object.fromEntries(['host', 'url', 'method', 'source', 'count', 'status', 'first', 'last',
    'file', 'size', 'code', 'time', 'body', 'urls', 'noUrls', 'download', 'block', 'unblock']
    .map((key) => [key, key === 'download' ? 'Download Stored Response' : key]));
async function environment() {
    const dom = new JSDOM(`<!doctype html><body style="overflow:scroll">
        <div class="erm-pro-wrap">
            <button class="erm-review-btn" data-id="1">Review</button>
            <button class="erm-delete-btn" data-id="1" data-is-blocked="1" data-has-rate-limit="1">Delete</button>
            <button class="erm-toggle-block-btn" data-id="1">Block</button>
            <button class="erm-remove-rate-limit-btn" data-id="1">Remove limit</button>
            <button disabled id="already-disabled">Disabled</button>
            <input id="erm-select-all" type="checkbox">
            <input class="erm-request-checkbox" type="checkbox" value="1">
            <input class="erm-request-checkbox" type="checkbox" value="2">
            <div class="erm-stat-card"><span class="erm-stat-number"></span></div>
            <div class="erm-stat-blocked"><span class="erm-stat-number"></span></div>
            <div class="erm-stat-allowed"><span class="erm-stat-number"></span></div>
            <ul class="erm-filter-tabs"><li><a>Localized<span data-count="total"></span></a></li></ul>
        </div>
        <div id="erm-detail-modal" class="erm-modal hidden" aria-hidden="true">
            <div class="erm-modal-content" tabindex="-1">
                <button class="erm-modal-close">Close</button>
                <div id="erm-detail-body"></div>
                <div id="erm-detail-actions">
                    <input id="erm-rate-interval"><input id="erm-rate-calls">
                    <button id="erm-save-rate-limit">Save</button>
                    <button id="erm-modal-toggle-block"></button>
                    <button id="erm-modal-delete">Delete</button>
                </div>
            </div>
        </div>
    </body>`, { url: 'https://example.test/wp-admin/', runScripts: 'outside-only', virtualConsole: new VirtualConsole() });
    const { window } = dom;
    const $ = jquery(window);
    const calls = [];
    const alerts = [];
    window.$ = window.jQuery = $;
    window.ermProData = { ajaxUrl: '/ajax', nonce: 'test', labels, messages: {
        error: 'Request failed', failed: 'Failed', confirmDelete: 'Delete?', invalidRateLimit: 'Invalid rate'
    } };
    window.alert = (message) => alerts.push(message);
    window.confirm = () => true;
    $.ajax = (options) => {
        const deferred = $.Deferred();
        calls.push({ options, deferred });
        return deferred.promise();
    };
    // jsdom has no layout engine; jQuery's visibility test needs a geometry shim.
    window.HTMLElement.prototype.getClientRects = function() {
        return this.closest('.hidden') ? [] : [{ width: 10, height: 10 }];
    };
    window.eval(fs.readFileSync('assets/js/admin.js', 'utf8'));
    await new Promise((resolve) => window.setTimeout(resolve, 20));
    return { dom, window, $, calls, alerts };
}
const detail = { id: 1, host: 'api.example.test', url: 'https://api.example.test/',
    method: 'GET', source: 'Core', count: 12, status: 'Allowed',
    first_request: 'now', last_request: 'now', source_file: '-', request_size: '80 B',
    response_code: 201, response_time: '0.001', response_data: '0', is_blocked: false,
    rate_limit_interval: 60, rate_limit_calls: 2, track_all_urls: true, urls_list: [] };

test('detail renders numeric values and zero response text without throwing', async () => {
    const ctx = await environment();
    ctx.$('.erm-review-btn').trigger('click');
    assert.doesNotThrow(() => ctx.calls[0].deferred.resolve({ success: true, data: detail }));
    assert.match(ctx.$('#erm-detail-body').text(), /201/);
    assert.equal(ctx.$('.erm-response-body').text(), '0');
    assert.equal(ctx.$('#erm-detail-modal').attr('aria-hidden'), 'false');
    assert.equal(ctx.$('#erm-rate-calls').val(), '2');
    ctx.dom.window.close();
});
test('untrusted response and URL content is rendered as text', async () => {
    const ctx = await environment();
    ctx.$('.erm-review-btn').trigger('click');
    ctx.calls[0].deferred.resolve({ success: true, data: { ...detail,
        host: '<img src=x onerror=alert(1)>', response_data: '<script>alert(1)</script>',
        urls_list: ['<img src=x>'] } });
    assert.equal(ctx.$('#erm-detail-body img, #erm-detail-body script').length, 0);
    assert.match(ctx.$('#erm-detail-body').text(), /<script>/);
    ctx.dom.window.close();
});
test('single deletion sends one operation and preserves original blocking state for audit', async () => {
    const ctx = await environment();
    ctx.$('.erm-delete-btn').trigger('click');
    assert.equal(ctx.calls.length, 1);
    assert.equal(ctx.calls[0].options.data.action, 'erm_bulk_action');
    assert.equal(ctx.calls[0].options.data.bulk_action, 'delete');
    assert.deepEqual(Array.from(ctx.calls[0].options.data.ids), [1]);
    ctx.dom.window.close();
});
test('rate-limit removal sends interval and calls fields expected by the server', async () => {
    const ctx = await environment();
    ctx.$('.erm-remove-rate-limit-btn').trigger('click');
    assert.equal(ctx.calls[0].options.data.interval, 0);
    assert.equal(ctx.calls[0].options.data.calls, 0);
    ctx.dom.window.close();
});
test('HTTP failure restores controls, preserves disabled buttons, and allows retry', async () => {
    const ctx = await environment();
    ctx.$('.erm-toggle-block-btn').trigger('click');
    assert.equal(ctx.$('.erm-toggle-block-btn').prop('disabled'), true);
    ctx.calls[0].deferred.reject({ responseJSON: { data: { message: 'Database failed' } } });
    assert.equal(ctx.$('.erm-toggle-block-btn').prop('disabled'), false);
    assert.equal(ctx.$('#already-disabled').prop('disabled'), true);
    assert.deepEqual(ctx.alerts, ['Database failed']);
    ctx.$('.erm-toggle-block-btn').trigger('click');
    assert.equal(ctx.calls.length, 2);
    ctx.dom.window.close();
});
test('unexpected nonce response shows a recoverable error', async () => {
    const ctx = await environment();
    ctx.$('.erm-review-btn').trigger('click');
    ctx.calls[0].deferred.resolve(-1);
    assert.deepEqual(ctx.alerts, ['Request failed']);
    assert.equal(ctx.$('.erm-review-btn').prop('disabled'), false);
    ctx.dom.window.close();
});
test('translated filter counts use stable attributes', async () => {
    const ctx = await environment();
    ctx.$('.erm-toggle-block-btn').trigger('click');
    ctx.calls[0].deferred.resolve({ success: true, data: { counts: { total: 12, blocked: 2, allowed: 10 } } });
    assert.equal(ctx.$('[data-count="total"]').text(), '12');
    assert.equal(ctx.$('.erm-stat-blocked .erm-stat-number').text(), '2');
    ctx.dom.window.close();
});
test('backdrop and Escape close dialogs, restore focus, and restore original overflow', async () => {
    const ctx = await environment();
    const trigger = ctx.$('.erm-review-btn')[0];
    trigger.focus();
    ctx.$(trigger).trigger('click');
    ctx.calls[0].deferred.resolve({ success: true, data: detail });
    ctx.$('#erm-detail-modal').trigger('click');
    assert.equal(ctx.$('#erm-detail-modal').attr('aria-hidden'), 'true');
    assert.equal(ctx.window.document.body.style.overflow, 'scroll');
    assert.equal(ctx.window.document.activeElement, trigger);
    ctx.$(trigger).trigger('click');
    ctx.calls[1].deferred.resolve({ success: true, data: detail });
    ctx.$(ctx.window.document).trigger(ctx.$.Event('keydown', { key: 'Escape' }));
    assert.equal(ctx.$('#erm-detail-modal').attr('aria-hidden'), 'true');
    ctx.dom.window.close();
});
test('Tab and Shift+Tab keep focus in the active dialog', async () => {
    const ctx = await environment();
    ctx.$('.erm-review-btn').trigger('click');
    ctx.calls[0].deferred.resolve({ success: true, data: detail });
    ctx.$('#erm-modal-delete')[0].focus();
    ctx.$(ctx.window.document).trigger(ctx.$.Event('keydown', { key: 'Tab' }));
    assert.equal(ctx.window.document.activeElement, ctx.$('.erm-modal-close')[0]);
    ctx.$(ctx.window.document).trigger(ctx.$.Event('keydown', { key: 'Tab', shiftKey: true }));
    assert.equal(ctx.window.document.activeElement, ctx.$('#erm-modal-delete')[0]);
    ctx.dom.window.close();
});
test('partial row selection shows an indeterminate select-all checkbox', async () => {
    const ctx = await environment();
    ctx.$('.erm-request-checkbox').first().prop('checked', true).trigger('change');
    assert.equal(ctx.$('#erm-select-all').prop('indeterminate'), true);
    ctx.$('#erm-select-all').prop('checked', true).trigger('change');
    assert.equal(ctx.$('.erm-request-checkbox:checked').length, 2);
    assert.equal(ctx.$('#erm-select-all').prop('indeterminate'), false);
    ctx.dom.window.close();
});
test('rate-limit save validates the current values before sending', async () => {
    const ctx = await environment();
    ctx.$('.erm-review-btn').trigger('click');
    ctx.calls[0].deferred.resolve({ success: true, data: detail });
    ctx.$('#erm-rate-interval').val('-2');
    ctx.$('#erm-save-rate-limit').trigger('click');
    assert.equal(ctx.calls.length, 1);
    ctx.$('#erm-rate-interval').val('30');
    ctx.$('#erm-rate-calls').val('3');
    ctx.$('#erm-save-rate-limit').trigger('click');
    assert.equal(ctx.calls[1].options.data.interval, '30');
    assert.equal(ctx.calls[1].options.data.calls, '3');
    ctx.dom.window.close();
});
