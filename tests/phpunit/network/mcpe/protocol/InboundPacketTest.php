<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\network\mcpe\protocol;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ActorPickRequestPacket;
use pocketmine\network\mcpe\protocol\BlockPickRequestPacket;
use pocketmine\network\mcpe\protocol\BookEditPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\ItemStackRequestPacket;
use pocketmine\network\mcpe\protocol\LecternUpdatePacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\InteractionMode;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\HandSlot;
use pocketmine\network\mcpe\protocol\types\inventory\InventoryTransactionChangedSlotsHack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\MismatchTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\NormalTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\ReleaseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftingConsumeInputStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftingCreateSpecificResultStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeAutoStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CreativeCreateStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DestroyStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DropStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequestSlotInfo;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\MineBlockStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\PlaceStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\SwapStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\TakeStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\TriggerType;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\ItemInteractionData;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayerBlockAction;
use pocketmine\network\mcpe\protocol\types\PlayMode;
use quark\item\VanillaItems;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\packet\ActorPickRequestPacket as NetActorPickRequest;
use quark\network\mcpe\protocol\packet\BlockPickRequestPacket as NetBlockPickRequest;
use quark\network\mcpe\protocol\packet\BookEditPacket as NetBookEdit;
use quark\network\mcpe\protocol\packet\InteractPacket as NetInteract;
use quark\network\mcpe\protocol\packet\InventoryTransactionPacket as NetInventoryTransaction;
use quark\network\mcpe\protocol\packet\ItemStackRequestPacket as NetItemStackRequest;
use quark\network\mcpe\protocol\packet\LecternUpdatePacket as NetLecternUpdate;
use quark\network\mcpe\protocol\packet\MobEquipmentPacket as NetMobEquipment;
use quark\network\mcpe\protocol\packet\ModalFormResponsePacket as NetModalFormResponse;
use quark\network\mcpe\protocol\packet\PlayerActionPacket as NetPlayerAction;
use quark\network\mcpe\protocol\packet\PlayerAuthInputPacket as NetPlayerAuthInput;
use quark\network\mcpe\protocol\packet\TextPacket as NetText;
use quark\network\mcpe\protocol\types\BookEditActionAddPage;
use quark\network\mcpe\protocol\types\BookEditActionFinalize;
use quark\network\mcpe\protocol\types\BookEditActionReplacePage;
use quark\network\mcpe\protocol\types\BookEditActionSwapPages;
use quark\network\mcpe\protocol\types\DropStackRequestAction as NetDropStackRequestAction;
use quark\network\mcpe\protocol\types\InventoryAction;
use quark\network\mcpe\protocol\types\ItemReleaseInventoryTransaction;
use quark\network\mcpe\protocol\types\ItemUseInventoryTransaction;
use quark\network\mcpe\protocol\types\ItemUseOnActorInventoryTransaction;
use quark\network\mcpe\protocol\types\LegacySetSlot;
use quark\network\mcpe\protocol\types\StackRequestSlotInfo;
use quark\network\mcpe\protocol\types\TextAuthorAndMessage;
use function array_key_first;
use function array_map;
use function get_object_vars;

/**
 * Encodes serverbound packets with BedrockProtocol and checks that the packet classes the handlers read from the
 * native decode hold the values the old handlers read from BedrockProtocol's objects.
 */
final class InboundPacketTest extends TestCase{

	/**
	 * @phpstan-template T of Packet
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return T
	 */
	private static function decode(DataPacket $packet, string $class) : Packet{
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$decoded = EncodedPacket::fromBytes($writer->getData())->getPacket();
		self::assertInstanceOf($class, $decoded);
		return $decoded;
	}

	private static function item() : ItemStackWrapper{
		return new ItemStackWrapper(0, TypeConverter::getInstance()->coreItemStackToNet(VanillaItems::DIAMOND_SWORD()->setCustomName("named")));
	}

	private static function action(int $sourceType, ?int $windowId, ?int $sourceFlags, int $slot, ItemStackWrapper $oldItem, ItemStackWrapper $newItem) : NetworkInventoryAction{
		$action = new NetworkInventoryAction();
		$action->sourceType = $sourceType;
		$action->windowId = $windowId;
		$action->sourceFlags = $sourceFlags;
		$action->inventorySlot = $slot;
		$action->oldItem = $oldItem;
		$action->newItem = $newItem;
		return $action;
	}

