# Camera API

Quark v6 exposes a per-player controller in `quark\camera`. Calls queue packets immediately; they do not wait for client interpolation or acknowledge client camera state.

```php
use quark\camera\CameraEase;
use quark\camera\CameraInstruction;
use quark\camera\CameraPreset;

$camera = $player->getCamera();
$camera->set(CameraPreset::FREE, position: $position, facing: $target,
    ease: CameraEase::IN_OUT_SINE, duration: 2.0);
$camera->fade(in: 0.5, stay: 1.0, out: 0.5);
$camera->fov(90, duration: 1.0);
$camera->fov(null); // Restore the player's FOV setting.
$camera->clear();
```

Use `pitch` and `yaw` together instead of `facing` to set explicit rotation. Angles are degrees; pitch is -90..90 and yaw -180..180. A duration of zero is immediate. Easing applies to both camera position and rotation; the active packet has no independent rotation-easing field. Standard gameplay perspectives reject free-camera transforms and eased transitions.

## Presets

Register definitions during plugin startup, before the first player finishes spawning:

```php
$server->getCameraPresets()->register(new CameraPreset(
    "example:overview",
    parent: CameraPreset::FREE,
    yPosition: 80,
    pitch: 30,
    playerEffects: false
));
$camera->set("example:overview");
```

Definitions are immutable, including snapshots of mutable vector inputs. Constructor names mirror the active protocol: optional coordinates/rotation, rotation speed, snap/continue targeting, horizontal/vertical rotation limits, block listening radius, view/entity offsets, radius, yaw limits, audio listener, player effects, aim assist, control scheme, starting rotation and inherited starting rotation. `null` omits an optional field; explicit `false` and zero retain their meanings.

Built-ins have constants for `first_person`, `third_person`, `third_person_front`, `free`, `follow_orbit`, `fixed_boom`, and `control_scheme_camera`. Custom identifiers must use their own namespace. Parents may be registered in any order; missing parents and cycles fail before synchronization. The complete list is sorted by identifier and frozen at the first synchronization. IDs cannot shift between players. Runtime registration/replacement and plugin hot reload of definitions are intentionally rejected; restart to change definitions.

## Entities and effects

```php
$camera->track($entity, $offset);
$camera->send(CameraInstruction::stopTracking());
$camera->attach($entity);
$camera->send(CameraInstruction::detach());
$camera->send(CameraInstruction::shake(0.4, 2.0, rotational: true));
$camera->send(CameraInstruction::stopShake());

use pocketmine\network\mcpe\protocol\types\hud\HudElement;
use pocketmine\network\mcpe\protocol\types\hud\HudVisibility;
use pocketmine\network\mcpe\protocol\types\ControlScheme;

$camera->send(CameraInstruction::hud(HudVisibility::HIDE, HudElement::CROSSHAIR));
$camera->send(CameraInstruction::fog(["example:cinematic_fog"]));
$camera->send(CameraInstruction::controlScheme(ControlScheme::CAMERA_RELATIVE));
```

Tracking and attachment use client interpolation, with no tracking task. Targets must be alive, in the same world and already spawned to the viewer (or the viewer itself). Instructions hold weak entity references and revalidate at execution. Actor removal resets affected cameras before the removal packet. `facing` looks at a fixed position; `track()` follows an entity.

Fog identifiers must exist in the client's resource packs. Fog operations replace the stack. `clear()` restores only effects changed through this controller: perspective, target/attachment, FOV, shake, fog, hidden HUD elements and control scheme. The control-scheme fallback is the built-in `LOCKED_PLAYER_RELATIVE_STRAFE` default. Raw packets sent outside this API are outside its state tracking.

`CameraInstruction::clear()` is the perspective/target/attachment instruction; `$camera->clear()` additionally cancels playback and restores tracked effects.

Fades expire naturally. Bedrock combines overlapping fades and has no reliable cancel-fade instruction, so `clear()` does not promise to cancel one. Each fade phase is limited to 10 seconds. FOV is 1..179 degrees; shake intensity is 0..4. Other transition/wait durations are finite and limited to one hour. Invalid or nonfinite input throws `InvalidArgumentException`.

## Aim assist

Use `$server->getCameraAimAssist()->registerCategory()` and `registerPreset()` with the existing protocol `CameraAimAssistCategory`, `CameraAimAssistCategoryPriorities`, `CameraAimAssistCategoryPriority`, `CameraAimAssistPreset`, `CameraAimAssistPresetExclusionDefinition`, and `CameraAimAssistPresetItemSettings` value objects. Register categories before presets. Definitions are validated, bounded, sorted and frozen with camera definitions; no duplicate registry layer or packet serializer is needed.

```php
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistTargetMode;
use quark\math\Vector2;

$camera->send(CameraInstruction::aimAssist(
    "example:combat", new Vector2(30, 30), 16, CameraAimAssistTargetMode::ANGLE
));
$camera->send(CameraInstruction::clearAimAssist());
$allowed = $camera->clientAllowsAimAssist(); // null until the client reports its preference.
```

Camera-preset aim-assist references must name registered definitions. A reported client opt-out rejects new aim-assist requests; the server cannot override the client's setting. This preference does not acknowledge any camera instruction.

## Timelines

