# TrustFix Risk Register

**Owner:** TrustFix security / management owner  
**Status:** Initial risk register  
**Last reviewed:** 2026-08-23  
**Review frequency:** Quarterly and after major changes/incidents

## Method

This initial register uses a simple 1–5 scale:

- **Likelihood:** 1 Rare, 2 Unlikely, 3 Possible, 4 Likely, 5 Very likely
- **Impact:** 1 Minimal, 2 Minor, 3 Moderate, 4 Major, 5 Severe
- **Risk score:** Likelihood × Impact

Suggested interpretation:

| Score | Level |
|---:|---|
| 1–4 | Low |
| 5–9 | Moderate |
| 10–16 | High |
| 17–25 | Critical |

This methodology should be formally approved before certification work. Residual risk should be assessed after controls are implemented and evidenced.

## Initial register

| ID | Risk | Assets/processes affected | L | I | Initial score | Existing/known controls | Treatment / next action | Owner | Status |
|---|---|---|---:|---:|---:|---|---|---|---|
| R-001 | Credential stuffing / brute-force login attacks | Accounts, authentication | 4 | 4 | 16 | Login throttling; login-attempt logging; IP blocking | Add MFA for privileged accounts; alert thresholds; periodic review | TBD | Partial |
| R-002 | Privileged account compromise | Admin functions, customer/contractor data | 3 | 5 | 15 | Role controls; administrative audit logging | Require MFA; privileged access review; recovery procedure | TBD | Partial |
| R-003 | Excessive privileges / authorization defect | API resources, PII, admin functions | 3 | 5 | 15 | Role middleware; ownership checks | Formal permission model; authorization tests; quarterly access review | TBD | Partial |
| R-004 | Secret/token exposure through source control | API keys, DB credentials, auth secrets | 3 | 5 | 15 | `.env` / live config excluded from Git | Add secret scanning and documented rotation process | TBD | Partial |
| R-005 | Vulnerable dependency or application flaw | Frontend, Laravel API | 4 | 4 | 16 | Framework validation/testing capabilities | Automated dependency/SAST scanning; remediation SLAs | TBD | Planned |
| R-006 | Unauthorized modification of production | Application integrity/availability | 3 | 5 | 15 | Git history; manual controlled deployment practice | Formal change/release approvals; CI; staging; rollback evidence | TBD | Partial |
| R-007 | Loss/corruption of production database | Customer jobs, accounts, operational records | 3 | 5 | 15 | Backup capability to verify | Define RPO/RTO; automated backup monitoring; restore tests | TBD | To verify |
| R-008 | Loss/corruption of uploaded files | Job/property/contractor documents | 3 | 4 | 12 | Hosting/file storage | Verify backup scope; restore tests; retention rules | TBD | To verify |
| R-009 | PII retained longer than necessary | User/property/contractor/support data | 4 | 4 | 16 | Application deletion capabilities vary | Approve retention schedule; automate deletion/anonymization | TBD | Planned |
| R-010 | Sensitive information exposed in logs | Authentication, support, API logs | 3 | 4 | 12 | Passwords/tokens should not be logged | Logging standard; redaction tests; restricted log access | TBD | Partial |
| R-011 | Third-party provider outage | Hosting, email, GitHub, Stripe | 3 | 4 | 12 | Provider redundancy varies | Vendor criticality reviews; contingency plans; service monitoring | TBD | Planned |
| R-012 | Third-party security/privacy incident | PII, payments, source code | 3 | 5 | 15 | Vendor security controls vary | Vendor reviews; DPA/contracts; incident notification process | TBD | Planned |
| R-013 | Payment workflow misuse/fraud | Payment/payout workflows | 3 | 5 | 15 | Stripe integration | Minimize stored payment data; audit sensitive actions; fraud controls | TBD | Partial |
| R-014 | Inadequate incident response | Entire service | 3 | 5 | 15 | Security logging exists | Formal incident plan; severity levels; exercises; evidence retention | TBD | Planned |
| R-015 | Production environment single-point dependency | Availability/recovery | 3 | 5 | 15 | Current hosting | Document recovery; off-system backups; future staging/DR design | TBD | Planned |
| R-016 | Failure to delete/export/correct PII when required | Privacy operations | 3 | 4 | 12 | Account/data workflows exist | Formal privacy request process; data map; identity verification | TBD | Planned |
| R-017 | Inadequate audit evidence retention | Future SOC/ISO readiness | 4 | 3 | 12 | Git and application logs provide some evidence | Evidence index; retention rules; restricted repository | TBD | Planned |
| R-018 | Quality defects reach production | Customer trust, service availability | 4 | 4 | 16 | Feature tests/manual testing | CI, staging, release checklist, defect metrics | TBD | Partial |
| R-019 | Malware or malicious file upload | File workflows, users/admins | 3 | 4 | 12 | Server-side file controls to verify | File-type validation; size limits; malware scanning strategy | TBD | To verify |
| R-020 | Privacy/security impact from new feature not assessed | PII, security, vendors | 4 | 4 | 16 | Informal development review | Add security/privacy questions to feature/change workflow | TBD | Planned |

## Risk treatment options

For each risk, choose and document one:

- **Mitigate** — reduce likelihood/impact with controls
- **Avoid** — stop the risky activity
- **Transfer/share** — contractual/insurance/vendor treatment
- **Accept** — management formally accepts residual risk

Risk acceptance should identify who accepted it, why, and when it must be reviewed.

## Review notes

At each quarterly review:

1. Confirm scores still reflect current architecture/threats.
2. Add risks created by new features/vendors.
3. Confirm treatment owners/dates.
4. Assess residual risk for implemented treatments.
5. Escalate overdue high/critical actions.
6. Link material incidents and corrective actions back to risks.

## Evidence to retain outside public Git

- approval of risk methodology
- completed periodic reviews
- risk-treatment decisions
- residual-risk acceptance
- meeting/management-review evidence
- remediation evidence
