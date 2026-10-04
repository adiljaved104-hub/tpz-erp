# TPZ mobile platform sync implementation report

## Verified starting state

| Repository | Worktree | Branch | Starting HEAD |
| --- | --- | --- | --- |
| Backend / TPZ ERP | `C:\laragon\www\tpz-erp-mobile-platform` | `codex/mobile-platform-sync` | `68df31302fd1b5905f372f6d359e2709fb8c979e` |
| Mobile / TPZ ERP Mobile | `C:\laragon\www\tpz-erp-mobile-platform-app` | `codex/mobile-platform-sync` | `f15e0070fe8e2080bcd65dccee3a29529151636d` |

Both worktrees started clean, with no merge/rebase/cherry-pick/revert/sequencer state. No branch switching, new worktrees, main/staging merges, deployments or production builds were performed. Only these approved worktrees were modified. Commit hashes and push confirmation are reported in the final chat response.

## Backend changed files (relative to approved backend root)

- `app/Http/Controllers/Api/Mobile/V1/AppVersionController.php`
- `app/Http/Controllers/Api/Mobile/V1/MobileController.php`
- `app/Http/Controllers/Api/Mobile/V1/ProductController.php`
- `app/Http/Controllers/Api/Mobile/V1/TaskController.php`
- `app/Http/Controllers/Api/Mobile/V1/WorkspaceController.php`
- `app/Services/Mobile/MobileManifest.php`
- `app/Services/Tasks/TaskService.php`
- `config/mobile.php`
- `routes/api.php`
- `public/downloads/.gitignore`
- `tests/Feature/Mobile/MobilePlatformSyncTest.php`
- `tests/Feature/Mobile/MobilePurchasingWorkTest.php`
- `tests/Feature/Mobile/MobileStagingPolishTest.php`
- `docs/MOBILE_PLATFORM_SYNC.md`
- `docs/MOBILE_PLATFORM_SYNC_REPORT.md`

## Mobile changed files (relative to approved mobile root)

- `app.json`
- `eas.json`
- `package.json`
- `package-lock.json`
- `src/app/_layout.tsx`
- `src/app/(app)/module.tsx`
- `src/app/(app)/record.tsx`
- `src/features/dashboard/screens/dashboard-screen.tsx`
- `src/features/workspace/components/operation-ui.tsx`
- `src/features/workspace/services/mobile-api.ts`
- `src/features/workspace/services/manifest-contract.ts`
- `src/features/workspace/services/manifest-service.ts`
- `src/features/updates/update-manager.tsx`
- `src/features/updates/update-service.ts`
- `src/features/updates/version-policy.ts`
- `tests/platform-contracts.test.mjs`
- `docs/MOBILE_PLATFORM_SYNC.md`

## Architecture and module coverage

The authenticated, versioned manifest is an explicit registry built on existing `MobileWorkspaceCapabilities`. One generic module route consumes server ordering, list fields, filters, statuses, API path and record module. Existing record details handle fields, items, history and permitted action forms. Products additionally expose read-only field schema. Runtime/schema compatibility is enforced, including direct record navigation, and a 404-only fallback preserves old-server use. Existing native routes remain available. No Filament metadata or arbitrary endpoints are executed.

Sixteen generic module lists/details: Sales / Orders / Web Sales, Products, Purchases, Suppliers, Locations, Stock Transfers, Stock Requests, Reservations, Invoices, Responsibilities, Returns, Warranty, Internal Repairs, Claims, Complaints and Tasks.

Five specialized modules remain: Inventory (stock facts/product navigation), Reports (parameters/exports), HR (directory/notices/warnings sections), Notifications (read/acknowledgment/push), Chat (participants/messages). Multi-item create forms, order/purchase draft editing and purchase receiving remain specialized. Invoice PDF handling stays intact within the shared detail screen. Future standard modules require backend visibility/schema/controller registration; custom native interactions can still require mobile work.

