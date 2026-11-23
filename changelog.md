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

## 2.1.0

### FEATURE

- Pages, posts, and templates can now be created once and then handed over to editors. Mark the content with `hand_over_after_create()`: the first site apply creates it, and later applies leave it alone. Edits made in WordPress are kept, later code changes to that content are not applied, and the apply reports it as unchanged. Its class styles and stylesheets stay managed by code. Remove the call to make code own the content again; the next apply then writes the code version.
- Existing WordPress content can now be brought under code ownership. Add `adopt_existing()` to a page, post, or template whose slug or ID matches a page someone created in wp-admin. The next apply takes over that page instead of stopping with a conflict. It keeps the post ID and URL and replaces the content with the code version; WordPress keeps the old content as a revision. Combined with `hand_over_after_create()`, the page keeps its current content and only changes owner. Adoption refuses when several records match or when the only match is a translation.
- Site applies now tell you when Builder-owned content is no longer in the Site Definition. A page you removed from code stays online as before, but the apply report lists it with what you can do next. A new explicit command moves those pages and posts to draft. Drafts keep their URL, revisions, and settings, and WordPress never empties drafts automatically. When the page returns to code, the next apply restores its previous status. The front page, the posts page, handed-over content, components, patterns, and templates are only reported, never moved to draft.

### FIX

- Class styles created through the Etch editor no longer break code-owned styles. When someone pastes, moves, or edits an element in Etch, Etch can create an empty copy of a code-owned class style in its own style list. That copy used to make later site applies fail with "Multiple existing Etch styles use selector", and the element lost its styling on the website. Applies now keep the code-owned style. Empty and identical copies follow the code-owned CSS, so linked elements render correctly again. Settings that the Etch editor drops when it saves are restored. A copy that someone filled with different CSS is never overwritten; the apply reports it and tells you how to resolve it.
- Applies of part of a site, for example a component-only first stage, no longer delete the styles and website stylesheets of the pages, templates, and site settings outside that stage. A later full apply now reports those styles as unchanged instead of creating them again.
- When you remove a page from code, its styles now stay until the page itself is trashed or deleted, so a page that is still online keeps its look.
- Sites with WPML translations no longer fail with "Multiple native Site entities claim this identity". Translations that copied the Builder's ownership details are recognised and set aside, and applies never write to a translation. Sites without WPML are unaffected.

### ENHANCEMENT

- The error for two code-owned styles with the same selector now lists each style with its collection and explains how to keep one.
- A new read-only check reports what needs a person's attention for a site without changing anything: style copies made in the editor, and content that was removed from code.

### MIGRATION NOTES

- `overwrite()` has no effect on compiled site applies. Those applies always write code-owned content, which matches 2.0.x behaviour. Use `hand_over_after_create()` for content that editors should own.
- Pages registered through the older direct registration path, before compiled site applies, carry no ownership record. A compiled apply therefore treats them like wp-admin pages. Use `adopt_existing()` to bring them under code. Setting the ownership post meta by hand also still works.
- Staged site registration (a component bootstrap before the full site) should mark every stage except the last as partial, with `$plan->as_partial()`. Otherwise the bootstrap stage removes the styles of every page and template on each run.
- Multilingual sites: the plugin that ships your site should tell WPML not to copy the `etch_builders_site_persistence_*` post meta. This package now handles copied meta safely either way.
- 2.0.x migration reports re-checked for this release:
  - The 2.0.4 package archive installs and autoloads normally.
  - Nested loops without a loop preset have had a regression test since 2.0.4.
  - The remaining reports (registration report methods, capability admission, missing project files) belong to the devkit.

### INTERNAL DEV CHANGELOG

