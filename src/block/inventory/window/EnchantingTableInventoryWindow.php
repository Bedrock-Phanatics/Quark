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

namespace quark\block\inventory\window;

use quark\event\player\PlayerEnchantingOptionsRequestEvent;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\Inventory;
use quark\inventory\InventoryListener;
use quark\inventory\SimpleInventory;
use quark\inventory\transaction\action\validator\CallbackSlotValidator;
use quark\inventory\transaction\TransactionValidationException;
use quark\item\enchantment\EnchantingHelper as Helper;
use quark\item\enchantment\EnchantingOption;
use quark\item\Item;
use quark\player\Player;
use quark\player\TemporaryInventoryWindow;
use quark\world\Position;
use function array_values;

final class EnchantingTableInventoryWindow extends BlockInventoryWindow implements TemporaryInventoryWindow{
	public const SLOT_INPUT = 0;
	public const SLOT_LAPIS = 1;

	/**
	 * @var EnchantingOption[]
	 * @phpstan-var list<EnchantingOption>
	 */
	private array $options = [];
	private InventoryListener $listener;

	public function __construct(Player $viewer, Position $holder){
		$inventory = new SimpleInventory(2);
		//enchanting consumes the whole input stack, so a stack here would be enchanted for the price of one item
		$inventory->getSlotValidators()->add(new CallbackSlotValidator(
			static fn(Inventory $_, Item $item, int $slot) : ?TransactionValidationException =>
				$slot === self::SLOT_INPUT && $item->getCount() !== 1 ?
					new TransactionValidationException("Enchanting table input must contain exactly 1 item") : null
		));
		parent::__construct($viewer, $inventory, $holder);

		//the inventory is ours alone, a strong reference here would make a cycle
		$weakThis = \WeakReference::create($this);
		$this->listener = new CallbackInventoryListener(
			onSlotChange: static function(Inventory $_, int $slot) use ($weakThis) : void{
				if($slot === self::SLOT_INPUT){
					$weakThis->get()?->regenerateOptions();
				}
			},
			onContentChange: static function() use ($weakThis) : void{
				$weakThis->get()?->regenerateOptions();
			}
		);
	}

	public function onOpen() : void{
		parent::onOpen();
		if($this->viewer->getCurrentWindow() === $this){
			$this->inventory->getListeners()->add($this->listener);
		}
	}

	public function onClose() : void{
		$this->inventory->getListeners()->remove($this->listener);
		$this->options = [];
		parent::onClose();
	}

	public function __destruct(){
		$this->inventory->getListeners()->remove($this->listener);
	}

	private function regenerateOptions() : void{
		if($this->viewer->getCurrentWindow() !== $this){
			return;
		}
		$this->options = [];
		$input = $this->getInput();
		$options = Helper::generateOptions($this->holder, $input, $this->viewer->getEnchantmentSeed());
		$event = new PlayerEnchantingOptionsRequestEvent($this->viewer, $this, $options);
		$event->call();
		if($this->viewer->getCurrentWindow() !== $this || !$input->equalsExact($this->getInput())){
			return;
		}
		$this->options = $event->isCancelled() ? [] : array_values($event->getOptions());
		$this->viewer->getNetworkSession()->getInvManager()?->syncEnchantingTableOptions($this->options);
	}

	public function getInput() : Item{
		return $this->inventory->getItem(self::SLOT_INPUT);
	}

	public function getLapis() : Item{
		return $this->inventory->getItem(self::SLOT_LAPIS);
	}

	public function getOutput(int $optionId) : ?Item{
		$option = $this->getOption($optionId);
		return $option === null ? null : Helper::enchantItem($this->getInput(), $option->getEnchantments());
	}

	public function getOption(int $optionId) : ?EnchantingOption{
		return $this->options[$optionId] ?? null;
	}
}
