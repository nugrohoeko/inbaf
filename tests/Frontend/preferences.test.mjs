import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/store.js', import.meta.url), 'utf8');

function deferred() {
    let resolve;
    const promise = new Promise(done => {resolve = done;});
    return {promise, resolve};
}

function response(data) {
    const payload = structuredClone(data);
    return {ok: true, status: 200, json: async () => structuredClone(payload)};
}

function createStore(fetch) {
    const storage = new Map();
    const documentElement = {dataset: {}, classList: {toggle() {}}, style: {setProperty() {}}};
    const csrf = {content: 'test-token'};
    const context = vm.createContext({
        // Stub imported libraries and browser boundaries; execute the real store functions.
        reactive: value => value,
        computed: getter => ({get value() {return getter();}}),
        en: {}, id: {}, fetch,
        localStorage: {
            getItem: key => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, String(value)),
        },
        document: {documentElement, querySelector: selector => selector.startsWith('meta') ? csrf : null},
        location: {pathname: '/admin/settings', search: '', origin: 'http://inbaf.test'},
        history: {pushState() {}}, window: {scrollTo() {}},
        addEventListener() {}, setTimeout() {}, FormData: class {}, URL,
    });
    const executable = source
        .replace(/^import .*;\r?$/gm, '')
        // The DOM sanitizer is exercised in the browser security suite. This
        // preference harness only strips its module linkage, like other imports.
        .replace(/^export \{[^}]+\} from ['"][^'"]+['"];\r?$/gm, '')
        .replace(/\bexport (?=(?:const|let|var|function|async function)\b)/g, '');
    vm.runInContext(executable + '\nglobalThis.subject = {state, applyTextSize, setTextSize, applyLanguage, setLanguage, bootstrap, clearSession, api};', context, {
        filename: 'resources/js/store.js',
    });
    const store = context.subject;
    store.state.user = {id: 1, text_size: 'normal'};
    store.applyTextSize('normal');
    return {store, storage, documentElement, csrf};
}

test('a stale bootstrap response cannot undo a preference saved after bootstrap started', async () => {
    const staleBootstrap = deferred();
    let persisted = 'normal';
    const {store, storage, documentElement} = createStore(async (url, options) => {
        if (url === '/api/bootstrap') return staleBootstrap.promise;
        assert.equal(url, '/api/preferences');
        persisted = JSON.parse(options.body).text_size;
        return response({user: {id: 1, text_size: persisted}});
    });

    const refresh = store.bootstrap();
    await store.setTextSize('large');
    assert.equal(persisted, 'large');
    assert.equal(store.state.textSizeSaving, false);
    staleBootstrap.resolve(response({user: {id: 1, text_size: 'normal'}, settings: {}}));
    await refresh;

    assert.equal(store.state.textSize, 'large');
    assert.equal(store.state.user.text_size, 'large');
    assert.equal(documentElement.dataset.textSize, 'large');
    assert.equal(storage.get('inbaf-text-size'), 'large');
});

test('a bootstrap response started before logout cannot restore the private account', async () => {
    const staleBootstrap = deferred();
    const {store, csrf} = createStore(async () => staleBootstrap.promise);
    const refresh = store.bootstrap();
    store.clearSession(true);
    csrf.content = 'new-logout-token';
    staleBootstrap.resolve(response({user: {id: 1, text_size: 'normal'}, settings: {}, csrf_token: 'stale-token'}));
    await refresh;

    assert.equal(store.state.user, null);
    assert.equal(store.state.path, '/login');
    assert.equal(csrf.content, 'new-logout-token');
});

test('an expired authenticated API session clears the account and returns to login', async () => {
    const {store} = createStore(async () => ({ok: false, status: 401, json: async () => ({message: 'Unauthenticated.'})}));
    await assert.rejects(store.api('/account'), error => error.status === 401);
    assert.equal(store.state.user, null);
    assert.equal(store.state.path, '/login');
});

