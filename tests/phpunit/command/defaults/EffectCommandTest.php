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

namespace quark\command\defaults;

use quark\entity\effect\Effect;
use quark\entity\effect\EffectInstance;
use quark\entity\effect\EffectManager;
use quark\entity\effect\VanillaEffects;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use function in_array;

final class EffectCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var list<EffectInstance> */
	private array $added = [];
	/** @phpstan-var list<Effect> */
	private array $removed = [];
	private int $cleared = 0;
	/** @phpstan-var list<Effect> */
	private array $held = [];

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$effects = self::createStub(EffectManager::class);
		$effects->method("add")->willReturnCallback(function(EffectInstance $instance) : bool{
			$this->added[] = $instance;
			return true;
		});
		$effects->method("remove")->willReturnCallback(function(Effect $effect) : void{
			$this->removed[] = $effect;
		});
		$effects->method("clear")->willReturnCallback(function() : void{
			++$this->cleared;
		});
		$effects->method("has")->willReturnCallback(fn(Effect $effect) : bool => in_array($effect, $this->held, true));
		$effects->method("all")->willReturnCallback(fn() : array => $this->held);
		$player->method("getEffects")->willReturn($effects);
		return $player;
	}

	public function testTimedEffect() : void{
		$steve = $this->spawn("Steve");
		$messages = $this->dispatch("effect Steve speed 30 2", $steve);
		self::assertCount(1, $this->added);
		self::assertSame([VanillaEffects::SPEED(), 600, 2, true, false], $this->summary($this->added[0]));
		self::assertCount(1, $messages);
		self::assertStringContainsString("30 seconds", $messages[0]);
	}

	/**
	 * @return mixed[]
	 */
	private function summary(EffectInstance $instance) : array{
		return [$instance->getType(), $instance->getDuration(), $instance->getAmplifier(), $instance->isVisible(), $instance->isInfinite()];
	}

	public function testDefaultsAndOptionalArguments() : void{
		$steve = $this->spawn("Steve");
		$this->dispatch("effect Steve haste", $steve);
		self::assertSame([VanillaEffects::HASTE(), VanillaEffects::HASTE()->getDefaultDuration(), 0, true, false], $this->summary($this->added[0]));

		$this->dispatch("effect Steve haste 10", $steve);
		self::assertSame([VanillaEffects::HASTE(), 200, 0, true, false], $this->summary($this->added[1]));

		$this->dispatch("effect Steve haste 10 255 true", $steve);
		self::assertSame([VanillaEffects::HASTE(), 200, 255, false, false], $this->summary($this->added[2]));

		$this->dispatch("effect Steve haste 10 1 FALSE", $steve);
		self::assertSame([VanillaEffects::HASTE(), 200, 1, true, false], $this->summary($this->added[3]));
	}

	public function testInfiniteEffect() : void{
		$steve = $this->spawn("Steve");
		$messages = $this->dispatch("effect Steve speed INFINITE 3 true", $steve);
		self::assertCount(1, $this->added);
		self::assertSame(VanillaEffects::SPEED(), $this->added[0]->getType());
		self::assertTrue($this->added[0]->isInfinite());
		self::assertSame(3, $this->added[0]->getAmplifier());
		self::assertFalse($this->added[0]->isVisible());
		self::assertStringContainsString("infinite", $messages[0]);

		$this->dispatch("effect Steve speed infinite", $steve);
		self::assertCount(2, $this->added);
	}

	public function testZeroSecondsRemovesTheEffect() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Couldn't take any effects from Steve as they do not have any"], $this->dispatch("effect Steve speed 0", $steve));

		$this->held = [VanillaEffects::HASTE()];
		self::assertSame(["Couldn't take Speed from Steve as they do not have the effect"], $this->dispatch("effect Steve speed 0", $steve));
		self::assertSame([], $this->removed);

		self::assertSame(["Took Haste from Steve"], $this->dispatch("effect Steve haste 0", $steve));
		self::assertSame([VanillaEffects::HASTE()], $this->removed);
		self::assertSame([], $this->added);
	}

	public function testClear() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Took all effects from Steve"], $this->dispatch("effect Steve CLEAR", $steve));
		self::assertSame(1, $this->cleared);
		self::assertSame([], $this->added);
	}

	public function testRejectsInvalidInputWithoutChangingEffects() : void{
		$steve = $this->spawn("Steve");
		$this->spawn("Alex");

		$lines = [
			"effect", "effect Steve", "effect Nobody speed", "effect Steve notaneffect", "effect Steve notaneffect 5",
			"effect Steve speed abc", "effect Steve speed -1", "effect Steve speed 107374183", "effect Steve speed 99999999999999999999",
			"effect Steve speed 1.5", "effect Steve speed 5 256", "effect Steve speed 5 -1", "effect Steve speed 5 x", "effect Steve speed 5 1 maybe",
			"effect Steve speed 5 1 true extra", "effect Steve speed infinite 256", "effect Steve speed infinite 1 true extra", "effect Steve clear 5",
			"effect Steve clear extra", "effect Steve speed infinit", "effect Steve speed 5 1 1", "effect Steve speed 5 1 on"
		];
		foreach($lines as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->added, $line);
			self::assertSame(0, $this->cleared, $line);
			self::assertSame([], $this->removed, $line);
		}
		self::assertStringContainsString("There is no such mob effect with ID notaneffect", $this->dispatch("effect Steve notaneffect", $steve)[0]);
	}

	public function testMaximumDurationIsAccepted() : void{
		$steve = $this->spawn("Steve");
		$this->dispatch("effect Steve speed 107374182", $steve);
		self::assertSame(2147483640, $this->added[0]->getDuration());
	}

	public function testConsoleNeedsATarget() : void{
		$this->spawn("Alex");
		$console = $this->console();
		$this->dispatch("effect Alex speed 5", $console);
		self::assertCount(1, $this->added);
		$this->added = [];
		self::assertStringContainsString("Cannot find a player matching \"@s\"", $this->dispatch("effect @s speed", $console)[0]);
		self::assertSame([], $this->added);
	}

	public function testPermissionsSplitSelfAndOther() : void{
		$steve = $this->spawn("Steve", [DefaultPermissionNames::COMMAND_EFFECT_SELF]);
		$this->spawn("Alex");
		$this->dispatch("effect Alex speed 5", $steve);
		$this->dispatch("effect Alex clear", $steve);
		$this->dispatch("effect Alex speed infinite", $steve);
		self::assertSame([], $this->added);
		self::assertSame(0, $this->cleared);

		$this->dispatch("effect Steve speed 5", $steve);
		$this->dispatch("effect @s speed infinite", $steve);
		$this->dispatch("effect Steve clear", $steve);
		self::assertCount(2, $this->added);
		self::assertSame(1, $this->cleared);

		$nobody = $this->spawn("Nobody", false);
		$this->added = [];
		$this->dispatch("effect Nobody speed 5", $nobody);
		$this->dispatch("effect Steve speed 5", $nobody);
		self::assertSame([], $this->added);
	}
}
