# Inventaris endpoint aplikasi final

60 definisi route aplikasi: 59 API dan satu shell SPA. Tiga route framework menambah total menjadi **63 definisi**: `GET/HEAD /up` untuk health, serta `GET/HEAD` dan `PUT /storage/{path}` yang didaftarkan filesystem Laravel. GET storage didahului fallback SPA; pengunjung anonim diarahkan ke login. Pemeriksaan browser final memeriksa respons asli tersebut tanpa mengikuti redirect, health, dan penolakan PUT storage tanpa signature. File aplikasi dibaca melalui endpoint API berizin. Metode HEAD mengikuti GET. Inventaris bukan klaim bahwa setiap kombinasi parameter memiliki tes browser tersendiri.

| Metode | Route | Controller |
| --- | --- | --- |
| GET / HEAD | `/api/account` | `AccountController@show` |
| PUT | `/api/account` | `AccountController@update` |
| PUT | `/api/account/password` | `AccountController@password` |
| GET / HEAD | `/api/account/photo` | `AccountController@photo` |
| POST | `/api/admin/certificates/{certificate}/revoke` | `CertificateController@revoke` |
| POST | `/api/admin/media` | `MediaController@store` |
| POST | `/api/admin/questions/bulk` | `AdminController@bulkQuestions` |
| GET / HEAD | `/api/admin/questions/export` | `AdminController@exportQuestions` |
| POST | `/api/admin/questions/import` | `AdminController@importQuestions` |
| GET / HEAD | `/api/admin/questions/template` | `AdminController@template` |
| POST | `/api/admin/questions/{id}/duplicate` | `AdminController@duplicate` |
| GET / HEAD | `/api/admin/questions/{id}/usage` | `AdminController@questionUsage` |
| GET / HEAD | `/api/admin/reports` | `ReportController@export` |
| POST | `/api/admin/reviews/{id}` | `AdminController@assess` |
| GET / HEAD | `/api/admin/settings` | `AdminController@settings` |
| PUT | `/api/admin/settings` | `AdminController@saveSettings` |
| GET / HEAD | `/api/admin/test-types` | `TestTypeController@index` |
| PUT | `/api/admin/test-types/{code}` | `TestTypeController@update` |
| GET / HEAD | `/api/admin/users` | `UserManagementController@index` |
| POST | `/api/admin/users` | `UserManagementController@store` |
| DELETE | `/api/admin/users/{id}` | `UserManagementController@destroy` |
| GET / HEAD | `/api/admin/users/{id}` | `UserManagementController@show` |
| PUT | `/api/admin/users/{id}` | `UserManagementController@update` |
| POST | `/api/admin/users/{id}/invite` | `UserManagementController@invite` |
| GET / HEAD | `/api/admin/users/{id}/photo` | `UserManagementController@photo` |
| POST | `/api/admin/users/{id}/reset-password` | `UserManagementController@resetPassword` |
| POST | `/api/admin/users/{id}/status` | `UserManagementController@status` |
| GET / HEAD | `/api/admin/{resource}` | `AdminController@index` |
| POST | `/api/admin/{resource}` | `AdminController@store` |
| DELETE | `/api/admin/{resource}/{id}` | `AdminController@destroy` |
| GET / HEAD | `/api/admin/{resource}/{id}` | `AdminController@show` |
| PUT | `/api/admin/{resource}/{id}` | `AdminController@update` |
| GET / HEAD | `/api/attempts/{attempt}` | `AttemptController@show` |
| PUT | `/api/attempts/{attempt}/answers` | `AttemptController@answer` |
| POST | `/api/attempts/{attempt}/events` | `AttemptController@event` |
| POST | `/api/attempts/{attempt}/questions/{key}/activate` | `AttemptController@activateQuestion` |
| POST | `/api/attempts/{attempt}/recordings` | `AttemptController@recording` |
| POST | `/api/attempts/{attempt}/submit` | `AttemptController@submit` |
| GET / HEAD | `/api/bootstrap` | `AuthController@bootstrap` |
| GET / HEAD | `/api/branding/{kind}` | `MediaController@branding` |
| GET / HEAD | `/api/certificates` | `ParticipantController@certificates` |
| GET / HEAD | `/api/certificates/{certificate}/pdf` | `CertificateController@pdf` |
| GET / HEAD | `/api/dashboard` | `ParticipantController@dashboard` |
| POST | `/api/login` | `AuthController@login` |
| POST | `/api/logout` | `AuthController@logout` |
| GET / HEAD | `/api/media/{media}` | `MediaController@show` |
| GET / HEAD | `/api/notifications` | `NotificationController@index` |
| GET / HEAD | `/api/packages` | `ParticipantController@packages` |
| GET / HEAD | `/api/packages/{package}` | `ParticipantController@package` |
| POST | `/api/packages/{package}/attempts` | `AttemptController@store` |
| PUT | `/api/preferences` | `AuthController@preferences` |
| PUT | `/api/profile` | `ParticipantController@profile` |
| POST | `/api/register` | `AuthController@register` |
| GET / HEAD | `/api/results` | `ParticipantController@results` |
| GET / HEAD | `/api/results/{attempt}` | `ParticipantController@result` |
| GET / HEAD | `/api/results/{attempt}/placement-report` | `PlacementReportController@show` |
| POST | `/api/set-password` | `CredentialController@store` |
| POST | `/api/set-password/inspect` | `CredentialController@inspect` |
| GET / HEAD | `/api/verify/{token}` | `CertificateController@verify` |
| GET / HEAD | `/{path?}` | `Closure` |

Resource admin dinamis yang diizinkan: `questions`, `packages`, `participants`, `competencies`, `groups`, `media`, `reviews`, `certificates`, `levels`, `audit`, `attempts`. Hak tulis tetap dibatasi whitelist controller; keberadaan route umum tidak berarti semua resource boleh dihapus atau diubah.

Sumber kode yang diinventarisasi: 13 controller aplikasi (di luar base controller), 20 model, 5 service, 11 migration, 25 komponen Vue ditambah `App.vue`. Uraian 26 keluarga halaman dan tindakan UI ada di [final-ui-audit.md](final-ui-audit.md).