```php
use quark\camera\CameraTimeline;
use quark\camera\CameraPlayback;

$intro = (new CameraTimeline())
    ->instruction(CameraInstruction::set(position: $start))
    ->instruction(CameraInstruction::set(position: $end, duration: 2.0,
        ease: CameraEase::IN_OUT_SINE))
    ->wait(2.0)
    ->awaitSignal("continue")
    ->callback(static function(CameraPlayback $playback) : void{
        $playback->send(CameraInstruction::fade());
    })
    ->wait(2.0);

$playback = $camera->play($intro, $plugin);
$playback->signal("continue");
$playback->cancel();
// Restart with $camera->play($intro, $plugin).
```

Timeline methods return new definitions, reusable across players. `loop(3)` runs three total iterations; loops are bounded to 10,000 and definitions to 4,096 steps. Completion resets by default; `resetAfter(false)` keeps the last requested state. Signals are not buffered: only the current matching wait resumes. Delays round up to server ticks. At most 64 immediate steps execute in one turn before yielding to the next tick.

One-shot tasks use the owning plugin's existing scheduler. Signal waits schedule nothing. Scheduled tasks hold weak playback references. Cancellation drops callbacks and invalidates old tasks before resetting. Send from callbacks through the supplied playback handle: it rejects calls after replacement/cancellation. Avoid capturing players/controllers in reusable definitions; normal PHP closure captures retain those objects as long as the definition is retained. Do not create independent delayed callbacks that bypass the playback handle.

## Lifecycle and ownership

Presets synchronize after the client spawn response and before `PlayerJoinEvent`. Calls before synchronization, while disconnected/dead, or in spectator mode throw `LogicException`; instructions are never silently queued for later. `isReady()` permits an explicit check. Repeated synchronization does not duplicate accepted packets. Rejected packet sends throw `RuntimeException` and do not advance recorded state. Session readiness, pending context resets and entity visibility are checked again after packet hooks, before queueing. A plugin that replaces packets in `DataPacketSendEvent` owns the resulting divergence; the API has no client-state acknowledgements.

A direct instruction cancels the current timeline without resetting its last state; a new timeline replaces the old one. Invalid direct instructions do not interrupt a valid timeline. Completion, explicit cancellation, death/respawn, world changes, gamemode changes, plugin disable and disconnect invalidate playback. Plugin disable also cancels signal waits and resets state retained with `resetAfter(false)`; ownership is weak and released when replaced. Disconnect cleanup releases callbacks without sending packets. Lifecycle reset failures are logged and do not interrupt world changes or other cleanup. Direct operations have no plugin owner; plugins using them should call `clear()` in their own disable cleanup.

## Client limits and verification

The implementation targets Quark's locked BedrockProtocol fork for protocol 2225 / Bedrock 1.26.60, also used by 1.26.61/62. Quark admits its configured protocol version; this API is not a multi-version translator. PHP packet serializers preserve the current optional fields; `bedwire` transports the encoded batches.

No experiment flags are forced into login packets. Microsoft still documents the new third-person presets as requiring **Creator Cameras: New Third Person Presets**. Such client-gated presets/properties require a matching client setup and must be checked on the supported devices; successful queueing does not establish that the client applied them. Control-scheme packets have no nullable reset operation. Moving a free camera does not load distant chunks; keep the camera within the player's available world data.

Splines are deliberately unavailable. The reference reports disconnects on 1.26.0 and does not verify newer clients; current protocol schemas alone cannot establish safety. Per-actor aim-assist priority packets are also unavailable: the actor-index mapping is undocumented and unverified. Named category priorities remain supported. JSON timelines and infinite loops are omitted.

Run `php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/phpunit/camera` with Quark's runtime. Tests cover registry validation, packet fields/serialization, ordering, rejected sends, state resets, scheduler timing, signals, replacement, cancellation, reference release and context changes. `encoding` is needed for wire tests; `qmath` is needed for vector tests. Mocks do not verify rendering. Before deployment, use actual supported Bedrock clients to check custom presets/IDs, movement/rotation/FOV interpolation, attachment/despawn, fade overlap, aim-assist preferences, HUD/fog, spectator transitions and disconnect during cutscenes.

The send-path guard is optional for ordinary packets. A local PHP 8.4.14 microbenchmark of 100,000 preencoded sends per round (seven measured rounds, profiling disabled, no handlers or transport) measured medians of 670.4 ns before and 707.4 ns after the guard. The actor-removal cleanup measured 182.1 ns added per recipient across 1,000 idle-camera recipients (300 removals per round, seven rounds, no-op broadcaster). These isolate bookkeeping cost, not real network throughput or client performance. Static cameras do no per-tick work; timeline waits use one delayed task and signals schedule nothing.

Sources: [Mojang protocol changelog](https://mojang.github.io/bedrock-protocol-docs/changelog/), [Microsoft camera system](https://learn.microsoft.com/en-us/minecraft/creator/documents/camerasystem/cameracommandintroduction?view=minecraft-bedrock-stable), [third-person presets](https://learn.microsoft.com/en-us/minecraft/creator/documents/camerasystem/camerapresetthirdperson?view=minecraft-bedrock-stable), [control schemes](https://learn.microsoft.com/en-us/minecraft/creator/documents/controlschemes?view=minecraft-bedrock-stable), [CameraAPI reference](https://github.com/presentkim-pm/CameraAPI). The reference was studied for behavior; its code was not copied.
