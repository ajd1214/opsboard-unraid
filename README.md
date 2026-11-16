# Opsboard for Unraid

A small Unraid page (Utilities → Opsboard) and Dashboard tile for the routine jobs on your server:

- **Self-check (optional):** a button that runs your own check script, and a table of its last result
  (PASS / WARN / FAIL / MANUAL per item). The Dashboard tile shows it at a glance.
- **Jobs:** each job has a **Dry run / check** button and, if you give it one, a **GO** (and optionally a
  **Roll back**). GO unlocks only for a while after a finished dry run of the same job, and that is enforced
  on the server, not just in the browser. Output streams into the card.

Opening the page runs nothing. The page can only start the jobs listed in your settings file: a request only
picks a job and a mode, never a command.

## Install

Plugins → Install Plugin, paste:

```
https://raw.githubusercontent.com/ajd1214/opsboard-unraid/main/opsboard.plg
```

Updates then show on the Plugins tab like any other plugin.

## Settings: `/boot/config/plugins/opsboard/opsboard.json`

Opsboard has no settings page on purpose: the file is meant to be written by your own scripts' installer, so
the buttons always match the scripts they run. Without the file the page says so and the tile is hidden.

```json
{
  "title": "My Server Ops",
  "go_window_minutes": 30,
  "check": {
    "label": "Run all checks",
    "run": "us:my-self-check",
    "result_file": "/mnt/cache/appdata/my-ops/last.json",
    "note": "MANUAL items are hand checks."
  },
  "jobs": [
    { "key": "docker_cln", "label": "Docker housekeeping", "desc": "Removes dangling images.",
      "dry": "us:docker-housekeeping-check", "go": "us:docker-housekeeping-go" },
    { "key": "unraid_upd", "label": "Unraid update check", "desc": "Check only.",
      "dry": "cmd:/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/unraidcheck" },
    { "key": "ca_upd", "label": "Container updates", "desc": "Runs Auto Update Applications now.",
      "dry": "us:docker-pull-check",
      "go": "cmd:php /usr/local/emhttp/plugins/ca.update.applications/scripts/updateDocker.php",
      "go_if_file": "/usr/local/emhttp/plugins/ca.update.applications/scripts/updateDocker.php" }
  ]
}
```

- `key`: lowercase letters, digits and `_`. Jobs with any other key are ignored.
- `dry`, `go`, `rollback`: `us:<folder>` runs that User Scripts entry
  (`/boot/config/plugins/user.scripts/scripts/<folder>/script`); `cmd:<command>` runs a fixed command.
  A job without `go` is check-only.
- `<mode>_if_file`: that mode only exists when the file does (for jobs that depend on another plugin).
- `check.result_file`: JSON your check script writes:
  `{"result": "short summary", "at": "<ISO time>", "checks": [{"item": 1, "name": "...", "status": "PASS", "detail": "..."}]}`.
  The banner colour comes from the statuses: any FAIL is red, any WARN is amber, otherwise green.

Commands run as root, so treat the settings file like a script: only root should write it (the flash drive
is root-only on Unraid).

## Build

`./build.sh` packs `src/` into `packages/opsboard-<version>.txz` and writes its SHA256 into `opsboard.plg`.
Bump the version in `opsboard.plg` first and add a `###<version>` note under `<CHANGES>`.

## License

MIT (see LICENSE).
