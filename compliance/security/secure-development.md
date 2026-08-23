# Secure Development and Change Management

**Owner:** TrustFix technical / security owner  
**Status:** Working standard and maturity roadmap  
**Last reviewed:** 2026-08-23

## Objective

Make security, privacy, quality, traceability, and recoverability normal parts of TrustFix development and production change.

## Current working model

TrustFix uses Git/GitHub for source history and a live production environment. Updates are tested before becoming the canonical GitHub state.

This model provides useful traceability but should mature over time toward a development/staging/production release pipeline.

## Target lifecycle

```text
Requirement / issue
       |
Risk + privacy impact consideration
       |
Development
       |
Automated tests + security checks
       |
Review / approval
       |
Merge / versioned release
       |
Staging verification
       |
Approved production deployment
       |
Post-deployment verification
       |
Evidence retained
```

## Change requirements

Material changes should be traceable to:

- purpose/requirement
- files/components changed
- person making change
- test results
- security/privacy consideration where relevant
- approval/review
- production deployment date
- rollback/recovery approach
- post-deployment verification

For a small team, approval roles may overlap, but the history should still be explicit.

## Secure coding expectations

- server-side validation for untrusted input
- authorization enforced server-side
- least-privilege data access
- parameterized ORM/query practices
- output encoding appropriate to context
- CSRF protection for browser actions
- secure session/token handling
- safe file-upload validation
- avoid sensitive data in errors/logs
- secrets outside source control
- dependencies kept supported/current
- error handling should fail safely
- security-sensitive actions should be auditable

## Authentication / authorization changes

Changes to:

- login
- password recovery
- verification
- JWT/session behavior
- roles/permissions
- admin capabilities
- security logs
- IP blocks
- account status

should receive explicit regression testing and security review.

## Database migrations

Before production:

- migration is reviewed
- backup/recovery implications are considered
- destructive changes are identified
- rollback/forward-fix strategy is understood
- application compatibility is checked

Future CI should test migrations against a clean database and, where feasible, representative upgrade paths.

## Dependency management

Future target:

- automated dependency vulnerability checks
- defined remediation priority/SLA based on severity/exposure
- documented exception process
- periodic removal of unused dependencies

## CI security roadmap

Add progressively:

- PHP syntax validation
- PHPUnit/Laravel feature tests
- dependency vulnerability scanning
- secret scanning
- static application security testing
- code-quality/lint checks
- migration validation
- frontend tests
- build/package verification

## Branch / repository controls

Future target:

- protected `main`
- reviewed pull requests for material changes
- required CI before merge
- limited repository administrators
- MFA for GitHub accounts with write/admin access
- periodic collaborator/access review
- release tags for production milestones

## Environment separation

Target:

```text
Development -> Staging -> Production
```

Production secrets/data should not be casually copied into development.

Use:

- synthetic/test data
- anonymized/sanitized fixtures
- separate Stripe/test configuration
- separate credentials
- controlled mail behavior

## Vulnerability handling

When a vulnerability is identified:

1. record it
2. assess severity/exploitability/exposure
3. assign owner
4. remediate or formally accept risk
5. test fix
6. deploy
7. retain evidence
8. update risk/control documentation if systemic

Critical exploitable vulnerabilities should receive immediate attention.

## Security/privacy design questions

Before adding a material feature:

- What data does this collect?
- Is any of it PII or sensitive?
- Why is it required?
- Who can access it?
- What authorization protects it?
- What should be logged?
- How is it deleted?
- How long is it retained?
- Does it introduce a vendor?
- Does it create a new attack surface?
- What happens if it fails?
- How do we test the control?

## Evidence

Useful evidence:

- commits / PRs
- CI runs
- test reports
- vulnerability results
- release notes
- deployment logs
- change approvals
- rollback tests
- corrective actions

Do not put secrets or customer data into issue/CI evidence.
