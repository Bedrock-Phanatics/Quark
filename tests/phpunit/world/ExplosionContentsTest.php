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

namespace quark\world;

use PHPUnit\Framework\TestCase;
use quark\block\Block;
use quark\block\tile\Jukebox as TileJukebox;
use quark\block\tile\Lectern as TileLectern;
use quark\block\tile\ShulkerBox as TileShulkerBox;
use quark\block\VanillaBlocks;
use quark\entity\object\ItemEntity;
use quark\event\block\BlockExplodeEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\plugin\Plugin;
use quark\plugin\PluginManager;
use quark\Server;
use quark\world\redstone\RedstoneHarness;
use function array_filter;
use function array_map;
use function array_values;
use function mt_srand;

final class ExplosionContentsTest extends TestCase{

	protected function tearDown() : void{
		HandlerListManager::global()->unregisterAll();
		mt_srand();
	}

	/** @return list<Item> */
	private static function explode(World $world, ?Block $what = null) : array{
		$explosion = new Explosion(new Position(0.5, 1.5, 0.5, $world), 4.0, $what);
		self::assertTrue($explosion->explodeA());
		self::assertTrue($explosion->explodeB());
		$items = array_filter($world->getEntities(), fn($entity) => $entity instanceof ItemEntity && !$entity->isFlaggedForDespawn());
		return array_values(array_map(fn(ItemEntity $entity) => $entity->getItem(), $items));
	}

	public function testExplodedShulkerBoxesAlwaysKeepTheirContents() : void{
		//TNT yield is 25%, so most of these seeds used to delete the box and its contents
		for($seed = 0; $seed < 8; ++$seed){
			mt_srand($seed);
			$h = new RedstoneHarness(1);
			$h->set(2, 1, 0, VanillaBlocks::SHULKER_BOX());
			$tile = $h->world->getTileAt(2, 1, 0);
			self::assertInstanceOf(TileShulkerBox::class, $tile);
			$tile->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(64));

			$boxes = array_values(array_filter(self::explode($h->world), fn(Item $item) => $item->equals(VanillaBlocks::SHULKER_BOX()->asItem(), true, false)));

			self::assertCount(1, $boxes, "seed $seed");
			self::assertSame(1, $boxes[0]->getNamedTag()->getListTag("Items")?->count(), "seed $seed");
		}
	}

	public function testZeroYieldStillDropsNothing() : void{
		$plugin = self::createStub(Plugin::class);
		$plugin->method('isEnabled')->willReturn(true);
		(new PluginManager($this->createMock(Server::class), null))->registerEvent(BlockExplodeEvent::class, static function(BlockExplodeEvent $event) : void{
			$event->setYield(0.0);
		}, EventPriority::NORMAL, $plugin);
		$h = new RedstoneHarness(1);
		$h->set(2, 1, 0, VanillaBlocks::SHULKER_BOX());

		self::assertSame([], self::explode($h->world, $h->get(0, 1, 0)));
	}

	public function testExplodedJukeboxesAlwaysDropTheirRecord() : void{
		for($seed = 0; $seed < 8; ++$seed){
			mt_srand($seed);
			$h = new RedstoneHarness(1);
			$h->set(2, 1, 0, VanillaBlocks::JUKEBOX());
			$tile = $h->world->getTileAt(2, 1, 0);
			self::assertInstanceOf(TileJukebox::class, $tile);
			$tile->setRecord(VanillaItems::RECORD_CAT());

			$records = array_filter(self::explode($h->world), fn(Item $item) => $item->equals(VanillaItems::RECORD_CAT()));

			self::assertCount(1, $records, "seed $seed");
		}
	}

	public function testExplodedLecternsAlwaysDropTheirBook() : void{
		for($seed = 0; $seed < 8; ++$seed){
			mt_srand($seed);
			$h = new RedstoneHarness(1);
			$h->set(2, 1, 0, VanillaBlocks::LECTERN());
			$tile = $h->world->getTileAt(2, 1, 0);
			self::assertInstanceOf(TileLectern::class, $tile);
			$tile->setBook(VanillaItems::WRITABLE_BOOK());

			$books = array_filter(self::explode($h->world), fn(Item $item) => $item->equals(VanillaItems::WRITABLE_BOOK()));

			self::assertCount(1, $books, "seed $seed");
		}
	}
}
