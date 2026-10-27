# Release qualification probes

These opt-in CLI probes exercise application code against isolated synthetic data. They are not production load tests. Run from the repository root in a fresh terminal, with the normal local environment available. Never point PHPUnit or browser setup at the main database.

## MySQL restore and concurrency

```powershell
php tests/Operations/qualify-release.php --run
```

The explicit `--run` guard permits a private backup of the configured MySQL database and creates a **new** `inbaf_qa_rc_<12 hex digits>` database. It restores SQL and registered media into that copy, compares domain-table fingerprints, runs migrations there, and exercises starts, answer saves, submission, manual review, certificate issuance, question usage limits, archive replay, transaction failure, and a real queue worker with duplicate jobs. Ten separate PHP processes contend in the concurrency phases; twenty synthetic participants complete the automatic path. It does not reset, seed, repair, or drop the main database. It verifies the main domain fingerprints again at the end.

The restored database, backup and private media remain for review; the runner does not automatically delete them. They can contain copied local data. Keep `tmp/`, private storage and backup SQL out of Git, web serving and public artifacts. Retention/removal requires a separately checked procedure.

Results: `tmp/qa/final/operations/report.json`, `restored-integrity.json`, and `post-concurrency-integrity.json`. Worker manifests and error diagnostics in the same directory are private. A worker manifest from the most recent successful run can be used for the following probes; do not publish its contents.

```powershell
$qaManifest = Get-ChildItem -LiteralPath 'tmp/qa/final/operations' -Filter 'worker-*.json' |
    Where-Object { $_.Name -match '^worker-[a-f0-9]+-\d+\.json$' } |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1
php tests/Operations/register-concurrency.php --run $qaManifest.FullName
php tests/Operations/cache-smoke.php $qaManifest.FullName
php tests/Operations/query-benchmark.php $qaManifest.FullName
```

The registration probe synchronizes ten controller calls after the uniqueness query, expecting one HTTP-equivalent 201 result and nine validation 422 results, with one user/profile/audit. It excludes HTTP transport. The cache probe uses separate configuration/route/view cache paths under ignored QA storage, checks seven commands, and leaves the developer's bootstrap caches alone. Neither probe demonstrates a production scheduler, worker supervisor or mail delivery.

The query benchmark accepts only an existing guarded worker manifest for a generated QA database distinct from the configured database. It calls the real administrator dashboard, participant dashboard, and first question-bank page. One warm-up is excluded, followed by five samples per operation. `query-benchmark.json` reports N/min/p50/p95/max for controller wall time, query count, cumulative SQL time, and maximum single-query time, without SQL, bindings, identities, or response bodies. It uses a server-enforced read-only transaction and rejects non-read SQL before execution. Because the actual dashboard can finalize expired attempts, the benchmark marks dashboard measurements BLOCKED if expired activity is present; it never finalizes it. Timings include JSON serialization but exclude HTTP transport and middleware, and apply only to the reported local QA dataset. They do not establish production capacity or a production acceptance threshold.

## Certificate artifacts

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
$env:CACHE_STORE='array'
$env:SESSION_DRIVER='array'
$env:MAIL_MAILER='array'
php tests/Operations/certificate-artifact-probe.php
```

This probe refuses a database other than SQLite memory. It renders eight actual signed PDFs. `verify-certificate-artifacts.py` requires PyMuPDF and OpenCV in an isolated Python environment; it parses/renders the PDFs and decodes each embedded QR. Reports omit verification URLs/tokens. Raw PDF/PNG artifacts remain private under `tmp/qa/certificate-artifacts`.

Open a fresh terminal afterward so testing environment overrides cannot affect ordinary application commands. See [the final QA report](../../docs/final-qa-report.md) and [release runbook](../../docs/release-runbook.md) for results, limits and deployment steps.
