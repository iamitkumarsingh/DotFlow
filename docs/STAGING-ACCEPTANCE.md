# DotFlow 0.1.0 — staging acceptance record

## Purpose

This is the required runtime acceptance record for a real WordPress environment. Automated GitHub CI verifies code-level behaviour; it does not prove that a specific hosting provider, consent manager, DotForm installation, Postmark account or Elementor release is correctly configured.

## Environment record

Complete on the disposable staging site:

| Item | Record |
| --- | --- |
| Site URL | |
| WordPress version | |
| PHP version | |
| Database engine/version | |
| Elementor version (if used) | |
| DotForm version (if used) | |
| Consent manager/version | |
| Postmark server/streams | |
| Test date and operator | |

Do not include credentials or personal data in this record.

## Test outcomes

Record PASS / FAIL / NOT APPLICABLE and link to redacted evidence.

| Test | Expected result | Outcome |
| --- | --- | --- |
| Fresh install | Plugin activates; migrations create expected tables | |
| Upgrade rehearsal | Existing test CRM data remains intact | |
| Backup/restore | Restored staging copy boots and retains CRM data | |
| Capability checks | Restricted user cannot access admin-only operations or PII | |
| Elementor | Published form renders; missing/unpublished form is not exposed | |
| DotForm | Persisted successful submission creates one event | |
| DotForm idempotency | Duplicate event ID does not dispatch twice | |
| Marketing eligibility | Non-marketable contact receives no marketing trigger | |
| Analytics default | No consent means no GA4/GTM forwarding | |
| Analytics consent | Allowlisted events only; no PII or query strings | |
| Analytics revocation | No subsequent forwarding after consent withdrawal | |
| Postmark connection | Server API test succeeds without sending an email | |
| Postmark delivery | Delivered, bounce, deferred and complaint states are recorded | |
| Postmark idempotency | Duplicate webhook does not duplicate event effects | |
| Inbound email | Routed message reaches the intended inbox/contact | |
| Inbound attachments | Attachment payload is rejected with HTTP 415 | |
| Outbox | Retryable 429 is retried; ambiguous network/5xx is not resent blindly | |
| Operations dashboard | Status visible to admins; no secrets/payloads exposed | |
| Runtime health | No critical PHP errors, JS errors or unsafe network requests | |
| Security review | No unresolved critical/high issues | |

## Sign-off

- Staging acceptance: PENDING until the environment-specific tests above are run.
- Production deployment: NOT AUTHORISED by this record.
- Any failure must be fixed and the affected test rerun before production approval.
