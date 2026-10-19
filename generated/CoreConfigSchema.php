<?php

// Generated from resources/server.toml; run composer update-codegen after editing defaults.
return array (
  'memory' =>
  array (
    'global-limit' => 0,
    'main-limit' => 0,
    'main-hard-limit' => 1024,
    'async-worker-hard-limit' => 256,
    'check-rate' => 20,
    'continuous-trigger' => true,
    'continuous-trigger-rate' => 30,
    'garbage-collection' =>
    array (
      'threshold' => 10001,
      'period' => 36000,
    ),
    'memory-dump' =>
    array (
      'dump-async-worker' => true,
    ),
    'max-chunks' =>
    array (
      'chunk-radius' => 4,
    ),
  ),
  'transport' =>
  array (
    'name' => 'nethernet,raknet',
    'nethernet' =>
    array (
      'key-file' => 'nethernet.key',
      'ice-udp-mux' => false,
      'mtu' => 1200,
      'builtin-signaling' =>
      array (
        'enabled' => true,
        'port' => 0,
        'reverse-proxy' =>
        array (
          'enabled' => false,
          'header' => 'X-Forwarded-For',
        ),
      ),
    ),
  ),
  'auto-updater' =>
  array (
    'enabled' => false,
    'preferred-channel' => 'stable',
    'suggest-channels' => true,
    'host' => '',
    'on-update' =>
    array (
      'warn-console' => true,
    ),
  ),
  'migration' =>
  array (
    'auto_on_startup' => false,
  ),
  'server' =>
  array (
    'motd' => 'Quark Server',
    'server-port' => 19132,
    'server-portv6' => 19133,
    'enable-ipv6' => true,
    'white-list' => false,
    'max-players' => 20,
    'gamemode' => 'SURVIVAL',
    'force-gamemode' => false,
    'hardcore' => false,
    'pvp' => true,
    'difficulty' => 2,
    'generator-settings' => '',
    'level-name' => 'world',
    'level-seed' => '',
    'level-type' => 'DEFAULT',
    'enable-query' => true,
    'auto-save' => true,
    'view-distance' => 16,
    'xbox-auth' => true,
    'language' => 'eng',
  ),
  'settings' =>
  array (
    'force-language' => false,
    'shutdown-message' => 'Server closed',
    'query-plugins' => true,
    'enable-profiling' => false,
    'async-workers' => 'auto',
    'enable-dev-builds' => false,
  ),
  'network' =>
  array (
    'compression-algorithm' => 'zlib',
    'batch-threshold' => 256,
    'compression-level' => 6,
    'async-compression' => false,
    'async-compression-threshold' => 10000,
    'combat-low-latency-feedback' => true,
    'upnp-forwarding' => false,
    'max-mtu-size' => 1492,
    'enable-encryption' => true,
  ),
  'debug' =>
  array (
    'level' => 1,
  ),
  'player' =>
  array (
    'save-player-data' => true,
    'verify-xuid' => true,
  ),
  'level-settings' =>
  array (
    'default-format' => 'leveldb',
  ),
  'chunk-sending' =>
  array (
    'per-tick' => 4,
    'spawn-radius' => 4,
  ),
  'chunk-ticking' =>
  array (
    'tick-radius' => 3,
    'blocks-per-subchunk-per-tick' => 3,
  ),
  'chunk-generation' =>
  array (
    'population-queue-size' => 32,
  ),
  'ticks-per' =>
  array (
    'autosave' => 6000,
  ),
  'crash-dumps' =>
  array (
    'include-code' => true,
    'include-settings' => true,
    'include-phpinfo' => false,
  ),
  'console' =>
  array (
    'enable-input' => true,
    'title-tick' => true,
  ),
  'redstone' =>
  array (
    'enabled' => true,
    'world-policy' => 'all',
    'worlds' =>
    array (
    ),
    'max-wire-network-size' => 65536,
    'max-scheduled-updates' => 262144,
    'max-updates-per-tick' => 8192,
    'pistons-enabled' => true,
    'max-piston-actions-per-tick' => 1024,
    'dispensers-enabled' => true,
    'max-dispenser-actions-per-tick' => 4096,
  ),
  'tnt-limits' =>
  array (
    'enabled' => false,
    'max-active-per-chunk' => 64,
    'max-active-nearby' => 192,
    'max-ignitions-per-chunk-per-tick' => 24,
    'max-dispenser-ignitions-per-second' => 20,
  ),
  'plugins' =>
  array (
    'legacy-data-dir' => false,
  ),
);
