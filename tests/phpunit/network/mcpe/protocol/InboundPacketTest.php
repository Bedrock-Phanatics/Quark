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
use pocketmine\math\Vector3;
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
use quark\network\mcpe\protocol\types\inventory\ContainerIds;
use quark\network\mcpe\protocol\types\inventory\ContainerUIIds;
use quark\network\mcpe\protocol\types\inventory\ItemStack;
use quark\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use quark\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use quark\network\mcpe\protocol\types\inventory\PredictedResult;
use quark\network\mcpe\protocol\types\inventory\ReleaseItemTransactionData;
use quark\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use quark\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use quark\network\mcpe\protocol\types\InventoryAction;
use quark\network\mcpe\protocol\types\ItemReleaseInventoryTransaction;
use quark\network\mcpe\protocol\types\ItemUseInventoryTransaction;
use quark\network\mcpe\protocol\types\ItemUseOnActorInventoryTransaction;
use quark\network\mcpe\protocol\types\LegacySetSlot;
use quark\network\mcpe\protocol\types\PlayerAction;
use quark\network\mcpe\protocol\types\PlayerAuthInputFlags;
use quark\network\mcpe\protocol\types\StackRequestSlotInfo;
use quark\network\mcpe\protocol\types\TextAuthorAndMessage;
use function array_key_first;
use function array_map;
use function get_object_vars;

/**
 * Decodes serverbound packets BedrockProtocol encoded, recorded in golden/bedrock-protocol.json, and checks that the
 * packet classes the handlers read hold the values those packets were built with.
 */
final class InboundPacketTest extends TestCase{

	/**
	 * @phpstan-template T of Packet
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return T
	 */
	private function decode(int $index, string $class) : Packet{
		$decoded = EncodedPacket::fromBytes(RecordedPackets::get($this, $index))->getPacket();
		self::assertInstanceOf($class, $decoded);
		return $decoded;
	}

	/**
	 * The diamond sword named "named" the recorded packets carry.
	 */
	private static function item() : ItemStackWrapper{
		return new ItemStackWrapper(0, TypeConverter::getInstance()->coreItemStackToNet(VanillaItems::DIAMOND_SWORD()->setCustomName("named")));
	}

	private static function nothing() : ItemStackWrapper{
		return ItemStackWrapper::legacy(ItemStack::null());
	}

	private static function assertInventoryAction(int $sourceType, ?int $windowId, int $slot, ItemStackWrapper $oldItem, ItemStackWrapper $newItem, InventoryAction $action) : void{
		self::assertSame([$sourceType, $windowId, $slot], [$action->source->sourceType, $action->source->containerId, $action->slot]);
		self::assertEquals($oldItem->getItemStack(), Fields::toItemStack($action->fromItem));
		self::assertEquals($newItem->getItemStack(), Fields::toItemStack($action->toItem));
		self::assertSame($newItem->getItemStack()->getCount(), $action->toItem->stackSize);
	}

	/**
	 * @phpstan-param array{int, int, int} $expected container, slot and stack ID
	 */
	private static function assertSlotInfo(array $expected, StackRequestSlotInfo $info) : void{
		self::assertSame($expected, [$info->fullContainerName->containerName, $info->slot, $info->netIdVariant]);
	}

	/**
	 * Checks a recorded use item transaction: a block click at (1, -2, 3) on face 4 from hotbar slot 5, moving the sword
	 * out of inventory slot 3.
	 */
	private static function assertUseItem(ItemUseInventoryTransaction $use) : void{
		self::assertSame(
			[UseItemTransactionData::ACTION_CLICK_BLOCK, 4, 5, PredictedResult::SUCCESS->value, self::item()->getItemStack()->getBlockRuntimeId()],
			[$use->actionType, $use->face, $use->slot, $use->clientInteractPrediction, $use->item->blockRuntimeId]
		);
		self::assertTrue($use->position->equals(new Vector3(1, -2, 3)));
		self::assertTrue($use->clickPosition->equals(new Vector3(0.25, 0.5, 0.75)));
		self::assertTrue($use->fromPosition->equals(new Vector3(1.5, 2.5, 3.5)));
		self::assertCount(1, $use->actions);
		self::assertInventoryAction(NetworkInventoryAction::SOURCE_CONTAINER, ContainerIds::INVENTORY, 3, self::item(), self::nothing(), $use->actions[0]);
	}

