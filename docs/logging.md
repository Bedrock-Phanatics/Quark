# Logging

`MainLogger` implements the PMMP logger and attachment APIs. String calls filter before timestamping or serializing an event. Producers cache their thread identity and enqueue primitive records in `EventQueue`. One dispatcher thread batches records and owns the file sinks. Formatters and sinks are separate interfaces.

Console output goes through a nonblocking socket to a small PHP child process. This isolates blocking Windows and Unix terminal writes without adding another thread per sink. The Windows console input reader is unchanged. Input remains line based and asynchronous output does not redraw partially typed text.

```php
$logger->info("World loaded");
$logger->event("warning", "Chunk loading delayed", "World", ["world" => $name, "chunk_x" => $x, "chunk_z" => $z]);
$logger->logException($exception);
```

`SourceLogger` supplies subsystem names without parsing message prefixes. Plugin attachments still receive their original messages. Main logger attachments receive clean formatted text on the producer thread, outside transport and output locks. Slow attachments can still delay their caller. Recursive attachment dispatch is suppressed.

## Console and configuration

The default console uses aligned time, severity, source, and message columns. Only timestamps and severity labels receive ANSI colors. Multiline continuations share one prefix. `console-style = 'compact'` reduces padding for narrow terminals. File logs retain full source and thread names. Human-readable output caps continuations at 1,024 lines and marks truncation. JSON retains the bounded original message.

Settings live in `[logging]` in `config/server.toml`. Defaults and validation come from `resources/server.toml`, `LoggingConfig`, and the generated config schema.

- `console-level`, `file-level`, `json-level`: emergency, alert, critical, error, warning, notice, info, or debug
- `color`: auto, always, or never
- `console-style`: columns or compact
- `json`: also write `logs/server.log.jsonl`
- `buffer-bytes`: serialized queue payload budget, 256 KiB to 64 MiB, default 8 MiB
- `flush-ms`: routine file flush interval, default 200 ms
- `max-file-size`, `max-archives`: rotation size and archive retention, zero archives keeps all archives

`--disable-ansi` wins over `--enable-ansi`. Explicit CLI settings override the configured color mode. Auto mode respects `NO_COLOR`, redirection, and `TERM=dumb`. No shell capability probes run during rendering. `--no-log-file` disables both persistent sinks.

Command input uses `CMD` and responses use `OUT`. Both remain visible regardless of the console log threshold. File thresholds still apply. Explicit sink levels also take precedence over the legacy debug flag. Audit events record only registered command names and omit every argument. Unknown commands use a fixed placeholder. Watchdog diagnostics bypass the console and severity filtering so stalled-terminal output cannot suppress persistent diagnostics.

## Backpressure, flushing, and failures

Acceptance order defines FIFO ordering. Timestamps are captured before acceptance, so cross-thread timestamps need not be ordered. A buffer closure groups chunks of up to 64 events or roughly 256 KiB without holding a lock during application callbacks.

The queue caps both serialized bytes and record count. Routine traffic can use 75% of its byte and record budgets. Errors and diagnostics can use the reserve. Overflow drops new routine events and reports cumulative counts. Important events that cannot enter the queue synchronously append to `server.log.emergency`, outside queue locks. This rare fallback may block on storage. If the emergency file fails, PHP's emergency error output is used. Emergency files are append-only to preserve diagnostics and need external retention when a persistent failure lasts a long time.

The console has a separate 4 MiB pending-output budget. Its overflow counts are exposed through `$logger->getDispatcher()->getQueue()->stats()` and reported to persistent sinks. A permanently blocked console can lose output, including its final pending bytes. It cannot prevent file diagnostics from being written. Failed file sinks are disabled, explicit flushes report the failure, and console dispatch continues. Subsequent important events use emergency persistence. Exception logging catches flush failures and writes its original exception through the fallback path.

A successful `syncFlushBuffer()` confirms that events accepted before its watermark reached enabled file sinks and `fflush()` completed. It does not promise an `fsync()` durability barrier or terminal display acknowledgment. Errors and diagnostics request immediate file flushing. Each flush or shutdown wait has a five-second deadline. Console shutdown allows 500 ms before terminating its helper. Shutdown rejects later routine events, accounts for them, and routes later important events to fallback. PHP cannot cancel a kernel filesystem operation already in progress, so a blocked disk can require external process termination. Existing server shutdown supervision remains in place.

