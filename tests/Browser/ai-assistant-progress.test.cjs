const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const { test } = require('node:test');

const blade = readFileSync('resources/views/filament/hooks/ai-assistant.blade.php', 'utf8');
const script = blade.match(/<script>([\s\S]*?)<\/script>/)?.[1];

assert.ok(script, 'assistant component script should be present');

test('a second question in the same conversation shows progress until its first token', () => {
    const context = {};
    runInNewContext(script, context);
    const assistant = context.tpzAssistant();

    assistant.messages = [
        { role: 'user', content: 'First question' },
        { role: 'assistant', content: 'First answer', status: 'complete' },
        { role: 'user', content: 'Second question' },
        { role: 'assistant', content: '', status: 'streaming' },
    ];
    assistant.busy = true;

    assert.equal(assistant.busy && !assistant.hasCurrentAssistantText(), true);

    assistant.messages.at(-1).content = 'Second';

    assert.equal(assistant.busy && !assistant.hasCurrentAssistantText(), false);
});

test('the compact assistant starts with history collapsed and lets users rename inline', () => {
    const context = {};
    runInNewContext(script, context);
    const assistant = context.tpzAssistant();

    assert.equal(assistant.historyOpen, false);
    assert.equal(assistant.editingConversationId, null);
    assert.equal(assistant.deletePendingId, null);

    assistant.startRename({ id: 17, title: 'Old chat title' });
    assert.equal(assistant.editingConversationId, 17);
    assert.equal(assistant.editingTitle, 'Old chat title');

    assistant.cancelRename();
    assert.equal(assistant.editingConversationId, null);
    assert.equal(assistant.editingTitle, '');

    assistant.requestDelete(17);
    assert.equal(assistant.deletePendingId, 17);
    assistant.cancelDelete();
    assert.equal(assistant.deletePendingId, null);

    assert.ok(blade.includes('<strong>TPZ AI Assistant</strong>'));
    assert.ok(blade.includes('class="tpz-ai-inline-rename"'));
    assert.ok(blade.includes('class="tpz-ai-confirm-dialog"'));
    assert.ok(blade.includes('class="tpz-ai-shortcuts"'));
    assert.ok(!/\b(?:prompt|confirm)\(/.test(script), 'native browser modal calls should not be used');
});

test('user-provided HTML is escaped while assistant Markdown remains formatted', () => {
    const context = {};
    runInNewContext(script, context);
    const html = context.tpzSafeMarkdown('<img src=x onerror=alert(1)> **bold**');

    assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));
    assert.ok(html.includes('<strong>bold</strong>'));
    assert.ok(!html.includes('<img '));
});

test('stale server-side streaming replies become neutral history notices rather than fake live progress', async () => {
    const context = {
        fetch: async () => ({
            ok: true,
            json: async () => ({
                messages: [
                    { id: 1, role: 'user', content: 'Show inventory', status: 'complete' },
                    { id: 2, role: 'assistant', content: '', status: 'streaming' },
                ],
            }),
        }),
    };
    runInNewContext(script, context);
    const assistant = context.tpzAssistant();
    assistant.scroll = () => {};
    await assistant.select(7);

    assert.equal(assistant.messages.length, 2);
    assert.equal(assistant.messages[1].status, 'stale');
    assert.equal(assistant.busy, false);
    assert.ok(blade.includes("message.status!=='streaming' || !busy"));
    assert.ok(blade.includes("message === messages.at(-1) && message.status==='streaming'"));
    assert.ok(blade.includes('This earlier response may have been interrupted.'));
});

test('retry after reloading a failed reply uses its original question', async () => {
    const context = {};
    runInNewContext(script, context);
    const assistant = context.tpzAssistant();
    assistant.messages = [
        { role: 'user', content: 'Show inventory' },
        { role: 'assistant', content: '', status: 'failed' },
    ];
    let sent = 0;
    assistant.send = async () => { sent += 1; };
    await assistant.retry();
    assert.equal(sent, 1);
    assert.equal(assistant.draft, 'Show inventory');
    assert.equal(assistant.messages.length, 1);
    assert.ok(blade.includes("buffer.split(/\\r?\\n\\r?\\n/)"), 'SSE parser handles CRLF and LF boundaries');
});

test('streaming reply writes to the reactive Alpine message proxy without another question', async () => {
    // Alpine proxies objects when they are read from a reactive array.
    // Mutating a detached pre-push object skips the reactive setter and leaves
    // the answer invisible until another UI update (e.g. the next question).
    const writes = [];
    const wrapped = new WeakMap();
    const events = [
        { type: 'delta', text: 'Hello, this is the answer.' },
        { type: 'done', message_id: 42, metrics: { server_ms: 300 } },
    ];
    const stream = events.map(event => 'data: ' + JSON.stringify(event)).join('\r\n\r\n') + '\r\n\r\n';
    let reads = 0;
    const context = {
        AbortController,
        TextDecoder,
        performance: { now: () => 300 },
        fetch: async () => ({
            ok: true,
            body: {
                getReader: () => ({
                    read: async () => ++reads === 1
                        ? { done: false, value: new TextEncoder().encode(stream) }
                        : { done: true },
                }),
            },
        }),
    };
    runInNewContext(script, context);
    const assistant = context.tpzAssistant();
    assistant.conversationId = 7;
    assistant.draft = 'Hi';
    assistant.scroll = () => {};
    assistant.list = async () => {};
    assistant.headers = () => ({});
    assistant.messages = new Proxy([], {
        get(target, key, receiver) {
            const item = Reflect.get(target, key, receiver);
            if (typeof key !== 'string' || !/^\d+$/.test(key) || item === null || typeof item !== 'object') {
                return item;
            }
            if (!wrapped.has(item)) {
                wrapped.set(item, new Proxy(item, {
                    set(raw, field, value) {
                        writes.push({ field, value });
                        return Reflect.set(raw, field, value);
                    },
                }));
            }
            return wrapped.get(item);
        },
    });

    await assistant.send();

    assert.equal(assistant.messages.length, 2);
    assert.equal(assistant.messages.at(-1).content, 'Hello, this is the answer.');
    assert.equal(assistant.messages.at(-1).status, 'complete');
    assert.equal(assistant.busy, false);
    assert.ok(writes.some(change => change.field === 'content' && change.value === 'Hello, this is the answer.'),
        'streamed deltas must update a reactive proxy, not the original detached object');
    assert.ok(writes.some(change => change.field === 'status' && change.value === 'complete'),
        'completion must be observable without waiting for a second question');
});
