# Filesystem layout (version 1)

The `--data` option selects the installation root. Without `--data`, the current directory is the root. `--plugins` can override the package directory; otherwise packages use `plugins/` under the root.

| Path | Purpose |
| --- | --- |
| `config/` | Server, plugin list, and access list configuration |
| `plugins/` | Installed plugin packages |
| `data/players/`, `data/worlds/`, `data/plugins/` | Persistent player, world, and plugin data |
| `data/access.sqlite` | Reserved for a future access storage handler; not created by version 1 |
| `resource-packs/` | Resource packs and their configuration |
| `logs/` | Current and archived server logs |
| `diagnostics/crashes/`, `diagnostics/profiles/` | Crash and performance reports |
| `backups/` | World conversion backups and operator-created migration snapshots |
| `runtime/` | Temporary runtime state |

`.quark-layout.json` records the layout version. `.quark-migration.json` records incomplete work; `.quark-migration.lock` excludes concurrent migration. An installation with an incomplete journal cannot start normally.

Migration is disabled by default. Set the following in `config/migration.ini` to permit startup migration:

```ini
[migration]
auto_on_startup = true
```

For older installations without that file, run `--migration-dry-run` to inspect planned moves and collisions, then `--migrate-legacy` to migrate. Keep a separate backup before migrating. If a copy is required because a rename cannot be used, Quark checks available space, verifies the copied content, and only then retires the legacy source. A failed step can be resumed with `--migrate-legacy`.

Legacy installations with `plugins.legacy-data-dir: true` require manual separation of package and plugin data before migration. This version has no handler that can distinguish arbitrary plugin packages from their colocated data; migration stops instead of guessing.
