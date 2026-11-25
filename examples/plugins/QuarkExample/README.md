# Quark 6 example plugin

Copy this folder into the server's `plugins` directory. Its `plugin.toml` declares API `6.0.0`, and its source imports Quark's `quark\plugin\PluginBase`.

To package it from the repository root:

```bash
php -dphar.readonly=0 tests/build-plugin-phar.php examples/plugins/QuarkExample QuarkExample.phar
```

Use `getConfig()` to load `config/plugins/QuarkExample/config.toml`. Quark copies
`resources/config.toml` from the plugin package on first use. Store additional
settings under `getConfigFolder()` and persistent files under `getDataFolder()`.