Editable product fields remain name, brand/category, condition, specifications/model, warranty, description and separately authorized selling/cost price fields. New task editing covers title, description, priority and due date through `TaskService::update`; updates write safe activity logs. Existing order/purchase draft edit, stock request decisions/execution, transfer lifecycle, return inspection/receipt, task workflow, repair service details/assignment/lifecycle, claims/complaints operations, responsibility deactivation and reservation release remain authorized through existing domain services. The detailed audit is in the backend activation guide. No roles, security, tax, journals or destructive inventory editor was introduced. Backend authorization remains authoritative; no separate mobile business layer was added.

## Updates and activation

- Manifest schema/runtime support: 1. Server minimum runtime is configurable through `MOBILE_MINIMUM_RUNTIME_VERSION`.
- Expo-compatible `expo-updates ~57.0.24`; existing EAS project preserved; fingerprint native runtime policy; preview and production channels/environments assigned to matching build profiles.
- API environments preserved: development/preview → staging; private/production → production.
- OTA launch/resume checking is coalesced and throttled; compatible downloads apply on explicit restart or next cold start. Failures cannot prevent ERP use and no automatic reload loop is introduced.
- Public `/api/mobile/v1/app/version` uses `MOBILE_APP_LATEST_VERSION`, `MOBILE_APP_LATEST_BUILD`, `MOBILE_APP_MINIMUM_BUILD`, `MOBILE_APP_UPDATE_REQUIRED`, `MOBILE_APP_DOWNLOAD_URL`, and optional message configuration. Native build information comes from `expo-application`. Optional updates allow Later; required updates block with Update Now only.
- Stable APK URL: `https://tpzerp.cloud/downloads/tpz-erp.apk`. Upload a temporary file into the public downloads directory, verify its signature/build/hash, then rename atomically on the same filesystem. APKs are ignored by Git. No Nginx modification was introduced.
- Migrations: **none**. `.env` modifications: **none**. APK/AAB binaries committed: **none**. Native builds, EAS publication and VPS deployment: **not performed**.

Backend activation/configuration and atomic APK procedure: `docs/MOBILE_PLATFORM_SYNC.md` in the backend. Exact preview/production OTA commands and initial APK build prerequisites: `docs/MOBILE_PLATFORM_SYNC.md` in the mobile repository.

## Validation

| Check | Result |
| --- | --- |
| Entire mobile API feature suite | 62 tests passed; 1,019 assertions |
| Mobile authentication + password reset | 28 tests passed; 168 assertions |
| Impacted task feature suite | 35 tests passed; 170 assertions |
| Combined backend suites | 125 tests passed; 1,357 assertions |
| Focused sync contract rerun | 5 tests passed; 438 assertions (already included in mobile suite, not added twice) |
| Mobile Node contract tests | 9 passed; Node does not report an aggregate assertion count |
| `npx.cmd tsc --noEmit` | Passed |
| `npm.cmd run lint` | Passed |
| `npx.cmd expo config --type public --json` | Passed |
| Backend local Vite asset build | Passed; ignored generated assets used for Filament tests |
| Laravel Pint on changed PHP | Passed after formatting |
| Backend and mobile `git diff --check` | Passed |

Dependencies were restored from existing lockfiles inside the approved worktrees. Only the requested updater package was added. Encryption tests used a process-only deterministic test key; no environment file or production credential was read/copied/generated. Two pre-existing purchase fixtures were corrected to create required default stock responsibility rather than weakening receiving rules. Extra Filament task checks initially needed missing local build assets and passed after the local asset build. Node emits a harmless module-format detection warning when directly loading TypeScript contracts; no package module mode was changed.

## Remaining activation work

There are no known implementation/test blockers. An authorized release operator must configure server/EAS public release values, create a new compatible APK with the updater native module, test signed devices, upload the verified APK and publish preview/production OTA updates when ready. Existing Build 11 APKs cannot acquire a missing native updater through OTA. Actual Android install prompts, EAS delivery/restarts, push registration, MFA, chat and invoice sharing require the signed-device release checks listed in the guides. This task does not claim those device checks or external deployment/publication were performed.
