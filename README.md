# DotFlow — The AI CRM for WordPress

DotFlow is a self-hosted WordPress CRM, email marketing and automation platform being developed from the open-source Groundhogg foundation.

**Product direction:** manage contacts, conversations, campaigns, automations, events and integrations in WordPress — with structured, permission-aware tools that AI agents can read and update.

> **Development status:** early development. This branch is not a production release and must not be installed on a live site. CI runs PHP syntax checks and WordPress/MariaDB integration tests on PHP 7.4, 8.2 and 8.3.

## What DotFlow is building

- **CRM:** contacts, custom fields, tags, notes, consent and suppression.
- **Campaigns:** email templates, broadcasts, delivery-provider adapters and reporting.
- **Automation:** visual workflows with triggers, conditions, delays, actions and execution history.
- **Conversations:** inbound and outbound message history, independent provider-neutral inbound webhook, signed reply routing and contact timelines.
- **Behaviour tracking:** consent-aware page, form and CTA events with attribution.
- **Integrations:** Elementor, DotForm, HubSpot, WooCommerce, GA4, SMTP/email APIs and webhooks.
- **AI/MCP management:** structured WordPress Abilities and REST contracts for safe discovery, reading and editing.

## AI and MCP

DotFlow reuses the WordPress Abilities API integration already present in its Groundhogg foundation. AI clients that support the WordPress Abilities API/MCP adapter can discover and invoke registered capabilities.

Initial DotFlow abilities include:

| Ability | Purpose |
|---|---|
| dotflow/get-system-manifest | Discover modules, capability states and AI data policy |
| dotflow/list-published-forms | Find published CRM forms and their Elementor widget mapping |
| dotflow/list-events | Search durable DotFlow event history |
| dotflow/list-messages | Read contact-scoped conversations; message bodies are opt-in |
| dotflow/set-message-read-state | Mark an authorised conversation message read/unread |

The inherited foundation also provides abilities for contacts, notes, tags, campaigns, email templates, broadcasts, visual flows, settings and reports. Every operation remains subject to WordPress capabilities and per-contact permissions. All currently exposed AI mutation abilities emit privacy-minimised audit events; this is not a claim of platform-wide auditing.

DotFlow does not expose SMTP passwords, IMAP passwords, API keys, webhook signing secrets or other credentials to AI clients. Sensitive keys in event payloads are redacted before storage.

### Independent inbound email

DotFlow includes an optional HTTPS webhook at `/wp-json/dotflow/v1/inbound/email`. It accepts a normalized provider envelope, authenticates with a server-side bearer token, and reuses Groundhogg's native message ingestion, signed reply-token verification, threading and duplicate detection. It does not provision or call the paid Groundhogg relay. Provider-specific payload adapters and live provider delivery validation remain in development; see the [integration guide](docs/MCP-AND-ELEMENTOR-INTEGRATION.md) for configuration.

See [MCP and Elementor Integration](docs/MCP-AND-ELEMENTOR-INTEGRATION.md) and the [Implementation Blueprint](docs/DOTFLOW-IMPLEMENTATION-PLAN.md).

## Elementor

DotFlow registers a native **DotFlow Form** widget when Elementor is active.

- Select an active CRM web-form step from the widget controls.
- The widget stores the selected form ID in the Elementor document.
- Rendering uses the CRM's existing form renderer, preserving validation and submission handling.
- Elementor MCP can edit the widget as part of the normal Elementor document workflow.
- CRM records remain managed through authenticated WordPress Abilities, not by embedding private data in public page markup.

Elementor MCP edits page layouts and widget settings. WordPress Abilities/MCP edits CRM records, workflows, conversations and settings. These are complementary interfaces, not interchangeable ones.

## Architecture principles

1. DotFlow owns its contacts, events, workflows, reporting and integration mappings.
2. External email and mailbox providers are adapters, not the system of record.
3. Existing Groundhogg CRM and queue infrastructure is reused where sound.
4. No arbitrary SQL or unrestricted generic object mutation is exposed to AI.
5. Sensitive data requires appropriate capabilities; public Elementor output never contains private CRM records.
6. Work is developed on dotflow/development; production deployment requires separate approval.

## Current implementation

- DotFlow bootstrap and typed domain event envelope.
- Durable event table registered with Groundhogg's database manager.
- Source-scoped event idempotency and recursive sensitive-key redaction.
- WordPress Abilities for the system manifest, event history, published forms and conversation access.
- Native Elementor form widget.
- Initial PHPUnit coverage for event storage, manifest and MCP-facing message operations.
- GitHub Actions PHP syntax validation.

PHP syntax validation has passed on recent development commits. Full WordPress/Elementor runtime testing and the PHPUnit suite still need to be completed before a release can be considered stable.

## Development

The working branch is dotflow/development.

Groundhogg's existing build and test conventions are retained:
- PHP dependencies: composer.json
- JavaScript build: package.json
- PHPUnit configuration: phpunit.xml.dist
- Development checks: .github/workflows/dotflow-ci.yml

Do not deploy this branch to production. Use an isolated staging WordPress installation for runtime, upgrade, Elementor and MCP tests.

## Upstream foundation and licence

DotFlow is a derivative work based on [Groundhogg](https://github.com/groundhoggwp/groundhogg), originally developed by Groundhogg Inc. The inherited code is licensed under **GNU GPL version 3 or later**. Applicable upstream copyright and licence notices must be preserved, and distributions of derivative works must comply with GPLv3.

This project does not claim the inherited Groundhogg code as wholly original DotFlow code.
