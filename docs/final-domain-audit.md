# Final audit: exams, scoring, certificates

Audit dates: 24-25 October 2026 (Asia/Jakarta). All data created by the commands below is synthetic and isolated from the application's local database. This document supplements the overall final QA report; it does not establish production readiness by itself.

## Executed evidence

The previously completed targeted domain run passed **129 tests / 657 assertions**, in 24.602 seconds on PHP 8.3.31, using PHPUnit's SQLite `:memory:` configuration. This includes **91 new reference cases** (63 unit cases and 28 feature cases) and 38 existing domain regressions. This is a historical subset of the final backend coverage and is not added to its test count.

The final backend run passed **309 tests / 2,426 assertions**, in **49.904 seconds**. The final frontend run passed **17 tests / 0 failures**, including the legacy rubric normalization regression, and the production asset build passed. The final MySQL operational exercise passed **19 of 19 checks**, including concurrent starts, autosaves, scoring/issuance, usage caps, repeated manual review and ten simultaneous archive calls. The main, restored-copy and post-concurrency integrity audits each passed **82 checks / 0 failures**.

The registration concurrency exercise returned one HTTP 201 and nine HTTP 422 responses, retaining one user, profile and registration audit. Seven isolated config/route/view cache checks also passed. Raw evidence is recorded under `tmp/qa/final/`, including `frontend-output.txt`, the completed backend/build outputs, `operations/report.json`, and the integrity and registration/cache reports. These are final executed backend/operations results; the final coordinated browser evidence records 76 unique passing scenarios, and the production decision remains NOT READY FOR DEPLOYMENT for the runtime/infrastructure reasons in the overall report. No browser retry was run as part of this documentation update.

```powershell
php vendor/bin/phpunit tests/Unit/ScoringReferenceMatrixTest.php tests/Feature/FinalDomainReferenceTest.php tests/Feature/ScoringFinalizationTest.php tests/Feature/AssessmentFlowTest.php tests/Feature/ReviewQueueTest.php tests/Feature/TestPolicyRegressionTest.php tests/Feature/TestTypeIsolationTest.php --colors=never
```

| Coverage | Executed result |
|---|---|
| All 18 question types | 54 known response cases: correct, incorrect and empty for 16 automatic types; two manual types explicitly return no automatic grade |
| Partial scoring | Nine reference cases across response sets, matching, drag and drop, blanks and ordering; duplicate choices count once; incorrect choices cannot produce negative scores |
| Internal A1–C2 classification | Twelve boundary cases, including immediately below every threshold, zero and 100; level thresholds are internal INBAF settings |
| Score scale and pass criteria | Reference percentages converted to scale 600, pass threshold 60% / 360 points; result, dashboard and public certificate values agree |
| Aggregation methods | For the fractional reference fixture, weighted competency and equal competency produce normalized 44.44 / scaled 266.67; weighted questions correctly produces 50 / 300. All three use independently calculated expected values. |
| Manual writing/speaking | Captured weighted rubrics determine the total; all criteria required; unknown criteria rejected; empty rubrics retain explicit overall grading |
| Invalid rubric activation | Seven invalid schema cases rejected before writing questions or versions |
| Logical replay | Identical manual grade requests return the previous result without duplicate assessments; conflicting replay receives HTTP 409; completed scoring cannot rewrite the official result |
| Failure recovery | Missing certificate signing key causes scoring and issuance to roll back together; restoring the synthetic key allows one successful retry |
| Stale account state | Starting with a stale active `User` object after deactivation creates no attempt, usage reservation or increment |
| Existing regressions | Snapshot and historical-name preservation, confidential-question isolation, IDOR, question usage limits, schedules/expiry, manual pending state, revoked/expired/not-found public verification, file ownership, signed images and placement report authorization |

`ScoringReferenceMatrixTest` covers `single_choice`, `multiple_response`, `true_false`, `matching`, `drag_drop`, `fill_blank`, `dropdown`, `short_answer`, `essay`, `sentence_ordering`, `word_ordering`, `listening`, `reading`, `image`, `speaking`, `video`, `error_identification` and `conversation`.

