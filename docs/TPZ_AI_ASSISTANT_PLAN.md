# TPZ AI Assistant — Owner Review Plan

**Status:** Owner-approved design baseline. Phase 1 implementation is in progress on `codex/ai-assistant-phase1`; the architecture's approval requirement was satisfied by the Owner's Phase 1 instruction.

## 1. Existing architecture findings

- The ERP is Laravel 13.8 / PHP 8.3 with Filament 4, Livewire views, and database-backed cache and queue configuration. The existing dependencies do not include an OpenAI SDK; the MVP can use Laravel's HTTP client without adding a package.
- The single authenticated Filament panel is registered by `AdminPanelProvider`; it supports global render hooks. This is the natural location for an authenticated, panel-wide floating widget.
- Employee-to-employee Chat is a substantial independent feature (`Chat` page, `ConversationService`, `ConversationMessageService`, `ChatAuthorization`, and mobile API routes). Keep its tables, services, events, and permissions unchanged. The AI Assistant should have separate models, services, permissions, routes, and UI.
- Authorization is already fine-grained: `EmployeeRole`, module permission enums/resolvers, `EmployeePermissionOverrideResolver`, policies, and Gate definitions. Admin does not imply access to all financial information: for example, Web Sales defaults deny Admin gross profit and cost permissions.
- The report subsystem has `ReportRegistry`, `ReportQueryService`, report definitions/providers, bounded query results, and authorization-aware reports. Core definitions include `sales_summary`, `current_inventory`, `damaged_stock`, and `qc_pending`. This is a promising source of verified read-only reporting, subject to confirming every relevant field permission and metric definition before exposing a tool.
- `ActivityLogger` intentionally rejects sensitive-looking property names, including amount, cost, profit, token, and secret. AI audit entries must therefore contain safe metadata (tool key, result count, date range, conversation/message IDs, authorization outcome), not prompt text, returned values, or token/API credentials.

## 2. Initial UI design

### Floating state (desktop)

Anchor a small amber TPZ AI button above the bottom-right corner, with a label/tooltip and unread or in-progress indicator. Keep it above page content but below Filament modal/dialog layers; respect safe-area margins. On click, open a panel about 400 px wide and 620 px tall, constrained to viewport height. Use TPZ's existing amber primary color, neutral surfaces, rounded corners, and restrained shadows.

```text
┌────────────────────────────────────────┐
│ ✦ TPZ AI      ERP answers · Authorized  │  −  □  ×
│ [＋ New chat]                 [History ▾]│
├────────────────────────────────────────┤
│ Good morning. What would you like to    │
│ know about TPZ operations?              │
│                                        │
│ [Today's sales] [Current stock]         │
│ [Pending QC returns]                    │
│                                        │
│                             ┌────────┐ │
│                             │Today's │ │
│                             │sales?  │ │
│                             └────────┘ │
│ ┌────────────────────────────────────┐ │
│ │ AED … · Today, …                   │ │
│ │ Based on … completed sales.        │ │
│ │ Sources: 12 orders  [View orders]  │ │
│ │                         Copy  ↻    │ │
├────────────────────────────────────────┤
│ Ask about sales or inventory…       ➤  │
│ Read-only · ERP permissions apply       │
└────────────────────────────────────────┘
```

History opens a conversation list in the header or a slim left pane when expanded. New Chat begins a separate owner-scoped conversation. Minimize collapses to the launcher; close dismisses the panel but preserves the conversation. Expand opens a full-height workspace with conversation list and a wider transcript. Mobile uses a full-screen sheet with a fixed composer and safe-area padding. Responses show typing/stream status, retry on failure, auto-scroll unless the user has scrolled upward, copy action, reporting period/currency/filters, and linked ERP records only when the requesting user can view them. Never visually present a figure when the reporting tool returned no authorized value.

Suggested prompts are capability-aware; hide prompts for tools/fields the user cannot access. The widget appears only when the feature flag is on and the authenticated active employee passes server authorization.

## 3. Proposed architecture

### UI and request flow

1. Register a small Livewire widget/view via `AdminPanelProvider` render hook on authenticated panel pages. Keep the dedicated full-screen chat as a separate assistant page or expanded widget state, not the employee Chat page.
2. Add authenticated, throttled endpoints for conversation listing/creation, message submission/streaming, retry, and history retrieval. Every endpoint rechecks feature flag, employee status, `ai_assistant.use`, and conversation ownership.
3. Persist the user's message before starting generation. Stream through a Laravel streamed response using server-side HTTP calls; save the final assistant text, usage, and response metadata. On failure, retain the user message and expose a retry that does not duplicate it.
4. Use Laravel HTTP client against OpenAI's Responses API. Keep provider interface pluggable (`OpenAiResponsesProvider` and deterministic `MockAssistantProvider`). OpenAI tool calls are handled in a bounded server loop: parse only a registered tool name, validate its JSON schema, execute through the tool registry for the authenticated user, and return only the tool result. Never run model-generated SQL, arbitrary code, web search, or write actions.

OpenAI's current documentation identifies Responses as the supported API for new integrations, documents explicit tool-call orchestration, and reports the Assistants API sunset on 26 August 2026. Pin the implementation to the Responses API and recheck event formats and model availability when implementation begins. ([Responses API overview](https://platform.openai.com/overview), [Assistants migration guide](https://platform.openai.com/playground/assistants?assistant=asst_l6WVKRD7q0FnvfMwTIgjxy3c&thread=thread_lfjcJBv9yew14O5k07Tg6rnl))

