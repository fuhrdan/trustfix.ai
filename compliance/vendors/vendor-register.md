# TrustFix Vendor / Subprocessor Register

**Owner:** TrustFix management / security / privacy owner  
**Status:** Initial register; contracts and assurance evidence require review  
**Last reviewed:** 2026-08-23  
**Review frequency:** At least annually; critical vendors preferably more often

## Objective

Identify third parties whose failure, security posture, privacy practices, or availability can materially affect TrustFix.

## Criticality model

- **Critical:** loss/compromise could materially stop service or expose sensitive data
- **High:** significant operational/security/privacy effect
- **Moderate:** limited effect or easier replacement
- **Low:** minimal sensitive access/business dependency

## Initial register

| Vendor/service | Purpose | Data/access | Criticality | Assurance/contracts to collect | Owner | Last review | Status |
|---|---|---|---|---|---|---|---|
| HawkHost | Production hosting/runtime/storage | Production systems, DB/files depending architecture | Critical | Security terms; backup scope; incident process; data location; available assurance | TBD | TBD | To review |
| GitHub | Source control/version history | Source code, CI metadata; no production secrets intended | High | MFA/access settings; security features; available assurance; terms | TBD | TBD | Partial |
| Stripe | Payments/payout integrations | Payment/account identifiers and transaction metadata | Critical/High | DPA/privacy terms; security/compliance materials; incident/contact process | TBD | TBD | To review |
| SMTP / email provider | Verification, password recovery, notifications | Email addresses/message content | High | Provider identity; DPA/privacy/security terms; data location | TBD | TBD | To identify/review |
| Domain registrar / DNS | Domain resolution/control | Domain/admin metadata | Critical | Provider identity; MFA; recovery process; DNS access review | TBD | TBD | To identify |
| Backup provider/location | Recovery | Potential full production data | Critical | Provider/location; encryption; retention; access; restore process | TBD | TBD | To identify/verify |
| Monitoring/security service | Availability/security detection | Logs/metadata depending tooling | High | Provider/data handling/security review | TBD | TBD | Future/TBD |

## Vendor onboarding questions

Before adding a vendor that handles sensitive data or critical service:

### Business
- What service do they provide?
- Can TrustFix operate without them?
- What is the replacement/exit path?
- Who owns the relationship?

### Security
- What TrustFix systems/data can they access?
- How is access authenticated?
- Is MFA available/required?
- What security attestations/certifications are available?
- How are incidents reported?
- What is their vulnerability/security program?

### Privacy
- What PII do they receive/process?
- For what purpose?
- Where is it processed/stored?
- Do they use subprocessors?
- What deletion/return obligations apply?
- Is a DPA or equivalent needed?
- Are international transfers relevant?

### Availability
- SLA/availability commitment
- backup/recovery dependencies
- outage communication method
- business-continuity alternatives

## Annual review

For critical/high vendors verify:

- service still needed
- data/access scope remains accurate
- contacts remain valid
- security/privacy documentation is current
- significant incidents/issues
- contract/DPA status
- new subprocessors/material changes
- backup/exit plan where relevant

## Offboarding

When terminating a vendor:

- revoke accounts/API keys
- rotate dependent secrets
- export required data
- request/verify data deletion where appropriate
- remove integration
- update architecture/data inventory
- retain contractual/audit evidence
- update risk register

## Evidence handling

Third-party SOC reports, contracts, DPAs, penetration-test summaries, or confidential security materials should **not** be committed to this public repository.

Store restricted evidence separately and reference it using a non-sensitive evidence index.
