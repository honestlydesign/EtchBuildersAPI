# StylesParser Selector Identity Design

## Goal

Make `StylesParser` authoring selector-first: CSS files should contain normal top-level selector blocks without required style ID comments, while existing production styles remain stable by resolving persisted IDs from selectors before creating new IDs.

## Context

Etch persists block styles in the `etch_styles` option as an ID-keyed map. The ID is the storage pointer referenced by block `attrs.styles[]`; the rendered CSS comes from each entry's `selector`, `css`, and `type`. Because production blocks can already reference existing IDs, the parser must never recreate a style under a different ID when the same selector already exists.

The current parser grammar requires this shape:

```css
/* omide-card */
.omide-card {
	display: grid;
}
```

That requirement adds no useful product value and misleads agents into spending effort on comment IDs. The new authoring shape should be:

```css
.omide-card {
	display: grid;
}
```

Existing comments may remain in CSS as comments, but they are not part of the parser contract.

## Recommended Approach

Use selector identity with create/update semantics.

1. Parse top-level selector blocks.
2. Normalize the selector into a lookup key.
3. Resolve an existing persisted style ID by normalized selector.
4. If exactly one persisted match exists, use that ID.
5. If no persisted match exists, create a deterministic new ID from the selector.
6. If multiple persisted styles match the same selector, fail with an actionable error instead of choosing one.

This makes the ID an implementation detail while keeping production references stable.

## Parser Behavior

`StylesParser` should support plain selector blocks:

```css
.omide-card {
	display: grid;

	@media (--tablet) {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}
```

The parser should ignore comments outside and between selector blocks. A legacy `/* old-id */` comment before a rule must not become the style ID and must not be included in the selector.

Single class selectors keep natural IDs when no persisted selector match exists:

```css
.omide-card { display: grid; }
```

creates `omide-card`. This preserves `ElementBlock::class( 'omide-card' )`, class props, and standalone class style expectations.

Non-single-class selectors get deterministic generated IDs when no persisted selector match exists. The generated ID can be selector-derived and hashed, but it must be stable across runs for the same normalized selector.

Duplicate selectors inside one CSS file should fail validation. One selector maps to one Etch style body.

## Selector Matching

Selector lookup must compare normalized selector keys, not raw text. Normalization should handle harmless whitespace differences such as:

```css
.foo > .bar
.foo>.bar
```

These should resolve to the same selector key. Normalization must be conservative around quoted strings, attribute selectors, and function arguments so valid selectors are not rewritten into different selectors.

Selector lists such as `.a, .b` are allowed only in fixed mode and should be treated as a single custom selector key. They remain invalid in class-prop mode.

## Validation Rules

`StylesValidator` should validate selector blocks without requiring ID comments.

Class-prop mode (`default-styling.css`) still requires each parsed block to use exactly one root class selector like `.thing`.

Fixed mode accepts standard non-at-rule selectors.

Root-level at-rules are not parsed styles. The validator must reject root-level `@media`, `@container`, `@keyframes`, `@property`, `@font-face`, `@import`, `@supports`, `@layer`, and similar constructs.

Nested responsive rules are allowed inside a selector:

```css
.foo {
	color: red;

	@media (min-width: 768px) {
		color: green;
	}

	@container (min-width: 40rem) {
		display: grid;
	}
}
```

True global CSS belongs in `Stylesheet` / `->stylesheet()`, not in parsed styles:

```php
$pattern->stylesheet( 'omide-site-global', __DIR__ . '/site-global.css' );
```

Nested `@keyframes`, `@property`, `@font-face`, `@import`, `@supports`, `@layer`, and `@charset` inside parsed blocks remain invalid because Etch renders parsed styles by wrapping CSS inside `selector { css }`.

## Static Error Messages

PHPStan errors surfaced by `bun run check` must be written for agents, not only maintainers.

For root-level media/container errors, the message should explain the correction with before/after guidance:

```css
/* Wrong */
.foo { color: red; }
@media (min-width: 768px) {
	.foo { color: green; }
}

/* Correct */
.foo {
	color: red;

	@media (min-width: 768px) {
		color: green;
	}
}
```

For global-only at-rules, the message should say that `@keyframes`, `@property`, `@font-face`, and similar root/global CSS cannot be represented as parsed styles and should be registered through `Stylesheet` or the builder `->stylesheet( $id, $file_path )` API.

For duplicate selector matches in persisted styles, the message should list the selector and matching IDs, and say that the ambiguity must be resolved before parser registration can safely update by selector.

For duplicate selectors in one CSS file, the message should list the normalized selector and say to merge the declarations into one block.

## Registration Behavior

`Style::register_all()` already removes existing DB entries that conflict by selector when a new registry style is registered. That behavior remains useful, but it is not sufficient by itself. The parser must resolve the existing ID before registration so block references do not change.

The create/update rule is:

- Existing selector match: update the same ID.
- No selector match: create a deterministic ID.
- Multiple selector matches: fail.

This rule applies before any overwrite/readonly behavior.

## Consumer Updates

After the package behavior is implemented, OhMyIDEtch must update its generated skill and guard surfaces:

- `build-skills/**` references should stop teaching required `/* style-id */` comments.
- `StylesParserCssStructureRule` tests should cover comment-free CSS and descriptive root at-rule errors.
- Site examples can keep old comments temporarily, but new examples should use plain selector blocks.
- `bun run skills:check` and `bun run check` should verify generated instructions and static checks.

## Testing Plan

Package unit tests should prove:

- Plain selector blocks parse into `Style` objects.
- Legacy comments are ignored.
- Single class selectors create natural IDs.
- Compound selectors create stable generated IDs when there is no persisted match.
- Persisted selector matches reuse the existing ID.
- Multiple persisted matches fail with a clear error.
- Duplicate selectors in one CSS file fail.
- Root-level `@media` and `@container` errors include nested-rule guidance.
- Root-level global at-rules include `->stylesheet()` guidance.
- Nested `@media` and `@container` inside selector blocks remain valid.

Rendering tests should prove a parsed style can still render through the real Etch runtime after selector-first parsing and that a persisted custom ID is reused when the selector matches.

OhMyIDEtch tests should prove PHPStan reports agent-readable errors and the generated skill text no longer requires style ID comments.

## Open Constraints

The parser should stay dependency-free unless the existing Composer package already has a suitable CSS parser dependency. A small brace-aware parser is acceptable, but it must handle nested braces and strings at least as safely as the current parser.

Do not add Vite, TypeScript, watchers, sync systems, or admin UI behavior as part of this work.
