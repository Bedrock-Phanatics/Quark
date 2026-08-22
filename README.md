# Quark

**Quark** is a high-performance Minecraft: Bedrock Edition server software based on [PocketMine-MP](https://github.com/pmmp/PocketMine-MP) and [Axolotl](https://github.com/Axolotl-MP/Axolotl).

Quark focuses on improving performance, reducing unnecessary PHP overhead, and moving performance-critical workloads to native extensions such as C and C++ where it makes sense.

The goal is to retain the flexibility and plugin ecosystem of PocketMine while providing a faster and more efficient foundation for production servers.

## Goals

- Improve overall server performance and efficiency
- Reduce overhead in frequently executed code paths
- Utilize native C/C++ extensions for performance-sensitive workloads
- Maintain compatibility with the PocketMine plugin ecosystem where possible
- Fix bugs and inconsistencies found upstream
- Keep changes clean, measurable, and maintainable
- Move non-essential vanilla features into optional plugins to eliminate unnecessary overhead on servers that do not use them

## Changes

Compared to upstream PocketMine-MP / Axolotl, Quark currently includes:

### Performance

- Added optional **Snappy compression** support
- Increased use of native extensions where they provide a meaningful performance benefit

### Gameplay

- Removed the runtime effects and acquisition paths for **Thorns** and **Frost Walker**. Existing items retain inert enchantment data for save and network compatibility.
- Removed per-tick worn-item callbacks from armor processing.
- Turtle helmets now behave as ordinary helmets and no longer grant Water Breathing.

### Fixes

- Fixed absorption heart client/server desynchronization

### API

- Updated some `EntityDamageEvent` damage modifier constant values
- Added broader `ProtectedDamage` and `FinalDamage` API for more configurable damage systems
- Added better Garbage Collector Configurability (and option to disable)

> [!NOTE]
> Plugins using the provided `EntityDamageEvent` constants should continue to work normally. Plugins relying directly on the previous integer values may require changes.

## Philosophy

Quark does not aim to replace PHP with native code everywhere.

Native implementations are used primarily when they provide a measurable advantage over equivalent PHP implementations, particularly for:

- Compression
- Encoding and decoding
- Serialization
- Database operations
- Network-related processing
- Other CPU-intensive hot paths

This allows Quark to retain PocketMine's developer-friendly PHP environment while taking advantage of native performance where it matters most.

## Compatibility

Quark is designed to remain compatible with PocketMine-MP plugins whenever practical.

However, because Quark may modify internals and APIs in pursuit of performance and correctness, complete compatibility with every PocketMine plugin is not guaranteed.

Plugins should avoid relying on undocumented behavior, internal implementation details, or hardcoded constant values.

## Requirements

Quark may require additional PHP extensions compared to standard PocketMine-MP depending on the enabled features.

Optional features such as Snappy compression require their corresponding native extension.

## Contributing

Contributions focused on performance, correctness, or maintainability are welcome.

Performance-related changes should ideally:

- Target a measurable bottleneck
- Avoid unnecessary complexity
- Maintain correctness
- Include benchmarks when appropriate
- Avoid sacrificing stability for negligible performance gains

## Credits

Quark is built upon the work of:

- [PocketMine-MP](https://github.com/pmmp/PocketMine-MP)
- Axolotl and its contributors
- The wider PocketMine ecosystem

Quark includes modifications and optimizations developed specifically for this project.

## License

Quark retains the licensing requirements of the upstream projects and any components it incorporates.
