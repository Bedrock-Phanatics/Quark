# Pulse performance metrics

Use `/pulse start [time]` to record, `/pulse status` to inspect metrics, and `/pulse stop` to stop and save a `.qpulse` report. `/pulse report` saves a snapshot while recording continues.

- Status includes session tick average, worst tick, and ticks exceeding the 50 ms budget. Recent p50, p95, and p99 use the latest 1,200 completed ticks, with the sample count displayed.
- `/pulse top [1-10]` lists main-thread zones by self time. `/pulse spikes [1-10]` lists the slowest retained ticks above 50 ms and each tick's largest measured self cost. Spike details retain the latest 32 spikes; status counts discarded spikes in the session total. Measured costs describe instrumented work and may not explain the entire stall.
- Process CPU includes worker and other server threads. **100% means one fully occupied core**, so values can exceed 100%. Current, average, and peak refer to retained resource samples. CPU requires an interval of at least one second; shorter stop intervals still record memory but have unavailable CPU values. This avoids false spikes from coarse system counters. Known averages and peaks remain available when the final CPU sample is unavailable.
- Memory shows main-thread PHP heap usage, allocated heap capacity, and the highest sampled usage in retained history. It excludes native allocations and worker heaps; it is not process RSS.

Resources are sampled at tick completion, at most once per second, with a final sample when recording stops. A stalled tick delays sampling, so its CPU sample averages the whole interval. Up to 1,200 samples are kept in a ring; old samples are counted as discarded. Disabled tick and zone hooks do not read resources. There is no CPU read per zone or background sampler. Percentiles and resource summaries are computed when status is requested.

## Report format

New reports use `quark.pulse` version 3; decoding versions 1 and 2 remains supported. Times are integer nanoseconds. Version 3 adds `slow_tick_count` to each thread and `resources`, which is `null` on workers. Main-thread `resources` contains `samples` and `samples_dropped`.

Each resource row is `[session_offset_ns, interval_ns, process_user_cpu_ns, process_system_cpu_ns, php_memory_bytes, php_allocated_bytes]`. CPU values are deltas over the sample interval and are both `null` when unavailable. The initial row has offset and interval zero with unavailable CPU deltas. Samples remain chronological after wraparound and share the report's row and byte budgets.
