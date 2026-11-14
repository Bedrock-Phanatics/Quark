<?php

// Generated from resources/server.toml; run composer update-codegen after editing defaults.
return array (
  'config-version' => 1,
  'server' =>
  array (
    'motd' => 'Quark Server',
    'max-players' => 20,
    'language' => 'eng',
    'gamemode' => 'SURVIVAL',
    'force-gamemode' => false,
    'difficulty' => 2,
    'hardcore' => false,
    'pvp' => true,
    'server-ip' => '',
    'server-ipv6' => '',
    'server-port' => 19132,
    'server-portv6' => 19133,
    'enable-ipv6' => true,
    'enable-query' => true,
    'white-list' => false,
    'xbox-auth' => true,
    'level-name' => 'world',
    'level-seed' => '',
    'level-type' => 'DEFAULT',
    'generator-settings' => '',
    'auto-save' => true,
    'view-distance' => 16,
  ),
  'settings' =>
  array (
    'force-language' => false,
    'shutdown-message' => 'Server closed',
    'query-plugins' => true,
    'enable-dev-builds' => false,
    'enable-profiling' => false,
    'async-workers' => 'auto',
  ),
  'transport' =>
  array (
    'name' => 'nethernet',
    'nethernet' =>
    array (
      'key-file' => 'nethernet-identity.der',
      'lan-discovery' => true,
      'ice-udp-mux' => false,
      'ice-servers' =>
      array (
      ),
      'unreliable-events' => false,
    ),
  ),
  'network' =>
  array (
    'max-mtu-size' => 1492,
    'upnp-forwarding' => false,
    'enable-encryption' => true,
    'trusted-proxy-keys' =>
    array (
    ),
    'compression-algorithm' => 'zlib',
    'batch-threshold' => 256,
    'compression-level' => 6,
    'async-compression' => false,
    'async-compression-threshold' => 10000,
    'combat-low-latency-feedback' => true,
  ),
  'player' =>
  array (
    'save-player-data' => true,
    'verify-xuid' => true,
  ),
  'level-settings' =>
  array (
    'default-format' => 'leveldb',
    'format-migration' => 'manual',
  ),
  'ticks-per' =>
  array (
    'autosave' => 6000,
  ),
  'chunk-sending' =>
  array (
    'per-tick' => 4,
    'spawn-radius' => 4,
  ),
  'chunk-ticking' =>
  array (
    'tick-radius' => 3,
    'unload-view-only-chunks' => true,
    'blocks-per-subchunk-per-tick' => 3,
    'disable-block-ticking' =>
    array (
    ),
  ),
  'chunk-generation' =>
  array (
    'population-queue-size' => 32,
  ),
  'entities' =>
  array (
    'item-merge-radius' => 0.5,
    'xp-orb-merge-radius' => 0.5,
    'activation-range' => 0,
    'inactive-tick-interval' => 20,
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
  'hoppers' =>
  array (
    'enabled' => true,
    'transfer-cooldown' => 8,
    'items-per-transfer' => 1,
    'max-updates-per-tick' => 512,
  ),
  'tnt-limits' =>
  array (
    'enabled' => false,
    'max-active-per-chunk' => 64,
    'max-active-nearby' => 192,
    'max-ignitions-per-chunk-per-tick' => 24,
    'max-dispenser-ignitions-per-second' => 20,
  ),
  'weather' =>
  array (
    'cycle' => true,
    'lightning-chance' => 100000,
    'lightning-fire' => true,
  ),
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
      'php-automatic' => true,
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
  'logging' =>
  array (
    'console-level' => 'info',
    'file-level' => 'info',
    'json-level' => 'info',
    'console-style' => 'columns',
    'json' => false,
    'buffer-bytes' => 8388608,
    'flush-ms' => 200,
    'max-file-size' => 33554432,
    'max-archives' => 0,
  ),
  'console' =>
  array (
    'enable-input' => true,
    'title-tick' => true,
  ),
  'crash-dumps' =>
  array (
    'include-code' => true,
    'include-settings' => true,
    'include-phpinfo' => false,
  ),
  'diagnostics' =>
  array (
    'max-crash-reports' => 0,
    'max-profiles' => 0,
    'pulse-auto-capture-ms' => 0,
  ),
  'auto-updater' =>
  array (
    'enabled' => false,
    'preferred-channel' => 'stable',
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
  'plugins' =>
  array (
    'legacy-data-dir' => false,
  ),
);
