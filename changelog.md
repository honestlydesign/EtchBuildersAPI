# Changelog

## Writing Mode

Use this changelog as an end-user document, not a developer log.

- Write in a clear, calm, user-first tone.
- Explain the outcome and benefit before the implementation detail.
- Prefer simple words over internal technical terms.
- Keep each bullet focused on one visible improvement or fix.
- Use semantic sections such as `FEATURE`, `FIX`, and `ENHANCEMENT`.
- Avoid commit hashes, class names, file paths, PR numbers, and internal architecture terms in the actual release notes.
- If a change is mostly internal, describe the user-facing result instead of the code change.
- If a fix improves stability, performance, or reliability, say that directly.
- Only mention breaking changes when they are real and intentional.
- Keep maintainer-facing work in the `INTERNAL DEV CHANGELOG` section of each release.

## 2.0.3

### FIX

- Site applies now adopt matching Builder-authored handoff styles instead of conflicting with the site's own pre-ledger copies, so style handoffs no longer produce ownership conflicts.

## 2.0.2

### ENHANCEMENT

- Stale Builder-owned native loop presets retire automatically during site applies, removing the manual database cleanup previously required when migrating a project to native loop contracts.

## 2.0.1

### ENHANCEMENT

- Site applies are serialized behind a site-wide lock, and claims left behind by crashed applies are recovered automatically, so concurrent or interrupted runs can no longer wedge apply state.
- Component composition now exports source facts, giving authoring tools grounded knowledge of how a composition was built.

### FIX

- Post type authoring and the full authoring lane are enforced as Composer-only, and Composer-only recipes execute correctly again.
- Native Etch loop dependencies are verified as part of applies.

### INTERNAL DEV CHANGELOG

- Honest evidence handling in Contract Lab outputs: expression literals, probe degradation, and evidence map joins.

## 2.0.0

2.0.0 is a breaking major version and a ground-up rewrite of the authoring surface. Composition written against 1.x must move to the typed 2.0 API; class styles carry stable migration diagnostics.

### FEATURE

- **Typed, fluent site composition.** Whole Etch sites are described as typed Site Definitions — typed registries, typed pattern use, and typed content sequences — serialized through fluent builders with predictable output.
- **Component contracts.** Components compose against schema-backed contracts: values, slots, class property paths, and expressions are checked against the contract before serialization, backed by an executable property matrix validated against source types and a contract catalog with explicit lookup.
- **A real class and style system.** Exact class style references, ordered class style sets, and recursive typed class values with validated defaults. External class style ownership is preserved, and class-style migrations produce stable diagnostics.
- **Guardrails against lazy HTML.** Flat BEM roots are enforced, nesting is owner-local for native blocks, global stylesheet fragments are constrained, block sequences are typed, raw fragments must be explicitly checked, and JavaScript authoring is kept deliberately narrow.
- **Compilation and persistence.** Sites compile into immutable plans covering identities, dependencies, and content metadata. Compiled persistence is centralized for sites, components, patterns, content, and assets; ownership is recorded instead of cleaned up by prefix; applies are idempotent with reporting.
- **Executable authoring knowledge.** Curated capability declarations, source-derived contract facts, and a validated capability evidence map; executable core authoring recipes with negative fixtures and composite reference-site recipes; authoring query commands and readable generated reference material.
- **Contract Lab.** A verification environment that probes block round-trips, persistence, component properties, composite frontend rendering, browser preservation, and JavaScript runtime markers; compares candidates through semantic diffs; keeps immutable content-addressed snapshots; and tracks release compatibility in a compatibility ledger.

### INTERNAL DEV CHANGELOG

- Migrated the legacy rendering harness into Contract Lab; added a current-Etch maintainer gate, deterministic fixture lifecycle, doctor with locking, and safe binding verification.
- Derived Builder release compatibility metadata from the compatibility ledger with explicit acceptance and classification.

## 1.1.8 and earlier

These releases predate this changelog.