	private static function assertInventoryAction(NetworkInventoryAction $expected, InventoryAction $action) : void{
		self::assertSame([$expected->sourceType, $expected->windowId, $expected->inventorySlot], [$action->source->sourceType, $action->source->containerId, $action->slot]);
		self::assertEquals($expected->oldItem->getItemStack(), Fields::toItemStack($action->fromItem));
		self::assertEquals($expected->newItem->getItemStack(), Fields::toItemStack($action->toItem));
		self::assertSame($expected->newItem->getItemStack()->getCount(), $action->toItem->stackSize);
	}

	private static function assertSlotInfo(ItemStackRequestSlotInfo $expected, StackRequestSlotInfo $info) : void{
		self::assertSame(
			[$expected->getContainerName()->getContainerId(), $expected->getSlotId(), $expected->getStackId()],
			[$info->fullContainerName->containerName, $info->slot, $info->netIdVariant]
		);
	}

	private static function useItemData() : UseItemTransactionData{
		$actions = [self::action(NetworkInventoryAction::SOURCE_CONTAINER, ContainerIds::INVENTORY, null, 3, self::item(), ItemStackWrapper::legacy(ItemStack::null()))];
		return UseItemTransactionData::new($actions, UseItemTransactionData::ACTION_CLICK_BLOCK, TriggerType::PLAYER_INPUT, new BlockPosition(1, -2, 3), 4, 5, HandSlot::MAINHAND, self::item(), new Vector3(1.5, 2.5, 3.5), new Vector3(0.25, 0.5, 0.75), 77, PredictedResult::SUCCESS, 0);
	}

	public function testPlayerAuthInput() : void{
		$flags = new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS);
		foreach([PlayerAuthInputFlags::SNEAKING, PlayerAuthInputFlags::START_JUMPING, PlayerAuthInputFlags::MISSED_SWING, PlayerAuthInputFlags::NUMBER_OF_FLAGS - 1] as $flag){
			$flags->set($flag, true);
		}
		$slot = new ItemStackRequestSlotInfo(new FullContainerName(ContainerUIIds::HOTBAR), 2, -9);
		$request = new ItemStackRequest(-9, [new DropStackRequestAction(3, $slot, false)], ["filtered"], 0);
		$blockAction = new PlayerBlockAction(PlayerAction::START_BREAK, new BlockPosition(7, 8, 9), 1);
		$packet = PlayerAuthInputPacket::create(
			new Vector3(1.5, 66.625, -3.25), 12.5, 270.0, 90.0, 0.0, 1.0, $flags, InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::CROSSHAIR,
			new Vector2(0, 0), 123, new Vector3(0, 0, 0), new ItemInteractionData(-3, null, self::useItemData()), $request, [$blockAction], null,
			0.0, 0.0, new Vector3(0, 0, 0), new Vector2(0, 0)
		);
		$decoded = self::decode($packet, NetPlayerAuthInput::class);

		self::assertTrue($decoded->position->equals($packet->getPosition()));
		self::assertSame([$packet->getPitch(), $packet->getYaw(), $packet->getHeadYaw()], [$decoded->playerRotation->x, $decoded->playerRotation->y, $decoded->playerHeadRotation]);
		foreach($decoded->inputData as $flag){
			self::assertTrue($packet->getInputFlags()->get($flag), "flag $flag");
		}
		foreach([PlayerAuthInputFlags::SNEAKING, PlayerAuthInputFlags::START_JUMPING, PlayerAuthInputFlags::MISSED_SWING, PlayerAuthInputFlags::PERFORM_ITEM_STACK_REQUEST] as $flag){
			self::assertContains($flag, $decoded->inputData);
		}
		self::assertNull($decoded->vehicleRotation);
		self::assertNull($decoded->clientPredictedVehicle);