- New public API (all additive; `SitePersistenceInterface` and `SitePersistenceOutcome` are unchanged):
  - `AbstractContentBuilder::hand_over_after_create( bool = true )` / `hands_over_after_create()`: compile the entity with the new `CompiledSiteEntityPersistenceIntent::CREATE_ONLY` intent (pages, posts, templates only).
  - `AbstractContentBuilder::adopt_existing( bool = true )` / `adopts_existing()`: compile the entity with `adopt_existing`. `CompiledSiteEntity::new()` takes a fifth `bool $adopt_existing = false` argument, exposes `adopts_existing()`, and adds `adopt_existing: true` to `to_array()` only when it is set.
  - `CompiledSitePlan::as_partial()` / `is_partial()`: a partial plan skips orphan resource cleanup and orphan reporting. `to_array()` adds `partial: true` only when the plan is partial.
  - `SitePersistence::diagnose( plan )`: read-only notices for editor style duplicates and, for complete plans, orphans.
  - `SitePersistence::orphans( plan )`: read-only orphan notices. A partial plan returns the blocking diagnostic `ETCH_SITE_PERSISTENCE_ORPHANS_PARTIAL_PLAN`.
  - `SitePersistence::retire_orphans( plan )`: runs under the apply lock and moves retirable orphans to draft. Each result has code `ETCH_SITE_PERSISTENCE_ORPHAN_RETIRED`.
  - `SitePersistenceReport::notices()` / `with()`: a third constructor field. `to_array()` adds `notices` only when there are any. Notices never affect `is_success()`.
  - Store capability interfaces, implemented by `WordPressSitePersistenceStore`:
    - `SitePersistenceEditorStyleDuplicateInterface`
    - `SitePersistenceHandOverInterface`
    - `SitePersistenceNativeClaimInterface`
    - `SitePersistenceNativeOrphanInterface`
  - Filter `etch_builders_site_persistence_is_translation( bool $is_translation, WP_Post $post )` for multilingual plugins other than WPML.
- New result codes:
  - `ETCH_SITE_PERSISTENCE_HANDED_OVER` (unchanged)
  - `ETCH_SITE_PERSISTENCE_ADOPTED` (updated)
  - `ETCH_SITE_PERSISTENCE_RESTORED` (updated)
  - `ETCH_SITE_PERSISTENCE_ADOPTION_AMBIGUOUS`, `ETCH_SITE_PERSISTENCE_ADOPTION_TRANSLATION`, `ETCH_SITE_PERSISTENCE_ADOPTION_UNSUPPORTED` (all conflict)
  - `ETCH_SITE_PERSISTENCE_STYLE_EDITOR_DUPLICATE_ADOPTED` (updated)
- New notice codes:
  - `ETCH_SITE_PERSISTENCE_ORPHAN` (warning)
  - `ETCH_SITE_PERSISTENCE_ORPHAN_KEPT` (info)
  - `ETCH_SITE_PERSISTENCE_ORPHAN_RETIRED` (info)
  - `ETCH_SITE_PERSISTENCE_STYLE_EDITOR_DUPLICATE_DIVERGENT` (warning)
  - `ETCH_SITE_PERSISTENCE_STYLE_EDITOR_DUPLICATE_ADOPTABLE` (info, from `diagnose()` only)
- New native state:
  - Option `etch_builders_site_persistence_style_aliases`: editor duplicate ID mapped to `{canonical, hash}`.
  - Post meta `etch_builders_site_persistence_handed_over`.
  - Post meta `etch_builders_site_persistence_retired_status`: the status to restore.
- Style resolution: `Style::resolve_id_for_selector()` ignores empty `default`-collection placeholders. It also ignores non-empty unrecorded `default` duplicates when a recorded owner exists (ledger, entity collection, or alias canonical), and maps aliases to their canonical ID.
- Orphan resource cleanup keeps resources whose owner is a live (not trashed) Builder-owned post outside the plan. If the owned post list cannot be read, it keeps every resource.
- Translation detection, used for native lookups, adoption, and orphan listing, checks in this order:
  1. The `_icl_lang_duplicate_of` meta.
  2. `source_language_code` from WPML's `wpml_element_language_details` filter.
  3. The package filter.

  A slug lookup that matches only translations maps to the original through `wpml_object_id`, or fails closed as a conflict. ID-targeted identities are not filtered. This was tested against stubbed filters only, not a real WPML install.
