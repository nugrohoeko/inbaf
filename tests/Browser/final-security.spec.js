import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';

const sanitizer = readFileSync(new URL('../../resources/js/security/sanitize.js', import.meta.url), 'utf8').replace('export function sanitize', 'function sanitize');
// Construct the callback in Node, then let Playwright execute its source. No
// runtime eval is needed in the page, whose production CSP forbids eval.
const inspectSanitizer = new Function(sanitizer + `;
        const payload = '<p>Allowed <strong>formatting</strong></p><a href="java&#10;script:window.__unsafe=1">tab scheme</a><a href="&#106;avascript:window.__unsafe=1">entity scheme</a><a href="vbscript:msgbox(1)">legacy scheme</a><svg><a xlink:href="javascript:window.__unsafe=1">SVG</a></svg><math><mtext><img src=x onerror="window.__unsafe=1"></mtext></math><img src="https://external-tracker.invalid/pixel"><img src="/brand/logo.svg" onerror="window.__unsafe=1"><iframe srcdoc="bad"></iframe><a href="https://example.org/help">Help</a>';
        const host = document.createElement('div');
        host.innerHTML = sanitize(payload);
        return {
            unsafe: window.__unsafe || false,
            links: [...host.querySelectorAll('a')].map(a => a.getAttribute('href')),
            images: [...host.querySelectorAll('img')].map(img => img.getAttribute('src')),
            foreign: host.querySelectorAll('svg,math,iframe,[onerror],[onclick]').length,
            formatting: host.querySelector('p strong')?.textContent,
            safeLinkRel: host.querySelector('a[href="https://example.org/help"]')?.getAttribute('rel'),
        };
`);

test('authored content rejects obfuscated executable URLs, foreign namespaces, and tracking images', async ({page}) => {
    expect((await page.request.get('/up')).status()).toBe(200);
    // The SPA GET fallback redirects anonymous private paths to login; the
    // framework's upload route rejects unsigned writes. Inspect the original
    // response so following a login redirect cannot look like file access.
    const privateRead = await page.request.get('/storage/final-qa-unsigned', {maxRedirects: 0});
    expect(privateRead.status()).toBe(302);
    expect(new URL(privateRead.headers().location, page.url() === 'about:blank' ? 'http://127.0.0.1:8001' : page.url()).pathname).toBe('/login');
    expect([403, 404]).toContain((await page.request.put('/storage/final-qa-unsigned', {data: 'QA'})).status());
    await page.goto('/login');
    const result = await page.evaluate(inspectSanitizer);
    expect(result.unsafe).toBe(false);
    expect(result.links).toEqual([null, null, null, 'https://example.org/help']);
    expect(result.images).toEqual([null, '/brand/logo.svg']);
    expect(result.foreign).toBe(0);
    expect(result.formatting).toBe('formatting');
    expect(result.safeLinkRel).toBe('noopener noreferrer');
});
