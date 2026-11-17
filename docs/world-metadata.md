# World metadata

Plugins can store their own data on block positions, chunks and whole worlds, without tile entities or a database of
their own. The data is saved with the world and stays on the server; clients never see it.

```php
use quark\world\metadata\MetadataCodec;
use quark\world\metadata\MetadataKey;
use quark\world\metadata\Retention;

// once, in onEnable()
$this->owner = MetadataKey::create($this, "owner", MetadataCodec::string(), Retention::BLOCK_TYPE);

$metadata = $world->getMetadata();
$metadata->setBlock($x, $y, $z, $this->owner, $player->getUniqueId()->toString());
$metadata->getBlock($x, $y, $z, $this->owner); // ?string
$metadata->removeBlock($x, $y, $z, $this->owner);

$metadata->setChunk($chunkX, $chunkZ, $this->owner, "...");
$metadata->set($this->owner, "..."); // the whole world
```

## Keys

A key belongs to the plugin that creates it and is stored as `pluginname:path`, so plugins can't overwrite each other's
values. Codecs turn values into bytes: `string()`, `int()`, `float()`, `bool()` and `compound()` (NBT) are built in,
and `new MetadataCodec($encode, $decode)` takes any pair of closures. Nothing is ever deserialized by class name.

Values of keys that are not registered, for example because their plugin is not installed, are kept and saved
unchanged. Disabling or removing a plugin deletes nothing; `removeNamespace("pluginname")` deletes everything a plugin
stored in a world. Loaded chunks lose the values immediately and the rest when they next load.

Limits: 64 KiB per value and about 1 MiB per chunk. Going over throws and changes nothing.

## Retention

Each key says what happens to block values when the block changes through the world (`setBlock`, `setChunk`,
explosions, pistons, block transactions and everything else built on them):

| Retention | Kept through | Removed by |
|---|---|---|
| `POSITION` | everything | `removeBlock()`, `clearChunk()` |
| `BLOCK_TYPE` | state changes such as rotation, waterlogging or growth | a block of another type, including air |
| `BLOCK_STATE` | nothing | any state change |

Breaking a block and placing the same type again removes `BLOCK_TYPE` values, since the block passed through air.
Replacing a chunk with `World::setChunk()` keeps values of blocks that didn't change and applies retention to the rest,
which covers population, generation and async editors that hand back whole chunks. Editing a loaded `Chunk` object
directly bypasses retention; go through the world.

Pistons move block-bound values with the block, `POSITION` values stay behind. To move or copy blocks yourself,
`takeBlock()` the values before removing the old block and `putBlock()` them after placing the new one, or use
`copyBlock()`, which leaves out keys created with `copyable: false` so unique values such as IDs are not duplicated.
The `BlockMetadata` snapshots these return work across worlds. `getBlocks($chunkX, $chunkZ)` lists the positions of a
chunk that hold values without scanning its blocks.

## Storage and guarantees

LevelDB and ZigriteDB worlds store a chunk's metadata as a record of the chunk, written in the same atomic write as
its terrain, so after a crash a chunk's blocks and metadata always come from the same save. World values are a
separate record written by world saves. Anvil, PMAnvil and McRegion worlds are read-only and are converted before they
load; `getMetadata()` throws for a world in a format that can't store metadata rather than dropping it.

- Values are written when their chunk is saved, like blocks: by autosaves, `World::save()`, chunk unloads and
  shutdown. A value set in a world with autosave off survives only a forced save. Changing only metadata writes only
  the metadata record, and setting a value it already has writes nothing.
- A failed save keeps the changes for the next one, and a chunk that fails to save stays loaded.
- Reading or writing a value in an unloaded chunk loads it synchronously. Reads in ungenerated terrain return null,
  writes there throw.
- A record that can't be read is logged and kept as stored: reads and writes of that chunk throw `MetadataException`
  instead of acting as if it were empty, and saves never overwrite it. `clearChunk()` discards it. An unreadable world
  record makes the world's metadata unavailable until it is repaired.
- A chunk whose terrain is corrupted and regenerated loses its metadata along with its blocks and tiles.
- `/world convert` between LevelDB and ZigriteDB keeps every record, which needs ext-zigritedb 0.3.3. Bedrock itself
  and other external tools don't know the records and may drop them.
