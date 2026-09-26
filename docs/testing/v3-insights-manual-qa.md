# V3 Insights — how to test

What's being tested: usage tracking in plugin-updater **V3**, built on 2026-09-26.
- **Free plugins** (`V3\Insights`) show an Appsero-style notice and send nothing until an admin
  clicks **Allow**. After that they send site, admin name + email, environment, plugin list and
  the plugin's own counters once a day.
- **Commercial plugins** (`V3\Integration`) do the same without asking, on top of V2's updates
  and licensing.
- The server side is the new `wp-repo-insights/v1` API in plugin-repo.

Three draft PRs make up the work:

| Repo | PR | Branch |
|---|---|---|
| plugin-updater | shazzad/plugin-updater#29 | `feature/v3-insights` |
| plugin-repo | shazzad/plugin-repo#77 | `feature/insights-api` |
| plugin-updater-test | shazzad/plugin-updater-test#1 | `feature/v3-insights` |

Everything runs on the local w4dev stack (https://w4dev.shazzad.me). Nothing touches w4dev.com.
Expect **15 minutes** for Part 1, about **15 more** for Part 2 in the browser, and a few minutes
for Part 3's isolation checks.

---

## 0. Setup (once)

```bash
cd ~/personal-assistant/w4dev-project
git -C shazzad-plugin-updater      switch feature/v3-insights
git -C shazzad-plugin-repo         switch feature/insights-api      # was on fix/versioned-download-url (#74)
git -C shazzad-plugin-updater-test switch feature/v3-insights
docker compose up -d

# shortcut used below
wpc() { docker compose exec -T -u 1000 -e HOME=/tmp wordpress wp "$@"; }
wpc eval 'Shazzad\PluginRepo\Plugin::get_instance()->maybe_upgrade_db();'   # creates wprepo_install_insights
```

## 1. Automated checks (≈5 min)

```bash
# client library: every suite, V1 + V2 + V3
(cd shazzad-plugin-updater && composer test)
# expect: OK, 443 tests (9 "risky" = assertion-less migration tests, pre-existing pattern)

# server: new suites + one old one as a regression spot-check
for t in insights-track insights-optout install-dedupe; do
  wpc eval-file wp-content/plugins/shazzad-plugin-repo/tests/$t-test.php | tail -1
done
# expect: "110 passed, 0 failed", "57 passed, 0 failed", "34 passed, 0 failed"

# end to end: real HTTP from two fixture plugins to the local server
shazzad-plugin-updater-test/bin/insights-e2e | tail -1
# expect: "48 passed, 0 failed"
```

`bin/insights-e2e` also leaves two fixture plugins installed (inactive) and two local products
set up. Part 2 uses them.

## 2. By hand in the browser (≈15 min)

Log in at https://w4dev.shazzad.me/wp-admin.

### A. Free plugin: the consent notice

1. Reset the free fixture to "never asked":
   ```bash
   wpc option delete spu-insights-free_insights_consent
   ```
2. **Plugins** → activate **SPU Insights Free Test**.
   - [ ] A blue notice appears: "Want to help make **SPU Insights Free Test** even better? Allow
         SPU Insights Free Test to collect diagnostic data and usage information."
   - [ ] **(what we collect)** expands (no page reload, works with JS off). The list includes
         "Your site's admin email address and administrator name", the active theme, the names
         and versions of active plugins, user counts by role, WordPress memory limit and debug
         mode, the multisite / local-site flags, and "Usage statistics specific to SPU Insights
         Free Test" (the fixture sends `meta`), plus a "Learn more" link to the privacy page.
   - [ ] The notice shows on other admin screens too (the default is every screen).
3. Check that nothing was sent: **Plugin Repo → Installs**, filter the plugin to *SPU Insights Free Test*.
   - [ ] No rows.
4. Click **No thanks**.
   - [ ] The notice disappears and stays gone after a reload.
   - [ ] Installs still has no rows for *SPU Insights Free Test*.
5. Reset again (`wpc option delete spu-insights-free_insights_consent`), reload, click **Allow**.
   - [ ] The notice disappears.
   - [ ] **Installs** now has one row for *SPU Insights Free Test*, showing your site URL, admin
         email and name, PHP/WP versions and status *active*.
   - [ ] Open the row (**View**). An **Insights** block shows: mode *consent*, site name, theme,
         user count, active/inactive plugin counts, the active plugin list, and the date consent
         was given.
6. **Plugins** → deactivate the free fixture, then reload the Installs row.
   - [ ] Status is *inactive*.
   - [ ] Reactivating shows **no** notice (the answer is remembered) and the row goes back to *active*.
7. Opt out (the fixture has no settings screen, so do it through code):
   ```bash
   wpc eval '$GLOBALS["spu_insights_free"]->opt_out();'
   ```
   - [ ] The Installs row is gone, including its Insights block.
   - [ ] Nothing reappears after `wpc cron event run --due-now`.
   - [ ] `wpc option get spu-insights-free_insights_optout_pending` finds nothing (the opt-out
         went through, so no retry is pending).
8. Uninstall cleanup (the fixture has no uninstall routine, so call it directly):
   ```bash
   wpc eval '\Shazzad\PluginUpdater\V3\Insights::uninstall( "spu-insights-free/spu-insights-free.php" );'
   wpc option list --search='spu-insights-free_insights_*' --format=count
   wpc cron event list --hook=wprepo_insights_track_spu-insights-free --format=count
   ```
   - [ ] Both counts are `0`. Re-running Part 1's `bin/insights-e2e` recreates everything.

### B. Commercial plugin: no notice, license bound

1. **Plugins** → activate **SPU Insights Commercial Test**.
   - [ ] **No** consent notice.
   - [ ] **Installs**, filtered to *SPU Insights Commercial Test*, has a row straight away.
2. **Plugins → SPU Insights Commercial License**: paste the code from
   `wpc option get spu_insights_commercial_license` and save.
   - [ ] The page accepts it (same license UI as V2).
3. Force a daily send:
   ```bash
   wpc option delete spu-insights-commercial_insights_last_send
   wpc cron event run wprepo_insights_track_spu-insights-commercial
   ```
   - [ ] The Installs row now shows the license, and its **Insights** block says mode *commercial*.
   - [ ] Meta shows `orders_synced = 7`.
4. The hourly backup (a plugin updated in place on a site nobody opens wp-admin on). Remove the
   daily event and the last-send time, then run only the hourly license sync:
   ```bash
   wpc cron event delete wprepo_insights_track_spu-insights-commercial
   wpc option delete spu-insights-commercial_insights_last_send
   wpc cron event run "$(wpc cron event list --field=hook | grep '^wprepo_sync_license_data_spu-insights-commercial')"
   wpc cron event list --hook=wprepo_insights_track_spu-insights-commercial --format=count
   ```
   - [ ] The count is `1` (the daily event was re-created) and the Installs row's last check-in
         moved to now.
   - [ ] Running the hourly event again right away sends nothing new (20-hour guard).

### C. The V2 test plugin still works

**Tools → SPU Test Scenarios** (the V2 mock harness):
- [ ] "Update available" → **Force update check** still offers an update.
- [ ] "Ping" still logs a request.

## 3. Isolation checks (≈2 min)

```bash
cd ~/personal-assistant/w4dev-project/shazzad-plugin-updater
git diff main -- src/Admin.php src/Client.php src/Integration.php src/Tracker.php src/Updater.php src/V2 | wc -l
# expect: 0 (V1 and V2 byte-identical)

cd ../shazzad-plugin-repo
git diff origin/main --stat -- includes/RestController/V3 includes/RestController/V4
# expect: nothing (existing routes untouched)
```

Optional: the free-plugin zip strip recipe is in plugin-updater's README ("Shipping a free
wp.org plugin"). Use it when Adminkeep adopts Insights, then run Plugin Check on that zip.

## 4. Clean up

```bash
cd ~/personal-assistant/w4dev-project
shazzad-plugin-updater-test/bin/insights-e2e --cleanup   # fixtures, products, installs, options
git -C shazzad-plugin-repo switch fix/versioned-download-url   # back to your #74 branch, if wanted
```

## Before shipping (not part of testing)

- **Deploy plugin-repo#77 before any plugin ships on V3.** Until then a V3 plugin's tracks get
  a 404. The client ignores it, and updates and licensing keep working.
- On deploy, the first request runs `Installer::upgrade()` once, because of the new
  `wprepo_db_version` schema check. That is the same as any version bump, and it creates
  `wprepo_install_insights`.
- The free products on the repo server (Adminkeep later) need a product row with **Track
  install** on and no versions uploaded.
- plugin-updater releases as **3.0.0** (CHANGELOG entry is ready, marked unreleased).
- Separate from this feature, shazzad/plugin-repo#78 tightens how the **existing** v3 API looks
  up license codes and install URLs (found during this review). It is independent of #77, and
  the plan is to review and deploy it on its own, ideally first.