test('an inactive account response immediately clears private UI and discards an outstanding bootstrap', async () => {
    const staleBootstrap = deferred();
    const {store, csrf} = createStore(async url => url === '/api/bootstrap' ? staleBootstrap.promise : {
        ok: false, status: 403, json: async () => ({message: 'Account inactive.', code: 'ACCOUNT_INACTIVE'}),
    });
    const refresh = store.bootstrap();
    await assert.rejects(store.api('/account'), error => error.status === 403 && error.data.code === 'ACCOUNT_INACTIVE');
    assert.equal(store.state.user, null);
    assert.equal(store.state.path, '/login');
    csrf.content = 'inactive-session-token';
    staleBootstrap.resolve(response({user: {id: 1, text_size: 'normal'}, settings: {}, csrf_token: 'stale-bootstrap-token'}));
    await refresh;
    assert.equal(store.state.user, null);
    assert.equal(store.state.path, '/login');
    assert.equal(csrf.content, 'inactive-session-token');
});

test('an ordinary forbidden response preserves the authenticated account', async () => {
    const {store} = createStore(async () => ({ok: false, status: 403, json: async () => ({message: 'Forbidden.'})}));
    await assert.rejects(store.api('/admin/users'), error => error.status === 403);
    assert.equal(store.state.user.id, 1);
    assert.equal(store.state.path, '/admin/settings');
});

for (const {status, code} of [{status: 401}, {status: 419}, {status: 403, code: 'ACCOUNT_INACTIVE'}]) {
    test('a delayed '+(code || status)+' from a previous session preserves the newly authenticated account and CSRF token', async () => {
        const expiredRequest = deferred();
        const {store, csrf} = createStore(async url => {
            if (url === '/api/account') return expiredRequest.promise;
            assert.equal(url, '/api/bootstrap');
            return response({
                user: {id: 2, role: 'participant', text_size: 'extra_large', preferred_language: 'id'},
                settings: {}, csrf_token: 'new-account-token',
            });
        });
        const staleFailure = assert.rejects(store.api('/account'), error => error.status === status);
        store.clearSession(true);
        csrf.content = 'logout-token';
        await store.bootstrap();
        store.state.path = '/dashboard';
        assert.equal(store.state.user.id, 2);
        assert.equal(csrf.content, 'new-account-token');

        expiredRequest.resolve({ok: false, status, json: async () => ({message: 'Old session expired.', code, csrf_token: 'old-account-token'})});
        await staleFailure;

        assert.equal(store.state.user.id, 2);
        assert.equal(store.state.user.text_size, 'extra_large');
        assert.equal(store.state.user.preferred_language, 'id');
        assert.equal(store.state.path, '/dashboard');
        assert.equal(csrf.content, 'new-account-token');
    });
}

test('a stale bootstrap cannot undo a participant language saved after it started', async () => {
    const stale = deferred();
    const {store, storage, documentElement} = createStore(async (url, options) => {
        if (url === '/api/bootstrap') return stale.promise;
        assert.equal(url, '/api/preferences');
        assert.equal(JSON.parse(options.body).preferred_language, 'id');
        return response({user: {id: 1, role: 'participant', preferred_language: 'id'}});
    });
    store.state.user.role = 'participant';
    const refresh = store.bootstrap();
    await store.setLanguage('id');
    stale.resolve(response({user: {id: 1, role: 'participant', text_size: 'normal', preferred_language: 'en'}, settings: {}}));
    await refresh;
    assert.equal(store.state.language, 'id');
    assert.equal(store.state.user.preferred_language, 'id');
    assert.equal(documentElement.lang, 'id');
    assert.equal(storage.get('inbaf-language'), 'id');
    assert.equal(store.state.languageSaving, false);
});

