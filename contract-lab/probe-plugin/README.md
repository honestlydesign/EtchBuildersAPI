# Etch Builders Contract Probe Plugin

Maintainer-only Contract Lab scaffold. It is not part of the Composer package,
the LocalWP Devkit, or an end-user plugin ZIP.

The plugin owns only this directory. Installation copies this directory to the
`contract-probe-plugin` WordPress plugin directory; removal removes only that
directory. The endpoint requires a local/development single site, the matching
Contract Lab marker, an authenticated user, and `manage_options`.

The current endpoint returns a versioned empty observation envelope. Probe
versions and observation schema versions are rejected before the callback can
emit an envelope. Later maintainer tickets add normalized public-surface
observations without copying proprietary Etch code or exposing arbitrary site
payloads.
