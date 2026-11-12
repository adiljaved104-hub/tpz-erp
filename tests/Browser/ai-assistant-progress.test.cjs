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