		$interaction = $decoded->itemUseTransaction;
		self::assertNotNull($interaction);
		self::assertSame(-3, $interaction->legacyRequestId);
		$use = $interaction->itemUseTransaction;
		$expected = self::useItemData();
		self::assertSame(
			[$expected->getActionType(), $expected->getFace(), $expected->getHotbarSlot(), $expected->getClientInteractPrediction()->value, $expected->getItemInHand()->getItemStack()->getBlockRuntimeId()],
			[$use->actionType, $use->face, $use->slot, $use->clientInteractPrediction, $use->item->blockRuntimeId]
		);
		self::assertTrue($use->position->equals(new Vector3(1, -2, 3)));
		self::assertTrue($use->clickPosition->equals($expected->getClickPosition()));
		self::assertTrue($use->fromPosition->equals($expected->getPlayerPosition()));
		self::assertCount(1, $use->actions);
		self::assertInventoryAction($expected->getActions()[0], $use->actions[0]);

		$stackRequest = $decoded->itemStackRequest;
		self::assertNotNull($stackRequest);
		self::assertSame([-9, ["filtered"]], [$stackRequest->clientRequestId, $stackRequest->stringsToFilter]);
		$drop = $stackRequest->actions[0]["drop"] ?? null;
		self::assertInstanceOf(NetDropStackRequestAction::class, $drop);
		self::assertSame(3, $drop->amount);
		self::assertSlotInfo($slot, $drop->source);

