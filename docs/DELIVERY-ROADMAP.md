# DotFlow delivery roadmap

This is the working delivery plan for the `dotflow/development` branch. A phase is complete only when its acceptance gates pass; merged code or a green PHP syntax job alone is not sufficient.

## Delivery guardrails

- Keep all work on `dotflow/development`; never modify `master` or deploy to production.
- Preserve Groundhogg GPLv3 notices and derivative-work obligations.
- CRM reads and writes use typed, permission-scoped WordPress Abilities or narrowly defined REST contracts.
- No generic AI SQL writes, unrestricted CRUD endpoint, credential readback or paid-relay licensing bypass.
- High-impact actions must be marked for confirmation; all exposed AI mutations must emit privacy-minimised audit events.
- Report runtime coverage honestly. CI results must match the exact branch commit being described.

## Phase 1 — Platform foundation and security

**Status: foundation implemented; hardening and coverage continue.**

Scope:
- DotFlow bootstrap, module manifest, versioned REST discovery and durable event store.
- Typed CRM, campaign, workflow, email and conversation abilities.
- PII-aware access, sensitive-setting and license-key redaction.
- Actor-attributed audit events for exposed AI mutations.
- High-impact action annotations and read-only database-query boundary.

Exit gates:
- No generic SQL write or unscoped mutation surface.
- Permission-denial, PII-redaction, audit and destructive-action tests pass.
- Database errors never disclose raw SQL or sensitive server details.

## Phase 2 — AI-facing CRM data layer

**Status: core inherited abilities and DotFlow contact-profile/timeline foundation exist; contract review continues.**

Scope:
- Permission-scoped contact search/profile, custom fields, tags, notes, campaigns, workflows and conversation reads.
- Explicitly bounded write operations for contact updates, notes, tags, custom fields and workflow enrolment.
- Consistent schemas, validation, consent/opt-in handling, audit metadata and pagination.
- PII-restricted users receive redacted contact fields and cannot read free-form notes or conversation bodies; contact activity remains metadata-only.
- No direct SQL writes from AI clients.

Exit gates:
- Positive and negative permission tests for every object family.
- Field-level validation and opt-in/suppression tests.
- Audit event assertions for each mutation path.
- Stable machine-readable schemas and useful error contracts.

## Phase 3 — Conversations and independent email transport

**Status: inbound webhook foundation implemented; provider adapters and operational hardening remain.**

Scope:
- Optional provider-neutral inbound webhook over HTTPS with server-side bearer-token authentication.
- Reuse Groundhogg's native message ingest, signed reply routing, contact matching, threading and duplicate detection.
- Privacy-minimised inbound activity events.
- Provider-specific webhook adapters, retry/idempotency policy, attachment handling and operational health checks.
- Independent outbound provider adapters while retaining existing queue/retry infrastructure.

Exit gates:
- Authentication, payload bounds, wrong-recipient, duplicate, threading and PII tests.
- Provider sandbox tests for delivery, retries, malformed payloads and attachments.
- No reliance on paid Groundhogg relay provisioning or private relay APIs.

## Phase 4 — Campaigns, workflows and event automation

**Status: inherited CRM automation abilities and the DotFlow Event workflow benchmark are implemented; end-to-end queue-enqueue and duplicate-delivery tests pass. Workflow simulation and operational retry validation remain.**

Scope:
- Connect consent-aware form, page, CTA and CRM events to workflow triggers.
- Typed workflow editing, validation, simulation, publishing and enrolment.
- Campaign/broadcast scheduling, cancellation, approval annotations and delivery outcomes.
- Durable event dispatch: listeners run only after a new event row is committed; duplicate source/event IDs are acknowledged without re-firing listeners.
- A native `dotflow_event` benchmark matches a configured event name and optional source, requires a linked contact, and passes only event name/source/ID into workflow args. Arbitrary event payloads and AI mutation audit events are excluded.

Exit gates:
- Duplicate and out-of-order event tests.
- Workflow simulation and publish/rollback tests.
- Consent, suppression, unsubscribe and frequency-cap tests.
- No accidental broadcast or bulk enrolment without an explicit approval boundary.

## Phase 5 — Elementor and WordPress operator experience

**Status: native DotFlow Form widget foundation implemented; editor/runtime validation remains.**

Scope:
- Native Elementor widgets and controls for CRM forms and approved experiences.
- Clear separation between Elementor MCP page editing and WordPress Abilities CRM operations.
- Admin screens for provider connections, event health, audit history and integration status.

Exit gates:
- Elementor supported-version editor and frontend tests.
- Responsive, accessibility, capability and nonce checks.
- Public pages never expose private CRM records or provider secrets.

## Phase 6 — Integrations and analytics

**Status: architecture and integration requirements documented; implementation is staged.**

Scope:
- DotForm and WordPress form integrations.
- GA4/GTM event forwarding with consent controls and deduplication.
- Email provider integrations and delivery webhooks.
- CRM attribution and campaign reporting.

Exit gates:
- Contract tests with provider sandboxes.
- Consent and data-minimisation verification.
- No duplicate conversion events or leaked credentials.

## Phase 7 — Release readiness

**Status: not started.**

Scope:
- Full test matrix, static analysis, dependency/security audit and performance profiling.
- Fresh-install, upgrade, rollback, backup/restore and multisite checks.
- Staging acceptance, documentation, release notes and GPL notices.

Exit gates:
- All required CI jobs pass on the release commit.
- Runtime tests pass on a disposable staging site with Elementor and representative integrations.
- No unresolved critical/high security findings.
- Explicit user approval before any production deployment.
