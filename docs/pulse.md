# Pulse performance metrics

Use `/pulse start [time]` to record, `/pulse status` to inspect metrics, and `/pulse stop` to stop and save a `.qpulse` report. `/pulse report` saves a snapshot while recording continues.

- Status includes session tick average, worst tick, and ticks exceeding the 50 ms budget. Recent p50, p95, and p99 use the latest 1,200 completed ticks, with the sample count displayed.
- `/pulse top [1-10]` lists main-thread zones by self time, after a line summing self time by category. `/pulse spikes [1-10]` lists the slowest retained ticks above 50 ms with each tick's largest measured self cost and its top categories. Spike details retain the latest 32 spikes; status counts discarded spikes in the session total. Measured costs describe instrumented work and may not explain the entire stall.
- Process CPU includes worker and other server threads. **100% means one fully occupied core**, so values can exceed 100%. Current, average, and peak refer to retained resource samples. CPU requires an interval of at least one second; shorter stop intervals still record memory but have unavailable CPU values. This avoids false spikes from coarse system counters. Known averages and peaks remain available when the final CPU sample is unavailable.
- Memory in status shows main-thread PHP heap usage, allocated heap capacity, and the highest sampled usage in retained history. `/pulse memory` also shows process RSS.

Resources are sampled at tick completion, at most once per second, with a final sample when recording stops. A stalled tick delays sampling, so its CPU sample averages the whole interval. Up to 1,200 samples are kept in a ring; old samples are counted as discarded. Disabled tick and zone hooks do not read resources. There is no CPU read per zone or background sampler. Percentiles and resource summaries are computed when a command asks for them.

## Categories and unexplained time

Spike and category summaries group zones by name: plugins, entities, tiles, hoppers, redstone, world (block updates, light, chunk GC), chunk loading, chunk generation, chunk storage (saves and serialization), chunk sending, network, compression, scheduler, async results, events, commands, gc, interrupts, and other. Zones owned by a plugin always count as plugins. World zones are grouped by suffix, so world names do not matter.

**Unattributed** time is spent inside a tick but outside every child zone: the self time of `server.tick` plus any tick time no zone covers. It is unexplained, not a guess; add zones if it dominates. Breakdowns use the self time stored with each retained spike, so they cost nothing per tick beyond the existing spike capture.

## Plugins

`/pulse plugins [1-10]` ranks plugins by main-thread time in event handlers, scheduled tasks, commands, and enable/disable callbacks.

- **Total** counts each plugin's outermost callbacks. Callbacks nested inside the same plugin are not counted twice. Total includes server work and other plugins' handlers that the callback triggers.
- **Self** excludes every nested measured zone and never overlaps between plugins.
- **Calls**, **avg**, and **max** describe outermost callbacks. The share is total divided by measured tick time.

Ownership comes from the plugin that registered the handler, task, or command. Pulse keeps plugin names, never plugin objects. Async task completions are not attributed to plugins, because tasks carry no owner.

## Async workers

`/pulse workers` shows running and busy workers and the tasks queued or running now, read from the pool's existing queues. While recording, tasks submitted to the main async pool are timestamped:

- **Queue wait**: submission to the start of `onRun()` on the worker.
- **Run**: `onRun()` duration on the worker.
- **Main-thread pickup**: end of `onRun()` to result collection on the main thread. High values mean the main thread is falling behind.
- **Peak queue**: the longest queue on one worker right after a submission.
- **Busy**: per-worker run time divided by the recording length.
- **Heap**: worker PHP heap after its last recorded task, and the highest such value.

Tasks queued before the session started are ignored, so restarts never mix sessions. Tasks still running when recording stops count as submitted but not completed. Quark async tasks cannot be cancelled, and a task that throws crashes its worker, so there are no cancelled or failed counters. Control and report-writing tasks are excluded.

## Memory

`/pulse memory` separates:

- **PHP heap (main thread)**: `memory_get_usage()`, allocated capacity, sampled peak, and growth since the session started.
- **Process RSS**: resident memory from `/proc/self/status` (`VmRSS`), growth since start, and the process peak since launch (`VmHWM`). RSS includes native allocations, thread stacks, and mapped libraries. It is not native heap usage, and Pulse does not estimate one. RSS is unavailable outside Linux.
- **Worker heaps**: sampled after each recorded task, as described above.

RSS is read only with the once-per-second resource sample.

## Network

`/pulse network` summarizes the receive counters Pulse already records per window, plus:

- Main-thread network, compression, and chunk sending self time from the existing zones.
- Transport counters, as deltas over the session. NetherNet (bedwire) reports native bytes, batches sent, packets received and rejected, dropped unreliable packets, receive pauses (backpressure), event stalls, and overload disconnects, plus peak active sessions and peak command and event queue bytes. RakNet reports bytes sent and received. Counters come from statistics the interfaces already poll each tick; no packet contents or session identifiers are stored.

## Automatic capture

Set `diagnostics.pulse-auto-capture-ms` in `server.toml` to record automatically after a tick slower than that many milliseconds. Pulse then records for 60 seconds, retains spikes above the same threshold, and saves a report when the recording expires. At most one capture starts per 10 minutes, and `diagnostics.max-profiles` still bounds stored reports. The slow tick that triggers a capture is not itself recorded. An automatic capture never replaces a manual session. A manual `/pulse stop` during an automatic capture saves the report as usual. Zero, the default, disables the feature. When disabled, it adds one integer comparison per tick.

## Report format

New reports use `quark.pulse` version 4; decoding versions 1, 2, and 3 remains supported. Times are integer nanoseconds. Version 3 adds `slow_tick_count` to each thread and `resources`, which is `null` on workers. Main-thread `resources` contains `samples` and `samples_dropped`.

Each version 3 resource row is `[session_offset_ns, interval_ns, process_user_cpu_ns, process_system_cpu_ns, php_memory_bytes, php_allocated_bytes]`. CPU values are deltas over the sample interval and are both `null` when unavailable. The initial row has offset and interval zero with unavailable CPU deltas. Samples remain chronological after wraparound and share the report's row and byte budgets.

Version 4 adds:

- Two resource columns, `rss_bytes` and `rss_peak_bytes`, which are both `null` when unavailable.
- `zone_owners` on every thread: `[zone_index, plugin_full_name]` rows in ascending zone order, at most one per zone.
- `async` on every thread: `null` on workers. On the main thread it contains `submitted`, `completed`, `queue_peak`, `wait_total_ns`, `wait_max_ns`, `run_total_ns`, `run_max_ns`, `delay_total_ns`, `delay_max_ns`, and `workers`. `workers` holds `[worker_id, completed, run_ns, heap_bytes, heap_peak_bytes]` rows for up to 128 workers.
- `network.transports`: up to one entry per transport (`raklib`, `bedwire`), each `{name, counters}` with up to 64 `[name, value]` counters. Counter names match `[a-z][a-z0-9_]{0,63}`.

Decoding checks types, ordering, totals (for example, completed tasks cannot exceed submissions), and list sizes. New rows count toward the existing row and text budgets.
