# honestlydesign/etch-builders

Pure-PHP builder API for serializing Etch blocks, styles, loop presets, and
component definitions. WordPress-free.

## Install

    composer require honestlydesign/etch-builders

## Why

Etch stores styling in a flat `etch_styles` option keyed by opaque style IDs, and
block markup references those IDs via `attrs.styles[]`. This package provides a
fluent, type-safe PHP builder that serializes blocks and styles correctly every
time, with guardrails that fail at build time rather than mis-rendering at runtime.

## Usage

```php
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Support\NullStorage;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;

// Wire the three seams once (or rely on Null* defaults for tests).
Environment::configure(new NullStorage(), new NullMode(), new NullAssetRegistry());

// Use the builders (full API lands in Phase 2; Phase 1 ships the seam only).
```

## Status

**Phase 1 (this release):** scaffold + three-interface seam + `Environment` +
pure-PHP `Json`/`Key`/`Esc` helpers + `Null*` defaults. No block builders yet.

## License

MIT
