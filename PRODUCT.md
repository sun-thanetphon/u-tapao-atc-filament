# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary: ATC officers (เจ้าหน้าที่ควบคุมจราจรทางอากาศ) of the U-Tapao ATC unit, who open the system to read the documents their section must acknowledge and to acknowledge them. Confirmed by the product owner.

Secondary, from the codebase: admins and super-admins who publish documents, follow who has and has not acknowledged, and force-acknowledge on someone's behalf (super-admin only). Roles in code: `user`, `admin`, `super-admin`. Users belong to a section (ADC, APP, FA, ...); documents declare which sections may view them and which must acknowledge them.

## Product Purpose

Store and track acknowledgement of controlled operational documents (work instructions, quality procedures, emergency plans, forms, supporting documents) for the U-Tapao ATC unit. Success, confirmed by the product owner: everyone required acknowledges each document, and that can be proven with evidence (who, which document, when).

## Positioning

An in-house system built around the unit's own section structure and document control practice, not a generic document manager: documents are targeted per section, acknowledgement is per person and dated, and a super-admin can follow and force acknowledgement. The service page states it is built and maintained voluntarily by unit members, non-commercially.

## Operating Context

- Documents arrive as PDFs under controlled codes (for example `WI-ATS.ATCD-008`, `QP-ATS.ATCD.EMP-001`); the system also assigns its own code (`D26090001`) that is secondary to the document's own code.
- Document categories are free-text names managed by admins, commonly `WI (Work Instruction)`, `QP (Quality Procedure)`, `SD`, `FM`; other categories exist.
- Email notifies users when a document needing acknowledgement is published.
- The Home page shows the unit's Air Navigation Services Certificate (valid until 13 Jan 2030) and links to the duty roster and news.
- Dates shown to users are Buddhist Era in places (Document Tasks, Home) and Gregorian in exports and the print report; this inconsistency is open.

## Capabilities and Constraints

- Stack: Laravel 12, Filament 3, MySQL; Livewire interactions; Thai-language UI.
- Production is shared hosting serving `public_html`, deployed by `git pull`; there is no npm/Vite build on the host, so the Filament theme is plain CSS inlined in the panel head. Tailwind 4 is in the repo but Filament 3 themes need Tailwind 3, so styling stays in `resources/css/filament-theme.css`. Confirmed by the product owner in earlier sessions.
- Local development runs in Docker (app, MySQL, nginx); tests run inside the container.
- PDF report is produced through the browser print dialog by design; no PDF library is installed on the server.
- Super-admin-only: follow page, force acknowledge, print/export of acknowledgements.
- Open: whether to show Buddhist or Gregorian years consistently; per-user email address is optional (nullable) in the data model.

## Brand Commitments

None confirmed by the product owner for this init (answered "no preference"). Existing assets in the repo (U-Tapao ATC logo at `assets/images/u-tapao-circle.png`, the brand name "U-Tapao ATC", Thai body font) are incumbent but not recorded as binding.

## Evidence on Hand

- Real document data exists only on the production server; the local database holds placeholder categories and no documents.
- The ANS certificate images are in `public/assets/home/`.
- No testimonials, case studies, or usage figures are available; do not fabricate any.

## Product Principles

1. Proof over convenience: acknowledgements are dated, per person, and exportable as evidence.
2. Read before acknowledging: never offer one-click bulk acknowledgement.
3. Show each person only what concerns their section; admin functions stay with admins, follow and force stay with super-admin.
4. Must run on the existing shared host: no build step, no server-side tooling beyond `git pull` and Laravel artisan.
5. Thai first: labels, statuses, and dates read naturally in Thai while document codes and technical terms stay in English.

## Accessibility & Inclusion

No product-specific standard established. Light and dark themes are supported by the current theme; readability in low light (control room use) is a plausible need but was not confirmed.