	public function testPlayerAuthInput() : void{
		$decoded = $this->decode(0, NetPlayerAuthInput::class);

		self::assertTrue($decoded->position->equals(new Vector3(1.5, 66.625, -3.25)));
		self::assertSame([12.5, 270.0, 90.0], [$decoded->playerRotation->x, $decoded->playerRotation->y, $decoded->playerHeadRotation]);
		//the flags it was built with, and the ones BedrockProtocol sets for the interaction, stack request and block action
		$set = [PlayerAuthInputFlags::SNEAKING, PlayerAuthInputFlags::START_JUMPING, PlayerAuthInputFlags::MISSED_SWING, PlayerAuthInputFlags::NUMBER_OF_FLAGS - 1, PlayerAuthInputFlags::PERFORM_ITEM_INTERACTION, PlayerAuthInputFlags::PERFORM_ITEM_STACK_REQUEST, PlayerAuthInputFlags::PERFORM_BLOCK_ACTIONS];
		foreach($decoded->inputData as $flag){
			self::assertContains($flag, $set, "flag $flag");
		}
		foreach([PlayerAuthInputFlags::SNEAKING, PlayerAuthInputFlags::START_JUMPING, PlayerAuthInputFlags::MISSED_SWING, PlayerAuthInputFlags::PERFORM_ITEM_STACK_REQUEST] as $flag){
			self::assertContains($flag, $decoded->inputData);
		}
		self::assertNull($decoded->vehicleRotation);
		self::assertNull($decoded->clientPredictedVehicle);

		$interaction = $decoded->itemUseTransaction;
		self::assertNotNull($interaction);
		self::assertSame(-3, $interaction->legacyRequestId);
		self::assertUseItem($interaction->itemUseTransaction);

		$stackRequest = $decoded->itemStackRequest;
		self::assertNotNull($stackRequest);
		self::assertSame([-9, ["filtered"]], [$stackRequest->clientRequestId, $stackRequest->stringsToFilter]);
		$drop = $stackRequest->actions[0]["drop"] ?? null;
		self::assertInstanceOf(NetDropStackRequestAction::class, $drop);
		self::assertSame(3, $drop->amount);
		self::assertSlotInfo([ContainerUIIds::HOTBAR, 2, -9], $drop->source);

		$blockActions = $decoded->playerBlockActions;
		self::assertNotNull($blockActions);
		self::assertCount(1, $blockActions);
		self::assertSame(PlayerAction::START_BREAK, $blockActions[0]->playerActionType);
		self::assertTrue($blockActions[0]->position->equals(new Vector3(7, 8, 9)));
		self::assertSame(1, $blockActions[0]->facing);
	}

