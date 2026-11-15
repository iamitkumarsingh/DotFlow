=== DenFlow — CRM, Email Marketing and Automation ===

Contributors: denflow
Tags: wordpress crm, marketing automation, email marketing, email automation, customer management
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

DenFlow is a self-hosted WordPress CRM and marketing automation platform. It includes original DenFlow modules and a modified GPLv3-licensed Groundhogg foundation. It brings contact management, email marketing, visual workflows, conversations, event automation and AI-facing WordPress Abilities into one WordPress installation.

== Description ==

DenFlow combines its CRM and automation foundation with DenFlow's own platform layer.

Key capabilities in this release:
* Contact CRM, tags, custom fields, notes, campaigns, broadcasts and visual automation.
* Typed WordPress Abilities for authorised AI/MCP clients, with capability checks, PII restrictions and mutation audit events.
* Durable DenFlow domain events for CRM changes, consent transitions, form submissions, page visits, email clicks and contact-aware CTA tracking.
* Native Elementor form widget that resolves and validates a published CRM form at render time.
* DenFlow Operations dashboard for event health, provider connection status, Postmark outbox counts and AI mutation audit metadata.
* Postmark outbound transport, inbound message normalisation, delivery webhooks and encrypted retry storage for explicit rate-limit responses.
* Optional DotForm submission bridge.
* Consent-gated GA4 Measurement Protocol and Google Tag Manager event forwarding. Analytics is disabled unless the site's consent manager explicitly grants consent.

Analytics, DotForm, Postmark and Elementor integrations require site-side configuration. See the [integration setup guide](https://github.com/iamitkumarsingh/DenFlow/blob/denflow/development/docs/INTEGRATION-SETUP.md), [tracking event contract](https://github.com/iamitkumarsingh/DenFlow/blob/denflow/development/docs/TRACKING-EVENTS.md) and [staging acceptance checklist](https://github.com/iamitkumarsingh/DenFlow/blob/denflow/development/docs/STAGING-ACCEPTANCE.md). The analytics consent filter defaults to false; connect it to your cookie/consent manager before adding GA4 or GTM identifiers.

== Installation ==

1. In WordPress, open Plugins → Add New Plugin → Upload Plugin.
2. Select the DenFlow ZIP and install it.
3. Activate DenFlow.
4. Configure email transport and optional integrations only after reviewing the documentation and your site's privacy/consent requirements.

Requirements: WordPress 6.9 or later and PHP 8.0 or later. Elementor is optional and required only for the native Elementor widget. Postmark, DotForm, GA4 and GTM are optional integrations.

== Privacy and security ==

* Analytics forwarding is off by default and requires explicit consent from a site consent integration.
* DenFlow does not send contact identifiers, email addresses, message bodies, submitted form fields or raw URLs to GA4/GTM.
* Provider credentials are write-only and are not exposed to AI abilities or the operations dashboard.
* Inbound email attachments are rejected in this release because secure scanning and retention are not yet implemented.
* Network and provider behaviour should be validated on a disposable staging site before enabling production traffic.

== Credits and license ==

DenFlow contains original DenFlow contributions and derivative components based on Groundhogg, originally developed by Groundhogg Inc. and contributors. Existing copyright, attribution and GPL notices for those components are preserved. DenFlow's derivative distribution is licensed under the GNU General Public License, version 3 or (at your option) any later version.

Full license text: https://www.gnu.org/licenses/gpl-3.0.txt
Source repository: https://github.com/iamitkumarsingh/DenFlow
