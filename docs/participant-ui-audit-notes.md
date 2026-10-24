# Participant, account, and public UI audit

Audit scope follows the existing Vue route switch and Laravel routes. No duplicate pages, API endpoints, scoring rules, attempt policy, password policy, or certificate workflows were added.

## Inventory

| Existing surface | Existing route / component | Coverage in this change |
| --- | --- | --- |
| Shared overview, participant and Superadmin | `/dashboard`, `Dashboard.vue` | Filters, welcome banner, metrics, competencies, recommendations, recent activity, loading/error presentation |
| Assessment types and available packages | `/tests?test_type=practice`, `placement`, `certification`; `TestTypeCards.vue`, `ParticipantPages.vue` | All three types, descriptions, availability, metadata, actions, search, empty state |
| Instructions and device readiness | `/tests/:id`, `ParticipantPages.vue` | Package policy, section weights, duration, level/pass score, speaker/microphone checks, access code, agreement and start action |
| Attempt history | `/results?test_type=practice`, `placement`, `certification` | Type tabs, empty state, status/score/level table, result/resume actions |
| Result detail and reports | `/results/:id` | Pending review, result hero, answer review, competency table, chart containers, placement report/certificate actions; chart internals are a separate audit scope |
| Digital certificates | `/certificates` | Empty state, preview, holder/title wrapping, expiry/status, PDF and verification actions |
| Personal information for both roles | `/profile`, `AccountProfile.vue` | Summary, detail view, edit form, participant metadata, language, role/status, read-only history notice |
| Profile editing and photos | `/profile?edit=1`, `AvatarCropper.vue` | Photo selection/errors, crop controls, removal confirmation, staged photo state, cancel/save actions |
| Password/security settings | `/profile?tab=password` | Current/new/confirmation fields, reveal controls, validation, strength/rules, security notice, save/cancel actions |
| Account dropdown for both roles | `AccountMenu.vue` | Profile/edit/password/logout, name/email/avatar, keyboard navigation, Escape/focus return, mobile anchoring |
| Authentication | `/login`, `/register`, `PublicPages.vue` | Accessible field labels, adjacent API validation messages, invalid-field focus, disabled/busy state, password reveal, auth switch |
| Public certificate verification | `/verify`, `/verify/:token`, `PublicPages.vue` | Token form, loading/error/not-found state, valid/revoked/expired results and wrapping details |

`/architecture` is included in the participant responsive test matrix. Its source and the global shell/public navigation are maintained by the global UI audit. `ParticipantPages.vue` still contains a legacy profile fallback; the real `/profile` route uses `AccountProfile.vue`.

## Findings and fixes

- Existing narrow readiness columns made optional device-check actions and long policy values compete for space. Readiness now stacks before either column becomes cramped; metadata has independent wrap points and device actions sit below their text.
- Section weight badges were hidden by responsive CSS. Scoped rules keep these values visible at every width.
- Three-column readiness metrics and the result score's fixed minimum width/nowrap label could overflow small phones. Metrics stack on phones, result summaries wrap, and long score labels use available width.
- Some dashboard package cards were hidden at intermediate breakpoints. Every displayed recommendation remains visible and the grid uses two columns or one column as space permits.
- Dashboard filters lacked a consistent layout for long assessment names. They now use a bounded grid with full-width controls and actions on narrow screens.
- Test-type cards now have equal structural sections, natural description height, readable facts, and a responsive grid. No assessment type was added or removed.
- Result/history tables retain all columns. Their own keyboard-focusable scrolling container handles horizontal movement, with names allowed to wrap.
- Certificate names, titles and numbers now wrap, and PDF/verification actions stack on narrow screens.
- Profile, password and crop controls use the shared emerald/lime design tokens. Local teal/blue primary overrides were removed or replaced with global token references. Primary button text uses the shared contrast token.
- Account and crop dialogs keep their footer actions reachable during scrolling, preserve native dialog focus/Escape behavior, and use 44–48 px controls. Password and profile notes use larger body text.
- Account dropdowns now measure the trigger position so the mobile menu follows a wrapped toolbar. Long names/emails and menu actions wrap within a viewport-bounded, scrollable menu.
- Public auth errors now appear next to their fields, with `aria-invalid`/`aria-describedby`, API-validation focus, and busy form states. Public verification has a visible label, loading feedback, and a specific not-found message instead of a list of empty certificate details.
- Public/auth/result/certificate accent surfaces now use shared theme colors. Body copy and controls were raised toward 18 px, section titles toward 22–28 px; no font-size selector was added.

## Validation

- The Vue SFC parser and script/template/style compiler passed for all seven changed components: `ParticipantPages`, `Dashboard`, `AccountProfile`, `AvatarCropper`, `AccountMenu`, `TestTypeCards`, `PublicPages`.
- `node --check tests/Browser/participant-layout.spec.js` passed.
- `participant-layout.spec.js` covers **320, 375, 768, 1024, 1366, 1440 and 1920 px** in both light and dark themes, all routes described above (readiness for each published type), page overflow, sticky headers, heading readability, menu keyboard focus/viewport bounds, and public password reveal. It records representative 320/1440 px screenshots under `tmp/qa/ui-audit/`.
- The four participant/public responsive scenarios passed in the integrated run: 210 participant route/viewport/theme combinations and 56 public form/viewport/theme combinations. Existing account, photo, password, session, and certificate regressions also passed. Screenshots were reviewed and the light-theme auth contrast and mobile menu bounds were corrected. The guarded SQLite fixture left the main database unchanged. See `dashboard-ui-audit.md` for the full combined result.

## Limits

The result charts, exam runner, global navigation and Superadmin screens are documented in separate audit scopes. Certificate PDF and simulated-microphone workflows passed the integrated checks. Physical-device microphone behavior and screen-reader behavior were not exhaustively tested; the UI changes preserve existing API calls and rules.
