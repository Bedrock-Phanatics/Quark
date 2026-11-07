# Inventories

Quark splits inventories into two parts:

- **Inventories** (`Inventory`, `SimpleInventory`) store items and notify listeners. They don't know who owns them or where they are.
- **Windows** (`InventoryWindow`) are one player's view of an inventory. A window says what kind of inventory it is and where it was opened from, like the block position of a chest. A window is created when a player opens something and discarded when it closes.

Because inventories hold no holder references, a tile's inventory can be read, copied or moved without dragging its position, world or viewers along.

## Opening menus

Blocks that open a menu implement `MenuAccessor`:

```php
use quark\block\utils\MenuAccessor;

$block = $world->getBlockAt($x, $y, $z);
if($block instanceof MenuAccessor){
    $block->openToUnchecked($player); //skips lock and obstruction checks
}
```

Container blocks (chests, barrels, furnaces, hoppers, dispensers, brewing stands, shulker boxes) also implement `block\utils\Container`. That adds `getInventory()` and `canOpenWith()` for locks. `Chest->getInventory()` returns both halves of a double chest as a `CombinedInventoryProxy`.

To show your own inventory, open a window on it:

```php
use quark\block\inventory\window\BlockInventoryWindow;

$inventory = new SimpleInventory(27);
$player->setCurrentWindow(new BlockInventoryWindow($player, $inventory, $position));
```

The client renders block windows at the given position, so a block must exist there client-side. `setCurrentWindow()` returns `false` if the open event was cancelled, or if an event handler opened another window first. Create a new window every time. Windows can't be reused or shared between players, and the same inventory can't be open in two windows of one player.

Several players can view the same inventory at once. `Inventory->getViewers()` returns everyone with a window open on it, and `Inventory->removeAllWindows()` closes all of them. Container tiles do this when they're destroyed.

## Window types

| Window | Used for |
|---|---|
| `player\PlayerInventoryWindow` | The player's own inventory, armor, off-hand, cursor and 2x2 crafting grid (`getType()` returns a `PlayerInventoryType`). These are always open, get them with `$player->getPermanentWindow()`. |
| `block\inventory\window\BlockInventoryWindow` | Generic containers like chests, barrels, shulker boxes and ender chests. |
| `DoubleChestInventoryWindow` | Double chests, with `getLeft()`/`getRight()` positions. |
| `FurnaceInventoryWindow`, `BrewingStandInventoryWindow`, `HopperInventoryWindow`, `DispenserInventoryWindow` | Container blocks with their own client UI. Slot numbers are constants on the window classes. |
| `CraftingTableInventoryWindow`, `EnchantingTableInventoryWindow`, `AnvilInventoryWindow`, `LoomInventoryWindow`, `StonecutterInventoryWindow`, `SmithingTableInventoryWindow`, `CartographyTableInventoryWindow` | Workstations. Their inventory exists only while the window is open. |

Windows implementing `TemporaryInventoryWindow` return anything left in them to the player (dropping what doesn't fit) when they close. The cursor and crafting grid are emptied the same way.

## Transactions and events

Transactions record which window each change was made through:

```php
use quark\block\inventory\window\BlockInventoryWindow;
use quark\event\inventory\InventoryTransactionEvent;
use quark\inventory\transaction\action\SlotChangeAction;

public function onTransaction(InventoryTransactionEvent $event) : void{
    foreach($event->getTransaction()->getActions() as $action){
        if($action instanceof SlotChangeAction && $action->getInventoryWindow() instanceof BlockInventoryWindow){
            $position = $action->getInventoryWindow()->getHolder();
        }
    }
}
```

`InventoryOpenEvent` and `InventoryCloseEvent` expose the window with `getWindow()`. To build a transaction yourself, use `TransactionBuilder->getActionBuilder($window)`, which gives an inventory you can change with the normal API without touching the real one, then turn the changes into actions with `generateActions()`.

Transactions are validated again after the event handlers run, so handlers that change or close inventories can't make a stale transaction go through.

## Hotbar and hands

The first `Hotbar::SIZE` slots of `Human->getInventory()` are the hotbar. `Human->getHotbar()` tracks the selected slot. Use `getMainHandItem()`/`setMainHandItem()` and `getOffHandItem()`/`setOffHandItem()` for held items. `Player->selectHotbarSlot()` fires `PlayerItemHeldEvent`, `Hotbar->setSelectedIndex()` doesn't.

## Threading

Inventories, windows and listeners are main-thread only. Don't keep windows after they close. Keep the inventory instead and open a new window when you need one.
