# API INBAF

Profil Superadmin tersedia melalui `GET/PUT /api/account`, foto privat `GET /api/account/photo`, dan password `PUT /api/account/password` (5 permintaan/menit). Multipart upload memakai `POST /api/account` dengan `_method=PUT`; kirim `name,email` beserta `photo` opsional atau `remove_photo=true`. Respons berhasil memperbarui `user` dan `csrf_token`. Password memakai `current_password,password,password_confirmation`; role dan data akun historis tidak dapat diubah melalui profil. Detail validasi, penyimpanan dan sesi terdapat pada [profil Superadmin](superadmin-profile.md).

Endpoint akun tersebut juga melayani peserta aktif pada akun miliknya sendiri. Peserta dapat menyimpan `country,nationality,native_language,institution,phone,date_of_birth,gender,preferred_language`; data pribadi dikembalikan pada `user.profile`, bahasa pada `user.preferred_language`. `PUT /api/preferences` peserta menerima bahasa `id|en`, ukuran teks, atau keduanya. `PUT /api/profile` peserta tetap kompatibel dan memakai logika password serta bucket rate limit yang sama dengan `/api/account/password`. Field yang tidak dikirim dipertahankan; field opsional `null` dikosongkan. Nama historis hasil/sertifikat tidak diubah oleh profil. Lihat [kontrak profil peserta](participant-profile.md).

`GET /api/bootstrap` memuat `test_types` aktif dan paket dengan `test_type` serta `effective_policy`. `GET /api/packages`, `/api/dashboard`, `/api/results` dan `/api/admin/reports` mendukung filter `test_type=practice|placement|certification`. Lihat [mekanisme dan kebijakan](test-types.md).

| Endpoint tambahan | Hak akses | Fungsi |
| --- | --- | --- |
| `GET /api/admin/test-types` | Superadmin | Semua jenis dan kebijakan |
| `PUT /api/admin/test-types/{code}` | Superadmin | Kebijakan, skala, metode dan `level_thresholds` |
| `GET /api/admin/questions/{id}/usage` | Superadmin | Riwayat penggunaan berpaginasi |
| `PUT /api/preferences` | Login | `text_size`: `small`, `normal`, `large`, `extra_large`; peserta juga dapat mengirim `preferred_language`: `id`, `en` |
| `GET /api/results/{attempt}/placement-report` | Pemilik/Superadmin | PDF penempatan hasil final |

Paket admin mendukung `test_type`, `policy_overrides`, `sections[].difficulties`; soal mendukung `allowed_test_types`, `confidential`, `usage_limit`. Penghitung `usage_count` hanya diubah server. Detail hasil menyediakan `review_answers` menurut snapshot. Sesi tanpa timer mengembalikan `expires_at`/`remaining_seconds` null dan `rules.timer_enabled=false`. Endpoint events menyediakan jumlah perpindahan tab, ambang, dan `limit_exceeded` bila pemantauan aktif.

`GET /api/admin/packages/{id}` juga mengembalikan `effective_policy`. Editor memakai skala efektif paket sertifikasi lama dengan `policy_overrides=null` ketika membentuk override eksplisit, sehingga penyimpanan edit mempertahankan skala legacy. Mengosongkan override berarti memilih kebijakan jenis tes.

Dashboard aggregates use a common 0–100 scale, while individual results retain their original snapshot scale:

| Response field | Scale and behavior |
| --- | --- |
| `stats.average_score`, `stats.best_score` | Normalized completed scores; filtered by owner, test type, package and dates |
| `stats.aggregate_score_max` | Always `100` |
| `trend[].average_score`, `monthly_stats[].average_score` | Normalized monthly averages under the same filters |
| `stats.latest_score` | Original score from the latest completed result |
| `stats.latest_score_max` | Latest result's `score_max`, or `100` for legacy results without a recorded scale |
| `stats.score_max` | Compatibility alias for the latest result's scale |
| `recent_attempts[].score`, result-detail scores | Original individual scores; use each result's `score_max` |

Normalization uses `result.normalized_score` when available. Historical fallback is `score / result.score_max × 100`, with maximum `100` when no valid positive scale is recorded. The frontend ignores stale dashboard/result-list responses after a newer filter request starts.

`review_answers` is empty until completion or when the snapshotted review policy is disabled; confidential questions are excluded. Permitted review entries include sanitized `metadata`, including `left_items`, `right_items`/`matches`/`right_options`, and `blanks` with their labels and option sets. This lets the client resolve stored IDs to readable answer labels. Internal metadata and nested answer-bearing keys are redacted through the same metadata filter used for attempt payloads; approved correct answers remain in the review's separate `answer_schema`.

