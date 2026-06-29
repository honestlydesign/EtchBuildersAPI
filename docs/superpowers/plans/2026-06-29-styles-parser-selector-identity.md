# StylesParser Selector Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Update `honestlydesign/etch-builders` so `StylesParser` accepts normal CSS selector blocks without required `/* style-id */` comments, preserves production style IDs by resolving existing styles by selector before generating new IDs, keeps PHPStan/static guardrails for root at-rules, and updates the rendering test repo plus OhMyIDEtch consumer guidance/tests.

**Architecture:** Introduce one shared CSS rule scanner used by both `StylesParser` and `StylesValidator`. Move identity resolution to selector-first logic in `Style`: normalize selector, check in-memory registry and persisted `etch_styles`, reuse exactly one existing ID, fail on ambiguous matches, otherwise use the single-class selector token or a deterministic generated `omide-` ID. `StylesParser` becomes selector-driven; `StylesValidator` becomes grammar/guardrail-driven and no longer requires comment IDs.

**Tech Stack:** PHP 8.1+, Composer package `honestlydesign/etch-builders`, PHPUnit, PHPStan, WordPress integration tests in `etch-builders-rendering-tests`, OhMyIDEtch Bun scripts (`bun run skills:check`, `bun run check`).

**Spec:** `docs/superpowers/specs/2026-06-29-styles-parser-selector-identity-design.md`

---

## Context Map

- Package repo: `/Users/woji/Dev/Packages/Composer/EtchBuildersAPI`
- Rendering tests repo: `/Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests`
- Consumer repo: `/Users/woji/Dev/OhMyIDEtch`

Key package files:

- `src/StylesParser.php` currently matches only `/* id */ selector { css }` blocks.
- `src/StylesValidator.php` currently uses the same comment-ID grammar and has stale root at-rule messages.
- `src/Style.php` registers styles by ID and cleans selector conflicts during `register_all()`, which is too late for production safety.
- `src/ClassStyleRegistry.php` has class-focused selector maps and validates `ElementBlock::class()`/style linkage; `.foo` must still naturally resolve to style ID `foo`.
- `tests/Unit/StylesParserTest.php` and `tests/Unit/StylesValidatorTest.php` are the focused package test surfaces.
- `tests/fixtures/test-stylesheet.css` currently contains comment IDs and should be converted to comment-free CSS.

Key consumer/test files:

- `/Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests/tests/Integration/StyleRenderingTest.php`
- `/Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests/tests/Integration/RenderingTestCase.php`
- `/Users/woji/Dev/OhMyIDEtch/tools/phpstan/Rules/StylesParserCssStructureRule.php`
- `/Users/woji/Dev/OhMyIDEtch/tests/Unit/Tools/PhpStan/StylesParserCssStructureRuleTest.php`
- `/Users/woji/Dev/OhMyIDEtch/tests/Unit/Builders/StylesValidatorTest.php`
- `/Users/woji/Dev/OhMyIDEtch/build-skills/skills/write-etch-css/SKILL.md`
- `/Users/woji/Dev/OhMyIDEtch/build-skills/skills/write-etch-css/references/styles-parser.md`
- `/Users/woji/Dev/OhMyIDEtch/build-skills/skills/use-etch-builders/references/css-apis.md`
- `/Users/woji/Dev/OhMyIDEtch/build-skills/skills/use-ome/SKILL.md`
- `/Users/woji/Dev/OhMyIDEtch/build-skills/profiles/localwp/skills/verify-etch-site/SKILL.md`

Runtime fact from `/Users/woji/Dev/temp/etch`: Etch stores styles in `etch_styles` keyed by ID, but renders from selector/css/type. Therefore ID is a storage pointer, and selector-first ID reuse is the production safety boundary.

---

## Safety Rules

- Never require agents to write `/* style-id */` comments in parsed CSS.
- Treat legacy comments as ordinary CSS comments. They may remain in files but must not be the parser identity source.
- Before generating any ID, resolve by selector against in-memory `Style::registered_styles()` and persisted `Environment::storage()->get( 'etch_styles' )`.
- If exactly one existing style matches the normalized selector, reuse its ID and update that style.
- If multiple existing styles match the normalized selector under different IDs, throw an explicit error listing the selector and IDs. Do not guess.
- If no existing style matches:
  - single class selector `.foo` uses ID `foo`;
  - every other selector uses a deterministic generated ID with a code-owned prefix, for example `omide-style-<hash>`.