### Data and tool registry

Create typed reporting tools, not generic query access. Initial allowlist:

- `sales_summary`: bounded date range (default today), optional marketplace/channel; only return fields authorized by the existing order/web-sales authorization services and established sales report definitions.
- `inventory_summary`: bounded product/brand/status filters; quantity fields authorized by inventory permission. Do not return valuation or average cost to Admin unless the existing owner-only financial permission explicitly allows it.
- `inventory_age`: only add after confirming stock-age definition and a trustworthy receipt/ledger source; explain exclusions and use bounded rows.
- `pending_qc_returns`: only add after confirming the existing QC read service's permissions, filters, and record links.

Each tool validates enums, IDs, and dates; limits date ranges, rows, and output tokens; returns structured data plus reporting period, currency, filters, caveats, and authorized record references. The model may explain results but may not alter them. Before promising a prompt like sales-and-profit comparison or inventory valuation, confirm field-level permissions and the ERP's exact calculation services. For incomplete cost data, return an explicit unavailable/incomplete marker; never derive average cost from `cost_price` or invent profit.

### Authorization and privacy

- Default deny. Add dedicated `AiAssistantPermission::Use` (and an owner-only configuration/usage view if needed); expose the widget only to active Owner/Admin users with the permission. Apply employee permission overrides only according to the existing resolver's rules, including the Owner override invariants.
- Check underlying module permissions separately for every tool and every sensitive field. Admin access to the assistant cannot elevate access to cost, gross profit, inventory valuation, payroll, or owner-only reports.
- Scope all conversations and messages by authenticated user; reject cross-user IDs. Do not send internal notes, credentials, personal/HR data, or full records to the model unless a later reviewed tool explicitly requires them.
- Treat user and retrieved text as untrusted prompt content. System policy and tool schemas remain server-controlled. Tool results are bounded and data-only; instructions contained in ERP text cannot enable extra tools or change permissions.
- Record access/tool audit metadata through a dedicated safe audit event or a narrowly reviewed audit schema. Avoid saving prompts/results in activity logs; set explicit retention/deletion behavior for conversation content.

### Persistence proposal (new migrations only)

- `ai_assistant_conversations`: owner `user_id`, title, timestamps, optional archive state; index by owner and updated time.
- `ai_assistant_messages`: conversation ID, role, content, status, provider/model, response ID, usage counts, safe error code, timestamps; index by conversation/time. Keep provider request/response secrets out of stored payloads.
- `ai_assistant_usage`: user, conversation/message references, model, input/output token counts, estimated USD cost, request status, created time; aggregate by day/month for owner reporting and configured hard limits.
- Optional dedicated assistant audit records if existing activity log's safe-property contract cannot represent needed metadata.

Foreign keys should cascade messages/usage appropriately when an owner deletes a conversation, while audit retention follows the approved retention policy. Confirm database engine and supported index behavior before migrations. No existing migrations should be edited.

### OpenAI, cost control, and operations

- Add `config/ai_assistant.php` for enabled flag, provider (`mock` by default until configured), model, request timeout, retry policy, per-user rate limits, max input/output tokens, query/date/page bounds, daily/monthly spend ceilings, and cost-rate table. `OPENAI_API_KEY` is read only from server environment/secret storage and never returned to the client or logs.
- Reconcile token usage from Responses API completion events; persist actual token counts. Estimate spend using versioned model rates in config and clearly label it estimated. Report daily/monthly token and estimated cost to Owner only. Hard-stop on configured caps; rate-limit by user and globally. Do not retry non-idempotent steps; reporting tools are read-only and may be safely retried with bounded exponential backoff on transient network/5xx errors.
- Log sanitized error class/code and correlation ID only. Do not log authorization headers, prompts, tool result content, or secrets. Keep the feature disabled until key/model/configuration and Owner approval are complete.

## 4. Delivery phases

1. **Owner review:** approve UI, permission semantics, retention, cost caps, and initial reporting definitions.
2. **Foundation:** configuration/feature flag, permission, migrations, models, isolated assistant services, mock provider, audit boundaries.
3. **UI MVP:** floating widget, expanded view, per-user history, suggestions, streaming states, copy/retry, responsive mobile behavior.
4. **Responses integration:** server-authenticated API streaming, controlled tool loop, usage/cost recording, limits and safe errors. Keep mock provider available for local development.
5. **Read-only reports:** sales summary and inventory summary first; add QC and age only when their data definitions and authorization paths are verified. Add links to authorized ERP pages.
6. **Release:** code review and relevant tests/Pint; stage branch and GitHub staging; PowerShell staging deployment; Owner UAT; promote only after explicit approval. No direct Main deployment and no employee activation.

## 5. Decisions needed before implementation

The architecture documentation requires approval before feature implementation and schema changes. Owner review should confirm: (a) this separate assistant experience and proposed panel placement, (b) retention/deletion policy for chat content, (c) initial daily/monthly token and estimated-spend caps, (d) which reports/fields Admin may ask for under existing permissions, and (e) the exact source definition for "today's total sales" (date basis, statuses, refunds/returns, channels, and currency). Until these are settled, keep the flag disabled and do not claim those metrics are implementation-ready.
