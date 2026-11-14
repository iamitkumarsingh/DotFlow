# DotFlow — Product & Engineering Blueprint

Status: Stage 2 implementation completed on `dotflow/development` — Postmark transport, provider webhooks, encrypted settings and bounded durable rate-limit retries implemented and CI-tested. No production deployment, provider credentials or live email sending performed.
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
- **Outbound email:** Existing Groundhogg email-service registration and channel routing are reused. Composed-email handling validates recipients, sender address and delivery channel. The shared transport restores channel/provider/message-ID context, owns its temporary `wp_mail_failed` hook safely, and preserves the outer send's error state during nested sends. It cleans up after provider and pre-send-hook exceptions without removing a hook owned by another integration. Postmark is registered as `dotflow_postmark` and sends through the Postmark HTTPS API using channel-specific Message Streams.
- **Email diagnostics:** A read-only ability reports registered delivery services, selected WordPress/transactional/marketing services, callable transport state, registered inbound/delivery adapters and aggregate Postmark outbox counts. It never returns provider credentials, message content or recipient data.
- **Outbound transport outcomes:** The existing Groundhogg email-service boundary emits provider-neutral result/exception hooks. DotFlow persists privacy-minimised `email.transport.accepted`, `email.transport.queued` and `email.transport.failed` events with provider, channel, a one-way Message-ID fingerprint and a safe error code only. Acceptance means the configured transport returned `true`; it is not a delivery receipt. Provider exception messages, recipient addresses, subjects and message bodies are never stored. Telemetry failures cannot alter the original send result.
- **Inbound provider adapter contract:** Providers can register an `Inbound_Email_Provider_Adapter` to verify their native authentication mechanism and normalize inbound messages. Postmark's adapter uses HTTPS plus HTTP Basic Authentication because Postmark does not offer HMAC webhook signatures. All adapter traffic is passed through the same canonical inbound validator and `Message::ingest()` pipeline as the existing token-authenticated endpoint, preserving configured inbox-address checks, signed reply-token validation, auto-reply filtering, threading and duplicate protection. Attachments remain rejected.
- **Delivery-status adapter contract:** Provider plugins can register a `Delivery_Webhook_Adapter` that authenticates the provider's native webhook mechanism and normalizes its payload. Postmark's adapter uses HTTPS plus HTTP Basic Authentication and returns HTTP 200 after successful callback processing. The provider-scoped REST endpoint accepts only delivered, bounced, complained and deferred outcomes, stores a one-way Message-ID fingerprint and a provider-scoped event ID, and uses the existing idempotent event dispatcher. Raw webhook bodies, addresses and message content are not copied into event storage. Postmark delivery, bounce and spam-complaint callbacks are implemented; other providers remain adapter contracts only.
- **Automation:** Existing Groundhogg flows remain the execution engine. DotFlow adds a typed, event-driven benchmark and AI flow configuration support; a separate workflow runtime is not claimed.
- **Not yet implemented:** Secure attachment ingestion; additional concrete providers beyond Postmark; automatic bounce/complaint suppression and delivery-state presentation in the message UI; full automation execution/monitoring; and remaining integrations. The Postmark settings UI is implemented, but a provider is not considered connected until an administrator supplies credentials and completes the connection test.

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
- Protect credentials; verify native webhook signatures where available or use provider-supported Basic Authentication and edge IP allowlisting where signatures are unavailable; add replay protection where supported; SSRF protections for configurable webhook destinations; rate limits for public endpoints.
- Consent-aware tracking and campaign eligibility; suppression always wins.
- Data minimisation, retention, export and erasure support.
- Never commit production credentials, customer data or mailbox secrets.

