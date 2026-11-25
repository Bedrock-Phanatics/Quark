<?php

// Generated from resources/server.toml; run composer update-codegen after editing defaults.
return array (
  'config-version' => 2,
  'server' =>
  array (
    'motd' => 'Quark Server',
    'max-players' => 20,
    'view-distance' => 16,
    'language' => 'eng',
    'force-language' => false,
    'whitelist' => false,
    'shutdown-message' => 'Server closed',
    'enable-dev-builds' => false,
  ),
  'gameplay' =>
  array (
    'gamemode' => 'SURVIVAL',
    'force-gamemode' => false,
    'difficulty' => 2,
    'hardcore' => false,
    'pvp' => true,
    'combat-low-latency-feedback' => true,
  ),
  'player' =>
  array (
    'save-data' => true,
    'verify-xuid' => true,
  ),
  'network' =>
  array (
    'bind-address' => '',
    'bind-address-v6' => '',
    'port' => 19132,
    'port-v6' => 19133,
    'enable-ipv6' => true,
    'enable-query' => true,
    'query-plugins' => true,
    'xbox-auth' => true,
    'enable-encryption' => true,
    'trusted-proxy-keys' =>
    array (
    ),
    'proxy-only' => false,
    'upnp-forwarding' => false,
    'compression' =>
    array (
      'algorithm' => 'zlib',
      'threshold' => 256,
      'level' => 6,
      'async' => false,
      'async-threshold' => 10000,
    ),
  ),
  'transport' =>
  array (
    'name' => 'nethernet',
    'nethernet' =>
    array (
      'key-file' => 'data/nethernet-identity.der',
      'lan-discovery' => true,
      'ice-udp-mux' => false,
      'ice-servers' =>
      array (
      ),
      'unreliable-events' => false,
    ),
    'raknet' =>
    array (
      'max-mtu-size' => 1492,
    ),
  ),
  'world' =>
  array (
    'name' => 'world',
    'seed' => '',
    'generator' => 'DEFAULT',
    'generator-settings' => '',
    'auto-save' => true,
    'autosave-interval' => 6000,
    'default-format' => 'leveldb',
    'format-migration' => 'manual',
  ),
  'chunks' =>
  array (
    'send-per-tick' => 4,
    'spawn-radius' => 4,
    'tick-radius' => 3,
    'unload-view-only-chunks' => true,
    'random-ticks-per-subchunk' => 3,
    'disabled-random-tick-blocks' =>
    array (
    ),
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
  'block-physics' =>
  array (
    'fast-falling-blocks' => false,
  ),
  'weather' =>
  array (
    'cycle' => true,
    'lightning-chance' => 100000,
    'lightning-fire' => true,
  ),
  'performance' =>
  array (
    'async-workers' => 'auto',
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
    'dump-async-workers' => true,
    'low-memory-view-distance' => 4,
    'garbage-collection' =>
    array (
      'php-automatic' => true,
      'threshold' => 10001,
      'period' => 36000,
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
  'diagnostics' =>
  array (
    'enable-profiling' => false,
    'max-crash-reports' => 0,
    'max-profiles' => 0,
    'pulse-auto-capture-ms' => 0,
  ),
  'crash-dumps' =>
  array (
    'include-code' => true,
    'include-settings' => true,
    'include-phpinfo' => false,
  ),
  'auto-updater' =>
  array (
    'enabled' => false,
    'preferred-channel' => 'stable',
    'host' => '',
    'warn-console' => true,
  ),
  'plugins' =>
  array (
    'mode' => 'blacklist',
    'names' =>
    array (
    ),
  ),
);
