# TPZ AI Assistant — Phase 1 Implementation

## State

Implemented on `codex/ai-assistant-phase1`. Feature is disabled by default; no deployment or migration has been run. Internal employee Chat remains untouched.

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

1. Install the repository's already-declared dependencies in the isolated development environment and build frontend assets (`composer install`, `npm ci`, `npm run build`). No new package was added.
2. Apply the new migration only in an approved local development database. Do not run it in Staging/Main without approval.
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

## Focused QA

Added tests for Owner/Admin/employee access, conversation ownership, default Admin financial denial, the read-only tool allowlist, credential redaction, and sanitized OpenAI API failure handling. PHP syntax lint passed for changed PHP files using Laragon PHP 8.3.30; the inline widget JavaScript also passed Node syntax checking, and the credential-redaction smoke check passed.

Full Laravel/Pint/feature execution and an integrated screenshot could not be run in this checkout: `vendor`, `node_modules`, and `.env` are absent. Dependency installation requires approval under repository guidance. No database migration was executed, and no local integrated app demonstration is currently running.

## Cost rates

Rates are estimates in `config/ai_assistant.php`, using standard input/output per-million rates for the selectable models. OpenAI currently lists GPT-6 Luna at USD 0.10 input / USD 0.50 output per million tokens; cached tokens, rate changes, taxes, and provider-side adjustments can make application estimates differ. [OpenAI API pricing](https://platform.openai.com/pricing/).
