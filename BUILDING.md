# Building
## Pre-requisites
- A bash shell (git bash is sufficient for Windows)
- [`git`](https://git-scm.com) available in your shell
- 64-bit PHP 8.4 or 8.5 with the extensions listed in `composer.json`
- [`composer`](https://getcomposer.org) available in your shell

## Custom PHP binaries
Quark requires several non-standard PHP extensions and configuration.

- [Prebuilt binaries](https://github.com/Bedrock-Phanatics/PHP-Binaries/releases)
- The [upstream PHP compile scripts](https://github.com/pmmp/php-build-scripts) are pinned as the `build/php` submodule. Initialize it with `git submodule update --init build/php` before building Docker.

The compiler's `-P5` option and `bin/php7/bin/php` directory select the inherited binary layout and extension family. Quark's plugin API is **6.0.0**; changing these binary identifiers to `6` would break the current compiler.

CI and release builds use Bedrock-Phanatics PHP 8.4 binaries. PHP 8.5 CI is temporarily paused until the Quark binary repository publishes 8.5 assets.

The optional `tests/plugins/DevTools` and `examples/plugins/ExamplePlugin` submodules are inherited PocketMine-MP projects, not Quark 6 plugins. CI does not initialize them. Build a migrated folder plugin with `php -dphar.readonly=0 tests/build-plugin-phar.php path/to/plugin path/to/plugin.phar`.

If you use a custom binary, you'll need to replace `composer` usages in this guide with `path/to/your/php path/to/your/composer.phar`.

## Setting up environment
1. `git clone https://github.com/Bedrock-Phanatics/Quark.git`
2. `composer install`

## Checking out a different branch to build
1. `git checkout <branch to checkout>`
2. Re-run `composer install` to synchronize dependencies.

## Optimizing for release builds
1. Add the flags `--no-dev --classmap-authoritative` to your `composer install` command. This will reduce build size and improve autoloading speed.

## Building `Quark.phar`
Run `composer make-server` using your preferred PHP binary. It'll drop a `Quark.phar` into the current working directory.

To select an output filename, first install production dependencies with `composer install --no-dev --classmap-authoritative`, then run `php -dphar.readonly=0 build/server-phar.php --out path/to/Quark.phar`.

`composer make-server` removes development dependencies. Run `composer install` again before running PHPUnit or PHPStan.

## CI and releases

CI runs PHPStan, PHPUnit, integration tests and two clean code-generation passes on PHP 8.4. PHP 8.5 coverage is temporarily paused. CI also checks handwritten PHP style, translations, tracked shell scripts and Docker startup/plugin persistence. Generated PHP is owned by the generators; formatting must be corrected in the generator rather than applied to generated output.

Push builds cover `stable` and `v6.0.0`; other branches are checked through pull requests or manual dispatch to avoid duplicate push/PR builds.

To prepare a release, set `IS_DEVELOPMENT_BUILD` to `false`, verify the version/channel in `src/VersionInfo.php`, update `CHANGELOG.md`, and push a matching version tag (for example `6.0.0` or `v6.0.0`). After CI passes, the tag workflow builds `Quark.phar`, launcher scripts, `build_info.json` and core permission documentation, then creates a draft release. Publishing the draft starts the container pipeline for `ghcr.io/bedrock-phanatics/quark`; stable releases also receive major, minor and `latest` tags. Container smoke tests must pass before tags are pushed.

## Running Quark from source code
Run `src/Quark.php` using your preferred PHP binary.