		$blockActions = $decoded->playerBlockActions;
		self::assertNotNull($blockActions);
		self::assertCount(1, $blockActions);
		self::assertSame(PlayerAction::START_BREAK, $blockActions[0]->playerActionType);
		self::assertTrue($blockActions[0]->position->equals(new Vector3(7, 8, 9)));
		self::assertSame(1, $blockActions[0]->facing);
	}

	public function testInventoryTransactions() : void{
		$drop = self::action(NetworkInventoryAction::SOURCE_WORLD, null, 0, NetworkInventoryAction::ACTION_MAGIC_SLOT_DROP_ITEM, ItemStackWrapper::legacy(ItemStack::null()), self::item());
		$source = self::action(NetworkInventoryAction::SOURCE_CONTAINER, ContainerIds::INVENTORY, null, 4, self::item(), ItemStackWrapper::legacy(ItemStack::null()));
		$changed = [new InventoryTransactionChangedSlotsHack(ContainerUIIds::HOTBAR, [0, 8, 255])];
		$decoded = self::decode(InventoryTransactionPacket::create(-5, $changed, NormalTransactionData::new([$drop, $source])), NetInventoryTransaction::class);
		self::assertSame(-5, $decoded->legacyRequestId);
		self::assertEquals([new LegacySetSlot(ContainerUIIds::HOTBAR, "\x00\x08\xff")], $decoded->legacySetItemSlots);
		$actions = $decoded->transaction["normal_transaction_data"] ?? null;
		self::assertNotNull($actions);
		self::assertInventoryAction($drop, $actions[0]);
		self::assertInventoryAction($source, $actions[1]);

		$decoded = self::decode(InventoryTransactionPacket::create(0, null, MismatchTransactionData::new()), NetInventoryTransaction::class);
		self::assertSame([], $decoded->transaction["inventory_mismatch_data"] ?? null);
		self::assertNull($decoded->legacySetItemSlots);

		$expected = self::useItemData();
		$decoded = self::decode(InventoryTransactionPacket::create(0, null, $expected), NetInventoryTransaction::class);
		$use = $decoded->transaction["item_use_inventory_transaction"] ?? null;
		self::assertInstanceOf(ItemUseInventoryTransaction::class, $use);
		self::assertSame([$expected->getActionType(), $expected->getHotbarSlot(), $expected->getFace()], [$use->actionType, $use->slot, $use->face]);

		$entity = UseItemOnEntityTransactionData::new([], 42, UseItemOnEntityTransactionData::ACTION_ATTACK, 6, self::item(), new Vector3(1, 2, 3), new Vector3(0.5, 0.25, 0.125));
		$decoded = self::decode(InventoryTransactionPacket::create(0, null, $entity), NetInventoryTransaction::class);
		$onActor = $decoded->transaction["item_use_on_actor_inventory_transaction"] ?? null;
		self::assertInstanceOf(ItemUseOnActorInventoryTransaction::class, $onActor);
		self::assertSame([42, UseItemOnEntityTransactionData::ACTION_ATTACK, 6], [$onActor->runtimeId, $onActor->actionType, $onActor->slot]);
		self::assertTrue($onActor->hitPosition->equals($entity->getClickPosition()));

		$release = ReleaseItemTransactionData::new([], ReleaseItemTransactionData::ACTION_RELEASE, 7, self::item(), new Vector3(1, 2, 3));
		$decoded = self::decode(InventoryTransactionPacket::create(0, null, $release), NetInventoryTransaction::class);
		$released = $decoded->transaction["item_release_inventory_transaction"] ?? null;
		self::assertInstanceOf(ItemReleaseInventoryTransaction::class, $released);
		self::assertSame([ReleaseItemTransactionData::ACTION_RELEASE, 7], [$released->actionType, $released->slot]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function fields(object $value) : array{
		$fields = get_object_vars($value);
		unset($fields["actionType"]);
		return array_map(static fn(mixed $field) : mixed => $field instanceof StackRequestSlotInfo ? [$field->fullContainerName->containerName, $field->slot, $field->netIdVariant] : $field, $fields);
	}

	public function testItemStackRequests() : void{
		$a = new ItemStackRequestSlotInfo(new FullContainerName(ContainerUIIds::HOTBAR, null), 1, 11);
		$b = new ItemStackRequestSlotInfo(new FullContainerName(ContainerUIIds::INVENTORY, null), 20, -4);
		$output = new ItemStackRequestSlotInfo(new FullContainerName(ContainerUIIds::CREATED_OUTPUT, null), 50, -4);
		$request = new ItemStackRequest(-4, [
			new TakeStackRequestAction(2, $a, $b),
			new PlaceStackRequestAction(3, $b, $a),
			new SwapStackRequestAction($a, $b),
			new DropStackRequestAction(4, $a, true),
			new DestroyStackRequestAction(5, $b),
			new CraftingConsumeInputStackRequestAction(6, $a),
			new CraftRecipeStackRequestAction(1234, 2),
			new CraftRecipeAutoStackRequestAction(1235, 3, []),
			new CreativeCreateStackRequestAction(99, 1),
			new CraftingCreateSpecificResultStackRequestAction(1),
			new MineBlockStackRequestAction(3, 120, 11),
			new TakeStackRequestAction(64, $output, $b),
		], [], 0);
		$decoded = self::decode(ItemStackRequestPacket::create([$request]), NetItemStackRequest::class);
		self::assertCount(1, $decoded->requests);
		$decodedRequest = $decoded->requests[0];
		self::assertSame(-4, $decodedRequest->clientRequestId);
		$slot = static fn(ItemStackRequestSlotInfo $info) : array => [$info->getContainerName()->getContainerId(), $info->getSlotId(), $info->getStackId()];
		$actions = [];
		foreach($decodedRequest->actions as $action){
			$variant = array_key_first($action);
			$body = $action[$variant];
			self::assertIsObject($body);
			$actions[] = [$variant => self::fields($body)];
		}
		self::assertSame([
			["take" => ["amount" => 2, "source" => $slot($a), "destination" => $slot($b)]],
			["place" => ["amount" => 3, "source" => $slot($b), "destination" => $slot($a)]],
			["swap" => ["source" => $slot($a), "destination" => $slot($b)]],
			["drop" => ["amount" => 4, "source" => $slot($a), "randomly" => true]],
			["destroy" => ["amount" => 5, "source" => $slot($b)]],
			["consume" => ["amount" => 6, "source" => $slot($a)]],
			["craft_recipe" => ["recipeNetId" => 1234, "numberOfRequestedCrafts" => 2]],
			["craft_recipe_auto" => ["recipeNetId" => 1235, "numberOfRequestedCrafts" => 3, "ingredients" => []]],
			["craft_creative" => ["creativeItemNetId" => 99, "numberOfRequestedCrafts" => 1]],
			["create" => ["resultsIndex" => 1]],
			["mine_block" => ["slot" => 3, "predictedDurability" => 120, "netIdVariant" => 11]],
			["take" => ["amount" => 64, "source" => $slot($output), "destination" => $slot($b)]],
		], $actions);
	}

	public function testSimplePackets() : void{
		$mob = self::decode(MobEquipmentPacket::create(1, self::item(), 7, 3, ContainerIds::INVENTORY), NetMobEquipment::class);
		self::assertSame([ContainerIds::INVENTORY, 3], [$mob->containerId, $mob->selectedSlot]);

		$text = new TextPacket();
		$text->type = TextPacket::TYPE_CHAT;
		$text->sourceName = "Steve";
		$text->message = "hello";
		$chat = self::decode($text, NetText::class);
		self::assertEquals(new TextAuthorAndMessage(TextPacket::TYPE_CHAT, "Steve", "hello"), $chat->body);

		$form = self::decode(ModalFormResponsePacket::response(5, "[1,2]"), NetModalFormResponse::class);
		self::assertSame([5, "[1,2]", null], [$form->formId, $form->jsonResponse, $form->formCancelReason]);
		$form = self::decode(ModalFormResponsePacket::cancel(6, 1), NetModalFormResponse::class);
		self::assertSame([6, null, 1], [$form->formId, $form->jsonResponse, $form->formCancelReason]);

		$interact = new InteractPacket();
		$interact->action = InteractPacket::ACTION_OPEN_INVENTORY;
		$interact->targetActorRuntimeId = 77;
		$interact->position = null;
		$interactFields = self::decode($interact, NetInteract::class);
		self::assertSame([InteractPacket::ACTION_OPEN_INVENTORY, 77], [$interactFields->action, $interactFields->targetRuntimeId]);

		$action = self::decode(PlayerActionPacket::create(1, PlayerAction::PREDICT_DESTROY_BLOCK, new BlockPosition(1, 2, 3), new BlockPosition(0, 0, 0), 5), NetPlayerAction::class);
		self::assertSame([PlayerAction::PREDICT_DESTROY_BLOCK, 5], [$action->action, $action->face]);
		self::assertTrue($action->blockPosition->equals(new Vector3(1, 2, 3)));

		$blockPick = self::decode(BlockPickRequestPacket::create(new BlockPosition(4, 5, 6), true, 2), NetBlockPickRequest::class);
		self::assertTrue($blockPick->position->equals(new Vector3(4, 5, 6)));
		self::assertTrue($blockPick->withData);
		$actorPick = self::decode(ActorPickRequestPacket::create(-123456789012, 2, true), NetActorPickRequest::class);
		self::assertSame(-123456789012, $actorPick->actorId);
		$lectern = self::decode(LecternUpdatePacket::create(3, 10, new BlockPosition(7, 8, 9)), NetLecternUpdate::class);
		self::assertSame(3, $lectern->newPageToShow);
		self::assertTrue($lectern->positionOfLecternToUpdate->equals(new Vector3(7, 8, 9)));
	}

	public function testBookEdits() : void{
		$base = static function(int $type) : BookEditPacket{
			$packet = new BookEditPacket();
			$packet->type = $type;
			$packet->inventorySlot = 4;
			$packet->pageNumber = 2;
			$packet->secondaryPageNumber = 5;
			$packet->text = "page";
			$packet->photoName = "";
			$packet->title = "title";
			$packet->author = "author";
			$packet->xuid = "123";
			return $packet;
		};
		$expected = [
			BookEditPacket::TYPE_REPLACE_PAGE => ["replace_page" => new BookEditActionReplacePage(2, "page", "")],
			BookEditPacket::TYPE_ADD_PAGE => ["add_page" => new BookEditActionAddPage(2, "page", "")],
			BookEditPacket::TYPE_DELETE_PAGE => ["delete_page" => 2],
			BookEditPacket::TYPE_SWAP_PAGES => ["swap_pages" => new BookEditActionSwapPages(2, 5)],
			BookEditPacket::TYPE_SIGN_BOOK => ["finalize" => new BookEditActionFinalize("title", "author", "123")],
		];
		foreach($expected as $type => $operation){
			self::assertEquals(new NetBookEdit(4, $operation), self::decode($base($type), NetBookEdit::class));
		}
	}
}
