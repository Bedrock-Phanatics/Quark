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

namespace quark\network\mcpe\handler;

use Bedwire\Nbt;
use Bedwire\NbtException;
use Bedwire\PacketId;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\InteractPacket as BedrockInteractPacket;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\TextPacket as BedrockTextPacket;
use pocketmine\network\mcpe\protocol\types\GameMode as ProtocolGameMode;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\ReleaseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayerBlockAction;
use pocketmine\utils\Limits;
use quark\block\BaseSign;
use quark\block\Lectern;
use quark\block\tile\Sign;
use quark\block\utils\SignText;
use quark\entity\Attribute;
use quark\entity\InvalidSkinException;
use quark\event\player\PlayerEditBookEvent;
use quark\inventory\transaction\action\DropItemAction;
use quark\inventory\transaction\InventoryTransaction;
use quark\inventory\transaction\TransactionBuilder;
use quark\inventory\transaction\TransactionCancelledException;
use quark\inventory\transaction\TransactionValidationException;
use quark\item\VanillaItems;
use quark\item\WritableBook;
use quark\item\WritableBookPage;
use quark\item\WrittenBook;
use quark\network\FilterNoisyPacketException;
use quark\network\mcpe\convert\ItemTranslator;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\packet\ActorPickRequestPacket;
use quark\network\mcpe\protocol\packet\AnimatePacket;
use quark\network\mcpe\protocol\packet\BlockActorDataPacket;
use quark\network\mcpe\protocol\packet\BlockPickRequestPacket;
use quark\network\mcpe\protocol\packet\BookEditPacket;
use quark\network\mcpe\protocol\packet\CommandRequestPacket;
use quark\network\mcpe\protocol\packet\ContainerClosePacket;
use quark\network\mcpe\protocol\packet\EmotePacket;
use quark\network\mcpe\protocol\packet\InteractPacket;
use quark\network\mcpe\protocol\packet\InventoryTransactionPacket;
use quark\network\mcpe\protocol\packet\ItemStackRequestPacket;
use quark\network\mcpe\protocol\packet\ItemStackResponsePacket;
use quark\network\mcpe\protocol\packet\LecternUpdatePacket;
use quark\network\mcpe\protocol\packet\MobEquipmentPacket;
use quark\network\mcpe\protocol\packet\ModalFormResponsePacket;
use quark\network\mcpe\protocol\packet\PlayerActionPacket;
use quark\network\mcpe\protocol\packet\PlayerAuthInputPacket;
use quark\network\mcpe\protocol\packet\PlayerSkinPacket;
use quark\network\mcpe\protocol\packet\RequestChunkRadiusPacket;
use quark\network\mcpe\protocol\packet\SetPlayerGameTypePacket;
use quark\network\mcpe\protocol\packet\TextPacket;
use quark\network\mcpe\protocol\types\BookEditActionAddPage;
use quark\network\mcpe\protocol\types\BookEditActionFinalize;
use quark\network\mcpe\protocol\types\BookEditActionReplacePage;
use quark\network\mcpe\protocol\types\BookEditActionSwapPages;
use quark\network\mcpe\protocol\types\InventoryAction;
use quark\network\mcpe\protocol\types\ItemReleaseInventoryTransaction;
use quark\network\mcpe\protocol\types\ItemStackRequestData;
use quark\network\mcpe\protocol\types\ItemStackRequestPacketData;
use quark\network\mcpe\protocol\types\ItemUseInventoryTransaction;
use quark\network\mcpe\protocol\types\ItemUseOnActorInventoryTransaction;
use quark\network\mcpe\protocol\types\TextAuthorAndMessage;
use quark\network\PacketHandlingException;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use quark\utils\TextFormat;
use quark\utils\Utils;
use quark\world\format\Chunk;
use function array_key_first;
use function array_push;
use function array_values;
use function count;
use function fmod;
use function get_debug_type;
use function implode;
use function in_array;
use function is_finite;
use function is_int;
use function json_decode;
use function ksort;
use function max;
use function mb_strlen;
use function microtime;
use function ord;
use function sprintf;
use function str_starts_with;
use function strlen;
use const JSON_THROW_ON_ERROR;

/**
 * This handler handles packets related to general gameplay.
 */
#[SilentDiscard(PacketId::ACTOR_EVENT, comment: "Not needed")]
#[SilentDiscard(PacketId::LEVEL_SOUND_EVENT, comment: "Sounds are always handled server side")]
#[SilentDiscard(PacketId::MOB_ARMOUR_EQUIPMENT, comment: "Not needed")]
#[SilentDiscard(PacketId::MOVE_PLAYER, comment: "Not needed, noisy debug when landing on ground")]
#[SilentDiscard(PacketId::NETWORK_STACK_LATENCY, comment: "Not used, noisy debug")]
#[SilentDiscard(PacketId::PLAYER_HOT_BAR, comment: "Not needed")]
#[SilentDiscard(PacketId::SET_ACTOR_MOTION, comment: "Not needed, erroneously sent by client when in a vehicle")]
#[SilentDiscard(PacketId::SPAWN_EXPERIENCE_ORB, comment: "XP drops should be server-calculated")]
#[SilentDiscard(PacketId::SERVER_BOUND_DIAGNOSTICS, comment: "Not needed, noisy debug")]
class InGamePacketHandler extends PacketHandler{
	public const int MAX_ITEM_STACK_REQUESTS = 80;
	public const int MAX_INVENTORY_TRANSACTION_ACTIONS = 50;

	private const int MAX_FORM_RESPONSE_SIZE = 10 * 1024; //10 KiB should be more than enough
	private const int MAX_FORM_RESPONSE_DEPTH = 2; //modal/simple will be 1, custom forms 2 - they will never contain anything other than string|int|float|bool|null

	//TODO: The client-side per-page character limit is inconsistent for non-ASCII text,
	//allowing input beyond 256 chars. Use a slightly higher bounded soft limit to
	//prevent rejected edits while still mitigating book-bomb attacks
	private const int PAGE_LENGTH_SOFT_LIMIT_CHARS = 512;

	private const int MAX_ITEM_STACK_REQUEST_ACTIONS = 60;

