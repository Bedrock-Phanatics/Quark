# NPC dialogues

Use `quark\npcdialogue\NpcDialogue` to open Minecraft Bedrock's NPC screen for a player. A definition can be reused across players. Quark creates a temporary client-side actor for the portrait by default.

## Open a dialogue

In a plugin, pass your plugin instance to `create()` so Quark can close its dialogues when the plugin is disabled.

```php
use quark\npcdialogue\NpcDialogue;
use quark\player\Player;

$merchant = NpcDialogue::create($this)
	->setName("Merchant")
	->setText("What would you like to purchase?")
	->addButton("Shop", function(Player $player) : void{
		$this->openShop($player);
	})
	->addButton("Goodbye")
	->onClose(function(Player $player) : void{
		$player->sendMessage("Come back anytime.");
	});

$session = $merchant->send($player);
```

`send()` returns an `NpcDialogueSession` when the network session queues the open. It returns `null` if the player cannot open a dialogue, the plugin is disabled, or a packet hook vetoes the send. Send to a connected, spawned, living player outside a dimension loading screen.

A button without a callback dismisses the screen. Quark retires the open dialogue before running a button callback or a close callback. Use `onClose()` for dismissal through the client's close control or an explicit `close()` call. Button selections and automatic cleanup retire the dialogue without calling `onClose()`.

Quark checks that a selection matches the player's active page and a button supplied by the plugin. Check any game-specific requirements, such as permissions, balances, or inventory, inside the callback. For callbacks that open a standard form, allow for the NPC screen's closing animation. Another NPC page can be sent directly from the callback.

## Reuse and branch

Each send captures the name, text, buttons, callbacks, and appearance at that moment. Later edits to the definition apply to future sends. Store player-specific progress in your plugin or create a page from that player's state.

```php
$weapons = NpcDialogue::create($this)
	->setName("Merchant")
	->setText("Swords are in stock.")
	->addButton("Done");

$armor = NpcDialogue::create($this)
	->setName("Merchant")
	->setText("Helmets and boots are in stock.")
	->addButton("Done");

$catalog = NpcDialogue::create($this)
	->setName("Merchant")
	->setText("Which catalog would you like to see?")
	->addButton("Weapons", static function(Player $player) use ($weapons) : void{
		$weapons->send($player);
	})
	->addButton("Armor", static function(Player $player) use ($armor) : void{
		$armor->send($player);
	});

$catalog->send($player);
```

## Update, replace, and close

```php
$session = $merchant->send($player);

$soldOut = (clone $merchant)
	->setText("Sold out for today.")
	->clearButtons()
	->addButton("Goodbye");

if($session !== null){
	$session = $session->update($soldOut) ?? $session;
}

// Close the player's current dialogue, for example when the shop closes.
$player->getNpcDialogue()?->close();
```

- `update($definition)` requires the active handle and the same owning plugin. It returns a new handle on success and reuses the temporary actor when the appearance is unchanged.
- `send($player)` replaces the current dialogue when the new page is queued. If the send fails, the previous page stays open unless the player's context changed.
- `isOpen()` checks whether the handle still represents a pending server-side dialogue. `close()` returns `false` for an expired handle.

Quark automatically retires pending dialogues when the player disconnects, transfers, changes worlds, or dies. Disabling the owning plugin or removing an existing NPC from the player's view also retires its dialogue.

Packet hooks can veto an open, update, or replacement as a group. Once Quark retires a dialogue, it queues any required close and temporary actor removal directly.

## Portraits

```php
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use quark\math\Vector3;

$merchant->setNpcTexture(12);
$catalog->setEntityTexture(EntityIds::VILLAGER);
$weapons->setSkin($skin); // quark\entity\Skin
$weapons->setPortraitOffsets(new Vector3(0, -35, 0), new Vector3(0.8, 0.8, 0.8));
```

- `setNpcTexture()` selects a built-in NPC texture from `0` through `59`.
- `setEntityTexture()` accepts a namespaced actor type available to the client. Use `setSkin()` for a player model.
- `setPortraitOffsets()` takes translation and optional scale vectors. Tune these values for the selected model. Omitting scale uses `(1, 1, 1)`.

Pass an existing NPC as the second argument to `send()` to use its portrait. The actor must be a live `minecraft:npc` with its NPC component enabled and already visible to that player. Its own appearance settings apply. The dialogue still supplies the screen name, text, and buttons.

```php
$merchant->send($player, $npc);
```

A page supports six buttons. Names and button labels allow up to 256 UTF-8 bytes each. Dialogue text allows up to 8,192 UTF-8 bytes.
