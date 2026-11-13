# DotFlow — AI, MCP and Elementor Integration Contract

## Product requirement

DotFlow must be discoverable and manageable by authorised AI agents through structured WordPress capabilities. AI must not need to scrape admin HTML, infer database schemas or execute arbitrary SQL to understand or update DotFlow data.

Elementor MCP and WordPress data management are distinct surfaces. Elementor MCP edits Elementor documents; DotFlow's WordPress Abilities and REST API manage CRM and automation records.

## 1. Elementor MCP surface

Elementor MCP is responsible for Elementor documents:
- Create and edit landing pages and page sections.
- Edit native Elementor containers, widgets, templates and supported global styles.
- Insert DotFlow-owned Elementor widgets and controls when the integration is installed.
- Configure DotFlow form embeds and supported front-end experiences.
- Read Elementor document structures and return concise semantic summaries.

Elementor content must be saved through Elementor's document/save lifecycle, not by raw SQL or direct unvalidated writes to the _elementor_data meta key. Native widgets and controls are preferred so users can continue editing output in Elementor's visual editor.

Elementor MCP does not automatically provide safe CRUD access to CRM contacts, campaigns, workflow executions or message records. Those records belong to DotFlow's management API.

## 2. WordPress MCP / Abilities surface

DotFlow exposes machine-readable WordPress Abilities for AI clients that support the WordPress Abilities API and MCP adapter.

Existing Groundhogg abilities are reused for inherited data:
- Contacts: search, get, create, update, custom fields, notes and tags.
- Campaigns and email: list/create campaigns, list/create/update templates and broadcasts.
- CRM profile and activity: a composite contact profile with optional tags/custom fields, separately permission-gated notes, and payload-free contact activity metadata. Individual contact event listing remains available for filtered/paginated timelines.
- Conversations: contact-scoped message history, optional message-body expansion and read/unread updates through DotFlow abilities. Raw message reads and administrator-wide event payload reads also enforce Groundhogg's `view_contact_pii` policy; a user restricted from PII cannot use these payload-bearing surfaces.
- Automation: list/get/create/edit/simulate/publish/activate/deactivate flows.
- Reporting: discover report types and retrieve reports.
- Settings: only registered settings with their own capability checks.

DotFlow-specific abilities are registered under the dotflow-platform category. Every ability must define a strict input schema, output schema, permission callback and read/write annotations.

## 3. REST API surface

Namespace: dotflow/v1

Implemented read-only endpoints:
- GET /manifest — machine-readable product/module manifest.
- GET /schema — supported object families, management abilities and write-safety policy.
- GET /events — paginated, filterable event history; administrator-only.

Planned endpoints:
- GET /manifest — versioned machine-readable product/module manifest.
- GET /schema — discover supported object types, fields, relationships and writable fields.
- GET /events — paginated, filterable DotFlow event history.
- POST /events — validated event ingestion with source authentication and idempotency.
- GET /integrations — provider names, connection state and supported capabilities; never return secrets.
- POST /integrations/{provider}/test — test a provider connection without exposing credentials.
- Provider-specific webhook endpoints — signature verification, replay protection and idempotent ingestion.

The REST API is not a generic database proxy. No arbitrary SQL, arbitrary option writes or unrestricted object mutation endpoints are exposed.

## 4. AI data contract

All returned records should use JSON with:
- Stable record ID.
- Object type and schema version.
- UTC timestamps in ISO 8601.
- Explicit field types and nullable fields.
- Pagination metadata.
- Related-record IDs rather than large nested expansions by default.
- Provenance for externally synchronised data (source, external_id, last_synced_at).
- Machine-readable error code and actionable message.

Private fields are minimised. Credentials, API keys, SMTP passwords, IMAP passwords, webhook signing secrets and license keys are never returned to AI clients. Sensitive settings are always redacted in list/update responses; AI cannot request a reveal override. PII access follows the same WordPress capabilities and per-contact ownership rules as the admin UI. Users without `view_contact_pii` cannot read raw message/event payloads or change contact identity fields and PII/unclassified custom-field values; safe CRM metadata remains manageable.