	private const int MAX_BLOCK_ACTOR_DATA_SIZE = 16 * 1024;
	private const int MAX_BLOCK_ACTOR_DATA_DEPTH = 8;

	private const int MAX_BLOCK_SYNCS_PER_PACKET = 128;

	protected float $lastRightClickTime = 0.0;
	protected ?ItemUseInventoryTransaction $lastRightClickData = null;

	protected ?Vector3 $lastPlayerAuthInputPosition = null;
	protected ?float $lastPlayerAuthInputYaw = null;
	protected ?float $lastPlayerAuthInputPitch = null;
	/** @phpstan-var array<int, true>|null */
	protected ?array $lastPlayerAuthInputFlags = null;
	/** @phpstan-var list<int>|null */
	protected ?array $lastPlayerAuthInputRawFlags = null;

	protected ?Vector3 $lastBlockAttacked = null;

	public bool $forceMoveSync = false;

	/** @phpstan-var array<string, Vector3>|null */
	private ?array $pendingBlockSyncs = null;

	protected ?string $lastRequestedFullSkinId = null;

	public function __construct(
		private Player $player,
		private NetworkSession $session,
		private InventoryManager $inventoryManager
	){}

	public function handleText(TextPacket $packet) : bool{
		$body = $packet->body["author_and_message"] ?? null;
		if($body instanceof TextAuthorAndMessage && $body->messageType === BedrockTextPacket::TYPE_CHAT){
			return $this->player->chat($body->message);
		}

		return false;
	}

	/**
	 * @phpstan-param array<int, true> $inputFlags
	 */
	private function resolveOnOffInputFlags(array $inputFlags, int $startFlag, int $stopFlag) : ?bool{
		$enabled = isset($inputFlags[$startFlag]);
		$disabled = isset($inputFlags[$stopFlag]);
		if($enabled !== $disabled){
			return $enabled;
		}
		//neither flag was set, or both were set
		return null;
	}

	/**
	 * @phpstan-param list<int> $flags
	 * @phpstan-return array<int, true>
	 * @throws PacketHandlingException
	 */
	private static function readInputFlags(array $flags) : array{
		if(count($flags) > PlayerAuthInputFlags::NUMBER_OF_FLAGS){
			throw PacketHandlingException::limit("Too many input flags in PlayerAuthInputPacket", count($flags), PlayerAuthInputFlags::NUMBER_OF_FLAGS);
		}
		$result = [];
		foreach($flags as $flag){
			if($flag < 0 || $flag >= PlayerAuthInputFlags::NUMBER_OF_FLAGS){
				throw new PacketHandlingException("Unknown input flag $flag");
			}
			if(isset($result[$flag])){
				throw new PacketHandlingException("Duplicate input flag $flag");
			}
			$result[$flag] = true;
		}
		ksort($result);
		return $result;
	}

	/**
	 * Checks every part of the packet before any of it is applied, so a rejected packet leaves no partial effects.
	 * Collection sizes the protocol schema already caps are enforced by the decoder.
	 *
	 * @phpstan-return array<int, true>
	 * @throws PacketHandlingException
	 */
	private function validateAuthInput(PlayerAuthInputPacket $packet) : array{
		$rawFlags = $packet->inputData;
		$inputFlags = ($rawFlags === $this->lastPlayerAuthInputRawFlags ? $this->lastPlayerAuthInputFlags : null) ?? self::readInputFlags($rawFlags);
		if(($packet->vehicleRotation === null) !== ($packet->clientPredictedVehicle === null)){
			throw new PacketHandlingException("Vehicle rotation and actor unique ID must both be present or both be absent");
		}
		if($packet->itemUseTransaction !== null){
			self::checkTransactionActions($packet->itemUseTransaction->itemUseTransaction->actions);
		}
		if($packet->itemStackRequest !== null){
			self::checkItemStackRequest($packet->itemStackRequest);
		}
		foreach($packet->playerBlockActions ?? [] as $blockAction){
			if(!PlayerBlockAction::isValidActionType($blockAction->playerActionType)){
				throw new PacketHandlingException("Invalid player block action type " . $blockAction->playerActionType);
			}
		}
		return $inputFlags;
	}

	/**
	 * @throws PacketHandlingException
	 */
	private static function checkItemStackRequest(ItemStackRequestData|ItemStackRequestPacketData $request) : void{
		if(count($request->actions) > self::MAX_ITEM_STACK_REQUEST_ACTIONS){
			//recipe book auto crafting can affect all slots of the inventory when consuming inputs or producing outputs
			//this means there could be as many as 50 CraftingConsumeInput actions or Place (taking the result) actions
			//in a single request (there are certain ways items can be arranged which will result in the same stack
			//being taken from multiple times, but this is behaviour with a calculable limit)
			//this means there SHOULD be AT MOST 53 actions in a single request, but 60 is a nice round number.
			//n64Stacks = ?
			//n1Stacks = 45 - n64Stacks
			//nItemsRequiredFor1Craft = 9
			//nResults = floor((n1Stacks + (n64Stacks * 64)) / nItemsRequiredFor1Craft)
			//nTakeActionsTotal = floor(64 / nResults) + max(1, 64 % nResults) + ((nResults * nItemsRequiredFor1Craft) - (n64Stacks * 64))
			throw PacketHandlingException::limit("Too many actions in ItemStackRequest", count($request->actions), self::MAX_ITEM_STACK_REQUEST_ACTIONS);
		}
	}