All endpoints use `/api`, JSON validation errors (422), server-side session authentication, CSRF protection for writes, and rate limits. Obtain a session by loading the application; send its CSRF token with write requests. There is no unrestricted bearer-token endpoint. User roles are `superadmin` and `participant`; admin routes require both an active user and `superadmin`.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/bootstrap` | Signed-in user or null, public settings, published packages, active competencies, question types, levels |
| POST | `/register` | `name,email,password,password_confirmation`; role always participant |
| POST | `/login` | `email,password`; session ID rotates |
| POST | `/logout` | Invalidate session |
| GET | `/dashboard` | Actual scoped database KPIs, trend, recent attempts, competencies, certificates |
| PUT | `/profile` | Name/email and country/nationality/native_language/institution |
| GET | `/packages` | `{packages:[]}` |
| GET | `/packages/{id}` | `{package:{...}}` with sections and usage |
| POST | `/packages/{id}/attempts` | Create or resume an attempt under transaction/attempt limit |
| GET | `/attempts/{uuid}` | Safe sections/questions, saved answers, server time and deadline |
| PUT | `/attempts/{uuid}/answers` | `question_key,response,marked?`; validates ownership and section window |
| POST | `/attempts/{uuid}/questions/{key}/activate` | First activation fixes the optional per-question deadline; revisits preserve it |
| POST | `/attempts/{uuid}/recordings` | Multipart `file,question_key,duration?`; private validated upload |
| POST | `/attempts/{uuid}/events` | Whitelisted event `type,metadata?` |
| POST | `/attempts/{uuid}/submit` | Atomic, repeatable finalization |
| GET | `/results` | `{results:[]}` owned submitted attempts |
| GET | `/results/{uuid}` | `{attempt,result,certificate}`; no premature final score |
| GET | `/certificates` | `{certificates:[]}` owned certificates |
| GET | `/certificates/{id}/pdf` | Authorized real PDF with QR |
| GET | `/verify/{token}` | Public valid/expired/revoked/not_found record, limited identity data |
| GET | `/media/{id}` | Authorized private media, supports HTTP file ranges |

Admin collections: `questions,packages,participants,competencies,groups,media,reviews,certificates,levels,audit,attempts`.

`GET /admin/{resource}` returns `{data,total,current_page,last_page,...}` and accepts `search,page,per_page` (maximum 100). Questions also accept `type,competency_id,status,difficulty`. Writable resources are questions, packages, participants, competencies, groups, and levels. Use POST to create, PUT `/{id}` to update. DELETE archives questions/packages/participants/competencies and deletes a stimulus group while preserving snapshotted exam data. Results, audit, and certificate content cannot be arbitrarily overwritten.

Additional admin endpoints:

| Method | Endpoint | Body/behavior |
|---|---|---|
| POST | `/admin/questions/{id}/duplicate` | New draft and initial version |
| POST | `/admin/questions/bulk` | `ids[],action` (`draft,review,active,archived`), versioned transaction |
| GET | `/admin/questions/template` | Download CSV template; `?format=xlsx` returns native Excel |
| GET | `/admin/questions/export` | CSV or `?format=xlsx`; spreadsheet formula execution neutralized |
| POST | `/admin/questions/import` | Multipart CSV/XLSX `file`; validates every row, max500, atomic import |
| POST | `/admin/media` | Multipart validated image/audio/video `file`, max50MB |
| POST | `/admin/reviews/{answer_id}` | `score` percentage0–100, `feedback`, optional rubric `scores` map |
| GET/PUT | `/admin/settings` | Validated flat settings map, audited mutation |
| POST | `/admin/certificates/{id}/revoke` | Required `reason` |

Objective `answer_schema.correct` references option IDs, an ordered array, or a mapping. Short text additionally supports `accepted` alternatives. Multiple response is exact-set by default; `partial_credit` explicitly enables partial scoring. Matching/drag-drop use maps, ordering uses arrays, grouped blanks use maps. The attempt snapshots keys, weights, levels, settings, media references, and certificate policy. Editing the bank changes future attempts only.

`GET /admin/reports` exports actual attempt results as CSV, using optional `date_from,date_to,package_id` filters. Only final results receive a final score in the report. Public configured branding is available from `/branding/logo` and `/branding/favicon`; other media remains authorized.

`GET /results/{uuid}` also returns `previous_result` for the previous completed attempt by the same participant on the same package and test type. Scores from repeated sections of a competency are aggregated by relative section weights; `weight_percentage` is calculated from total weights.

Late writes return HTTP 422 with `code: question_expired` or `section_closed`, `expired_question_key`, and the last accepted `answer`. Clients must retire those queued changes and restore the server response. A future section returns retryable `section_not_started`. Question deadlines are capped by the attempt deadline and, for locked sections, the section deadline.
