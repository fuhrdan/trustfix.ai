# Data Retention and Disposal Framework

**Owner:** TrustFix privacy / management owner  
**Status:** Framework only; final retention periods require approval  
**Last reviewed:** 2026-08-23

## Objective

Keep information only as long as needed for legitimate business, security, contractual, accounting, support, legal, and privacy purposes, then securely delete or anonymize it where appropriate.

## Important note

The periods below are **not legal advice and are not yet TrustFix policy**. Final retention periods must account for applicable law, accounting/tax obligations, disputes, contracts, insurance, privacy requirements, and operational needs.

## Principles

- Do not keep data forever by default.
- Document a purpose for retention.
- Minimize duplicate copies.
- Apply longer retention only when justified.
- Legal hold overrides routine deletion where required.
- Backups require their own expiration lifecycle.
- Deletion should include active systems and eventually age out of backups.
- Sensitive data should have shorter/minimum necessary retention where practical.
- Audit/security records should be retained long enough to investigate and demonstrate controls.

## Retention schedule — initial decision register

| Data category | Business reason | Proposed direction | Final period | Disposal method | Status |
|---|---|---|---|---|---|
| Active account profile | Deliver service | While account active + defined closure period | TBD | Delete/anonymize as appropriate | Planned |
| Authentication artifacts | Authentication/security | Minimum necessary | TBD | Expire/delete | Partial |
| Login-security records | Detect/investigate abuse; evidence | Define security retention window | TBD | Automated purge/anonymization | Planned |
| Admin audit records | Accountability/evidence | Longer audit window | TBD | Controlled purge/archive | Planned |
| Property records/images | Service history | Business/legal need | TBD | Delete/anonymize/files purge | Planned |
| Job records/images | Service/accounting/dispute history | Business/legal need | TBD | Controlled deletion/archive | Planned |
| Messages/activity | Job/support evidence | Align with job/support retention | TBD | Delete/archive | Planned |
| Contractor profile | Service/marketplace | Active + defined closure period | TBD | Delete/anonymize | Planned |
| Contractor credentials/docs | Verification/legal | Defined credential/business period | TBD | Secure file deletion | Planned |
| Payment/accounting metadata | Accounting/disputes/legal | Follow accounting/legal need | TBD | Delete/archive securely | Planned |
| Support/dispute records | Resolution/evidence | Defined post-closure period | TBD | Delete/archive | Planned |
| Application logs | Operations/security | Shorter operational window | TBD | Rotate/delete | Planned |
| Backups | Recovery | Rotation based on RPO/recovery need | TBD | Expire/delete securely | Planned |

## Deletion design

New features should answer:

- Can this record be deleted?
- If referenced by financial/audit records, can identifying fields be anonymized instead?
- Are uploaded files deleted with database records?
- Are derived/cached copies deleted?
- Does a vendor retain a copy?
- Is deletion logged without logging the deleted sensitive content?
- When does the deleted data age out of backups?

## Legal hold / dispute hold

Future process:

1. identify data subject/records
2. record reason/authority for hold
3. suspend routine disposal for affected data
4. restrict access
5. periodically review necessity
6. release hold when authorized
7. return records to normal retention lifecycle

## Account closure

Future account-closure workflow should distinguish:

- immediate access disablement
- retention needed for open jobs/payments/disputes
- privacy/legal requirements
- anonymization vs deletion
- backup expiration
- confirmation/evidence

## Automation roadmap

Add scheduled processes for:

- expired login-security records
- expired temporary files
- stale verification/recovery artifacts
- closed support records when appropriate
- deleted-account purge/anonymization
- expired backups

Automated deletion jobs should log aggregate outcome/errors without exposing deleted sensitive content.

## Review

Review the retention schedule:

- at least annually
- after legal/privacy changes
- when new data categories/vendors are introduced
- when storage architecture changes
- after significant incidents or privacy requests
