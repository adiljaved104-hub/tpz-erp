# Mobile platform sync activation

Starting commits: backend `68df31302fd1b5905f372f6d359e2709fb8c979e`, mobile `f15e0070fe8e2080bcd65dccee3a29529151636d`. Work is restricted to the two `codex/mobile-platform-sync` worktrees. This document describes activation; this task does not deploy, build or publish updates.

## Contract and authorization

`GET /api/mobile/v1/workspace/manifest` requires Sanctum authentication and an eligible employee, just like the existing workspace. It returns `schema_version: 1`, `minimum_runtime_version: 1`, and modules selected by `MobileWorkspaceCapabilities`. Responses are private and not cached. The legacy `/workspace/modules` endpoint remains available for older builds.

`MobileManifest` is an explicit presentation registry. It supplies keys, labels, paths, ordering, renderer, search, filters/statuses, list/detail/create/edit/action metadata, and supported feature flags. It does not execute Filament metadata. Every list, record and mutation still uses the existing controller, authorization service, responsibility scope, DTO and domain action/service. Manifest `features.edit` describes renderer support; actual edit permission is determined by each record's `actions`. Module-level creation permission is checked using the existing authorization service. Resource/state/financial checks still happen in the controller and domain service.

Generic lists/details: Sales (orders including Web Sales), Products, Purchases, Suppliers, Locations, Stock Transfers, Stock Requests, Reservations, Invoices, Responsibilities, Returns, Warranty, Internal Repairs, Claims, Complaints and Tasks. All use the shared mobile list and existing record-detail renderer. Internal Repair detail uses the warranty endpoint and context. Invoices keep their existing PDF download/share flow; purchase lists keep the GRN link.

Inventory remains specialized for stock facts and product navigation. Reports keep their report-specific parameters and export screen. HR keeps its directory, notices and warnings sections. Notifications keep read/acknowledge/push behavior. Chat keeps its conversation/message interface. Multi-item creation, order/purchase draft editing, purchase receiving and return creation use the existing native forms. Native screen identifiers are a controlled client mapping, not arbitrary server navigation.

To add a standard module, provide its existing authorized controller/domain workflow, add its visibility check in `MobileWorkspaceCapabilities`, and register its presentation in `MobileManifest`. V1 generic records use numeric identifiers and the existing `{data: ...}`/Laravel pagination responses. A new standard module does not need a mobile route or screen. Custom native interactions and new required field types need mobile work/OTA and possibly a new native runtime.

## Editing audit

Existing fields/actions were retained rather than duplicated:

| Module | Editable operations |
| --- | --- |
| Products | Name, brand/category, condition, model/specifications, warranty, description; selling/cost prices only with their separate view/edit permissions. SKU and stock quantities stay read-only. |
| Orders / Web Sales | Existing draft edit form; reserve, shipment, cancellation and return initiation. |
| Purchases | Existing authorized draft edit and receipt forms; approve, cancel, close. |
| Stock Requests | Source approval/rejection and execution through the allocation service. |
| Stock Transfers | Dispatch, receive, cancel and return to source through existing stock actions. |
| Returns | Receipt, item inspection quantities and cancellation through the return workflow. |
| Tasks | **New:** title, description, priority, due date via `TaskService::update`; existing progress, waiting/follow-up, completion approval, cancellation and reopening remain. |
| Warranty / Internal Repairs | Existing operational detail, assignment and permitted lifecycle changes. |
| Claims / Complaints | Existing filing/review/assignment/resolution/closure; financial actions retain their separate permissions. |
| Responsibilities / Reservations | Existing authorized responsibility deactivation and reservation release only. No broad assignment or destructive stock editor. |

Action input fields now include `editable`, `mobile_editable`, `read_only`, `placeholder`, `help_text` in addition to name/label/type/required/value/options. Products also supply a read-only `field_schema` for detail labels/types; other records retain compatible associative fields. Generic lists consume server-defined list field names. Shared forms support text, multiline, number, currency, date, datetime, select, boolean, phone and email. Backend validation remains authoritative. Unknown field types display safely and prevent action submission. Destructive actions supply confirmation metadata. Action transport continues using controlled POST routes; no arbitrary HTTP endpoint execution was added.

