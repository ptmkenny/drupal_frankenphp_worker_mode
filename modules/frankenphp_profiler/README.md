# FrankenPHP Profiler

Per-request XHProf profiling for Drupal under FrankenPHP worker mode, with
runs sent to XHGui. Dev-only; works only on DDEV.

DDEV's `ddev xhprof` starts profiling from an `auto_prepend_file`. In
FrankenPHP worker mode that file runs once per worker, not once per request,
so one run would cover up to `FRANKENPHP_LOOP_MAX` requests. Instead:

- `.ddev/web-build/Dockerfile.xhprof-worker` removes the `auto_prepend_file`
  line from the xhprof ini.
- The same Dockerfile replaces the packaged xhprof 2.3.10 with a build of
  upstream master, pinned by `XHPROF_REF`. In 2.3.10 the observer init returns
  no handlers when profiling is off, and PHP caches that per function. A worker
  has called every Drupal method before profiling starts, so 2.3.10 profiles
  contain only closures and internal functions. The fix is PR #94 (commit
  `8f020b9c`), which is not in any release. When a newer xhprof release is
  packaged as `php-zts-xhprof`, delete that build step.
- This module adds an HTTP middleware (`XhprofProfilerMiddleware`) that
  profiles one request at a time, in both worker and classic mode, and sends
  the run to XHGui.

Keep the module out of `core.extension.yml` on export, and keep config import
from uninstalling it, by excluding it in the DDEV branch of your settings:

```php
$settings['config_exclude_modules'][] = 'frankenphp_profiler';
```

## One-time setup

These commands restart FrankenPHP.

```shell
ddev restart                                 # rebuilds the web image
ddev drush pm:install frankenphp_profiler    # repeat after importing a production DB
ddev xhgui on                                # loads xhprof and starts the XHGui service
```

Repeat `ddev xhgui on` after every `ddev restart`. `ddev xhgui off` and
`ddev xhprof off` also restart FrankenPHP.

The extension stays loaded between profiles. Until a request triggers
profiling, it costs close to nothing.

## Profiling a request

Profile a request by sending the cookie `frankenphp_profile=1` or the header
`X-Frankenphp-Profile: 1`. Don't use a query parameter: JSON:API rejects
unknown query parameters.

```shell
# From the web container, with a session cookie copied from the browser.
curl -sk -o /dev/null -D - \
  -b 'SSESS…=…; frankenphp_profile=1' \
  "https://${DDEV_HOSTNAME}/node" \
  | grep -i -e x-frankenphp-profile -e server-timing
```

In a browser, set the `frankenphp_profile=1` cookie for the site and reload.
Every request is then profiled.

Response headers:

| Header | Meaning |
|---|---|
| `Server-Timing: drupal;dur=<ms>` | Time spent inside Drupal. Sent on every main request, profiled or not. Shown in DevTools → Network → Timing. |
| `X-Frankenphp-Profile-Run: <id>` | This request was profiled. XHGui assigns its own run ID; the mapping is logged to the `frankenphp_profiler` channel (`ddev drush watchdog:show --type=frankenphp_profiler`). |
| `X-Frankenphp-Profile: unavailable` | Profiling was requested, but xhprof is not loaded (run `ddev xhgui on`). |

Open the results with `ddev xhgui launch`. If the upload to XHGui fails, the
run is appended to `/tmp/xhgui.data.jsonl` in the web container and a warning
is logged to the `frankenphp_profiler` channel.

## Reading results

- Sort by inclusive wall time to find where a request spends its time
  (entity loading, access checks, normalization, SQL). Use XHGui's compare
  view for before/after runs.
- Profiling adds its own overhead, so profiled wall times are too high. Use
  profiles to find where time goes. Judge whether a change helped from
  unprofiled timings (median and p95 over 50+ requests).
- The profile covers the request and the kernel `terminate()` phase, which runs
  after the response has been sent. `Server-Timing` covers only the time before
  the response is sent.
- Only non-secret `$_SERVER` keys are sent to XHGui. In worker mode `$_SERVER`
  also holds the container environment, and cookies are never stored.
