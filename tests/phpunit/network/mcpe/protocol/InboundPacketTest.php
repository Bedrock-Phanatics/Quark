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

use Bedwire\Protocol;
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
use function is_array;

/**
 * Encodes serverbound packets with BedrockProtocol and checks that the fields the handlers read from the native
 * decode hold the values the old handlers read from BedrockProtocol's objects.
 *
 * @phpstan-import-type PlayerAuthInputFields from Packets
 * @phpstan-import-type InventoryTransactionFields from Packets
 * @phpstan-import-type InventoryAction from Packets
 * @phpstan-import-type ItemStackRequestFields from Packets
 * @phpstan-import-type StackRequestSlotInfo from Packets
 * @phpstan-import-type MobEquipmentFields from Packets
 * @phpstan-import-type BookEditFields from Packets
 * @phpstan-import-type ModalFormResponseFields from Packets
 * @phpstan-import-type TextFields from Packets
 * @phpstan-import-type InteractFields from Packets
 * @phpstan-import-type PlayerActionFields from Packets
 * @phpstan-import-type BlockPickRequestFields from Packets
 * @phpstan-import-type ActorPickRequestFields from Packets
 * @phpstan-import-type LecternUpdateFields from Packets
 */
final class InboundPacketTest extends TestCase{

	/**
	 * @return array<string, mixed>
	 */
	private static function decode(DataPacket $packet) : array{
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$fields = Protocol::decode($writer->getData());
		self::assertIsArray($fields);
		return $fields;
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

	/**
	 * @phpstan-param InventoryAction $action
	 */
	private static function assertInventoryAction(NetworkInventoryAction $expected, array $action) : void{
		self::assertSame([$expected->sourceType, $expected->windowId, $expected->inventorySlot], [$action["source"]["source_type"], $action["source"]["container_id"], $action["slot"]]);
		self::assertEquals($expected->oldItem->getItemStack(), Fields::toItemStack($action["from_item"]));
		self::assertEquals($expected->newItem->getItemStack(), Fields::toItemStack($action["to_item"]));
		self::assertSame($expected->newItem->getItemStack()->getCount(), $action["to_item"]["stack_size"]);
	}

	/**
	 * @phpstan-param StackRequestSlotInfo $info
	 */
	private static function assertSlotInfo(ItemStackRequestSlotInfo $expected, array $info) : void{
		self::assertSame(
			[$expected->getContainerName()->getContainerId(), $expected->getSlotId(), $expected->getStackId()],
			[$info["full_container_name"]["container_name"], $info["slot"], $info["net_id_variant"]]
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
		/** @phpstan-var PlayerAuthInputFields $fields */
		$fields = self::decode($packet);

		self::assertTrue(Fields::toVector3($fields["position"])->equals($packet->getPosition()));
		self::assertSame([$packet->getPitch(), $packet->getYaw(), $packet->getHeadYaw()], [$fields["player_rotation"]["x"], $fields["player_rotation"]["y"], $fields["player_head_rotation"]]);
		foreach($fields["input_data"] as $flag){
			self::assertTrue($packet->getInputFlags()->get($flag), "flag $flag");
		}
		foreach([PlayerAuthInputFlags::SNEAKING, PlayerAuthInputFlags::START_JUMPING, PlayerAuthInputFlags::MISSED_SWING, PlayerAuthInputFlags::PERFORM_ITEM_STACK_REQUEST] as $flag){
			self::assertContains($flag, $fields["input_data"]);
		}
		self::assertNull($fields["vehicle_rotation"]);
		self::assertNull($fields["client_predicted_vehicle"]);

		$interaction = $fields["item_use_transaction"];
		self::assertNotNull($interaction);
		self::assertSame(-3, $interaction["legacy_request_id"]);
		$use = $interaction["item_use_transaction"];
		$expected = self::useItemData();
		self::assertSame(
			[$expected->getActionType(), $expected->getFace(), $expected->getHotbarSlot(), $expected->getClientInteractPrediction()->value, $expected->getItemInHand()->getItemStack()->getBlockRuntimeId()],
			[$use["action_type"], $use["face"], $use["slot"], $use["client_interact_prediction"], $use["item"]["block_runtime_id"]]
		);
		self::assertTrue(Fields::toBlockVector($use["position"])->equals(new Vector3(1, -2, 3)));
		self::assertTrue(Fields::toVector3($use["click_position"])->equals($expected->getClickPosition()));
		self::assertTrue(Fields::toVector3($use["from_position"])->equals($expected->getPlayerPosition()));
		self::assertCount(1, $use["actions"]);
		self::assertInventoryAction($expected->getActions()[0], $use["actions"][0]);

		$stackRequest = $fields["item_stack_request"];
		self::assertNotNull($stackRequest);
		self::assertSame([-9, ["filtered"]], [$stackRequest["client_request_id"], $stackRequest["strings_to_filter"]]);
		$drop = $stackRequest["actions"][0]["drop"] ?? null;
		self::assertNotNull($drop);
		self::assertSame(3, $drop["amount"]);
		self::assertSlotInfo($slot, $drop["source"]);

		self::assertSame([["player_action_type" => PlayerAction::START_BREAK, "position" => ["x" => 7, "y" => 8, "z" => 9], "facing" => 1]], $fields["player_block_actions"]);
	}

	public function testInventoryTransactions() : void{
		$drop = self::action(NetworkInventoryAction::SOURCE_WORLD, null, 0, NetworkInventoryAction::ACTION_MAGIC_SLOT_DROP_ITEM, ItemStackWrapper::legacy(ItemStack::null()), self::item());
		$source = self::action(NetworkInventoryAction::SOURCE_CONTAINER, ContainerIds::INVENTORY, null, 4, self::item(), ItemStackWrapper::legacy(ItemStack::null()));
		$changed = [new InventoryTransactionChangedSlotsHack(ContainerUIIds::HOTBAR, [0, 8, 255])];
		/** @phpstan-var InventoryTransactionFields $fields */
		$fields = self::decode(InventoryTransactionPacket::create(-5, $changed, NormalTransactionData::new([$drop, $source])));
		self::assertSame([-5, [["container_enum" => ContainerUIIds::HOTBAR, "slots" => "\x00\x08\xff"]]], [$fields["legacy_request_id"], $fields["legacy_set_item_slots"]]);
		$actions = $fields["transaction"]["normal_transaction_data"] ?? null;
		self::assertNotNull($actions);
		self::assertInventoryAction($drop, $actions[0]);
		self::assertInventoryAction($source, $actions[1]);

		/** @phpstan-var InventoryTransactionFields $fields */
		$fields = self::decode(InventoryTransactionPacket::create(0, null, MismatchTransactionData::new()));
		self::assertSame([], $fields["transaction"]["inventory_mismatch_data"] ?? null);
		self::assertNull($fields["legacy_set_item_slots"]);

		$expected = self::useItemData();
		/** @phpstan-var InventoryTransactionFields $fields */
		$fields = self::decode(InventoryTransactionPacket::create(0, null, $expected));
		$use = $fields["transaction"]["item_use_inventory_transaction"] ?? null;
		self::assertNotNull($use);
		self::assertSame([$expected->getActionType(), $expected->getHotbarSlot(), $expected->getFace()], [$use["action_type"], $use["slot"], $use["face"]]);

		$entity = UseItemOnEntityTransactionData::new([], 42, UseItemOnEntityTransactionData::ACTION_ATTACK, 6, self::item(), new Vector3(1, 2, 3), new Vector3(0.5, 0.25, 0.125));
		/** @phpstan-var InventoryTransactionFields $fields */
		$fields = self::decode(InventoryTransactionPacket::create(0, null, $entity));
		$onActor = $fields["transaction"]["item_use_on_actor_inventory_transaction"] ?? null;
		self::assertNotNull($onActor);
		self::assertSame([42, UseItemOnEntityTransactionData::ACTION_ATTACK, 6], [$onActor["runtime_id"], $onActor["action_type"], $onActor["slot"]]);
		self::assertTrue(Fields::toVector3($onActor["hit_position"])->equals($entity->getClickPosition()));

		$release = ReleaseItemTransactionData::new([], ReleaseItemTransactionData::ACTION_RELEASE, 7, self::item(), new Vector3(1, 2, 3));
		/** @phpstan-var InventoryTransactionFields $fields */
		$fields = self::decode(InventoryTransactionPacket::create(0, null, $release));
		$released = $fields["transaction"]["item_release_inventory_transaction"] ?? null;
		self::assertNotNull($released);
		self::assertSame([ReleaseItemTransactionData::ACTION_RELEASE, 7], [$released["action_type"], $released["slot"]]);
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
		/** @phpstan-var ItemStackRequestFields $fields */
		$fields = self::decode(ItemStackRequestPacket::create([$request]));
		self::assertCount(1, $fields["requests"]);
		$decoded = $fields["requests"][0];
		self::assertSame(-4, $decoded["client_request_id"]);
		$slot = static fn(ItemStackRequestSlotInfo $info) : array => [
			"full_container_name" => ["container_name" => $info->getContainerName()->getContainerId(), "dynamic_id" => null],
			"slot" => $info->getSlotId(),
			"net_id_variant" => $info->getStackId(),
		];
		$actions = [];
		foreach($decoded["actions"] as $action){
			foreach($action as $variant => $body){
				if(is_array($body)){
					unset($body["action_type"]);
				}
				$actions[] = [$variant => $body];
			}
		}
		self::assertSame([
			["take" => ["amount" => 2, "source" => $slot($a), "destination" => $slot($b)]],
			["place" => ["amount" => 3, "source" => $slot($b), "destination" => $slot($a)]],
			["swap" => ["source" => $slot($a), "destination" => $slot($b)]],
			["drop" => ["amount" => 4, "source" => $slot($a), "randomly" => true]],
			["destroy" => ["amount" => 5, "source" => $slot($b)]],
			["consume" => ["amount" => 6, "source" => $slot($a)]],
			["craft_recipe" => ["recipe_net_id" => 1234, "number_of_requested_crafts" => 2]],
			["craft_recipe_auto" => ["recipe_net_id" => 1235, "number_of_requested_crafts" => 3, "ingredients" => []]],
			["craft_creative" => ["creative_item_net_id" => 99, "number_of_requested_crafts" => 1]],
			["create" => ["results_index" => 1]],
			["mine_block" => ["slot" => 3, "predicted_durability" => 120, "net_id_variant" => 11]],
			["take" => ["amount" => 64, "source" => $slot($output), "destination" => $slot($b)]],
		], $actions);
	}

	public function testSimplePackets() : void{
		/** @phpstan-var MobEquipmentFields $mob */
		$mob = self::decode(MobEquipmentPacket::create(1, self::item(), 7, 3, ContainerIds::INVENTORY));
		self::assertSame([ContainerIds::INVENTORY, 3], [$mob["container_id"], $mob["selected_slot"]]);

		$text = new TextPacket();
		$text->type = TextPacket::TYPE_CHAT;
		$text->sourceName = "Steve";
		$text->message = "hello";
		/** @phpstan-var TextFields $chat */
		$chat = self::decode($text);
		self::assertSame(["message_type" => TextPacket::TYPE_CHAT, "player_name" => "Steve", "message" => "hello"], $chat["body"]["author_and_message"] ?? null);

		/** @phpstan-var ModalFormResponseFields $form */
		$form = self::decode(ModalFormResponsePacket::response(5, "[1,2]"));
		self::assertSame([5, "[1,2]", null], [$form["form_id"], $form["json_response"], $form["form_cancel_reason"]]);
		/** @phpstan-var ModalFormResponseFields $form */
		$form = self::decode(ModalFormResponsePacket::cancel(6, 1));
		self::assertSame([6, null, 1], [$form["form_id"], $form["json_response"], $form["form_cancel_reason"]]);

		$interact = new InteractPacket();
		$interact->action = InteractPacket::ACTION_OPEN_INVENTORY;
		$interact->targetActorRuntimeId = 77;
		$interact->position = null;
		/** @phpstan-var InteractFields $interactFields */
		$interactFields = self::decode($interact);
		self::assertSame([InteractPacket::ACTION_OPEN_INVENTORY, 77], [$interactFields["action"], $interactFields["target_runtime_id"]]);

		/** @phpstan-var PlayerActionFields $action */
		$action = self::decode(PlayerActionPacket::create(1, PlayerAction::PREDICT_DESTROY_BLOCK, new BlockPosition(1, 2, 3), new BlockPosition(0, 0, 0), 5));
		self::assertSame([PlayerAction::PREDICT_DESTROY_BLOCK, ["x" => 1, "y" => 2, "z" => 3], 5], [$action["action"], $action["block_position"], $action["face"]]);

		/** @phpstan-var BlockPickRequestFields $blockPick */
		$blockPick = self::decode(BlockPickRequestPacket::create(new BlockPosition(4, 5, 6), true, 2));
		self::assertSame([["x" => 4, "y" => 5, "z" => 6], true], [$blockPick["position"], $blockPick["with_data"]]);
		/** @phpstan-var ActorPickRequestFields $actorPick */
		$actorPick = self::decode(ActorPickRequestPacket::create(-123456789012, 2, true));
		self::assertSame(-123456789012, $actorPick["actor_id"]);
		/** @phpstan-var LecternUpdateFields $lectern */
		$lectern = self::decode(LecternUpdatePacket::create(3, 10, new BlockPosition(7, 8, 9)));
		self::assertSame([3, ["x" => 7, "y" => 8, "z" => 9]], [$lectern["new_page_to_show"], $lectern["position_of_lectern_to_update"]]);
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
			BookEditPacket::TYPE_REPLACE_PAGE => ["replace_page" => ["page_index" => 2, "page_text" => "page", "photo_name" => ""]],
			BookEditPacket::TYPE_ADD_PAGE => ["add_page" => ["page_index" => 2, "page_text" => "page", "photo_name" => ""]],
			BookEditPacket::TYPE_DELETE_PAGE => ["delete_page" => 2],
			BookEditPacket::TYPE_SWAP_PAGES => ["swap_pages" => ["page_index" => 2, "swap_with_index" => 5]],
			BookEditPacket::TYPE_SIGN_BOOK => ["finalize" => ["title" => "title", "author" => "author", "xuid" => "123"]],
		];
		foreach($expected as $type => $operation){
			/** @phpstan-var BookEditFields $fields */
			$fields = self::decode($base($type));
			self::assertSame(["book_slot" => 4, "operation" => $operation], $fields);
		}
	}
}