Messages are limited to 64 KiB. Context permits up to 32 finite scalar fields with bounded keys and strings. Exception chains permit eight causes and bounded traces. Cross-thread records never serialize plugin objects. Text, JSONL, emergency records, and crash-report rendering strip terminal controls and Minecraft presentation codes.

## Verification and benchmarks

Run the logging tests with the repository's PHP runtime and pmmpthread:

```text
php vendor/bin/phpunit --bootstrap vendor/autoload.php --fail-on-warning tests/phpunit/LoggingPipelineTest.php tests/phpunit/LoggingFilesystemTest.php tests/phpunit/pulse/PulseNetworkWatchdogTest.php
php tests/benchmarks/logging.php --count=4000 --bytes=256 --producers=4
php tests/benchmarks/logging.php --micro
```

Redirect benchmark stdout to the platform null device to measure a fast console. Reports go to stderr. `--root=/path/to/baseline` runs the same workload against an older checkout and its dependencies. A warm-up event and 100 ms settling interval precede measurement on both implementations. Defaults flush every 500 attempted calls on each producer so throughput comparisons retain all events. `--batch=0` measures an unpaced burst and exits unsuccessfully if workload events are lost. Reports distinguish producer latency, completion and flush time, actual persisted events, queue losses, and peak PHP heap per producer. Heap figures exclude native allocations and the console helper process. `producer_phase_ms` includes the scheduled flush checkpoints and producer-thread startup. Per-call samples measure the log invocation itself. Microbenchmarks measure the new formatters and direct buffered file sink separately.

The available Windows runtime lacks ext-bedwire. The available Linux native runtime passes the logging and migration checks but cannot complete the full suite: its NBT test also segfaults on the unchanged base branch and its protocol API lacks `Bedwire\Protocol::bind()`. Bootstrap correctly rejects its ext-bedwire 0.3.0 because Quark requires at least 0.3.13. Full server gameplay and manual interactive-console validation therefore remain unverified.

### Recorded results

Measured on Windows with PHP 8.4.26, comparing base `c9434fe42` with this implementation. These are local measurements, not game-server tick benchmarks. Both versions enabled plain console output redirected to NUL and human-readable file logging. Each case used three runs of 4,000 attempted calls per producer, including 5% filtered debug calls, 2% errors, 10% warnings, and 83% informational messages. Throughput is actual persisted workload events divided by time through the final file flush, with checkpoint flushing every 500 calls. Every candidate run persisted all expected events with zero queue drops, emergency fallbacks, or counted console overflows.

| Nominal bytes | Producers | Before events/s | After events/s | Before mean call µs | After mean call µs | Before worker p99 µs | After worker p99 µs |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 64 | 1 | 76,003 | 132,320 | 11.86 | 4.28 | 33.9 | 32.3 |
| 64 | 4 | 42,011 | 115,427 | 86.70 | 15.32 | 373.0 | 105.3 |
| 256 | 1 | 68,833 | 128,059 | 13.11 | 4.30 | 31.9 | 26.8 |
| 256 | 4 | 45,286 | 126,239 | 80.78 | 13.74 | 222.9 | 85.8 |
| 2048 | 1 | 33,754 | 91,017 | 27.31 | 4.23 | 68.1 | 19.8 |
| 2048 | 4 | 32,974 | 85,588 | 112.22 | 12.55 | 271.0 | 81.5 |

Rates and mean-call times are medians of the three runs. Tail values are medians of worker reports, sampled once per sixteen calls, not an aggregate system p99. A follow-up batch comparison retained 64 events because smaller batches reduced throughput. Tail timings remain sensitive to scheduling.

Per-producer peak PHP heap was 2 MiB in both versions for most cases, rising to 4 MiB for the candidate's single-producer 2 KiB case. This excludes native queues and the console helper. Median final checkpoint-free flushes were approximately 0.04–0.05 ms before and 0.06–0.07 ms after. This small absolute increase accompanies explicit watermark coordination.

The new formatter microbenchmarks measured approximately 1.02 µs per plain console event, 1.20 µs with ANSI, and 1.34 µs for JSON. The direct buffered file sink measured approximately 864k small structured events/s, including its final flush. These isolated measurements exclude transport, concurrency, and console work and must not be substituted for end-to-end throughput.

Final Windows logging/watchdog tests (30 tests), the packaged-logger Phar smoke test, and focused PHPStan passed. The Linux logging, migration, and watchdog tests passed (71 tests). An earlier full repository PHPStan run passed, while a later full rescan reached its 180-second limit. The final focused analysis covers the changed logging implementation and regression tests.
