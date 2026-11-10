// Run with Node.js. All responses are local fixtures; no network is used.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

module.exports = (async function () {
    for (const file of fs.readdirSync(path.join(__dirname, '../assets/js'))) {
        new vm.Script(fs.readFileSync(path.join(__dirname, '../assets/js', file), 'utf8'), {filename: file});
    }
    console.log('PASS: syntax of all plugin JavaScript files');
    for (const tab of ['queue', 'settings']) {
        const listeners = {};
        const message = {textContent: ''};
        const button = {disabled: false, dataset: {itemId: '4'}, parentElement: {querySelector: () => message}, addEventListener: (event, fn) => { listeners[event] = fn; }};
        const calls = [];
        let reloaded = false;
        const context = {
            URLSearchParams,
            document: {
                querySelectorAll: () => [button],
                getElementById: (id) => id === 'acm-test-openai-connection' ? button : id === 'acm-openai-test-result' ? message : {value: 'gpt-4.1'},
            },
            window: {location: {reload: () => { reloaded = true; }}},
            acmQueueGeneration: {ajaxUrl: '/mock-ajax', nonce: 'queue-nonce', strings: {generating: 'working', failure: 'failed', connectionError: 'offline'}},
            acmOpenAiTest: {ajaxUrl: '/mock-ajax', nonce: 'settings-nonce', strings: {testing: 'working', success: 'done', failure: 'failed'}},
            fetch: async (url, options) => { calls.push({url, options}); return {ok: true, json: async () => ({success: true, data: {message: 'fixture success'}})}; },
        };
        const file = tab === 'queue' ? 'queue-generation.js' : 'admin-settings.js';
        vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js', file), 'utf8'), context);
        assert.equal(calls.length, 0);
        await listeners.click();
        assert.equal(calls.length, 1);
        assert.equal(calls[0].url, '/mock-ajax');
        const data = new URLSearchParams(calls[0].options.body);
        assert.equal(data.get('nonce'), tab + '-nonce');
        assert.equal(data.get('action'), tab === 'queue' ? 'acm_generate_item' : 'acm_test_openai_connection');
        assert.equal(message.textContent, 'fixture success');
        if (tab === 'settings') { assert.equal(data.get('model'), 'gpt-4.1'); assert.equal(button.disabled, false); }
        else { assert.equal(data.get('item_id'), '4'); assert.equal(reloaded, true); }
        context.fetch = async () => { throw new Error('fixture disconnection'); };
        await listeners.click();
        assert.equal(message.textContent, tab === 'queue' ? 'offline' : 'failed');
        console.log('PASS: ' + tab + ' explicit click, nonce, mock response, and disconnection; no request on load');
    }
    const elements = {};
    for (const id of ['acm-topic-input-mode', 'acm-topic', 'acm-topics', 'acm-single-topic-row', 'acm-multiple-topics-row', 'acm-topic-count', 'acm-primary-keyword-label', 'acm-secondary-keywords-label']) {
        elements[id] = {value: '', hidden: false, textContent: '', listeners: {}, addEventListener(event, fn) { this.listeners[event] = fn; }, setCustomValidity(message) { this.validityMessage = message; }};
    }
    elements['acm-topic-input-mode'].value = 'single';
    let formNetworkCalls = 0;
    const formContext = {
        document: {getElementById: id => elements[id] || null},
        acmContentForm: {limit: 5, strings: {count: 'count: ', singleKeyword: 'single', sharedKeyword: 'shared', singleSecondary: 'secondary', sharedSecondary: 'shared secondary', duplicate: 'duplicate', tooMany: 'too many'}},
        fetch() { formNetworkCalls++; throw new Error('network forbidden'); },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/content-page.js'), 'utf8'), formContext);
    assert.equal(elements['acm-multiple-topics-row'].hidden, true);
    assert.equal(elements['acm-topic'].required, true);
    elements['acm-topic-input-mode'].value = 'multiple';
    elements['acm-topics'].value = 'A\nB\n\nC\nD\nE';
    elements['acm-topic-input-mode'].listeners.change();
    assert.equal(elements['acm-single-topic-row'].hidden, true);
    assert.equal(elements['acm-topic-count'].textContent, 'count: 5 / 5');
    assert.equal(elements['acm-primary-keyword-label'].textContent, 'shared');
    elements['acm-topics'].value = 'يادگيري كار\nیادگیری  کار';
    elements['acm-topics'].listeners.input();
    assert.equal(elements['acm-topics'].validityMessage, 'duplicate');
    elements['acm-topics'].value = '1\n2\n3\n4\n5\n6';
    elements['acm-topics'].listeners.input();
    assert.equal(elements['acm-topics'].validityMessage, 'too many');
    assert.equal(formNetworkCalls, 0);
    console.log('PASS: topic modes, independent count, normalized duplicate feedback, limit and zero API calls');
}());