Task edits remain transactional and now write a safe `task.updated` activity log containing changed field names, alongside existing priority/due-date events. No financial values or free-text content are added to activity-log properties. Terminal tasks remain read-only. No migrations or database schema changes are required. No security, role, tax or journal editing was added.

## Compatibility and APK policy

The client supports manifest schema/runtime 1. A newer schema or minimum runtime produces “This ERP feature requires a newer version of Tech Point Zone.” Unknown optional filters/statuses/renderers degrade safely. The dashboard and record renderer check the contract; old-server fallback is limited to a 404 manifest response. Existing native routes remain for legacy links and older-server compatibility.

`GET /api/mobile/v1/app/version` is public, throttled and returns only the public release policy. Configure these on each server through its normal environment management process, then refresh Laravel's configuration cache:

```dotenv
MOBILE_MINIMUM_RUNTIME_VERSION=1
MOBILE_APP_LATEST_VERSION=1.4.0
MOBILE_APP_LATEST_BUILD=12
MOBILE_APP_MINIMUM_BUILD=11
MOBILE_APP_UPDATE_REQUIRED=false
MOBILE_APP_DOWNLOAD_URL=https://tpzerp.cloud/downloads/tpz-erp.apk
MOBILE_APP_UPDATE_MESSAGE="A newer version of Tech Point Zone is available."
```

Build values above are examples; use the actual verified APK versionCode. Defaults are zero for both build thresholds and false for required updates, so enabling the endpoint does not force an accidental update. The client reads `expo-application` native build information. On Android a build below latest gets Update Available / Update Now / Later. A build below minimum, or a true required flag, gets Update Required / Update Now only. A true flag applies to **every** installed Android build; reset it when the blanket requirement is no longer intended. No native build (Expo Go/web) skips APK prompts. HTTPS downloads open the OS browser; no silent install occurs. Network/invalid-policy failures keep the app usable, while an already loaded required prompt remains blocking until a successful new policy says otherwise. Launch/resume checks are coalesced and throttled to 15 minutes.

No `.env` file was edited by this task. Staging and production values should refer to the release each environment actually supports.

## Permanent APK deployment convention

`public/downloads/.gitignore` excludes all downloads except the ignore file. Do not commit APKs. The existing public directory serves `https://tpzerp.cloud/downloads/tpz-erp.apk`; no Nginx change is required when that directory is already served directly.

For an authorized release operator, using the VPS's actual application directory and deployment account:

```bash
# Upload from the release workstation to a temporary name in the SAME directory.
scp tpz-erp.apk DEPLOY_USER@VPS:/var/www/tpz-erp/public/downloads/tpz-erp.apk.new

# On the VPS: verify against the independently recorded release SHA256.
cd /var/www/tpz-erp/public/downloads
printf '%s  %s\n' VERIFIED_RELEASE_SHA256 tpz-erp.apk.new | sha256sum -c -
chmod 644 tpz-erp.apk.new
# Rename on the same filesystem is atomic; the stable URL remains unchanged.
mv -f -- tpz-erp.apk.new tpz-erp.apk
```

Verify the APK signature and versionCode on the release workstation before upload, and check the HTTPS download after replacement. Update release policy only after the verified APK is available. Keep any rollback APK in the operator's release storage, outside Git. These commands are documentation only and were not executed by this task.

## Validation and manual release checks

Automated coverage includes manifest authentication, permission-selected modules, contract/runtime, denied mutations, authorized edit metadata, task edits/audit, public version policy, existing mobile workflows and MFA. Mobile pure-contract tests use Node's built-in test runner without another test package.

Before activation, test a signed preview APK on Android: staff password login; privileged password → 202 MFA → OTP; logout redirect; push registration; chat and throttling; password reset; generic module lists/filters/pagination; product/task edits with restricted permissions; order/purchase native forms; invoice PDF sharing; optional/required APK prompts; interrupted OTA download; and a downloaded OTA applying only after restart/cold start. Automated validation cannot confirm device-level APK installs or EAS delivery.
