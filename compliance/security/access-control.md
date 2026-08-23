# Access Control Standard

**Owner:** TrustFix security / technical owner  
**Status:** Working standard; several target controls remain planned  
**Last reviewed:** 2026-08-23

## Objective

Ensure access to TrustFix systems and information is based on identity, business need, least privilege, and accountability.

## Principles

- Unique identities for users and administrators
- Least privilege
- Deny-by-default for sensitive operations
- Separation of normal and privileged access where practical
- Strong authentication proportional to risk
- Prompt removal of unnecessary access
- Periodic review of privileged/access rights
- Auditability of sensitive actions
- Secrets never stored in source control

## Application roles

TrustFix currently uses role-aware application access for customer/property-owner, contractor/handyman, administrator, and related account types supported by the application.

Future design should move toward explicit permissions where practical, for example:

```text
users.view
users.edit
users.disable

contractors.review
contractors.approve

documents.review

jobs.view
jobs.edit

payments.view

security.view
security.block_ip

audit.view
```

Roles may group permissions, but sensitive authorization should not depend only on UI visibility.

## Authentication controls

| Control | Target | Status |
|---|---|---|
| Unique account identity | Required | Implemented/verify completeness |
| Password authentication | Required | Implemented |
| Rate limiting / brute-force controls | Required | Implemented |
| Email verification | Required where applicable | Implemented |
| Password recovery | Controlled and logged | Implemented |
| Login-attempt audit data | Required | Implemented |
| Suspicious IP blocking | Admin-controlled | Implemented |
| MFA for administrators | Required future target | Planned |
| Recovery codes / MFA recovery | Required with MFA | Planned |
| Session/token revocation | Defined and testable | To verify |
| Reauthentication for high-risk actions | Risk-based | Planned |

## Privileged access

Administrators and future support/security operators should:

- use individual named accounts
- not share passwords/tokens
- use MFA when implemented
- use privileged access only for legitimate support/administrative purposes
- have sensitive actions logged
- have access reviewed at least quarterly
- lose access promptly when no longer required

Emergency access, if introduced, should be exceptional, time-bounded, and logged.

## Infrastructure / repository access

Access to the following should be explicitly inventoried and periodically reviewed:

- HawkHost/cPanel/SSH
- production database
- production files
- GitHub repository and settings
- Stripe dashboard/API keys
- SMTP/mail administration
- DNS/domain registrar
- backup locations
- monitoring/security tools

## Joiner / mover / leaver process

For employees/contractors/support users in the future:

### Grant
- documented business need
- approved role/access
- unique account
- MFA for privileged systems when available
- minimum required privileges

### Change
- update access when responsibilities change
- remove obsolete roles before adding broad new access

### Remove
- disable/remove access promptly
- revoke tokens/keys/sessions where appropriate
- transfer ownership of required records
- document completion

## Access review

Quarterly privileged access review should verify:

- account still belongs to an authorized person
- privileges remain necessary
- MFA status where required
- dormant accounts are disabled
- shared credentials are not being used
- API keys/tokens are still required
- vendor/support access remains justified

Record review date, reviewer, findings, and remediation.

## Secrets

Never commit:

- `.env`
- `config.local.php`
- passwords
- database credentials
- Stripe secrets
- JWT/application secrets
- SMTP passwords
- private keys
- personal access tokens

Future target:

- centralized secret storage
- documented secret owners
- rotation schedule/trigger criteria
- emergency revocation procedure
- automated secret scanning in CI

## Evidence

Possible evidence includes:

- application role/permission configuration
- access-review records
- GitHub/cPanel/Stripe user listings
- MFA configuration evidence
- login-security records
- admin audit records
- terminated-access checklist
- secret-scanning CI results

Sensitive screenshots/listings should not be committed to this public repository.