## Confirmed defects and corrections

| ID | Severity | Root cause and impact | Correction | Regression evidence |
|---|---|---|---|---|
| DS-01 | High | Section percentages were rounded before aggregation, and the rounded total was then converted to another scale. For sections earning 1/3 and 5/9, the normalized mean became 44.45 instead of 44.44; conversion to scale 600 could also discard precision. | `ScoringService` retains full precision through aggregation and scale conversion, then rounds each reported value to two decimals. | Weighted competency/equal competency: 44.44 and 266.67. Weighted questions: 50 and 300. Final 309-test backend regression PASS. |
| DS-02 | High | Manual review accepted a client-supplied overall grade even when weighted criterion scores contradicted it. Rubric names/weights were not validated before activation. | The server calculates from the attempt's captured rubric; validates complete criteria and positive weights; accepts legacy rubric formats. The review UI follows the same weights and normalizes names. | Clarity 90×3 plus Register 70×1 produces 85 even if the request supplies overall 100; missing/unknown criteria and seven malformed rubrics rejected. |
| DS-03 | High | While another answer remained pending, replaying a grade could add assessments and change an already reviewed answer. | Lock the attempt and answer, return an identical existing assessment, reject conflicting changes with 409. | Repeated first grade retains exactly one assessment/audit; final identical retry retains two assessments and one certificate. |
| DS-04 | High | Calling `ScoringService::finalize` again could rewrite a completed official result and emit repeated grading audits while the signed certificate retained its first values. | Completed results return unchanged. Corrections require a separate explicit procedure. | Replayed scoring after an out-of-band answer mutation leaves raw result JSON, certificate payload and grading audit count unchanged. |
| DS-05 | High | `AttemptService::start` acquired a user lock but continued to use the caller's stale user object. | Use the freshly locked account row for availability checks and snapshot creation. | Deactivation between object retrieval and start is rejected without any domain rows or counters changing. |
| DS-06 | Medium | Both certificate templates displayed only one decimal despite official results retaining two. A score of 92.55 appeared as 92.6. | Both total and competency score displays retain two decimals. Signed data is unchanged. | Blade regression plus actual PDF text extraction across eight variants confirms 92.55. |
| DS-07 | High | The real MySQL concurrency probe found duplicate-key failures when several participants started against the same question. `firstOrCreate` could read a stale REPEATABLE READ snapshot after waiting for the question lock. | Read the existing question version with `FOR UPDATE` while holding the question lock, then create it only when absent. Historical version snapshots are never overwritten. | Final 19-check MySQL exercise PASS: ten users compete for a three-use question, with exactly three admitted attempts and no duplicate version error. |
| DS-08 | High | A `FOR UPDATE` lookup for a missing active attempt acquired secondary-index gap locks. Different participants then waited on shared question locks while the question-lock holder waited on an attempt insert intention, exhausting deadlock retries. Sanitized InnoDB lock metadata confirmed this cycle. | The account row still serializes starts for that account. Look up an existing attempt ID without a locking range read, then lock only its primary-key row when found and recheck its status. | Final MySQL exercise PASS: twenty participants admitted in concurrent batches of ten; ten simultaneous starts for one participant return one logical attempt, answer set and start audit. |
| DS-09 | High | Manual review opened a repeatable-read snapshot before waiting for the parent attempt lock, so a waiting reviewer could miss the assessment committed by the preceding reviewer and incorrectly reject an identical retry. | Resolve only the parent attempt ID outside the transaction, acquire the parent attempt first, then use locking/current reads for the answer and existing assessment; retry genuine transaction deadlocks up to five times. | Final MySQL exercise PASS: ten concurrent grading processes using one reviewer account, across six attempts with two essays each, including four repeated calls for one attempt; exactly twelve assessments, six results of 80 and six certificates. |
| DS-10 | Medium | Legacy rubrics stored as string arrays remained strings when opened in the question editor. Binding `criterion.name` could not edit those entries, even though the backend supported that historical format. | A shared `normalizeRubric` helper converts legacy string arrays, associative weighted maps and modern criterion objects into editable criterion objects; the editor and review form use the same normalization without mutating the input. | The completed 17-test frontend run passed the new regression for all three representations, trimmed names, retained weights/descriptions, non-mutation and empty input. |