	public function testInventoryTransactions() : void{
		$decoded = $this->decode(0, NetInventoryTransaction::class);
		self::assertSame(-5, $decoded->legacyRequestId);
		self::assertEquals([new LegacySetSlot(ContainerUIIds::HOTBAR, "\x00\x08\xff")], $decoded->legacySetItemSlots);
		$actions = $decoded->transaction["normal_transaction_data"] ?? null;
		self::assertNotNull($actions);
		self::assertInventoryAction(NetworkInventoryAction::SOURCE_WORLD, null, NetworkInventoryAction::ACTION_MAGIC_SLOT_DROP_ITEM, self::nothing(), self::item(), $actions[0]);
		self::assertInventoryAction(NetworkInventoryAction::SOURCE_CONTAINER, ContainerIds::INVENTORY, 4, self::item(), self::nothing(), $actions[1]);

		$decoded = $this->decode(1, NetInventoryTransaction::class);
		self::assertSame([], $decoded->transaction["inventory_mismatch_data"] ?? null);
		self::assertNull($decoded->legacySetItemSlots);

		$decoded = $this->decode(2, NetInventoryTransaction::class);
		$use = $decoded->transaction["item_use_inventory_transaction"] ?? null;
		self::assertInstanceOf(ItemUseInventoryTransaction::class, $use);
		self::assertUseItem($use);

		$decoded = $this->decode(3, NetInventoryTransaction::class);
		$onActor = $decoded->transaction["item_use_on_actor_inventory_transaction"] ?? null;
		self::assertInstanceOf(ItemUseOnActorInventoryTransaction::class, $onActor);
		self::assertSame([42, UseItemOnEntityTransactionData::ACTION_ATTACK, 6], [$onActor->runtimeId, $onActor->actionType, $onActor->slot]);
		self::assertTrue($onActor->hitPosition->equals(new Vector3(0.5, 0.25, 0.125)));

		$decoded = $this->decode(4, NetInventoryTransaction::class);
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
		$a = [ContainerUIIds::HOTBAR, 1, 11];
		$b = [ContainerUIIds::INVENTORY, 20, -4];
		$output = [ContainerUIIds::CREATED_OUTPUT, 50, -4];
		$decoded = $this->decode(0, NetItemStackRequest::class);
		self::assertCount(1, $decoded->requests);
		$decodedRequest = $decoded->requests[0];
		self::assertSame(-4, $decodedRequest->clientRequestId);
		$actions = [];
		foreach($decodedRequest->actions as $action){
			$variant = array_key_first($action);
			$body = $action[$variant];
			self::assertIsObject($body);
			$actions[] = [$variant => self::fields($body)];
		}
		self::assertSame([
			["take" => ["amount" => 2, "source" => $a, "destination" => $b]],
			["place" => ["amount" => 3, "source" => $b, "destination" => $a]],
			["swap" => ["source" => $a, "destination" => $b]],
			["drop" => ["amount" => 4, "source" => $a, "randomly" => true]],
			["destroy" => ["amount" => 5, "source" => $b]],
			["consume" => ["amount" => 6, "source" => $a]],
			["craft_recipe" => ["recipeNetId" => 1234, "numberOfRequestedCrafts" => 2]],
			["craft_recipe_auto" => ["recipeNetId" => 1235, "numberOfRequestedCrafts" => 3, "ingredients" => []]],
			["craft_creative" => ["creativeItemNetId" => 99, "numberOfRequestedCrafts" => 1]],
			["create" => ["resultsIndex" => 1]],
			["mine_block" => ["slot" => 3, "predictedDurability" => 120, "netIdVariant" => 11]],
			["take" => ["amount" => 64, "source" => $output, "destination" => $b]],
		], $actions);
	}

	public function testSimplePackets() : void{
		$mob = $this->decode(0, NetMobEquipment::class);
		self::assertSame([ContainerIds::INVENTORY, 3], [$mob->containerId, $mob->selectedSlot]);

		$chat = $this->decode(1, NetText::class);
		self::assertEquals(new TextAuthorAndMessage(NetText::TYPE_CHAT, "Steve", "hello"), $chat->body);

		$form = $this->decode(2, NetModalFormResponse::class);
		self::assertSame([5, "[1,2]", null], [$form->formId, $form->jsonResponse, $form->formCancelReason]);
		$form = $this->decode(3, NetModalFormResponse::class);
		self::assertSame([6, null, 1], [$form->formId, $form->jsonResponse, $form->formCancelReason]);

		$interactFields = $this->decode(4, NetInteract::class);
		self::assertSame([NetInteract::ACTION_OPEN_INVENTORY, 77], [$interactFields->action, $interactFields->targetRuntimeId]);

		$action = $this->decode(5, NetPlayerAction::class);
		self::assertSame([PlayerAction::PREDICT_DESTROY_BLOCK, 5], [$action->action, $action->face]);
		self::assertTrue($action->blockPosition->equals(new Vector3(1, 2, 3)));

		$blockPick = $this->decode(6, NetBlockPickRequest::class);
		self::assertTrue($blockPick->position->equals(new Vector3(4, 5, 6)));
		self::assertTrue($blockPick->withData);
		$actorPick = $this->decode(7, NetActorPickRequest::class);
		self::assertSame(-123456789012, $actorPick->actorId);
		$lectern = $this->decode(8, NetLecternUpdate::class);
		self::assertSame(3, $lectern->newPageToShow);
		self::assertTrue($lectern->positionOfLecternToUpdate->equals(new Vector3(7, 8, 9)));
	}

	public function testBookEdits() : void{
		//slot 4, pages 2 and 5, as replace, add, delete, swap and sign in that order
		$expected = [
			["replace_page" => new BookEditActionReplacePage(2, "page", "")],
			["add_page" => new BookEditActionAddPage(2, "page", "")],
			["delete_page" => 2],
			["swap_pages" => new BookEditActionSwapPages(2, 5)],
			["finalize" => new BookEditActionFinalize("title", "author", "123")],
		];
		foreach($expected as $index => $operation){
			self::assertEquals(new NetBookEdit(4, $operation), $this->decode($index, NetBookEdit::class));
		}
	}
}
