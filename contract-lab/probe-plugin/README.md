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

Composite frontend observations are run by the maintainer-side
`ContractLabFrontendProbe` with root-relative HTTP fixtures. The probe keeps
DOM and stylesheet ordering, checks explicit class/slot/loop/dynamic markers,
and treats unavailable product prerequisites as skips. It is not a browser
harness and is not shipped to LocalWP Devkit or end users.

Browser Preservation Sentinels use a separate maintainer-side browser adapter:
the adapter owns editor controls and save actions, while the Builder API only
compares the frontend observation before save with the observation after
reload. Unsupported editors are explicit skips and browser transport failures
are inconclusive; neither can promote compatibility.
