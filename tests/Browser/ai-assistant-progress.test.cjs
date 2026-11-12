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
