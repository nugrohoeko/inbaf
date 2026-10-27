import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const component = readFileSync(new URL('../../resources/js/components/AdminWorkspace.vue', import.meta.url), 'utf8');
const script = component.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\r?$/gm, '');

function createEditor() {
    const writes = [];
    const context = vm.createContext({
        ref: value => ({value}),
        computed: getter => ({get value() {return getter();}}),
        onMounted() {}, watch() {}, URLSearchParams,
        state: {path: '/admin/packages', query: '', competencies: [], test_types: [{code: 'certification', score_scale: 100}]},
        t: key => key, notify() {}, bootstrap: async () => {},
        list: response => response.data || [],
        api: async (path, options = {}) => {
            if (options.method === 'PUT') writes.push(JSON.parse(JSON.stringify(options.body)));
            return {data: []};
        },
    });
    // Execute the real editor functions while replacing only Vue and network boundaries.
    vm.runInContext(script + '\nglobalThis.subject = {form, setupPackagePolicy, save, normalizeRubric};', context, {
        filename: 'resources/js/components/AdminWorkspace.vue',
    });
    return {editor: context.subject, writes};
}

test('legacy rubric names, weighted maps and modern criteria remain editable with consistent names', () => {
    const {editor} = createEditor();
    const actual = value => JSON.parse(JSON.stringify(editor.normalizeRubric(value)));
    assert.deepEqual(actual([' Grammar ', 'Vocabulary']), [{name: 'Grammar', weight: 1}, {name: 'Vocabulary', weight: 1}]);
    assert.deepEqual(actual({' Clarity ': 3, Register: 1}), [{name: 'Clarity', weight: 3}, {name: 'Register', weight: 1}]);
    const original = [{name: ' Organization ', weight: 2, description: 'Connected ideas'}];
    assert.deepEqual(actual(original), [{name: 'Organization', weight: 2, description: 'Connected ideas'}]);
    assert.equal(original[0].name, ' Organization ');
    assert.deepEqual(actual(null), []);
});

function packageData(policyOverrides) {
    return {
        id: 1, test_type: 'certification', name: 'Renamed certification',
        policy_overrides: policyOverrides, attempts_limit: 2, question_selection: 'random',
        effective_policy: {score_scale: 700},
        settings: {lock_sections: false, show_review: true, randomize_options: false},
        sections: [{question_count: 1, question_ids: []}],
    };
}

test('editing a legacy certification keeps its effective score scale when saved', async () => {
    const {editor, writes} = createEditor();
    editor.form.value = packageData(null);
    editor.setupPackagePolicy();
    await editor.save();

    assert.equal(writes.length, 1);
    assert.deepEqual(writes[0].policy_overrides, {
        lock_sections: false, randomize_options: false, show_review: true,
        attempts_limit: 2, score_scale: 700,
    });
});

test('a package already inheriting type policy keeps inheritance after an edit', async () => {
    const {editor, writes} = createEditor();
    editor.form.value = packageData([]);
    editor.setupPackagePolicy();
    await editor.save();

    assert.deepEqual(writes[0].policy_overrides, {});
});

test('an explicit package score scale remains unchanged after an edit', async () => {
    const {editor, writes} = createEditor();
    editor.form.value = packageData({score_scale: 300});
    editor.setupPackagePolicy();
    await editor.save();

    assert.deepEqual(writes[0].policy_overrides, {score_scale: 300});
});