The misleading PDF upload invitation in `AdminWorkspace.vue` was also removed at the browser auditor's request. Its finding is counted in that audit, not duplicated here.

## Actual PDF and QR validation

Eight real certificates were signed and rendered by the application's `CertificateController`, from a newly migrated **SQLite memory database**: modern/formal × A4/Letter × landscape/portrait. The probe refuses other database configurations. No main database migration, seeding or data cleanup occurred.

An independent PyMuPDF parser opened all eight PDFs without repair. Each contains one page, the expected synthetic holder, level C2 and the exact score 92.55. OpenCV decoded the QR image extracted from each actual PDF. Each decoded URL matches the hash of that certificate's issued verification URL, uses the configured `/verify/` route, and contains the expected 43-character random token. Verification URLs and tokens are omitted from the JSON reports.

| Artifact | Bytes | Pages | Parse, score and QR |
|---|---:|---:|---|
| modern-a4-landscape | 28,894 | 1 | PASS |
| modern-a4-portrait | 28,922 | 1 | PASS |
| modern-letter-landscape | 29,027 | 1 | PASS |
| modern-letter-portrait | 29,134 | 1 | PASS |
| formal-a4-landscape | 41,252 | 1 | PASS |
| formal-a4-portrait | 41,333 | 1 | PASS |
| formal-letter-landscape | 41,393 | 1 | PASS |
| formal-letter-portrait | 41,399 | 1 | PASS |

Raw evidence: `tmp/qa/certificate-artifacts/verification-report.json`, PDFs and rendered PNGs in the same directory. Sizes can vary between runs because certificate identifiers and QR images are random. Independently parsing/rendering/decoding each artifact took 108.51–214.88 ms on this machine; these are **not** production response-time or PHP PDF generation measurements.

Modern A4 landscape and formal Letter portrait were visually inspected. Names, seven competency scores, exact totals, signatures, expiry dates and QR codes were readable; no page overflow was visible in those inspected layouts. The other six variants passed document parsing/rendering, page-count, text and QR checks; no claim of manual visual inspection is made for them.

Reproduction after supplying temporary QA-only PyMuPDF and OpenCV dependencies:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
$env:CACHE_STORE='array'
$env:SESSION_DRIVER='array'
$env:MAIL_MAILER='array'
php tests/Operations/certificate-artifact-probe.php
$env:PYTHONPATH=Join-Path (Get-Location) 'tmp/qa/python-tools'
python tests/Operations/verify-certificate-artifacts.py
```

The temporary decoder packages live under ignored `tmp/qa/python-tools`; they are not application dependencies. Use a fresh terminal afterward so testing-only environment variables do not affect ordinary development commands.

## Limits and operational implications

- SQLite feature tests do not prove MySQL row locking or production load capacity. The final real MySQL exercise passed all 19 operational checks and all 82 integrity checks at each audit point. These local results do not establish production capacity or a supported production database version.
- Browser audio playback/recording, offline recovery, multi-tab behavior and production asset checks belong to the browser audit; no browser server was started by this audit.
- Regrading a completed official result is intentionally blocked. The application has no approved historical correction/reissue workflow; do not alter answers or results directly to perform a correction.
- Existing completed results are not recalculated automatically after the precision fix. Any institution-approved correction of historical results must preserve an audit trail and address an already issued certificate explicitly.
- Historical certificate signatures depend on the original application key. Back up that key and certificate image bytes with the database/media backup; do not rotate it without a documented certificate migration procedure.
- Actual production domain, TLS, mail delivery, deployment storage and scanner behavior on physical devices were not validated here. This domain evidence alone cannot support `READY FOR DEPLOYMENT`.
