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

namespace quark\event;

use PHPUnit\Framework\TestCase;
use quark\entity\AttributeMap;
use quark\entity\Human;
use quark\entity\HungerManager;
use quark\event\entity\EntityExhaustEvent;
use quark\event\fixtures\TestChildEvent;
use quark\event\fixtures\TestGrandchildEvent;
use quark\event\fixtures\TestParentEvent;
use quark\plugin\Plugin;
use quark\plugin\PluginManager;
use quark\Server;

final class EventTest extends TestCase{

	private Plugin $mockPlugin;
	private PluginManager $pluginManager;

	protected function setUp() : void{
		HandlerListManager::global()->unregisterAll();

		//TODO: this is a really bad hack and could break any time if PluginManager decides to access its Server field
		//we really need to make it possible to register events without a Plugin or Server context
		$mockServer = $this->createMock(Server::class);
		$this->mockPlugin = self::createStub(Plugin::class);
		$this->mockPlugin->method('isEnabled')->willReturn(true);

		$this->pluginManager = new PluginManager($mockServer, null);
	}

	public static function tearDownAfterClass() : void{
		HandlerListManager::global()->unregisterAll();
	}

	public function testHandlerInheritance() : void{
		$expectedOrder = [
			TestGrandchildEvent::class,
			TestChildEvent::class,
			TestParentEvent::class
		];
		$actualOrder = [];

		foreach($expectedOrder as $class){
			$this->pluginManager->registerEvent(
				$class,
				function(TestParentEvent $event) use (&$actualOrder, $class) : void{
					$actualOrder[] = $class;
				},
				EventPriority::NORMAL,
				$this->mockPlugin
			);
		}

		$event = new TestGrandchildEvent();
		$event->call();

		self::assertSame($expectedOrder, $actualOrder, "Expected event handlers to be called from most specific to least specific");
	}

	public function testHumanExhaustionEvent() : void{
		$human = new \ReflectionClass(ExhaustionTestHuman::class)->newInstanceWithoutConstructor();
		$human->initializeAttributes();
		$hunger = new HungerManager($human);
		self::assertSame(0.5, $hunger->exhaust(0.5));

		$calls = 0;
		$this->pluginManager->registerEvent(
			EntityExhaustEvent::class,
			function(EntityExhaustEvent $event) use ($human, &$calls) : void{
				self::assertSame($human, $event->getEntity());
				self::assertSame(EntityExhaustEvent::CAUSE_CUSTOM, $event->getCause());
				self::assertSame(1.0, $event->getAmount());
				$event->setAmount(4.0);
				if(++$calls === 2){
					$event->cancel();
				}
			},
			EventPriority::NORMAL,
			$this->mockPlugin
		);

		self::assertSame(4.0, $hunger->exhaust(1.0));
		self::assertSame(0.5, $hunger->getExhaustion());
		self::assertSame(19.0, $hunger->getSaturation());
		self::assertSame(0.0, $hunger->exhaust(1.0));
		self::assertSame(0.5, $hunger->getExhaustion());
		self::assertSame(19.0, $hunger->getSaturation());
		self::assertSame(2, $calls);
	}
}

final class ExhaustionTestHuman extends Human{
	public function initializeAttributes() : void{
		$this->attributeMap = new AttributeMap();
	}

	public function __destruct(){}
}