- Devkit follow-ups (not in this package):
  - Call `->as_partial()` on bootstrap stage plans.
  - Remove the pre-register style wipe in `scripts/localwp/sync.mjs`. It makes every style report as created.
  - Print `notices()` from apply reports, and call `diagnose()` during verify.
  - Treat `CREATE_ONLY` entities as owned but not content-compared in `SiteRuntimeVerification`.
  - Tie the PROD registration lock to the plugin version or plan digest, and back off after a failure.
  - Ship a `wpml-config.xml` that tells WPML not to copy the Builder meta.

## 2.0.6

### FIX

- Site applies now adopt an existing website stylesheet when it exactly matches the CSS the project compiles to, instead of failing with an ownership conflict. This heals projects whose global stylesheet was created by an earlier Builder version, before stylesheet ownership was recorded: syncing used to fail on every run until someone deleted the stylesheet by hand, and now the first sync claims it automatically and later syncs report no changes.
- When an existing website stylesheet really does differ from the compiled CSS, the apply still refuses to touch it, and the error now names the stylesheet and explains how to resolve it — remove the stylesheet so the next sync recreates it under code ownership, or keep a copy when a person wrote it — instead of leaving a dead end.

### INTERNAL DEV CHANGELOG

- Added `Contracts\SitePersistenceStylesheetAdoptionInterface` (`adopt_unowned_stylesheet_records`) and implemented it in `WordPressSitePersistenceStore`. `SitePersistence::apply()` runs the batch adoption inside the site apply lock before the per-record writes, because one native stylesheet aggregates fragments from several plan records and can only be proven Builder-authored against the complete planned aggregate. Adoption is fail-closed: the native entry must match the planned aggregate byte for byte, so claiming ownership changes no rendered output.
- The unowned-native-stylesheet conflict in `persist_asset` now names the stylesheet id and the remediation instead of a bare conflict string.

## 2.0.5

### FEATURE

- Agent tools can now query exact, versioned interface facts for pages, posts, templates, patterns, whole-site definitions, entity style sets, and website-level stylesheets. The builders for those lanes now publish the same source-contract annotation as the composition lanes, and a package-owned entity content contract exports the curated source selections for hosts that validate their own runtime contracts. Hosts that have not admitted those lanes keep seeing them as pending, exactly as before.

### INTERNAL DEV CHANGELOG

- Added `@authoring-contract-version 1.0` to the public entry points of `Page`, `Post`, `Template`, `Pattern`, `EntityStyleSet`, `Stylesheet`, and the shared `AbstractContentBuilder` content lifecycle.
- Added `CoreEntityContentAuthoringContract` (pending capability declarations, exact source symbols, linked recipe evidence, and a generated catalog) mirroring the component composition contract, plus `CoreEntityContentAuthoringContractTest`.

## 2.0.4

### FIX

- Nested loops that read their items from the surrounding loop context (for example a dropdown looping `item.children` inside a menu loop) are accepted in serialized markup again. The compiler previously rejected every serialized loop without an explicit loop preset id; it now mirrors Etch's runtime rule exactly: a loop preset id must still be registered in the Site Definition, and a loop without one must declare a non-empty target expression instead of silently rendering empty.

### FEATURE

- Slot filling in serialized markup is now checked against the exact component contract. Etch only extracts slot content that sits directly inside a component instance, keeps the first fill per slot name, and matches names exactly against the component's declared slots — so the compiler now reports unknown slot names, missing names, duplicate fills, and misplaced slot content before apply, instead of letting the intent silently drop at render time. The same placement rule is enforced for typed sequences.
- Untyped and typed loops now follow one shared rule set: a loop that could never render (no preset id and no target) is reported for both authoring styles.

### ENHANCEMENT

- The test parser used in WordPress-free environments now builds the same nested block tree WordPress returns, so nested component markup is validated identically inside and outside WordPress.

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