## 5. Write safety model

Read operations are annotated read-only and idempotent.

Write operations:
- Require a relevant WordPress capability and per-object authorization.
- Validate against strict schemas and allowlisted writable fields.
- Respect contact consent and suppression.
- Use optimistic concurrency or version checks for workflow edits.
- Are idempotent where possible.
- Record actor, timestamp, object, operation and outcome in an audit trail.
- Return the changed record and a revision/version identifier.

High-impact actions (send a campaign, enrol contacts into a live workflow, activate a workflow, publish workflow changes, install/activate extensions or licenses, delete/erase records, change provider credentials) are marked destructive so compatible AI hosts can require explicit confirmation or an approval mechanism. AI must never receive secret values back after writing them.

## 6. Elementor widgets and dynamic data

Planned native Elementor integration:
- DotFlow Form widget with a selectable published form/experience.
- DotFlow CTA widget with configurable tracked action and destination.
- DotFlow Contact Field widget only for explicitly public, consent-appropriate fields.
- DotFlow event/analytics bindings for supported page interactions.

Widgets must register only when Elementor is active, expose native controls and use Elementor's render lifecycle. Do not place private CRM records in public page markup or expose contact data through public dynamic tags.

## 7. Current implementation status

- DotFlow bootstrap and typed in-process event envelope: implemented.
- DotFlow event dispatcher: implemented.
- Machine-readable system manifest ability: initial implementation.
- Native inherited Groundhogg MCP abilities: available from the foundation.
- Durable DotFlow event storage with source-scoped idempotency, recursive secret-key redaction and administrator-only event-query ability: initial implementation.
- DotFlow Form Elementor widget with active-form selection, server-side form-type/status revalidation and CRM shortcode rendering: initial implementation; runtime validation still required.
- DotFlow-specific CTA/dynamic tags: planned.
- WordPress Abilities category and MCP abilities for the system manifest, published form discovery and event history: initial implementation.
- Read-only `dotflow/v1` REST discovery routes for manifest, schema and administrator-only event history: implemented; runtime route and permission tests still required.
- Mutation audit helper: implemented for message read-state; contact create/upsert/update; tag create/update; custom-field-definition create/update; campaign create/upsert and association; and flow create/edit/activate/deactivate/publish/discard Abilities. Audit payloads record the acting WordPress user ID, operation metadata, field names and IDs rather than contact values or message content. Broadcast schedule/cancel, email-template create/update, and changed settings are also audited. Settings values, email content and other sensitive values are not copied into audit payloads. Contact-note creation, workflow enrolment, composed/template/test email sends, media uploads, extension installation and license activation are also audited; message bodies, recipients, note content and license keys are excluded. Deletion and remaining mutation paths still need coverage.
- CRM profile MCP access: `dotflow/get-contact-profile` returns the shared contact schema and only expands notes/activity when explicitly requested. It checks `view_contact`, separately requires `view_notes` for notes, relies on Groundhogg's note-author scoping, and never returns event payloads. `dotflow/list-contact-events` exposes filtered, paginated event metadata for one authorised contact.
- Conversation MCP access: contact-scoped read/list and read-state mutation abilities are implemented against the inherited message store. Raw conversation reads are blocked for users restricted from PII; metadata-only contact activity remains available with contact permissions. Runtime privacy tests are being added.

## 8. Compatibility and validation

- Elementor 3.x classic widgets/containers and Elementor 4.x atomic elements have different document formats. Detect installed version and use corresponding native APIs/schema.
- Preserve existing page structures when editing.
- Use Elementor's save lifecycle and regenerate caches through supported APIs.
- Test with Elementor absent, Elementor 3.x and Elementor 4.x where feasible.
- Test MCP discovery, permission-denied paths, invalid schemas, PII restrictions, idempotency and audit records. PHPUnit coverage has been added but has not yet been executed in a live WordPress test environment.
