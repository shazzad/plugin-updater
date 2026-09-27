# V4 Insights — implementation plan

Spec: `docs/superpowers/specs/2026-09-26-v4-insights-design.md`. Executed subagent-driven in the
overnight run of 2026-09-26 (hub run log `docs/overnight/RUN-2026-09-26-telemetry.md`).

> **Note 2026-09-27:** this plan was executed with the namespace named `V3` and a 3.0.0 release
> in mind. Before release the namespace was renamed `V4` (to match the `wp-repo/v4` API it
> calls) and the release became 4.0.0; the names below follow. Branch names are left as they
> were. The side namespace `wp-repo-insights/v1` in Task A was later replaced by `wp-repo/v4`,
> which then moved to `plugins/{uid}` routes with `product_uid` required (see the spec's
> revision notes).

Branches: `shazzad-plugin-updater` `feature/v3-insights`, `shazzad-plugin-repo`
`feature/insights-api` (from `origin/main`), `shazzad-plugin-updater-test` `feature/v3-insights`.

## Task A — server (`shazzad-plugin-repo`), parallel with B

1. `Installer`: create `wprepo_install_insights` (DDL per spec), register `$wpdb->wprepo_install_insights`,
   bump DB version so `maybe_upgrade_db()` creates it on existing sites.
2. `includes/InstallInsights/Data.php` (+ `install-insights-functions.php`): upsert by `install_id`,
   get, delete; token hashed with `wp_hash_password`-free HMAC (`hash_hmac('sha256', $token, wp_salt())`).
3. `includes/RestController/Insights/V1/{Controller,TrackController,OptoutController}.php`,
   namespace `wp-repo-insights/v1`; product resolution copied (not shared) from V3 PublicApi.
   Track reuses `Install\Data::create_install()` + `wprepo_sync_install_meta()`.
   Optout deletes install + meta + insights + install events when the token matches.
4. Register the two controllers in `RestApi.php` (additive lines only).
5. Installs admin detail: "Insights" block when a row exists.
6. Tests: `tests/insights-track-test.php`, `tests/insights-optout-test.php` (eval-file pattern,
   `check()` helper, cleans up its rows); run in the w4dev stack; existing tests still pass.
7. Docs: `docs/insights-api.md`, CLAUDE.md REST tree + tables list.

## Task B — client Insights core + free entry (`shazzad-plugin-updater`), parallel with A

1. `src/V4/Insights/{Collector,Client,Scheduler,Consent,Notice}.php` + `src/V4/Insights.php`
   per spec; ABSPATH + `class_exists` guards like every file here; PHP 7.4 syntax.
2. `tests/V4/` PHPUnit + Brain Monkey: collector shape + 200 cap, consent transitions, notice
   renders only when unset/capable/on-screen, Allow/No thanks handler (nonce, cap, ajax skip),
   no HTTP before consent, optout sends token, cron scheduled/cleared, and a static guard test
   that `src/V4/Insights.php` + `src/V4/Insights/` never mention `Updater`, `License` or `LicensePage`.
3. `composer.json` autoload unchanged (PSR-4 covers V4); `phpunit.xml.dist` includes tests/V4.

## Task C — commercial `V4\Integration` (after B)

1. Copy `src/V2/{Integration,Client,Updater,Tracker}.php`, `License/Store.php`, `Admin/*` to
   `src/V4/` with namespace V4; Integration builds Insights parts with consent granted
   (`mode: commercial`), accepts `insights_api_url`; Tracker's hourly sync drops the `/ping`
   call, Insights Scheduler handles daily + activate/deactivate/upgrade with license in payload.
2. Copy the V2 tests that apply to `tests/V4/` + tests for the commercial path.
3. README "Which namespace" + V4 quick starts (free + commercial) + free-build strip recipe;
   CHANGELOG 4.0.0 entry; version bump to 4.0.0 wherever the package states it.
4. Verify `git diff main -- src/*.php src/V2` is empty; `composer test` + `composer lint` green.

## Task D — end-to-end (after A + C)

`shazzad-plugin-updater-test`: add `insights-free-test.php` (free, consent) and
`insights-commercial-test.php` (V4\Integration) as extra top-level plugin files, pointed at the
local stack's own `wp-repo-insights/v1`; create local product rows; run the flows with wp-cli
(consent unset → no rows; opt_in → row + insights; daily; deactivate; opt_out → rows gone;
commercial → row without consent, license bound). Record evidence in the run log.

## Task E — manual QA doc

`docs/testing/v4-insights-manual-qa.md`: stack up, activate test plugins, click-through of the
notice, where to look in the repo admin, curl checks, the V1/V2 untouched check, cleanup.

## Task F — review

Second agent reviews all three branches against the spec; fix findings; draft PRs updated.
