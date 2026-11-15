# DotFlow 0.1.0 — integration setup and staging guide

This guide configures the integrations shipped in DotFlow 0.1.0. Use a disposable staging site first. Do not paste provider secrets into tickets, chat messages, browser-side JavaScript, or screenshots.

## 1. Prerequisites

- WordPress 6.9 or later.
- PHP 8.0 or later with OpenSSL enabled.
- HTTPS enabled end-to-end.
- A working database backup and a tested restore path.
- Elementor installed only if you intend to use the native DotFlow Form widget.
- A valid Groundhogg installation/database for the inherited CRM foundation.

DotFlow is derived from Groundhogg. Do not activate DotFlow alongside a separately active Groundhogg plugin on the same WordPress site. Back up first and follow a tested migration/upgrade plan.

## 2. DotForm submission bridge

DotFlow listens for the WordPress action `dotform/submission/after`. DotForm should emit it only after the submission has been saved and an existing Groundhogg contact has been associated.

Argument order:

1. `$contact_id`: existing Groundhogg contact ID.
2. `$form_id`: DotForm form ID.
3. `$submission_id`: persisted DotForm submission ID.
4. `$event_id`: optional stable event ID. If omitted, DotFlow derives one from the submission ID.

Example from the DotForm submission-success path:

```php
do_action(
    'dotform/submission/after',
    $groundhogg_contact_id,
    $form_id,
    $submission_id,
    'dotform-submission-' . $submission_id
);
```

Do not fire this hook for validation failures, abandoned forms, or before the database transaction commits. Never pass submitted values, email addresses, names, message content, file URLs or payment data as arguments.

Verify:
- A successful submission creates one `form.submitted` event.
- A duplicate hook delivery with the same event ID does not create a second event or re-fire listeners.
- `form.marketing_eligible` is created only when the associated Groundhogg contact is marketable.
- An unknown contact or invalid identifier creates no event.

The DotFlow bridge is shipped, but it cannot make a separate DotForm plugin emit this hook automatically. Confirm the hook exists in the installed DotForm build before enabling conversion workflows.

## 3. GA4 and Google Tag Manager

Add server-side settings to `wp-config.php` before the “stop editing” line:

```php
define( 'DOTFLOW_GA4_MEASUREMENT_ID', 'G-XXXXXXXXXX' );
define( 'DOTFLOW_GA4_API_SECRET', 'YOUR_SERVER_SIDE_MEASUREMENT_PROTOCOL_SECRET' );
define( 'DOTFLOW_GTM_CONTAINER_ID', 'GTM-XXXXXXX' );
```

Keep the Measurement Protocol API secret server-side. Never put it in page markup, GTM variables, a public script, or a client-side settings field.

Connect your cookie/consent manager through server-side filters. Example adapter (replace the example function names with the API used by your consent platform):

```php
add_filter(
    'dotflow/analytics/consent_granted',
    function ( $granted, $event = null ) {
        return function_exists( 'my_consent_manager_has_consent' )
            && my_consent_manager_has_consent( 'analytics' );
    },
    10,
    2
);

add_filter(
    'dotflow/analytics/client_id',
    function ( $client_id, $event = null ) {
        if ( ! function_exists( 'my_consent_manager_ga_client_id' ) ) {
            return '';
        }
        return (string) my_consent_manager_ga_client_id();
    },
    10,
    2
);
```

The example functions above are placeholders, not functions supplied by DotFlow. Implement the adapter against the actual consent manager and ensure it returns a client ID only after valid analytics consent. DotFlow defaults to no consent and does not create browser identifiers.

The event allowlist is limited to form submissions, known-contact page views, tracked link clicks and consent-aware CTA clicks. DotFlow strips query strings and fragments from paths and excludes contact IDs, email addresses, form answers, message bodies, consent events and AI audit events.

