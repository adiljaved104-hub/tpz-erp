# TPZ AI Assistant — Phase 1 Implementation

## State

Implemented on `codex/ai-assistant-phase1`. Feature is disabled by default in application configuration. The new migration and an empty ERP schema were rehearsed only on a disposable local MySQL 8.4.3 database; nothing was deployed to Staging/UAT/Main. Internal employee Chat remains untouched.

## Included

- Global floating widget via Filament `BODY_END` hook: desktop popup, mobile full-screen sheet, minimize/close/expand, history, new conversation, suggested prompts, streamed bubbles, copy, retry, errors, and secure delete action endpoint.
- Owner-only AI Assistant settings page for enablement, mock/OpenAI provider, supported model selection, daily/monthly USD budget, and Owner-visible usage estimates/token totals. API key stays in server `OPENAI_API_KEY`; OpenAI mode reports unavailable if the server key is missing, while Mock mode is explicitly labeled as a demonstration.
- Per-user conversation/message isolation; 30-day scheduled conversation pruning; append-only metadata audit records; prompt credential redaction.
- OpenAI Responses API streaming (`store: false`), server-side function call loop, bounded token/history limits, retry on transient HTTP errors, usage recording, conservative budget reservation, and 80% warning tracking.
- Read-only tools: order lifecycle and fulfilled sales summary, Web Sales report, current product inventory, scoped employee allocation, scoped Stock Requests, Customer Returns, and separately authorized refund exposure. Existing report catalogue/services enforce source permissions and financial field filtering.
- Admin must have an explicit `ai_assistant.use` permission override. Owner has access; active employees outside Owner/Admin are denied. Admin assistant access cannot elevate report, inventory, return/refund, or financial permissions.

## Database changes (new migration only)

`2026_11_12_090000_create_ai_assistant_tables.php` creates:

- `ai_assistant_settings` — feature flag, provider/model, daily/monthly caps.
- `ai_assistant_conversations` and `ai_assistant_messages` — owner-scoped local chat history.
- `ai_assistant_usage` — token counts, model, status, estimated USD cost and budget reservations.
- `ai_assistant_audits` — safe tool/event metadata without message contents or credentials.

The migration creates five tables in one new migration. Reservations cover the maximum bounded Responses call loop and remain budget-accounted after interrupted requests because provider usage may already have accrued.

Existing migrations and operational data were not changed. Migration must be reviewed and explicitly approved before Staging or Main.

## Local activation / deployment requirements

1. The locked Composer and npm dependencies have been installed in the isolated checkout; no dependency lockfile was changed. The normal Vite production build completed.
2. Apply the single new migration only in an approved target database. Do not run it in Staging/Main without approval.
3. Configure `OPENAI_API_KEY` in the server's secret environment; optionally set `OPENAI_MODEL`. Do not put the key in browser configuration or Git.
4. Ensure the scheduler is running for `ai-assistant:prune-history` (daily at 02:35; retention defaults to 30 days).
5. Owner opens Administration → AI Assistant, keeps Provider on Mock for local preview or selects OpenAI, sets pilot caps, and enables the feature. Owner grants Admins the `ai_assistant.use` permission through existing employee permission controls.
6. Verify stream responses over HTTPS and reconcile estimates with provider billing before broader activation.

## Reporting definitions and limits

- Orders report follows the existing order-date report. It returns lifecycle status rows, not a complete per-status count when rows are truncated.
- Fulfilled sales summary comes separately from the ERP `sales_summary` report. Its units/value are the report's fulfillment-derived measure; it is not a VAT- or refund-adjusted net-sales metric.
- Web Sales returns only fields allowed by `WebSalesAuthorization`; gross profit and costs remain absent unless the requester has their dedicated source permission.
- Inventory values follow the current-inventory report. Admin does not receive owner-only average cost or valuation.
- Customer return refunds use the existing financial-sensitive `refund_exposure` report and remain unavailable when the source permission denies them.
- Reports are limited to 30 rows per tool call; date windows are bounded. Results include period/timezone/currency/source/filter metadata and record references. Figures not present in source outputs must be reported as unavailable.

## QA rehearsal (2026-11-12)

- Composer installed the exact lockfile set: 158 installs, zero updates/removals, with scripts disabled. `package:discover` was run explicitly against an empty disposable SQLite bootstrap database. `npm ci` installed 60 locked packages with lifecycle scripts disabled by `.npmrc`. `composer.lock` and `package-lock.json` remained unchanged; no `.env` file was created or modified.
- On disposable MySQL 8.4.3, Laravel applied the prerequisite `users` table, this AI migration, and then the remaining repository migrations. `migrate:status` reported no pending migrations. `information_schema` confirmed all five AI tables use InnoDB, the declared indexes exist, and the foreign keys use the expected cascade/set-null rules. The temporary database and data directory were removed after rehearsal.
- Focused Laravel QA passed: 15 AI Assistant tests, 60 assertions. Coverage includes Owner/Admin/employee authorization, financial access policy, conversation privacy, read-only tool allowlist and per-call authorization, credential redaction, missing-key failure, API failures, streamed text/usage, Responses tool-call continuation, budget reservation math/hard limit, and retention cleanup.
- A separate concurrent MySQL reservation rehearsal held the settings-row lock while two workers attempted a request against a USD 0.08 cap. One reserved USD 0.0536; the other was denied. The workers and disposable database were removed afterward.
- Laravel Pint passed on the Phase 1 PHP files. Blade view compilation passed. PHP lint and the widget's inline JavaScript syntax check passed. The standard `npm run build` passed and generated Vite assets; the configured font plugin emitted a non-blocking notice that optional `fontaine` is absent.
- In mock mode, the real inventory report service returned one clearly labeled test-only inventory row (7 sellable units) with the configured report period, UTC timezone, AED currency, filters, and source. No live OpenAI key was configured.
- A browser screenshot was not captured: the available computer-use workflow prohibits automating the authenticated login dialog, and no Owner-operated browser session was available. An authenticated visual review, mobile layout review, and compatibility check against the live employee-chat UI remain outstanding.
- The published npm audit reported 4 advisories in locked development dependencies (2 high, 2 critical, including Vite 8.3.0 and `concurrently`/`shell-quote`). Dependency files were not upgraded under the approval scope. Resolve these advisories in a separately approved dependency update before Staging.
- Reporting cases not fully validated with representative fixtures: sales lifecycle/cancellation/refund reconciliation, Zaid allocation, QC-pending returns, stock requests, and Amazon-versus-Carrefour comparisons. The current report registry has no inventory-ageing report for “older than 60 days”; do not answer that question with an inferred purchase date. These checks require agreed business definitions and representative disposable records. Live account model availability and real-provider streaming cannot be confirmed without an Owner-provided API key.

## Cost rates

Rates are estimates in `config/ai_assistant.php`, using standard input/output per-million rates for the selectable models. OpenAI currently lists GPT-6 Luna at USD 0.10 input / USD 0.50 output per million tokens; cached tokens, rate changes, taxes, and provider-side adjustments can make application estimates differ. [OpenAI API pricing](https://platform.openai.com/pricing/).