test('rapid participant language changes are persisted in order with the last choice retained', async () => {
    const arrived = deferred(), release = deferred(), writes = [];
    let persisted;
    const {store} = createStore(async (url, options) => {
        assert.equal(url, '/api/preferences');
        const value = JSON.parse(options.body).preferred_language;
        writes.push(value);
        if (writes.length === 1) {arrived.resolve(); await release.promise;}
        persisted = value;
        return response({user: {id: 1, role: 'participant', preferred_language: value}});
    });
    store.state.user.role = 'participant';
    const first = store.setLanguage('id');
    await arrived.promise;
    const last = store.setLanguage('en');
    assert.equal(store.state.language, 'en');
    release.resolve();
    await Promise.all([first, last]);
    assert.deepEqual(writes, ['id', 'en']);
    assert.equal(persisted, 'en');
    assert.equal(store.state.user.preferred_language, 'en');
    assert.equal(store.state.languageSaving, false);
});

test('a participant restores the account language while Superadmin retains its browser language', async () => {
    const {store} = createStore(async () => response({user: {id: 2, role: 'participant', text_size: 'normal', preferred_language: 'id'}, settings: {}}));
    await store.bootstrap();
    assert.equal(store.state.language, 'id');
    const admin = createStore(async () => response({user: {id: 3, role: 'superadmin', text_size: 'normal', preferred_language: 'id'}, settings: {}}));
    admin.store.applyLanguage('en');
    await admin.store.bootstrap();
    assert.equal(admin.store.state.language, 'en');
});

test('rapid preference writes reach the server in order and retain the last choice', async () => {
    const firstArrived = deferred();
    const releaseFirst = deferred();
    const writes = [];
    let persisted = 'normal';
    const {store} = createStore(async (url, options) => {
        assert.equal(url, '/api/preferences');
        const value = JSON.parse(options.body).text_size;
        writes.push(value);
        if (writes.length === 1) {
            firstArrived.resolve();
            await releaseFirst.promise;
        }
        persisted = value;
        return response({user: {id: 1, text_size: value}});
    });

    const first = store.setTextSize('large');
    await firstArrived.promise;
    const second = store.setTextSize('normal');
    const last = store.setTextSize('extra_large');
    assert.deepEqual(writes, ['large']);
    assert.equal(store.state.textSize, 'extra_large');
    assert.equal(store.state.textSizeSaving, true);
    releaseFirst.resolve();
    await Promise.all([first, second, last]);

    assert.deepEqual(writes, ['large', 'normal', 'extra_large']);
    assert.equal(persisted, 'extra_large');
    assert.equal(store.state.user.text_size, 'extra_large');
    assert.equal(store.state.textSizeSaving, false);
});

test('an account change hydrates its own preference and discards queued writes for the previous account', async () => {
    const accountBootstrap = deferred();
    const firstArrived = deferred();
    const releaseFirst = deferred();
    const writes = [];
    const {store, storage, documentElement} = createStore(async (url, options) => {
        if (url === '/api/bootstrap') return accountBootstrap.promise;
        assert.equal(url, '/api/preferences');
        writes.push(JSON.parse(options.body).text_size);
        firstArrived.resolve();
        await releaseFirst.promise;
        return response({user: {id: 1, text_size: 'large'}});
    });

    const refresh = store.bootstrap();
    const first = store.setTextSize('large');
    await firstArrived.promise;
    const queued = store.setTextSize('normal');
    accountBootstrap.resolve(response({user: {id: 2, text_size: 'extra_large'}, settings: {}}));
    await refresh;

    assert.equal(store.state.user.id, 2);
    assert.equal(store.state.user.text_size, 'extra_large');
    assert.equal(store.state.textSize, 'extra_large');
    assert.equal(store.state.textSizeSaving, false);
    releaseFirst.resolve();
    await Promise.all([first, queued]);

    assert.deepEqual(writes, ['large']);
    assert.equal(store.state.user.id, 2);
    assert.equal(store.state.user.text_size, 'extra_large');
    assert.equal(documentElement.dataset.textSize, 'extra_large');
    assert.equal(storage.get('inbaf-text-size'), 'extra_large');
});