Staging verification:
- With no analytics consent, no GTM container is enqueued and no GA4 request is made.
- After consent, only allowlisted events are forwarded.
- Revoke consent and verify subsequent events are not forwarded.
- Inspect GA4 DebugView/Realtime and GTM Preview; confirm no personal data or query-string values appear.
- Confirm GA4 Measurement Protocol requests use the correct property and secret. A non-blocking request only confirms WordPress accepted the request for dispatch, not that Google processed it.

## 4. Postmark email transport and webhooks

In WordPress, open Groundhogg Settings → Email and find the **DotFlow · Postmark** section.

1. Enter the Postmark Server Token and webhook Basic Auth password. Values are write-only in the UI and encrypted at rest using AES-256-GCM.
2. Save settings.
3. Select **Postmark (DotFlow)** as the appropriate Groundhogg transactional/marketing email service.
4. Set the Postmark transactional and marketing Message Stream names to match the streams configured in your Postmark account.
5. Use **Test Postmark connection**. It calls the Postmark server endpoint and does not send an email.

The encryption key is derived from WordPress salts and the site URL. If salts are rotated or the site is migrated to a different URL, re-enter the provider credentials.

### Delivery webhooks

Configure Postmark to send delivery events to:

`https://YOUR-SITE.example/wp-json/dotflow/v1/email/delivery/postmark`

Set HTTP Basic Authentication:
- Username: `dotflow`
- Password: the webhook password saved in DotFlow settings

The endpoint requires HTTPS and validates the Basic Auth credentials. Postmark's standard webhook integration uses Basic Auth rather than a signed HMAC header. Use a strong random password and IP allowlisting at your WAF/reverse proxy where feasible.

Test delivered, hard-bounce, transient/deferred and spam-complaint events. Confirm duplicate notifications are idempotent and only status metadata/fingerprints are stored.

### Inbound email

The inbound endpoint is:

`https://YOUR-SITE.example/wp-json/dotflow/v1/inbound/email/postmark`

Use the same Basic Auth credentials. Configure a Postmark inbound stream and the DotFlow inbox routing address before sending real inbound traffic. Attachments are intentionally rejected (HTTP 415) in this release until scanning and retention controls are implemented. Do not enable attachment-dependent workflows.

## 5. Elementor form widget

Elementor is optional. On a staging site with the supported Elementor version:
- Open a test page in Elementor.
- Add the **DotFlow Form** widget.
- Select a published CRM web-form step.
- Save, preview, and submit a test form.
- Verify the front-end renders only the published form and the CRM records the submission.
- Unpublish/delete the form and confirm the widget does not expose stale or private data.

Do not treat the widget's existence as proof of compatibility with every Elementor release. Verify the editor and frontend against the Elementor version installed on the target site.

## 6. Release acceptance checklist

- [ ] Fresh install on a clean WordPress 6.9+ / PHP 8.0+ staging site.
- [ ] Existing CRM data backup, upgrade rehearsal and restore rehearsal.
- [ ] No simultaneous active Groundhogg plugin copy.
- [ ] Admin and restricted-role capability checks.
- [ ] Elementor editor/frontend test, including a missing/unpublished form.
- [ ] DotForm success, invalid contact, duplicate delivery and marketability tests.
- [ ] GA4/GTM no-consent, consent, revoke-consent and payload privacy checks.
- [ ] Postmark connection test, one approved test email, delivery webhook, bounce and complaint.
- [ ] Inbound routing test without attachments; attachment request rejected safely.
- [ ] Outbox retry, duplicate send and operational dashboard review.
- [ ] PHP error log, WordPress debug log, browser console and network requests reviewed.
- [ ] No critical/high security findings and no PII/secrets in logs or analytics.
- [ ] Site owner signs off on staging before production deployment.

## 7. Known release boundaries

- DotForm hook integration requires the separate DotForm build to emit the documented action.
- Google credentials, consent-manager adapters and live property/container checks are site-specific.
- Postmark requires a real account, verified sender/domain and configured streams.
- Inbound attachments are unsupported and rejected.
- CRM attribution and campaign revenue reporting are not included in this release.
- Multisite, backup/restore and broad Elementor-version compatibility still require site-specific acceptance.

These are explicit release boundaries, not claims of completed live validation.