## Roadmap
0. Foundation: audit codebase, dependencies, licences, compatibility and test strategy; establish safe development workflow.
1. Product foundation: DotFlow identity and controlled compatibility layer, module registration, feature flags and boot smoke tests.
2. CRM/events: typed event contracts, ingestion, deduplication, contact timeline, consent and suppression invariants.
3. Messaging: provider contracts and provider-authenticated delivery callback endpoint, Postmark outbound/inbound/delivery adapters, encrypted credentials, idempotent retry ledger for explicit HTTP 429 rate-limit responses, bounded exponential backoff and privacy-safe queue diagnostics implemented. Other providers, attachments, full message-state UI and advanced suppression remain planned.
4. Automation: versioned workflow schema, validation, triggers, conditions, actions, waits and execution observability.
5. Tracking/integrations: browser/server events, attribution, Elementor, DotForm, HubSpot, WooCommerce, GA4 and generic webhooks.
6. Reporting/release: campaign/workflow/provider reports, security and performance hardening, upgrade tests, release package and rollback guide.

## Definition of done
Every feature needs automated normal/invalid/unauthorised/failure tests, PHP and JS checks, fresh-install and upgrade coverage for schema changes, loading/empty/error/success UI states, secret-safe logging, documentation and changelog updates. No production deployment or real sending during development.

## Licence and guardrails
This is a Groundhogg-derived project. Preserve upstream notices and comply with GPLv3 for derivative distributions. Work only on `dotflow/development` until explicit release approval. Do not alter production WordPress, live provider credentials or mailboxes. Use small, reviewable commits with tests.


## Postmark provider checkpoint

- The first concrete provider is Postmark, registered as `dotflow_postmark` through Groundhogg's existing email-service hook.
- Configure Postmark in the Groundhogg Email settings tab. The server token and webhook password are write-only and AES-256-GCM encrypted at rest; wp-config.php constants and server-side filters are also supported and take precedence. Rotating WordPress salts requires re-entering values saved in the options table.
- Outbound sending uses Postmark's HTTPS Email API, separate default transactional (`outbound`) and marketing (`broadcasts`) Message Streams, channel metadata, recipient validation and a deliberate no-attachments policy. Stream IDs can be overridden with `dotflow/postmark/message_stream/{channel}` filters.
- Postmark delivery, bounce and complaint webhooks use HTTPS and HTTP Basic Authentication with username `dotflow`. Postmark does not currently support HMAC webhook signatures. Successful Postmark callbacks return HTTP 200.
- Inbound Postmark payloads are normalized through the canonical inbound pipeline. Attachments remain rejected and the independent inbox address configuration in wp-config.php is still required.
- No Postmark account credentials have been configured and no email has been sent from this development branch.


## Postmark retry handling

- Postmark HTTP 429 rate-limit responses are persisted to a dedicated retry ledger. The message payload is encrypted with AES-256-GCM before database storage; the idempotency key is a SHA-256 hash of the stable RFC Message-ID.
- The worker claims bounded batches atomically and applies exponential backoff, with a maximum of five retry attempts.
- Network failures and HTTP 5xx responses are marked uncertain and are not automatically retried because Postmark may have accepted the request before the connection failed. Stale in-flight jobs are also marked uncertain, not resent.
- Only explicit HTTP 429 responses are automatically retried. Other 4xx responses are permanent failures.
- Provider acceptance and subsequent delivery/bounce/complaint callbacks remain separate states. Retry acceptance emits the same provider-neutral transport outcome event.

- Once a retry reaches a terminal state (accepted, failed or uncertain), the encrypted message payload is erased; only the non-sensitive status, attempt count, provider message identifier and hashed idempotency key remain for diagnostics.


## Stage 2 acceptance boundary

Stage 2 is complete for the first provider (Postmark) on the development branch. Initial outbound requests remain synchronous; only explicit HTTP 429 responses enter the durable retry queue. Retries are bounded to five attempts with exponential backoff. Network timeouts, 5xx responses and stale in-flight jobs are marked uncertain and are never automatically resent because provider acceptance may be ambiguous. Terminal queue records have their encrypted payload erased; only non-sensitive state, hashed Message-ID key and provider message identifier remain. Postmark provider configuration includes write-only encrypted credentials, configurable message streams, webhook URLs, Basic Auth setup and a connection test that does not send email. No live credentials or production sending have been used.
