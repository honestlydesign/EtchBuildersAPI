# Etch Builders Contract Probe Plugin

Maintainer-only Contract Lab scaffold. It is not part of the Composer package,
the LocalWP Devkit, or an end-user plugin ZIP.

The plugin owns only this directory. Installation copies this directory to the
`contract-probe-plugin` WordPress plugin directory; removal removes only that
directory. The endpoint requires a local/development single site, the matching
Contract Lab marker, an authenticated user, and `manage_options`.

The endpoint accepts explicit style IDs, component keys, and optional document
slugs. It returns a versioned normalized persistence handoff containing only
the requested Builder-owned style/component facts, plus a separately named
public Etch runtime-resolution outcome for style loading. WordPress IDs,
URLs, CSS bodies, arbitrary posts, and private Etch implementation data never
cross the response boundary. Missing runtime surfaces remain inconclusive;
they are not reported as compatibility failures or successes.
