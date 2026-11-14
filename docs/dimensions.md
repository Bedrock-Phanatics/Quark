# Dimension API

Every world belongs to a dimension. A dimension is an immutable type shared by any number of worlds: its height
range, whether it has sky light and weather, and whether beds and respawn anchors work. Worlds that don't name one,
including every world created before dimensions existed, are overworld.

```php
use quark\world\Dimension;
use quark\world\DimensionRegistry;
use quark\world\WorldCreationOptions;
use quark\world\generator\hell\Nether;

$nether = DimensionRegistry::getInstance()->get(Dimension::NETHER);

$server->getWorldManager()->generateWorld("nether", WorldCreationOptions::create()
    ->setGeneratorClass(Nether::class)
    ->setDimension($nether)
);

$world->getDimension()->identifier; // "minecraft:nether"
```

The dimension does not pick a generator, so set one that fits. Teleporting a player into a world of another dimension
is an ordinary `$player->teleport($position)`.

Servers can also set it in `config/server.toml` for worlds created at startup, and with `/world create <name>
[generator] [seed] [dimension]`:

```toml
[worlds.nether]
generator = "nether"
dimension = "minecraft:nether"
```

## Built-in dimensions

| Identifier            | Client dimension | Height    | Sky light | Weather | Beds    | Respawn anchors |
|-----------------------|------------------|-----------|-----------|---------|---------|-----------------|
| `minecraft:overworld` | overworld        | -64..320  | yes       | yes     | work    | explode         |
| `minecraft:nether`    | nether           | 0..128    | no        | no      | explode | work            |
| `minecraft:the_end`   | end              | 0..256    | no        | no      | explode | explode         |

What Quark enforces for a world from its dimension:

- `minY` and `maxY` are the world's build limits (`World::getMinY()`, `getMaxY()`, `isInWorld()`), and entities take
  void damage 16 blocks below `minY`.
- Without `skyLight`, sky light reads as 0 everywhere, so crops, daylight sensors and other light checks only see
  block light.
- Without `weather`, the weather stays clear, the weather cycle is off and `World::setWeather()` returns false for rain
  and thunder.
- Without `bedWorks`, using a bed blows it up like in vanilla. With `respawnAnchorWorks`, a charged anchor sets the
  player's spawn by default instead of exploding. Both go through the existing explode and anchor events.

## Custom dimensions

Bedrock clients only know the overworld, nether and end. A custom dimension is shown to the client as one of them,
picked by its network ID, and that decides the sky, fog and ambience players see. Its height range must be 16-aligned
and fit inside that client dimension's range (overworld -64..320, nether 0..128, end 0..256).

```php
use pocketmine\network\mcpe\protocol\types\DimensionIds;

public function onLoad() : void{
    DimensionRegistry::getInstance()->register(new Dimension(
        "myplugin:skyblock",
        DimensionIds::OVERWORLD,
        minY: 0,
        maxY: 256,
        weather: false
    ));
}
```

Worlds store the identifier, so register in `onLoad()`, before worlds load. A world whose dimension is not registered
fails to load with an error instead of being read as another dimension. Identifiers can't be registered twice or
unregistered.

## Dimension changes

When a player moves to a world whose client dimension differs, Quark sends the change, resends the destination chunks
and keeps the client on its loading screen until the spawn area has arrived, as on join. Input sent from the loading
screen is ignored. The client can't take a second change while it is still loading one, so a change requested
meanwhile is sent once the loading screen closes. Moving between worlds of the same client dimension works as before,
without a loading screen.

Inventory, effects, gamemode, abilities and other player state live on the server and are untouched by the change.

## Limitations

- Portals don't move players between worlds yet; plugins can do it with `teleport()`. Coordinate scaling is up to them.
- Time keeps running in every dimension. The nether and end clients ignore it for their sky.
- Sky light is still calculated in dimensions without it, only never read.
- Custom client-side height ranges (`DimensionDataPacket`) are not sent, so a custom dimension uses its client
  dimension's height on the client and Quark rejects blocks outside its own range.
- Dimension changes were tested against the protocol flow, not with a live client.
