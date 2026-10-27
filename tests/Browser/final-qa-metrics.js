import fs from 'node:fs';

// Only aggregate operation labels are retained: never query strings, IDs,
// emails, payloads, cookies, verification links, or credential tokens.
export function observeHttp(page) {
    const samples = [];
    const operations = [
        [/^\/api\/dashboard$/, 'dashboard'],
        [/^\/api\/admin\/questions$/, 'question-bank'],
        [/^\/api\/attempts\/[^/]+\/answers$/, 'answer-save'],
        [/^\/api\/attempts\/[^/]+\/submit$/, 'attempt-submit'],
        [/^\/api\/admin\/reviews\/[^/]+$/, 'manual-grade'],
        [/^\/api\/certificates\/[^/]+\/pdf$/, 'certificate-pdf'],
        [/^\/api\/admin\/media$/, 'media-upload'],
        [/^\/api\/media\/[^/]+$/, 'media-read'],
        [/^\/api\/admin\/questions\/import$/, 'question-import'],
        [/^\/api\/admin\/questions\/export$/, 'question-export'],
    ];
    page.on('requestfinished', async request => {
        const operation = operations.find(([pattern]) => pattern.test(new URL(request.url()).pathname))?.[1];
        if (!operation) return;
        const response = await request.response();
        const milliseconds = request.timing().responseEnd;
        if (response && milliseconds >= 0) samples.push({operation, method: request.method(), status: response.status(), duration_ms: Math.round(milliseconds * 100) / 100});
    });
    return samples;
}

export function saveHttpMetrics(testInfo, samples) {
    fs.mkdirSync('tmp/qa/final/http', {recursive: true});
    const name = testInfo.title.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
    fs.writeFileSync('tmp/qa/final/http/' + name + '.json', JSON.stringify({scenario: testInfo.title, status: testInfo.status, samples}, null, 2));
}

export async function timedHttp(samples, operation, send) {
    const started = performance.now();
    const response = await send();
    samples.push({operation, method: 'GET', status: response.status(), duration_ms: Math.round((performance.now() - started) * 100) / 100});
    return response;
}