- Generated IDs must be stable for the same normalized selector and must match `/^[A-Za-z0-9_-]+$/`.
- In-file duplicate selectors are invalid. A single CSS file declaring the same normalized selector twice should fail before registration.
- Root at-rules are not parsed styles: reject root `@media`, `@container`, `@keyframes`, `@property`, `@font-face`, `@import`, `@supports`, `@layer`, `@charset`, and similar at-rules.
- Nested responsive/container rules are valid inside selector blocks: `.foo { color: red; @media (...) { color: green; } @container (...) { ... } }`.
- True global CSS belongs in `Stylesheet`/`->stylesheet()`.
- Error text must be written for agents: include the incorrect root-at-rule shape and the corrected nested shape.

---

## Task 1: Add Failing Parser Tests for Comment-Free CSS

- [ ] Modify `/Users/woji/Dev/Packages/Composer/EtchBuildersAPI/tests/fixtures/test-stylesheet.css` so it contains comment-free selector blocks, including a nested media rule:

```css
.test-hero {
	color: red;
	@media (--tablet) {
		color: blue;
	}
}

.test-card:hover {
	color: green;
}
```

- [ ] Update `/Users/woji/Dev/Packages/Composer/EtchBuildersAPI/tests/Unit/StylesParserTest.php`.

Add or replace assertions so they prove:

```php
public function test_parser_uses_single_class_selector_as_style_id(): void {
	$parser = StylesParser::new( self::FIXTURE_CSS );

	self::assertContains( 'test-hero', $parser->get_style_ids() );
	self::assertSame( '.test-hero', $parser->get_from_id( 'test-hero' )?->to_array()['selector'] ?? null );
}

public function test_parser_generates_stable_id_for_compound_selector(): void {
	$first  = StylesParser::new( self::FIXTURE_CSS )->get_style_ids();
	$second = StylesParser::new( self::FIXTURE_CSS )->get_style_ids();

	$compound_ids = array_values(
		array_filter(
			$first,
			static fn ( string $id ): bool => str_starts_with( $id, 'omide-style-' )
		)
	);

	self::assertCount( 1, $compound_ids );
	self::assertSame( $first, $second );
}
```

- [ ] Add a focused test proving legacy comments are ignored as IDs:

```php
public function test_parser_ignores_legacy_comment_ids(): void {
	$file = $this->write_temp_css(
		'/* old-custom-id */ .comment-free-card { color: red; }'
	);

	$parser = StylesParser::new( $file );

	self::assertSame( array( 'comment-free-card' ), $parser->get_style_ids() );
	self::assertNull( $parser->get_from_id( 'old-custom-id' ) );
}
```

- [ ] Add a private temp CSS helper in the test class:

```php
private function write_temp_css( string $content, string $basename = 'styles.css' ): string {
	$dir = sys_get_temp_dir() . '/etch-builders-styles-parser-' . bin2hex( random_bytes( 6 ) );
	self::assertTrue( mkdir( $dir, 0777, true ) );
	$file = $dir . '/' . $basename;
	self::assertNotFalse( file_put_contents( $file, $content ) );

	return $file;
}
```

- [ ] Add temp cleanup tracking if needed. Keep cleanup in `tearDown()` together with `Environment::reset()` and `ClassStyleRegistry::reset_cache()`.
- [ ] Run the focused tests and confirm they fail for the current reason:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
composer test -- --filter StylesParserTest
```

Expected initial failure: validator/parser still requires `/* style-id */` blocks or comment IDs are still used.

---

## Task 2: Implement Shared CSS Rule Scanner

- [ ] Add `/Users/woji/Dev/Packages/Composer/EtchBuildersAPI/src/StylesParserRuleScanner.php`.

Public API:

```php
<?php
declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