	public function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : bool{
		$inputFlags = $this->validateAuthInput($packet);
		$rawPos = $packet->position;
		$rawPitch = $packet->playerRotation->x;
		$rawYaw = $packet->playerRotation->y;
		if(!is_finite($rawPos->x) || !is_finite($rawPos->y) || !is_finite($rawPos->z) || !is_finite($rawYaw) || !is_finite($packet->playerHeadRotation) || !is_finite($rawPitch)){
			$this->session->getLogger()->debug("Invalid movement received, contains NAN/INF components");
			return false;
		}
		$useItemTransaction = $packet->itemUseTransaction;

		if($rawYaw !== $this->lastPlayerAuthInputYaw || $rawPitch !== $this->lastPlayerAuthInputPitch){
			$this->lastPlayerAuthInputYaw = $rawYaw;
			$this->lastPlayerAuthInputPitch = $rawPitch;

			$yaw = fmod($rawYaw, 360);
			$pitch = fmod($rawPitch, 360);
			if($yaw < 0){
				$yaw += 360;
			}

			$this->player->setRotation($yaw, $pitch);
		}

		$hasMoved = $this->lastPlayerAuthInputPosition === null || !$this->lastPlayerAuthInputPosition->equals($rawPos);
		$newPos = $rawPos->subtract(0, 1.62, 0)->round(4);

		if($this->forceMoveSync && $hasMoved){
			$curPos = $this->player->getLocation();

			if($newPos->distanceSquared($curPos) > 1){  //Tolerate up to 1 block to avoid problems with client-sided physics when spawning in blocks
				$this->session->getLogger()->debug("Got outdated pre-teleport movement, received " . $newPos . ", expected " . $curPos);
				//Still getting movements from before teleport, ignore them
				return true;
			}

			// Once we get a movement within a reasonable distance, treat it as a teleport ACK and remove position lock
			$this->forceMoveSync = false;
		}

		if($inputFlags !== $this->lastPlayerAuthInputFlags){
			$this->lastPlayerAuthInputFlags = $inputFlags;
			$this->lastPlayerAuthInputRawFlags = $packet->inputData;

			$sneakPressed = isset($inputFlags[PlayerAuthInputFlags::SNEAKING]);

			$sneaking = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_SNEAKING, PlayerAuthInputFlags::STOP_SNEAKING);
			$sprinting = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_SPRINTING, PlayerAuthInputFlags::STOP_SPRINTING);
			$swimming = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_SWIMMING, PlayerAuthInputFlags::STOP_SWIMMING);
			$gliding = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_GLIDING, PlayerAuthInputFlags::STOP_GLIDING);
			$flying = $this->resolveOnOffInputFlags($inputFlags, PlayerAuthInputFlags::START_FLYING, PlayerAuthInputFlags::STOP_FLYING);
			$mismatch =
				(!$this->player->toggleSneak($sneaking ?? $this->player->isSneaking(), $sneakPressed)) |
				($sprinting !== null && !$this->player->toggleSprint($sprinting)) |
				($swimming !== null && !$this->player->toggleSwim($swimming)) |
				($gliding !== null && !$this->player->toggleGlide($gliding)) |
				($flying !== null && !$this->player->toggleFlight($flying));
			if((bool) $mismatch){
				$this->player->sendData([$this->player]);
			}

			if(isset($inputFlags[PlayerAuthInputFlags::START_JUMPING])){
				$this->player->jump();
			}
			if(isset($inputFlags[PlayerAuthInputFlags::MISSED_SWING])){
				$this->player->missSwing();
			}
		}

		if(!$this->forceMoveSync && $hasMoved){
			$this->lastPlayerAuthInputPosition = $rawPos;
			//TODO: this packet has WAYYYYY more useful information that we're not using
			$this->player->handleMovement($newPos);
		}

		$packetHandled = true;

		$this->pendingBlockSyncs = [];
		try{
			if($useItemTransaction !== null){
				$transactionData = $useItemTransaction->itemUseTransaction;
				$this->inventoryManager->setCurrentItemStackRequestId($useItemTransaction->legacyRequestId);
				$this->inventoryManager->addRawPredictedSlotChanges($transactionData->actions);
				if(!$this->handleUseItemTransaction($transactionData)){
					$packetHandled = false;
					$this->session->getLogger()->debug("Unhandled transaction in PlayerAuthInputPacket (type " . $transactionData->actionType . ")");
				}else{
					$this->inventoryManager->syncMismatchedPredictedSlotChanges();
				}
				$this->inventoryManager->setCurrentItemStackRequestId(null);
			}

			$itemStackRequest = $packet->itemStackRequest;
			$itemStackResponseBuilder = $itemStackRequest !== null ? $this->handleSingleItemStackRequest($itemStackRequest) : null;

			//itemstack request or transaction may set predictions for the outcome of these actions, so these need to be
			//processed last
			foreach($packet->playerBlockActions ?? [] as $k => $blockAction){
				if(!$this->handlePlayerActionFromData($blockAction->playerActionType, $blockAction->position, $blockAction->facing)){
					$packetHandled = false;
					$this->session->getLogger()->debug("Unhandled player block action at offset $k in PlayerAuthInputPacket");
				}
			}
			$blockSyncs = $this->pendingBlockSyncs;
		}finally{
			$this->pendingBlockSyncs = null;
		}
		if(count($blockSyncs) > 0){
			$this->sendBlockUpdates(array_values($blockSyncs));
		}

		if($itemStackRequest !== null){
			$itemStackResponse = $itemStackResponseBuilder?->build() ?? ItemStackResponseBuilder::error($itemStackRequest->clientRequestId);
			$this->session->sendDataPacket(new ItemStackResponsePacket(responses: [$itemStackResponse]));
		}

		return $packetHandled;
	}

	/**
	 * @phpstan-param list<InventoryAction> $actions
	 * @throws PacketHandlingException
	 */
	private static function checkTransactionActions(array $actions) : void{
		if(count($actions) > self::MAX_INVENTORY_TRANSACTION_ACTIONS){
			throw PacketHandlingException::limit("Too many actions in inventory transaction", count($actions), self::MAX_INVENTORY_TRANSACTION_ACTIONS);
		}
	}

	public function handleInventoryTransaction(InventoryTransactionPacket $packet) : bool{
		$transaction = $packet->transaction;
		$variant = array_key_first($transaction);
		$data = $transaction[$variant];
		$actions = $data instanceof ItemUseInventoryTransaction || $data instanceof ItemUseOnActorInventoryTransaction || $data instanceof ItemReleaseInventoryTransaction ? $data->actions : $data;
		self::checkTransactionActions($actions);
		$requestChangedSlots = $packet->legacySetItemSlots;

		$requestId = $packet->legacyRequestId;
		$this->inventoryManager->setCurrentItemStackRequestId($requestId);
		$this->inventoryManager->addRawPredictedSlotChanges($actions);

		$result = match(true){
			$data instanceof ItemUseInventoryTransaction => $this->handleUseItemTransaction($data),
			$data instanceof ItemUseOnActorInventoryTransaction => $this->handleUseItemOnEntityTransaction($data),
			$data instanceof ItemReleaseInventoryTransaction => $this->handleReleaseItemTransaction($data),
			$variant === "normal_transaction_data" => $this->handleNormalTransaction($actions, $requestId),
			default => $this->handleMismatchTransaction()
		};

		$this->inventoryManager->syncMismatchedPredictedSlotChanges();

		//requestChangedSlots asks the server to always send out the contents of the specified slots, even if they
		//haven't changed. Handling these is necessary to ensure the client inventory stays in sync if the server
		//rejects the transaction. The most common example of this is equipping armor by right-click, which doesn't send
		//a legacy prediction action for the destination armor slot.
		foreach($requestChangedSlots ?? [] as $containerInfo){
			$slots = $containerInfo->slots;
			for($i = 0, $length = strlen($slots); $i < $length; ++$i){
				[$windowId, $slot] = ItemStackContainerIdTranslator::translate($containerInfo->containerEnum, $this->inventoryManager->getCurrentWindowId(), ord($slots[$i]));
				$inventoryAndSlot = $this->inventoryManager->locateWindowAndSlot($windowId, $slot);
				if($inventoryAndSlot !== null){ //trigger the normal slot sync logic
					$this->inventoryManager->onSlotChange($inventoryAndSlot[0], $inventoryAndSlot[1]);
				}
			}
		}

		$this->inventoryManager->setCurrentItemStackRequestId(null);
		return $result;
	}

	private function handleMismatchTransaction() : bool{
		$this->session->getLogger()->debug("Mismatch transaction received");
		$this->inventoryManager->requestSyncAll();
		return true;
	}

	private function executeInventoryTransaction(InventoryTransaction $transaction, int $requestId) : bool{
		$this->player->setUsingItem(false);

		$this->inventoryManager->setCurrentItemStackRequestId($requestId);
		$this->inventoryManager->addTransactionPredictedSlotChanges($transaction);
		try{
			$transaction->execute();
		}catch(TransactionValidationException $e){
			$this->inventoryManager->requestSyncAll();
			$logger = $this->session->getLogger();
			$logger->debug("Invalid inventory transaction $requestId: " . $e->getMessage());

			return false;
		}catch(TransactionCancelledException){
			$this->session->getLogger()->debug("Inventory transaction $requestId cancelled by a plugin");

			return false;
		}finally{
			$this->inventoryManager->syncMismatchedPredictedSlotChanges();
			$this->inventoryManager->setCurrentItemStackRequestId(null);
		}

		return true;
	}

	/**
	 * @phpstan-param list<InventoryAction> $actions
	 */
	private function handleNormalTransaction(array $actions, int $itemStackRequestId) : bool{
		//When the ItemStackRequest system is used, this transaction type is used for dropping items by pressing Q.
		//I don't know why they don't just use ItemStackRequest for that too, which already supports dropping items by
		//clicking them outside an open inventory menu, but for now it is what it is.
		//Fortunately, this means we can be much stricter about the validation criteria.

		$actionCount = count($actions);
		if($actionCount > 2){
			if($actionCount > 5){
				throw new PacketHandlingException("Too many actions ($actionCount) in normal inventory transaction");
			}

			//Due to a bug in the game, this transaction type is still sent when a player edits a book. We don't need
			//these transactions for editing books, since we have BookEditPacket, so we can just ignore them.
			$this->session->getLogger()->debug("Ignoring normal inventory transaction with $actionCount actions (drop-item should have exactly 2 actions)");
			return false;
		}

		$sourceSlot = null;
		$clientItemStack = null;
		$droppedCount = null;

		foreach($actions as $networkInventoryAction){
			$source = $networkInventoryAction->source;
			if($source->sourceType === NetworkInventoryAction::SOURCE_WORLD && $networkInventoryAction->slot === NetworkInventoryAction::ACTION_MAGIC_SLOT_DROP_ITEM){
				$droppedCount = $networkInventoryAction->toItem->stackSize;
				if($droppedCount <= 0){
					throw new PacketHandlingException("Expected positive count for dropped item");
				}
			}elseif($source->sourceType === NetworkInventoryAction::SOURCE_CONTAINER && $source->containerId === ContainerIds::INVENTORY){
				//mobile players can drop an item from a non-selected hotbar slot
				$sourceSlot = $networkInventoryAction->slot;
				$clientItemStack = Fields::toItemStack($networkInventoryAction->fromItem);
			}else{
				$this->session->getLogger()->debug("Unexpected inventory action type " . $source->sourceType . " in drop item transaction");
				return false;
			}
		}
		if($sourceSlot === null || $clientItemStack === null || $droppedCount === null){
			$this->session->getLogger()->debug("Missing information in drop item transaction, need source slot, client item stack and dropped count");
			return false;
		}

		$inventory = $this->player->getInventory();

		if(!$inventory->slotExists($sourceSlot)){
			return false; //TODO: size desync??
		}

		$sourceSlotItem = $inventory->getItem($sourceSlot);
		if($sourceSlotItem->getCount() < $droppedCount){
			return false;
		}
		$serverItemStack = $this->session->getTypeConverter()->coreItemStackToNet($sourceSlotItem);
		//Sadly we don't have itemstack IDs here, so we have to compare the basic item properties to ensure that we're
		//dropping the item the client expects (inventory might be out of sync with the client).
		if(
			$serverItemStack->getId() !== $clientItemStack->getId() ||
			$serverItemStack->getMeta() !== $clientItemStack->getMeta() ||
			$serverItemStack->getCount() !== $clientItemStack->getCount() ||
			$serverItemStack->getBlockRuntimeId() !== $clientItemStack->getBlockRuntimeId()
			//Raw extraData may not match because of TAG_Compound key ordering differences, and decoding it to compare
			//is costly. Assume that we're in sync if id+meta+count+runtimeId match.
			//NB: Make sure $clientItemStack isn't used to create the dropped item, as that would allow the client
			//to change the item NBT since we're not validating it.
		){
			return false;
		}

		//this modifies $sourceSlotItem
		$droppedItem = $sourceSlotItem->pop($droppedCount);

		$builder = new TransactionBuilder();
		$builder->getInventory($inventory)->setItem($sourceSlot, $sourceSlotItem);
		$builder->addAction(new DropItemAction($droppedItem));

		$transaction = new InventoryTransaction($this->player, $builder->generateActions());
		return $this->executeInventoryTransaction($transaction, $itemStackRequestId);
	}

	private function handleUseItemTransaction(ItemUseInventoryTransaction $data) : bool{
		$this->player->selectHotbarSlot($data->slot);

		switch($data->actionType){
			case UseItemTransactionData::ACTION_CLICK_BLOCK:
				//TODO: start hack for client spam bug
				$clickPos = $data->clickPosition;
				$lastData = $this->lastRightClickData;
				$spamBug = ($lastData !== null &&
					microtime(true) - $this->lastRightClickTime < 0.1 && //100ms
					$lastData->face === $data->face &&
					$lastData->fromPosition->distanceSquared($data->fromPosition) < 0.00001 &&
					$lastData->position->equals($data->position) &&
					$lastData->clickPosition->distanceSquared($clickPos) < 0.00001 //signature spam bug has 0 distance, but allow some error
				);
				//get rid of continued spam if the player clicks and holds right-click
				$this->lastRightClickData = $data;
				$this->lastRightClickTime = microtime(true);
				if($spamBug){
					throw new FilterNoisyPacketException();
				}
				//TODO: end hack for client spam bug

				self::validateFacing($data->face);

				$vBlockPos = $data->position;
				$this->player->interactBlock($vBlockPos, $data->face, $clickPos);
				if($data->clientInteractPrediction === PredictedResult::SUCCESS->value){
					//If the item has an associated blockstate ID, this means it will only place one block.
					//We can avoid syncing the adjacent blocks of the place position in this case, since that's only
					//necessary if there might be multiple blocks around the placement location affected.
					//Adjacents of the clicked block are still always synced, since it's too complicated to figure out
					//if the client might've predicted something in this case. However, since the clicked block is always
					//"behind" the placed block, this shouldn't affect bridging or fast placement.
					//This would be much easier if the client would just tell us which blocks it thinks changed...
					$syncAdjacentFace = null;
					if($data->item->blockRuntimeId === ItemTranslator::NO_BLOCK_RUNTIME_ID){
						$this->session->getLogger()->debug("Placing held item might place multiple blocks client-side; doing full adjacent sync");
						$syncAdjacentFace = $data->face;
					}
					$this->syncBlocksNearby($vBlockPos, $syncAdjacentFace);
				}
				return true;
			case UseItemTransactionData::ACTION_CLICK_AIR:
				if($this->player->isUsingItem()){
					if(!$this->player->consumeHeldItem()){
						$hungerAttr = $this->player->getAttributeMap()->get(Attribute::HUNGER) ?? throw new AssumptionFailedError();
						$hungerAttr->markSynchronized(false);
					}
					//TODO: workaround goat horns getting stuck in the "using item" state
					//this timed-trigger behaviour is also used for other items apart from food
					//in the future we'll generalise this logic and add proper hooks for it
					$this->player->setUsingItem(false);
					return true;
				}
				$this->player->useHeldItem();
				return true;
		}

		return false;
	}

	/**
	 * @throws PacketHandlingException
	 */
	private static function validateFacing(int $facing) : void{
		if(!in_array($facing, Facing::ALL, true)){
			throw new PacketHandlingException("Invalid facing value $facing");
		}
	}

	/**
	 * Syncs blocks nearby to ensure that the client and server agree on the world's blocks after a block interaction.
	 */
	private function syncBlocksNearby(Vector3 $blockPos, ?int $face) : void{
		if($blockPos->distanceSquared($this->player->getLocation()) < 10000){
			$blocks = $blockPos->sidesArray();
			if($face !== null){
				$sidePos = $blockPos->getSide($face);

				/** @var Vector3[] $blocks */
				array_push($blocks, ...$sidePos->sidesArray()); //getAllSides() on each of these will include $blockPos and $sidePos because they are next to each other
			}else{
				$blocks[] = $blockPos;
			}
			if($this->pendingBlockSyncs === null){
				$this->sendBlockUpdates($blocks);
				return;
			}
			foreach($blocks as $block){
				if(count($this->pendingBlockSyncs) >= self::MAX_BLOCK_SYNCS_PER_PACKET){
					break;
				}
				$this->pendingBlockSyncs["$block->x:$block->y:$block->z"] ??= $block;
			}
		}
	}

	/**
	 * @param Vector3[] $blocks
	 */
	private function sendBlockUpdates(array $blocks) : void{
		foreach($this->player->getWorld()->createBlockUpdatePackets($blocks) as $packet){
			$this->session->sendDataPacket($packet);
		}
	}

	private function handleUseItemOnEntityTransaction(ItemUseOnActorInventoryTransaction $data) : bool{
		$target = $this->player->getWorld()->getEntity($data->runtimeId);
		//TODO: HACK! We really shouldn't be keeping disconnected players (and generally flagged-for-despawn entities)
		//in the world's entity table, but changing that is too risky for a hotfix. This workaround will do for now.
		if($target === null || $target->isFlaggedForDespawn()){
			return false;
		}

		$this->player->selectHotbarSlot($data->slot);

		switch($data->actionType){
			case UseItemOnEntityTransactionData::ACTION_INTERACT:
				$this->player->interactEntity($target, $data->hitPosition);
				return true;
			case UseItemOnEntityTransactionData::ACTION_ATTACK:
				$this->player->attackEntity($target);
				return true;
		}

		return false;
	}

	private function handleReleaseItemTransaction(ItemReleaseInventoryTransaction $data) : bool{
		$this->player->selectHotbarSlot($data->slot);

		if($data->actionType === ReleaseItemTransactionData::ACTION_RELEASE){
			$this->player->releaseHeldItem();
			return true;
		}

		return false;
	}

	private function handleSingleItemStackRequest(ItemStackRequestData|ItemStackRequestPacketData $request) : ?ItemStackResponseBuilder{
		$executor = new ItemStackRequestExecutor($this->player, $this->inventoryManager, $request);
		try{
			$transaction = $executor->generateInventoryTransaction();
			if($transaction !== null){
				$result = $this->executeInventoryTransaction($transaction, $request->clientRequestId);
			}else{
				$result = true; //predictions only, just send responses
			}
		}catch(ItemStackRequestProcessException $e){
			$result = false;
			$this->session->getLogger()->debug("ItemStackRequest #" . $request->clientRequestId . " failed: " . $e->getMessage());
			$this->session->getLogger()->debug(implode("\n", Utils::printableExceptionInfo($e)));
			$this->inventoryManager->requestSyncAll();
		}

		return $result ? $executor->getItemStackResponseBuilder() : null;
	}

	public function handleItemStackRequest(ItemStackRequestPacket $packet) : bool{
		$responses = [];
		if(count($packet->requests) > self::MAX_ITEM_STACK_REQUESTS){
			throw PacketHandlingException::limit("Too many requests in ItemStackRequestPacket", count($packet->requests), self::MAX_ITEM_STACK_REQUESTS);
		}
		foreach($packet->requests as $request){
			self::checkItemStackRequest($request);
		}
		foreach($packet->requests as $request){
			$responses[] = $this->handleSingleItemStackRequest($request)?->build() ?? ItemStackResponseBuilder::error($request->clientRequestId);
		}

		$this->session->sendDataPacket(new ItemStackResponsePacket(responses: $responses));

		return true;
	}

	public function handleMobEquipment(MobEquipmentPacket $packet) : bool{
		if($packet->containerId === ContainerIds::OFFHAND){
			return true; //this happens when we put an item into the offhand
		}
		if($packet->containerId === ContainerIds::INVENTORY){
			$this->inventoryManager->onClientSelectHotbarSlot($packet->selectedSlot);
			if(!$this->player->selectHotbarSlot($packet->selectedSlot)){
				$this->inventoryManager->syncSelectedHotbarSlot();
			}
			return true;
		}
		return false;
	}

	public function handleInteract(InteractPacket $packet) : bool{
		if($packet->action === BedrockInteractPacket::ACTION_MOUSEOVER){
			//TODO HACK: silence useless spam (MCPE 1.8)
			//due to some messy Mojang hacks, it sends this when changing the held item now, which causes us to think
			//the inventory was closed when it wasn't.
			//this is also sent whenever entity metadata updates, which can get really spammy.
			//TODO: implement handling for this where it matters
			return true;
		}
		$target = $this->player->getWorld()->getEntity($packet->targetRuntimeId);
		if($target === null){
			return false;
		}
		if($packet->action === BedrockInteractPacket::ACTION_OPEN_INVENTORY && $target === $this->player){
			$this->inventoryManager->onClientOpenMainInventory();
			return true;
		}
		return false; //TODO
	}

	public function handleBlockPickRequest(BlockPickRequestPacket $packet) : bool{
		return $this->player->pickBlock($packet->position, $packet->withData);
	}

	public function handleActorPickRequest(ActorPickRequestPacket $packet) : bool{
		return $this->player->pickEntity($packet->actorId);
	}

	public function handlePlayerAction(PlayerActionPacket $packet) : bool{
		return $this->handlePlayerActionFromData($packet->action, $packet->blockPosition, $packet->face);
	}

	private function handlePlayerActionFromData(int $action, Vector3 $pos, int $face) : bool{
		switch($action){
			case PlayerAction::START_BREAK:
			case PlayerAction::CONTINUE_DESTROY_BLOCK: //destroy the next block while holding down left click
				self::validateFacing($face);
				if($this->lastBlockAttacked !== null && $pos->equals($this->lastBlockAttacked)){
					//the client will send CONTINUE_DESTROY_BLOCK for the currently targeted block directly before it
					//sends PREDICT_DESTROY_BLOCK, but also when it starts to break the block
					//this seems like a bug in the client and would cause spurious left-click events if we allowed it to
					//be delivered to the player
					$this->session->getLogger()->debug("Ignoring PlayerAction $action on $pos because we were already destroying this block");
					break;
				}
				if(!$this->player->attackBlock($pos, $face)){
					$this->syncBlocksNearby($pos, $face);
				}
				$this->lastBlockAttacked = $pos;

				break;

			case PlayerAction::ABORT_BREAK:
			case PlayerAction::STOP_BREAK:
				$this->player->stopBreakBlock($pos);
				$this->lastBlockAttacked = null;
				break;
			case PlayerAction::START_SLEEPING:
				//unused
				break;
			case PlayerAction::STOP_SLEEPING:
				$this->player->stopSleep();
				break;
			case PlayerAction::CRACK_BREAK:
				self::validateFacing($face);
				$this->player->continueBreakBlock($pos, $face);
				$this->lastBlockAttacked = $pos;
				break;
			case PlayerAction::INTERACT_BLOCK: //TODO: ignored (for now)
				break;
			case PlayerAction::CREATIVE_PLAYER_DESTROY_BLOCK:
				//in server auth block breaking, we get PREDICT_DESTROY_BLOCK anyway, so this action is redundant
				break;
			case PlayerAction::PREDICT_DESTROY_BLOCK:
				self::validateFacing($face);
				if(!$this->player->breakBlock($pos)){
					$this->syncBlocksNearby($pos, $face);
				}
				$this->lastBlockAttacked = null;
				break;
			case PlayerAction::START_ITEM_USE_ON:
			case PlayerAction::STOP_ITEM_USE_ON:
				//TODO: this has no obvious use and seems only used for analytics in vanilla - ignore it
				break;
			default:
				$this->session->getLogger()->debug("Unhandled/unknown player action type " . $action);
				return false;
		}

		$this->player->setUsingItem(false);

		return true;
	}

	public function handleAnimate(AnimatePacket $packet) : bool{
		//this spams harder than a firehose on left click if "Improved Input Response" is enabled, and we don't even
		//use it anyway :<
		throw new FilterNoisyPacketException();
	}

	public function handleContainerClose(ContainerClosePacket $packet) : bool{
		$this->inventoryManager->onClientRemoveWindow($packet->containerId);
		return true;
	}

	/**
	 * @throws PacketHandlingException
	 */
	private function updateSignText(CompoundTag $nbt, string $tagName, bool $frontFace, BaseSign $block, Vector3 $pos) : bool{
		$textTag = $nbt->getTag($tagName);
		if(!$textTag instanceof CompoundTag){
			throw new PacketHandlingException("Invalid tag type " . get_debug_type($textTag) . " for tag \"$tagName\" in sign update data");
		}
		$textBlobTag = $textTag->getTag(Sign::TAG_TEXT_BLOB);
		if(!$textBlobTag instanceof StringTag){
			throw new PacketHandlingException("Invalid tag type " . get_debug_type($textBlobTag) . " for tag \"" . Sign::TAG_TEXT_BLOB . "\" in sign update data");
		}

		try{
			$text = SignText::fromBlob($textBlobTag->getValue());
		}catch(\InvalidArgumentException $e){
			throw PacketHandlingException::wrap($e, "Invalid sign text update");
		}

		$oldText = $block->getFaceText($frontFace);
		if($text->getLines() === $oldText->getLines()){
			return false;
		}

		try{
			if(!$block->updateFaceText($this->player, $frontFace, $text)){
				foreach($this->player->getWorld()->createBlockUpdatePackets([$pos]) as $updatePacket){
					$this->session->sendDataPacket($updatePacket);
				}
				return false;
			}
			return true;
		}catch(\UnexpectedValueException $e){
			throw PacketHandlingException::wrap($e);
		}
	}

	public function handleBlockActorData(BlockActorDataPacket $packet) : bool{
		if(strlen($packet->actorDataTags) > self::MAX_BLOCK_ACTOR_DATA_SIZE){
			throw PacketHandlingException::limit("Block actor data too large", strlen($packet->actorDataTags), self::MAX_BLOCK_ACTOR_DATA_SIZE);
		}
		$pos = $packet->blockPosition;
		if($pos->distanceSquared($this->player->getLocation()) > 10000){
			return false;
		}

		$block = $this->player->getLocation()->getWorld()->getBlock($pos);
		if($block instanceof BaseSign){
			try{
				$nbt = Nbt::read($packet->actorDataTags, Nbt::NETWORK, 0, self::MAX_BLOCK_ACTOR_DATA_DEPTH)->mustGetCompoundTag();
			}catch(NbtException|NbtDataException $e){
				throw PacketHandlingException::wrap($e, "Expected TAG_Compound NBT root");
			}
			if(!$this->updateSignText($nbt, Sign::TAG_FRONT_TEXT, true, $block, $pos)){
				//only one side can be updated at a time
				$this->updateSignText($nbt, Sign::TAG_BACK_TEXT, false, $block, $pos);
			}

			return true;
		}

		return false;
	}

	public function handleSetPlayerGameType(SetPlayerGameTypePacket $packet) : bool{
		$protocolGameMode = ProtocolGameMode::tryFrom($packet->playerGameType) ?? throw new PacketHandlingException("Unknown game mode " . $packet->playerGameType);
		$gameMode = $this->session->getTypeConverter()->protocolGameModeToCore($protocolGameMode);
		if($gameMode !== $this->player->getGamemode()){
			//Set this back to default. TODO: handle this properly
			$this->session->syncGameMode($this->player->getGamemode(), true);
		}
		return true;
	}

	public function handleRequestChunkRadius(RequestChunkRadiusPacket $packet) : bool{
		$this->player->setViewDistance($packet->chunkRadius);

		return true;
	}

	public function handleCommandRequest(CommandRequestPacket $packet) : bool{
		if(str_starts_with($packet->command, '/')){
			$this->player->chat($packet->command);
			return true;
		}
		return false;
	}

	public function handlePlayerSkin(PlayerSkinPacket $packet) : bool{
		$fullSkinId = $packet->serializedSkin->fullId;
		if($fullSkinId === $this->lastRequestedFullSkinId){
			//TODO: HACK! In 1.19.60, the client sends its skin back to us if we sent it a skin different from the one
			//it's using. We need to prevent this from causing a feedback loop.
			$this->session->getLogger()->debug("Refused duplicate skin change request");
			return true;
		}
		$this->lastRequestedFullSkinId = $fullSkinId;

		$this->session->getLogger()->debug("Processing skin change request");
		try{
			$skin = $this->session->getTypeConverter()->getSkinAdapter()->fromSkinData(Fields::toSkinData($packet->serializedSkin));
		}catch(InvalidSkinException|PacketDecodeException|\InvalidArgumentException $e){
			throw PacketHandlingException::wrap($e, "Invalid skin in PlayerSkinPacket");
		}
		return $this->player->changeSkin($skin, $packet->localizedNewSkinName, $packet->localizedOldSkinName);
	}

	/**
	 * @throws PacketHandlingException
	 */
	private function checkBookText(string $string, string $fieldName, int $softLimit, int $hardLimit, bool &$cancel) : string{
		if(strlen($string) > $hardLimit){
			throw new PacketHandlingException(sprintf("Book %s must be at most %d bytes, but have %d bytes", $fieldName, $hardLimit, strlen($string)));
		}

		$result = TextFormat::clean($string, false);
		//strlen() is O(1), mb_strlen() is O(n)
		if(strlen($result) > $softLimit * 4 || mb_strlen($result, 'UTF-8') > $softLimit){
			$cancel = true;
			$this->session->getLogger()->debug("Cancelled book edit due to $fieldName exceeded soft limit of $softLimit chars");
		}

		return $result;
	}

	public function handleBookEdit(BookEditPacket $packet) : bool{
		$inventory = $this->player->getInventory();
		$inventorySlot = $packet->bookSlot;
		if(!$inventory->slotExists($inventorySlot)){
			return false;
		}
		//TODO: break this up into book API things
		$oldBook = $inventory->getItem($inventorySlot);
		if(!($oldBook instanceof WritableBook)){
			return false;
		}

		$newBook = clone $oldBook;
		$modifiedPages = [];
		$cancel = false;
		$operation = array_values($packet->operation)[0];
		$maxPageNumber = max(49, count($oldBook->getPages()));
		if($operation instanceof BookEditActionReplacePage){
			$pageNumber = $operation->pageIndex;
			$text = self::checkBookText($operation->pageText, "page text", self::PAGE_LENGTH_SOFT_LIMIT_CHARS, WritableBookPage::PAGE_LENGTH_HARD_LIMIT_BYTES, $cancel);
			if($pageNumber < 0){
				throw new PacketHandlingException("Page number cannot be negative");
			}
			if($pageNumber > $maxPageNumber){
				throw PacketHandlingException::limit("Book page number too large", $pageNumber, $maxPageNumber);
			}
			$newBook->setPageText($pageNumber, $text);
			$modifiedPages[] = $pageNumber;
			$action = PlayerEditBookEvent::ACTION_REPLACE_PAGE;
		}elseif($operation instanceof BookEditActionAddPage){
			$pageNumber = $operation->pageIndex;
			if(!$newBook->pageExists($pageNumber)){
				//this may only come before a page which already exists
				//TODO: the client can send insert-before actions on trailing client-side pages which cause odd behaviour on the server
				return false;
			}
			$text = self::checkBookText($operation->pageText, "page text", self::PAGE_LENGTH_SOFT_LIMIT_CHARS, WritableBookPage::PAGE_LENGTH_HARD_LIMIT_BYTES, $cancel);
			$newBook->insertPage($pageNumber, $text);
			$modifiedPages[] = $pageNumber;
			$action = PlayerEditBookEvent::ACTION_ADD_PAGE;
		}elseif(is_int($operation)){
			$pageNumber = $operation;
			if(!$newBook->pageExists($pageNumber)){
				return false;
			}
			$newBook->deletePage($pageNumber);
			$modifiedPages[] = $pageNumber;
			$action = PlayerEditBookEvent::ACTION_DELETE_PAGE;
		}elseif($operation instanceof BookEditActionSwapPages){
			$pageNumber = $operation->pageIndex;
			$secondaryPageNumber = $operation->swapWithIndex;
			if($pageNumber < 0 || $secondaryPageNumber < 0){
				throw new PacketHandlingException("Page numbers cannot be negative");
			}
			if(max($pageNumber, $secondaryPageNumber) > $maxPageNumber){
				throw PacketHandlingException::limit("Book page number too large", max($pageNumber, $secondaryPageNumber), $maxPageNumber);
			}
			if(!$newBook->pageExists($pageNumber) || !$newBook->pageExists($secondaryPageNumber)){
				//the client will create pages on its own without telling us until it tries to switch them
				$newBook->addPage(max($pageNumber, $secondaryPageNumber));
			}
			$newBook->swapPages($pageNumber, $secondaryPageNumber);
			$modifiedPages = [$pageNumber, $secondaryPageNumber];
			$action = PlayerEditBookEvent::ACTION_SWAP_PAGES;
		}elseif($operation instanceof BookEditActionFinalize){
			$title = self::checkBookText($operation->title, "title", 16, Limits::INT16_MAX, $cancel);
			//this one doesn't have a limit in vanilla, so we have to improvise
			$author = self::checkBookText($operation->author, "author", 256, Limits::INT16_MAX, $cancel);

			$newBook = VanillaItems::WRITTEN_BOOK()
				->setPages($oldBook->getPages())
				->setAuthor($author)
				->setTitle($title)
				->setGeneration(WrittenBook::GENERATION_ORIGINAL);
			$action = PlayerEditBookEvent::ACTION_SIGN_BOOK;
		}else{
			return false;
		}

		/*
		 * Plugins may have created books with more than 50 pages; we allow plugins to do this, but not players.
		 * Don't allow the page count to grow past 50, but allow deleting, swapping or altering text of existing pages.
		 */
		$oldPageCount = count($oldBook->getPages());
		$newPageCount = count($newBook->getPages());
		if(($newPageCount > $oldPageCount && $newPageCount > 50)){
			$this->session->getLogger()->debug("Cancelled book edit due to adding too many pages (new page count would be $newPageCount)");
			$cancel = true;
		}

		$event = new PlayerEditBookEvent($this->player, $oldBook, $newBook, $action, $modifiedPages);
		if($cancel){
			$event->cancel();
		}

		$event->call();
		if($event->isCancelled()){
			return true;
		}

		$this->player->getInventory()->setItem($inventorySlot, $event->getNewBook());

		return true;
	}

	public function handleModalFormResponse(ModalFormResponsePacket $packet) : bool{
		$formId = $packet->formId;
		$formData = $packet->jsonResponse;
		if($packet->formCancelReason !== null){
			//TODO: make APIs for this to allow plugins to use this information
			return $this->player->onFormSubmit($formId, null);
		}elseif($formData !== null){
			if(strlen($formData) > self::MAX_FORM_RESPONSE_SIZE){
				throw new PacketHandlingException("Form response data too large, refusing to decode (received" . strlen($formData) . " bytes, max " . self::MAX_FORM_RESPONSE_SIZE . " bytes)");
			}
			if(!$this->player->hasPendingForm($formId)){
				$this->session->getLogger()->debug("Got unexpected response for form $formId");
				return false;
			}
			try{
				$responseData = json_decode($formData, true, self::MAX_FORM_RESPONSE_DEPTH, JSON_THROW_ON_ERROR);
			}catch(\JsonException $e){
				throw PacketHandlingException::wrap($e, "Failed to decode form response data");
			}
			return $this->player->onFormSubmit($formId, $responseData);
		}else{
			throw new PacketHandlingException("Expected either formData or cancelReason to be set in ModalFormResponsePacket");
		}
	}

	public function handleLecternUpdate(LecternUpdatePacket $packet) : bool{
		$pos = $packet->positionOfLecternToUpdate;
		$x = (int) $pos->x;
		$y = (int) $pos->y;
		$z = (int) $pos->z;
		$world = $this->player->getWorld();
		if(!$world->isChunkLoaded($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE) || $world->isChunkLocked($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)){
			return false;
		}

		$lectern = $world->getBlockAt($x, $y, $z);
		if($lectern instanceof Lectern && $this->player->canInteract($lectern->getPosition(), 15)){
			if(!$lectern->onPageTurn($packet->newPageToShow)){
				$this->syncBlocksNearby($lectern->getPosition(), null);
			}
			return true;
		}

		return false;
	}

	public function handleEmote(EmotePacket $packet) : bool{
		$this->player->emote($packet->emoteId);
		return true;
	}
}
