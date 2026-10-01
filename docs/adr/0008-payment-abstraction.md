# ADR-0008: Payments - Merchant of Record behind a provider abstraction

Status: accepted (2026-09-25)

## Context
Stripe direct does not support Georgia (verified on Stripe's global
availability page during research). Lemon Squeezy / Paddle act as Merchant of
Record (5% + $0.50), handling international VAT/sales tax - but their payout
country lists must be validated for a Georgian individual entrepreneur during
onboarding BEFORE vendor-specific code is written.

## Decision
- Launch path: MoR (Paddle or Lemon Squeezy, whichever passes onboarding).
- All vendor logic server-side behind `CommerceProvider`
  (PaddleProvider / LemonSqueezyProvider / FutureProvider).
- The WordPress plugin talks only to MUDRAVA's own licensing endpoint;
  no commerce secrets in plugin code.
- Local Georgian acquiring (TBC / Bank of Georgia) is Phase 2, not
  launch-critical (it shifts sales-tax compliance onto MUDRAVA).
- Licensing model per ER in research report: Customer → Subscription →
  License → Activations; store `install_id` + domain hash, not plaintext URLs.

## Consequences
- Vendor swap is a server-side change only.
- Before any checkout code: written accountant memo (Small Business Status
  applicability, Article 90 Georgian-source income, VAT Art.165 for
  cross-border e-services) + payout eligibility confirmation.