final class StylesParserRuleScanner {
	/**
	 * @return array<int, array{selector:string, css:string, start:int, end:int}>
	 */
	public static function scan_style_rules( string $content ): array;

	public static function normalize_selector_key( string $selector ): string;

	public static function single_class_token( string $selector ): ?string;

	public static function generated_style_id_for_selector( string $selector ): string;
}
```

- [ ] The scanner must:
  - skip whitespace and `/* ... */` comments at root;
  - read a top-level selector up to the next `{`;
  - extract matching brace content with nested braces;
  - preserve strings and escaped string characters while scanning;
  - ignore braces inside comments and strings;
  - return rules in file order;
  - not validate semantic selector policy. Validation stays in `StylesValidator`.

- [ ] Implement selector normalization conservatively:

```php
public static function normalize_selector_key( string $selector ): string {
	$selector = trim( $selector );
	$selector = preg_replace( '/\s+/', ' ', $selector ) ?? $selector;
	$selector = preg_replace( '/\s*([>+~,])\s*/', '$1', $selector ) ?? $selector;

	return $selector;
}
```

- [ ] Implement single class fallback:

```php
public static function single_class_token( string $selector ): ?string {
	$selector = self::normalize_selector_key( $selector );

	if ( 1 !== preg_match( '/^\.[A-Za-z][A-Za-z0-9_-]*$/', $selector ) ) {
		return null;
	}

	return substr( $selector, 1 );
}
```

- [ ] Implement generated IDs with the existing code-owned prefix:

```php
public static function generated_style_id_for_selector( string $selector ): string {
	$key  = self::normalize_selector_key( $selector );
	$hash = substr( sha1( $key ), 0, 12 );

	return 'omide-style-' . $hash;
}
```

- [ ] Refactor `StylesParser::parse_content()` to call `StylesParserRuleScanner::scan_style_rules( $content )`.
- [ ] Keep `StylesParser::normalize_css()` behavior unless a test proves it is unsafe.
- [ ] Run:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
composer test -- --filter StylesParserTest
```

Expected after Task 2: comment-free parser tests pass except selector-to-persisted-ID tests that do not exist yet.

---

## Task 3: Add Failing Selector-First ID Resolution Tests

- [ ] Extend `/Users/woji/Dev/Packages/Composer/EtchBuildersAPI/tests/Unit/StylesParserTest.php` with persisted selector reuse.

```php
public function test_parser_reuses_persisted_id_for_matching_selector(): void {
	Environment::storage()->set(
		'etch_styles',
		array(
			'legacy-custom-card' => array(
				'selector'   => '.legacy-card:hover',
				'css'        => 'color: black;',
				'type'       => 'custom',
				'collection' => 'OhMyIDEtch',
			),
		)
	);

	$file = $this->write_temp_css( '.legacy-card:hover { color: red; }' );

	$parser = StylesParser::new( $file );

	self::assertSame( array( 'legacy-custom-card' ), $parser->get_style_ids() );
	self::assertSame( '.legacy-card:hover', $parser->get_from_id( 'legacy-custom-card' )?->to_array()['selector'] ?? null );
}
```

- [ ] Add an in-memory registry precedence test:

```php
public function test_parser_reuses_in_memory_id_for_matching_selector(): void {
	Style::new()
		->id( 'memory-card-style' )
		->selector( '.memory-card:hover' )
		->css( 'color: black;' )
		->add();

	$file = $this->write_temp_css( '.memory-card:hover { color: red; }' );

	self::assertSame(
		array( 'memory-card-style' ),
		StylesParser::new( $file )->get_style_ids()
	);
}
```

- [ ] Add an ambiguous persisted selector guard:

```php
public function test_parser_fails_when_persisted_selector_matches_multiple_ids(): void {
	Environment::storage()->set(
		'etch_styles',
		array(
			'first-card-style' => array(
				'selector' => '.ambiguous-card',
				'css'      => 'color: red;',
				'type'     => 'class',
			),
			'second-card-style' => array(
				'selector' => '.ambiguous-card',
				'css'      => 'color: blue;',
				'type'     => 'class',
			),
		)
	);

	$this->expectException( \RuntimeException::class );
	$this->expectExceptionMessage( 'Multiple existing Etch styles use selector `.ambiguous-card`' );

	StylesParser::new( $this->write_temp_css( '.ambiguous-card { color: green; }' ) );
}
```

- [ ] Run and confirm these new tests fail:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
composer test -- --filter StylesParserTest
```

Expected initial failure: parser still generates/falls back without consulting persisted selector identity.

---

## Task 4: Implement Selector-First ID Resolution in Style

- [ ] Add public resolver methods to `/Users/woji/Dev/Packages/Composer/EtchBuildersAPI/src/Style.php` rather than coupling parser identity to `ClassStyleRegistry`.

Suggested API:

```php
/**
 * Resolve the stable Etch style ID for a selector before registering a parsed style.
 *
 * @throws \RuntimeException When persisted/current state contains multiple IDs for the selector.
 */
public static function resolve_id_for_selector( string $selector ): string;
```

- [ ] Implementation requirements:
  - use `StylesParserRuleScanner::normalize_selector_key( $selector )`;
  - scan `self::$registry` first;
  - scan `Environment::storage()->get( self::STYLES_OPTION_NAME, array() )` second;
  - collect all distinct IDs whose normalized selector key equals the requested selector key;
  - if exactly one ID exists, return it;
  - if more than one distinct ID exists, throw `RuntimeException`;
  - otherwise return `StylesParserRuleScanner::single_class_token( $selector )` or `StylesParserRuleScanner::generated_style_id_for_selector( $selector )`.

Skeleton:

```php
public static function resolve_id_for_selector( string $selector ): string {
	$selector_key = StylesParserRuleScanner::normalize_selector_key( $selector );
	$matches      = array();

	foreach ( self::$registry as $style_id => $style ) {
		if ( ! isset( $style['selector'] ) || ! is_string( $style['selector'] ) ) {
			continue;
		}

		if ( StylesParserRuleScanner::normalize_selector_key( $style['selector'] ) === $selector_key ) {
			$matches[ (string) $style_id ] = true;
		}
	}

	$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
	if ( is_array( $persisted ) ) {
		foreach ( $persisted as $style_id => $style ) {
			if ( ! is_array( $style ) || ! isset( $style['selector'] ) || ! is_string( $style['selector'] ) ) {
				continue;
			}

			if ( StylesParserRuleScanner::normalize_selector_key( $style['selector'] ) === $selector_key ) {
				$matches[ (string) $style_id ] = true;
			}
		}
	}

	$ids = array_keys( $matches );
	if ( 1 === count( $ids ) ) {
		return $ids[0];
	}

	if ( count( $ids ) > 1 ) {
		throw new \RuntimeException(
			sprintf(
				'Multiple existing Etch styles use selector `%s`: %s. Resolve duplicate persisted styles before parsing CSS.',
				$selector_key,
				implode( ', ', $ids )
			)
		);
	}

	return StylesParserRuleScanner::single_class_token( $selector_key )
		?? StylesParserRuleScanner::generated_style_id_for_selector( $selector_key );
}
```

- [ ] Update `StylesParser::parse_content()` to call:

```php
$id = Style::resolve_id_for_selector( $selector );
```

- [ ] Run:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
composer test -- --filter StylesParserTest
```

Expected after Task 4: all parser tests pass.

---

## Task 5: Add Failing Validator Tests for New Grammar and Agent Errors

- [ ] Update `/Users/woji/Dev/Packages/Composer/EtchBuildersAPI/tests/Unit/StylesValidatorTest.php`.

Replace comment-ID assumptions with selector block assumptions:

```php
public function test_valid_class_prop_style_block_returns_empty_errors(): void {
	$errors = StylesValidator::validate(
		'.title { color: red; }',
		StylesParserMode::CLASS_PROP
	);

	self::assertSame( array(), $errors );
}

public function test_valid_fixed_style_blocks_without_comment_ids_return_empty_errors(): void {
	$errors = StylesValidator::validate(
		'.card { color: red; } .card:hover { color: blue; }',
		StylesParserMode::FIXED
	);

	self::assertSame( array(), $errors );
}
```

- [ ] Add duplicate selector test:

```php
public function test_duplicate_root_selector_returns_error(): void {
	$errors = StylesValidator::validate(
		'.card { color: red; } .card { color: blue; }',
		StylesParserMode::FIXED
	);

	self::assertNotSame( array(), $errors );
	self::assertStringContainsString( 'Duplicate selector `.card`', implode( "\n", $errors ) );
}
```

- [ ] Add root `@media` and root `@container` agent guidance tests:

```php
public function test_root_level_media_returns_nested_correction_error(): void {
	$errors = StylesValidator::validate(
		'.card { display: grid; } @media (max-width: 48rem) { .card { grid-template-columns: 1fr; } }',
		StylesParserMode::FIXED
	);

	$output = implode( "\n", $errors );

	self::assertStringContainsString( 'StylesParser cannot parse root-level @media', $output );
	self::assertStringContainsString( 'Wrong: .foo { color: red; } @media', $output );
	self::assertStringContainsString( 'Right: .foo { color: red; @media', $output );
}

public function test_root_level_container_returns_nested_correction_error(): void {
	$errors = StylesValidator::validate(
		'.card { display: grid; } @container (min-width: 40rem) { .card { gap: 2rem; } }',
		StylesParserMode::FIXED
	);

	$output = implode( "\n", $errors );

	self::assertStringContainsString( 'StylesParser cannot parse root-level @container', $output );
	self::assertStringContainsString( 'nest it inside the selector block', $output );
}
```

- [ ] Add root global guidance test:

```php
public function test_root_level_global_at_rule_returns_stylesheet_guidance(): void {
	$errors = StylesValidator::validate(
		'.card { color: red; } @keyframes fade { from { opacity: 0; } to { opacity: 1; } }',
		StylesParserMode::FIXED
	);

	$output = implode( "\n", $errors );

	self::assertStringContainsString( 'StylesParser cannot parse root-level @keyframes', $output );
	self::assertStringContainsString( 'Use Stylesheet or ->stylesheet()', $output );
}
```

- [ ] Add nested container pass test:

```php
public function test_nested_container_inside_style_block_returns_empty_errors(): void {
	$errors = StylesValidator::validate(
		'.card { display: grid; @container (min-width: 40rem) { gap: 2rem; } }',
		StylesParserMode::FIXED
	);

	self::assertSame( array(), $errors );
}
```

- [ ] Run and confirm failures:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
composer test -- --filter StylesValidatorTest
```

Expected initial failure: validator still requires comment-ID blocks and stale root at-rule messages.

---

## Task 6: Implement Validator on the Shared Scanner

- [ ] Refactor `/Users/woji/Dev/Packages/Composer/EtchBuildersAPI/src/StylesValidator.php` to use `StylesParserRuleScanner::scan_style_rules()`.
- [ ] Remove `STYLE_BLOCK_PATTERN` and any error text saying `/* style-id */` is required.
- [ ] Keep `StylesParserMode::FLEXIBLE` unchanged.
- [ ] Keep comment-only CSS as valid.
- [ ] Validate each scanned selector:
  - empty selector: error;
  - `CLASS_PROP`: selector must match single class selector;
  - `FIXED`: selector must not start with `@`;
  - duplicate normalized selector: error;
  - nested forbidden global at-rules: still reject `@keyframes`, `@property`, `@import`, `@supports`, `@layer`, `@charset`, `@font-face`.
- [ ] Allow nested `@media` and nested `@container`.
- [ ] Detect root-level content that the scanner cannot parse as selector style blocks. Root at-rule messages should be explicit.

Suggested helper shape:

```php
private static function format_root_at_rule_error( string $at_rule ): string {
	if ( in_array( strtolower( $at_rule ), array( '@media', '@container' ), true ) ) {
		return sprintf(
			'StylesParser cannot parse root-level %1$s. Nest responsive/container rules inside the selector block instead. Wrong: .foo { color: red; } %1$s (...) { .foo { color: green; } } Right: .foo { color: red; %1$s (...) { color: green; } }',
			$at_rule
		);
	}

	return sprintf(
		'StylesParser cannot parse root-level %s. Use Stylesheet or ->stylesheet() for true global CSS such as @keyframes, @property, @font-face, @import, @supports, @layer, and @charset.',
		$at_rule
	);
}
```

- [ ] Use clear block messages, for example:

```text
Style block 2 (`.card:hover`): ...
```

- [ ] Replace trailing/root errors with selector grammar wording:

```text
CSS contains root-level rules outside parsed selector blocks. StylesParser accepts `selector { css }` blocks only.
```

- [ ] Run:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
composer test -- --filter StylesValidatorTest
composer test -- --filter StylesParserTest
```

Expected after Task 6: parser and validator focused tests pass.

---

## Task 7: Package Full Test and Static Verification

- [ ] Run package PHPUnit:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
composer test
```

Expected: all PHPUnit tests pass.

- [ ] Run package PHPStan:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
composer phpstan
```

Expected: PHPStan passes with no new errors.

- [ ] Run a quick stale wording search:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
rg -n 'style-id|style id|/\\* id|/\\* style|comment-style|CSS structure must be' src tests README.md docs
```

Expected: no remaining required-comment parser guidance. Non-parser references to "style ID" are acceptable.

- [ ] Commit the package implementation:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
git status --short
git add src tests docs README.md
git diff --cached --check
git commit -m "fix: resolve parsed styles by selector"
```

Expected: one focused package commit.

---

## Task 8: Add Rendering Test Coverage Against Runtime Etch

- [ ] In `/Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests`, inspect current dependency state:

```bash
cd /Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests
composer show honestlydesign/etch-builders
git status --short --branch
```

- [ ] For local verification before package release, use a temporary path repository pointing to the package checkout. Do not commit this path repo unless the maintainer explicitly wants the rendering repo pinned to local source.

```bash
cd /Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests
composer config repositories.local-etch-builders path ../EtchBuildersAPI
composer update honestlydesign/etch-builders --with-dependencies
```

- [ ] Add a rendering integration test to `/Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests/tests/Integration/StyleRenderingTest.php` or a new `StylesParserRenderingTest.php`.

The test should create a temporary CSS file, parse it with `StylesParser`, register parsed styles, render an element, and prove the CSS emits:

```php
public function test_styles_parser_comment_free_css_emits_runtime_style(): void {
	$file = $this->write_temp_css(
		'.parser-runtime-card { color: rgb(255, 0, 0); @media (min-width: 768px) { color: rgb(0, 0, 255); } }'
	);

	foreach ( \HonestlyDesign\EtchBuilders\StylesParser::new( $file )->get_all() as $style ) {
		$style->collection( 'OhMyIDEtch' )->readonly( true )->add();
	}

	\HonestlyDesign\EtchBuilders\Style::register_all();

	$result = $this->render_element_with_class( 'parser-runtime-card' );

	$this->assertStyleEmitted( $result, '.parser-runtime-card', 'color: rgb(255, 0, 0)' );
	$this->assertStyleEmitted( $result, '@media' );
}
```

- [ ] Add a persisted selector reuse rendering test:

```php
public function test_styles_parser_reuses_persisted_id_for_selector_before_runtime_registration(): void {
	update_option(
		'etch_styles',
		array(
			'legacy-runtime-style' => array(
				'selector'   => '.parser-runtime-legacy',
				'css'        => 'color: rgb(0, 0, 0);',
				'type'       => 'class',
				'collection' => 'OhMyIDEtch',
			),
		)
	);

	$file   = $this->write_temp_css( '.parser-runtime-legacy { color: rgb(255, 0, 0); }' );
	$parser = \HonestlyDesign\EtchBuilders\StylesParser::new( $file );

	self::assertSame( array( 'legacy-runtime-style' ), $parser->get_style_ids() );

	foreach ( $parser->get_all() as $style ) {
		$style->collection( 'OhMyIDEtch' )->readonly( true )->add();
	}

	\HonestlyDesign\EtchBuilders\Style::register_all();

	$persisted = get_option( 'etch_styles', array() );
	self::assertArrayHasKey( 'legacy-runtime-style', $persisted );
	self::assertArrayNotHasKey( 'parser-runtime-legacy', $persisted );
	self::assertSame( 'color: rgb(255, 0, 0)', $persisted['legacy-runtime-style']['css'] );
}
```

- [ ] Add a temp CSS helper in the rendering test class or `RenderingTestCase`.
- [ ] Run the focused integration tests:

```bash
cd /Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests
composer test -- --filter "StyleRenderingTest|StylesParserRenderingTest"
```

Expected: tests pass against the local path package.

- [ ] If this repo should remain release-version based, remove only the temporary Composer path repository changes after local proof. First inspect the diff and confirm `composer.json`/`composer.lock` contain no unrelated work:

```bash
cd /Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests
git diff -- composer.json composer.lock
composer config --unset repositories.local-etch-builders
composer update honestlydesign/etch-builders --with-dependencies
```

Do this only if `composer.json`/`composer.lock` were changed solely for local path verification. Do not remove or revert test files. If the package has not been released yet and Composer cannot resolve the new version without the path repository, leave dependency files unstaged and document that final consumer lock updates are blocked on package release.

---

## Task 9: Update OhMyIDEtch Consumer Tests and Skill Guidance

- [ ] In `/Users/woji/Dev/OhMyIDEtch`, update the package dependency after the package implementation is available locally. For local verification before a package tag, use a temporary path repository and do not commit that path config:

```bash
cd /Users/woji/Dev/OhMyIDEtch
composer config repositories.local-etch-builders path ../Packages/Composer/EtchBuildersAPI
composer update honestlydesign/etch-builders --with-dependencies
```

- [ ] Update `/Users/woji/Dev/OhMyIDEtch/tests/Unit/Builders/StylesValidatorTest.php` to mirror the package validator tests from Task 5.
- [ ] Update `/Users/woji/Dev/OhMyIDEtch/tests/Unit/Tools/PhpStan/StylesParserCssStructureRuleTest.php`.

Keep the class-prop invalid selector test, but remove required comment-ID assumptions:

```css
.headline, .headline-alt {
	color: red;
}
```

Add a second PHPStan test for root media guidance:

```css
.card {
	color: red;
}

@media (max-width: 48rem) {
	.card {
		color: blue;
	}
}
```

Expected output assertions:

```php
self::assertStringContainsString( 'Invalid StylesParser CSS structure', $result['output'] );
self::assertStringContainsString( 'StylesParser cannot parse root-level @media', $result['output'] );
self::assertStringContainsString( 'Wrong: .foo { color: red; } @media', $result['output'] );
self::assertStringContainsString( 'Right: .foo { color: red; @media', $result['output'] );
```

- [ ] Update build skill docs so agents learn the new CSS shape:
  - `build-skills/skills/write-etch-css/SKILL.md`
  - `build-skills/skills/write-etch-css/references/styles-parser.md`
  - `build-skills/skills/use-etch-builders/references/css-apis.md`
  - `build-skills/skills/use-ome/SKILL.md`
  - `build-skills/profiles/localwp/skills/verify-etch-site/SKILL.md`

Required wording:

```md
Use `StylesParser` for normal entity presentation CSS. Write normal root selector blocks such as `.foo { ... }`; do not add `/* style-id */` comments. The parser resolves existing styles by selector and otherwise creates a stable ID. Keep `@media` and `@container` nested inside the selector block they modify. Use `->stylesheet()` for true global CSS such as `@keyframes`, `@property`, `@font-face`, `@import`, `@supports`, `@layer`, and `@charset`.
```

- [ ] Run the OhMyIDEtch generated-skill gate:

```bash
cd /Users/woji/Dev/OhMyIDEtch
bun run skills:check
```

Expected: skills build/check passes.

- [ ] Run focused PHPStan rule test first:

```bash
cd /Users/woji/Dev/OhMyIDEtch
bun run phpunit -- --filter StylesParserCssStructureRuleTest
```

Expected: PHPStan fixture assertions pass and errors contain the new agent-readable guidance.

- [ ] Run OhMyIDEtch full configured gate:

```bash
cd /Users/woji/Dev/OhMyIDEtch
bun run check
```

Expected: `skills:check`, PHPStan, and configured PHPUnit filter pass.

- [ ] If Composer path repository was temporary, remove only that temporary dependency wiring after verification unless a real package version/tag is already available. First inspect the dependency diff and confirm it contains no unrelated work:

```bash
cd /Users/woji/Dev/OhMyIDEtch
git diff -- composer.json composer.lock
composer config --unset repositories.local-etch-builders
composer update honestlydesign/etch-builders --with-dependencies
```

Do not remove or revert docs/tests/source edits. If the package has not been released yet and Composer cannot resolve the new version without the path repository, leave dependency files unstaged and document that final consumer lock updates are blocked on package release.

---

## Task 10: Release/Version Coordination

- [ ] Decide whether to tag/release `honestlydesign/etch-builders` before committing consumer dependency updates. If no release is being cut in this session, keep consumer Composer path changes out of the commit.
- [ ] If releasing the package, update package versioning using the repo's existing release process. Do not invent a release workflow.
- [ ] After release/tag is available, update consumers to the released version instead of local path repositories:

```bash
cd /Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests
composer update honestlydesign/etch-builders --with-dependencies

cd /Users/woji/Dev/OhMyIDEtch
composer update honestlydesign/etch-builders --with-dependencies
```

- [ ] Re-run:

```bash
cd /Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests
composer test -- --filter "StyleRenderingTest|StylesParserRenderingTest"

cd /Users/woji/Dev/OhMyIDEtch
bun run check
```

- [ ] Commit each repo independently with tight scope:

```bash
cd /Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests
git status --short
git add tests composer.json composer.lock
git diff --cached --check
git commit -m "test: cover selector-first parsed styles"

cd /Users/woji/Dev/OhMyIDEtch
git status --short
git add composer.json composer.lock tests/Unit/Builders/StylesValidatorTest.php tests/Unit/Tools/PhpStan/StylesParserCssStructureRuleTest.php build-skills
git diff --cached --check
git commit -m "chore: document selector-first parsed styles"
```

Only stage `composer.json` and `composer.lock` when they point to a real released package version or an intentionally committed local path repository.

---

## Task 11: Final Verification Checklist

- [ ] Package repo:

```bash
cd /Users/woji/Dev/Packages/Composer/EtchBuildersAPI
composer test
composer phpstan
rg -n 'CSS file must define at least one|Only .+style-id|/\\* style-id|/\\* id' src tests docs README.md
```

- [ ] Rendering repo:

```bash
cd /Users/woji/Dev/Packages/Composer/etch-builders-rendering-tests
composer test -- --filter "StyleRenderingTest|StylesParserRenderingTest"
```

- [ ] OhMyIDEtch:

```bash
cd /Users/woji/Dev/OhMyIDEtch
bun run skills:check
bun run phpunit -- --filter StylesParserCssStructureRuleTest
bun run check
```

- [ ] Manual production-safety review:
  - existing selector in `etch_styles` reuses existing ID;
  - single `.class` selector produces class token ID;
  - compound selector produces stable `omide-style-<hash>`;
  - duplicate persisted selector throws and lists IDs;
  - root `@media`/`@container` errors include wrong/right nested example;
  - global root at-rules mention `Stylesheet`/`->stylesheet()`;
  - no generated end-user `.agents`, `.claude`, `.opencode`, or source-root generated files are committed in OhMyIDEtch.

---

## Implementation Notes

- Do not rely on `Style::register_all()` conflict cleanup as the primary safety mechanism. It happens after the parser has already chosen an ID.
- Do not reuse `ClassStyleRegistry::selector_to_id_map()` for parser identity unless it is changed to detect duplicate selector IDs. Its current map shape silently keeps the first/last match and is class-registry oriented.
- `Style::is_code_owned_style_id()` already recognizes `omide-`, so generated `omide-style-<hash>` IDs will be cleaned as code-owned orphaned styles.
- Keep scanner implementation conservative. If malformed CSS cannot be safely scanned, report a validator error instead of accepting it.
- Do not add Vite/watchers/HMR or source-root generated agent outputs in OhMyIDEtch.
