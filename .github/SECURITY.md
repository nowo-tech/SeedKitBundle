# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 0.1.x   | :white_check_mark: (tagged only after its immediate REQ-SEC-004 security audit) |
| < 0.1.0 (unreleased `main` before the first tag) | :x: |

SeedKitBundle is tagged 0.x per slice (0.1.0 core + console, 0.2.0 UI + Messenger), each after its immediate
REQ-SEC-004 audit (an import is a write path); only the latest 0.x minor receives fixes until 1.0.0. See
[docs/ROADMAP.md](../docs/ROADMAP.md).

## Reporting a Vulnerability

We take the security of SeedKitBundle seriously. If you believe you have found a security vulnerability, please report it privately:

- **Email**: hectorfranco@nowo.tech
- **Do not** open a public GitHub issue for security-sensitive bugs.

Please include:

- Type of issue (e.g., injection, path traversal, access bypass, deserialization risk, secret leak, etc.)
- Affected file(s) and version/tag/commit
- Steps to reproduce
- Impact assessment
- PoC (if available)

## Response Timeline

- Initial acknowledgment: within 48 hours
- Follow-up status: within 7 days
- Resolution: depends on complexity and impact

## Disclosure Policy

- We confirm receipt and validate the report.
- We prepare and publish a fix as soon as possible.
- We coordinate disclosure with the reporter.
- We credit responsible disclosure (unless anonymity is requested).

For technical details and the threat model, see [docs/SECURITY.md](../docs/SECURITY.md).
