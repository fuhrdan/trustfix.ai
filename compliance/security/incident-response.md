# Incident Response Plan

**Owner:** TrustFix security / management owner  
**Status:** Initial plan  
**Last reviewed:** 2026-08-23  
**Exercise frequency target:** At least annually and after major architectural changes

## Objective

Provide a repeatable process to identify, contain, investigate, recover from, document, and learn from security/privacy/availability incidents.

## What is an incident?

Examples include:

- suspected account compromise
- administrator credential compromise
- unauthorized access to customer/contractor information
- confirmed or suspected data exposure
- malware or malicious upload
- payment/payout abuse
- significant vulnerability exploitation
- destructive/unauthorized production change
- loss/corruption of database or files
- extended service outage
- backup/restore failure with business impact
- lost secret/API token/private key
- material third-party provider security incident
- privacy request/data handling failure with material impact

## Severity model

| Severity | Description | Example |
|---|---|---|
| SEV-1 Critical | Severe confidentiality/integrity/availability or safety/legal impact | Confirmed broad data breach; unrecoverable production loss |
| SEV-2 High | Material impact requiring urgent response | Privileged-account compromise; major outage |
| SEV-3 Moderate | Limited impact, contained scope | Single-account compromise; significant vulnerability without known exploitation |
| SEV-4 Low | Minor event or suspicious activity | Blocked scanning/brute-force activity |

Severity definitions should be refined as TrustFix matures.

## Response lifecycle

### 1. Detect / report

Sources may include:

- login-security logs
- admin audit logs
- application errors/logs
- hosting alerts
- GitHub/security scanning
- Stripe alerts
- customer/contractor reports
- support cases
- vendor notifications

Capture the initial report without exposing sensitive data unnecessarily.

### 2. Triage

Record:

- date/time
- reporter/detection source
- systems/data affected
- suspected scope
- initial severity
- immediate risks
- incident owner

### 3. Contain

Possible actions:

- block malicious IPs
- disable compromised accounts
- revoke tokens/sessions
- rotate credentials/secrets
- restrict affected endpoints/features
- isolate malicious files
- temporarily disable risky integration
- preserve logs/evidence

Do not destroy evidence during containment.

### 4. Investigate

Determine:

- what happened
- when it began
- affected accounts/systems/data
- attack/failure path
- whether data was accessed/changed/exported
- whether persistence remains
- relevant logs/evidence
- root cause and contributing factors

### 5. Eradicate / remediate

Examples:

- patch vulnerability
- remove malicious content
- rotate keys
- correct permissions
- restore clean data
- add detection/control
- update vendor configuration

### 6. Recover

- restore service safely
- verify functionality
- monitor for recurrence
- validate authentication/authorization
- verify data integrity
- communicate status as appropriate

### 7. Notify / communicate

Determine legal, contractual, customer, insurer, and vendor notification requirements.

**Do not use this document as legal advice.** Notification obligations depend on affected people/data, jurisdictions, contracts, and incident facts. Obtain qualified advice when required.

### 8. Post-incident review

For material incidents record:

- timeline
- root cause
- what controls worked/did not work
- customer/business impact
- corrective actions
- risk-register updates
- policy/process changes
- lessons learned
- owner/due dates
- closure approval

## Evidence preservation

Preserve relevant:

- logs
- audit records
- Git commits/diffs
- hosting events
- vendor alerts
- account-access records
- timestamps
- communications
- remediation evidence

Sensitive incident evidence should be stored in a restricted location, not the public repository.

## Contact list

Maintain outside public Git:

- incident owner
- management contact
- hosting provider emergency/support contact
- GitHub/security contact method
- Stripe support/security contact method
- email/DNS provider contacts
- legal/privacy adviser when applicable
- cyber-insurance contact when applicable

## Exercises

Future target:

- annual tabletop exercise
- backup/restore exercise
- privileged-account-compromise scenario
- data-exposure scenario
- third-party outage scenario

Document participants, scenario, findings, corrective actions, and closure.
