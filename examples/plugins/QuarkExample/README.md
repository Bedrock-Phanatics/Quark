# Quark 6 example plugin

Copy this folder into the server's `plugins` directory. Its `plugin.yml` declares API `6.0.0`, and its source imports Quark's `quark\plugin\PluginBase`.

To package it from the repository root:

```bash
php -dphar.readonly=0 tests/build-plugin-phar.php examples/plugins/QuarkExample QuarkExample.phar
```
