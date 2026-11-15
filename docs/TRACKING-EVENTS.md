# DotFlow tracking events

DotFlow bridges Groundhogg's existing contact-aware tracking into the durable DotFlow event store. It does not add a second visitor fingerprint, browser cookie, or independent page-view tracker.

## Native events

| DotFlow event | Groundhogg source | Trigger |
| --- | --- | --- |
| \`form.submitted\` | \`groundhogg\` | A successful CRM form submission |
| \`form.marketing_eligible\` | \`groundhogg\` | A successful submission when the contact is marketable |
| \`contact.marketing_consent.granted\` | \`groundhogg\` | Marketing consent is granted |
| \`contact.marketing_consent.revoked\` | \`groundhogg\` | Marketing consent is revoked |
| \`page.viewed\` | \`groundhogg\` | Groundhogg records a page visit for a known contact |
| \`link.clicked\` | \`groundhogg\` | Groundhogg records a tracked email link click |
| \`cta.clicked\` | \`groundhogg\` | A consent-aware integration emits the CTA action below |

All events require a valid Groundhogg contact before they can start a contact workflow. Anonymous visits are not converted into contact records.

## Emit a website CTA event

A website component or integration that already has a lawful, consent-aware click signal can emit a CTA event:

\`\`\`php
do_action(
    'dotflow/track/cta_click',
    $contact,                         // Existing Groundhogg Contact.
    'header_book_appointment',        // Stable element identifier.
    home_url( '/book/?campaign=x' ),  // Destination; only its path is retained.
    'booking-cta-' . $click_uuid      // Stable unique ID for this click.
);
\`\`\`

Use a unique, stable event ID for each actual click and reuse that same ID if the caller retries delivery. Do not use an email address, phone number, form value, session token, or other personal data as the identifier.

The caller is responsible for enforcing the site's consent policy before emitting the action. DotFlow deliberately does not install a global click listener or infer consent from the presence of a contact cookie.

## Data minimisation

- Event payloads contain contact/object identifiers and limited event metadata.
- Page and link paths are retained without URL query strings or fragments.
- Full destination URLs, email addresses, form answers, message content and uploaded file URLs are not copied into tracking event payloads.
- Event source and event ID are used for idempotency. A duplicate delivery does not dispatch the event a second time.
- The event history and payload-reading abilities remain permission-scoped.

## Configure a workflow

1. Observe a real event by using the relevant Groundhogg feature or integration.
2. Discover event names and sources with \`dotflow/list-event-types\`.
3. Add the DotFlow Event benchmark to a workflow.
4. Configure the exact event name and, when required, the exact source.
5. Simulate and review the workflow before publishing it.

Marketing sends must continue to use Groundhogg's native marketability, suppression and unsubscribe checks. A page-view, link-click or CTA event is an engagement signal, not marketing consent.
