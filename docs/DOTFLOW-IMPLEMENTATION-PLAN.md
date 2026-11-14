# DotFlow — Product & Engineering Blueprint

Status: Foundation active — CRM/events and messaging foundations implemented; provider integrations, expanded automation and product UI remain in progress.
Working branch: `dotflow/development`.
Foundation: Groundhogg, preserving applicable GPLv3 notices, attribution and derivative-work obligations.

## Product direction
DotFlow is a standalone WordPress CRM, marketing automation and customer-engagement platform. DotFlow owns contact records, consent/preferences, event history, segments, campaigns, workflow definitions, execution state, reporting and integration mappings. External services are adapters: providers deliver outbound email; mailbox providers expose inbound messages through webhooks or IMAP; connected platforms supply events and synchronisation data.

DotFlow will not depend on Groundhogg's hosted inbox relay or paid licence for reply ingestion. Implement independent, documented provider webhook and IMAP adapters; do not bypass licensed services.

## Current implementation checkpoint

The repository has moved beyond planning. The following is the current, deliberately conservative status:

- **CRM:** Groundhogg contact, tag, custom-field, note, search and permission infrastructure is retained and exposed through scoped abilities.
- **Events:** DotFlow typed events, durable storage, source-scoped idempotency, privacy-safe event discovery and AI mutation audit events are implemented.
- **Conversations:** Inbound/outbound message records, contact-scoped access, threading, duplicate detection, signed reply routing and a provider-neutral authenticated inbound webhook are implemented.
- **Outbound email:** Existing Groundhogg email-service registration and channel routing are reused. Composed-email handling validates recipients, sender address and delivery channel. The shared transport restores channel/provider/message-ID context, owns its temporary `wp_mail_failed` hook safely, and preserves the outer send's error state during nested sends. It cleans up after provider and pre-send-hook exceptions without removing a hook owned by another integration. Regression coverage exercises nested sends, hook ownership and exception paths.
- **Email diagnostics:** A read-only ability reports registered delivery services and the selected WordPress, transactional and marketing services. It never returns provider credentials.
- **Automation:** Existing Groundhogg flows remain the execution engine. DotFlow adds a typed, event-driven benchmark and AI flow configuration support; a separate workflow runtime is not claimed.
- **Not yet implemented:** Provider-specific outbound API adapters, provider-specific inbound adapters, attachment ingestion, a complete provider-settings UI, the full automation execution/monitoring experience, and the remaining integrations.

Do not interpret inherited Groundhogg capabilities as newly built DotFlow modules. Attachments remain explicitly unsupported by the current inbound webhook until safe storage, malware handling, access control and retention are implemented together.

## Functional scope
- CRM: contacts, custom fields, tags, lists/segments, lifecycle status, consent provenance, suppression, duplicate management, import/export and unified activity timeline.
- Campaigns: broadcasts, reusable templates, audience eligibility, provider adapters, queue/rate limits, retries/backoff, idempotency, bounce/complaint processing and delivery reporting.
- Visual automation: trigger → condition/branch → action → delay/wait; versioned immutable published definitions; execution history, pause/resume, cancellation, bounded retries and loop protection.
- Behaviour tracking: consent-aware page views, CTA clicks, form submissions, custom events, UTM/referrer attribution and privacy-conscious identity resolution.
- Conversations: inbound/outbound message timeline, signed webhooks or IMAP, threading, deduplication, unknown senders, attachment handling and auto-reply detection.
- Integrations: Elementor, DotForm, HubSpot, WooCommerce, GA4, SMTP/email APIs and generic signed webhooks/REST.

## Architecture direction
Retain proven WordPress runtime, CRM, database and queue capabilities where sound. Add isolated DotFlow modules after inspecting existing autoloading, migration, REST, admin and queue conventions:
- `includes/dotflow/Events/`
- `includes/dotflow/Automation/`
- `includes/dotflow/Messaging/`
- `includes/dotflow/Tracking/`
- `includes/dotflow/Integrations/`
- `includes/dotflow/Privacy/`
- `includes/dotflow/REST/`
- `includes/dotflow/Database/`
- `admin/dotflow/`
- `tests/`
Avoid duplicating existing infrastructure without a documented reason.

## Data, security and privacy requirements
- Use WordPress database APIs and versioned migrations; timestamps stored in UTC.
- Stable external IDs/UUIDs for idempotency; queue long-running work outside public requests.
- Bounded retries, dead-letter/error states, concurrency safeguards and audit trails.
- Capability checks, nonces, scoped API authentication, sanitisation, validation, escaping and prepared queries.
- Protect credentials; verify webhook signatures and replay windows; SSRF protections for configurable webhook destinations; rate limits for public endpoints.
- Consent-aware tracking and campaign eligibility; suppression always wins.
- Data minimisation, retention, export and erasure support.
- Never commit production credentials, customer data or mailbox secrets.

## Roadmap
0. Foundation: audit codebase, dependencies, licences, compatibility and test strategy; establish safe development workflow.
1. Product foundation: DotFlow identity and controlled compatibility layer, module registration, feature flags and boot smoke tests.
2. CRM/events: typed event contracts, ingestion, deduplication, contact timeline, consent and suppression invariants.
3. Messaging: provider contracts, outbound queue adapter, inbound webhook/IMAP, threading and conversation UI.
4. Automation: versioned workflow schema, validation, triggers, conditions, actions, waits and execution observability.
5. Tracking/integrations: browser/server events, attribution, Elementor, DotForm, HubSpot, WooCommerce, GA4 and generic webhooks.
6. Reporting/release: campaign/workflow/provider reports, security and performance hardening, upgrade tests, release package and rollback guide.

## Definition of done
Every feature needs automated normal/invalid/unauthorised/failure tests, PHP and JS checks, fresh-install and upgrade coverage for schema changes, loading/empty/error/success UI states, secret-safe logging, documentation and changelog updates. No production deployment or real sending during development.

## Licence and guardrails
This is a Groundhogg-derived project. Preserve upstream notices and comply with GPLv3 for derivative distributions. Work only on `dotflow/development` until explicit release approval. Do not alter production WordPress, live provider credentials or mailboxes. Use small, reviewable commits with tests.
