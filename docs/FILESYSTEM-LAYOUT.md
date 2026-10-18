# Filesystem layout (version 2)

The `--data` option selects the installation root. Without `--data`, the current directory is the root. `--plugins` can override the package directory; otherwise packages use `plugins/` under the root.

| Path | Purpose |
| --- | --- |
| `config/server.toml`, `config/plugins.toml` | Server settings and plugin loading rules |
| `config/` | Access lists and other server configuration |
| `plugins/` | Installed plugin packages |
| `data/players/`, `data/worlds/`, `data/plugins/` | Persistent player, world, and plugin data |
| `data/access.sqlite` | Reserved for a future access storage handler; not created by version 2 |
| `resource-packs/` | Resource packs and their configuration |
| `logs/` | Current and archived server logs |
| `diagnostics/crashes/`, `diagnostics/profiles/` | Crash and performance reports |
| `backups/` | World conversion backups and verified configuration migration snapshots |
| `runtime/` | Temporary runtime state and the active server lock |

`.quark-layout.json` records the layout version. `.quark-migration.json` and `.quark-config-migration.json` record incomplete work; `.quark-migration.lock` excludes concurrent migration. An installation with an incomplete journal cannot start normally.

Migration is disabled by default. Set the following in `config/server.toml` to permit startup migration:

```toml
[migration]
auto_on_startup = true
```

An existing `config/migration.ini` is also read during the upgrade and its setting is preserved in `server.toml`. For installations without a modern configuration, run `--migration-dry-run` to inspect planned moves, conversion, snapshots, and collisions, then `--migrate-legacy` to migrate. Quark snapshots legacy configuration in `backups/`, verifies copied data and generated TOML, and retains the snapshot after retiring old files. A failed step can be resumed with `--migrate-legacy`.

The coordinator converts `pocketmine.yml` or `quark.yml`, `server.properties`, and `plugin_list.yml` into the two TOML files. Existing `ServerConfigGroup` getter keys remain valid; former properties live under `[server]`. Unknown settings are retained, and conflicting or invalid values stop migration with the originals recoverable from the snapshot.

Legacy installations with `plugins.legacy-data-dir: true` require manual separation of package and plugin data before migration. This version has no handler that can distinguish arbitrary plugin packages from their colocated data; migration stops instead of guessing.
