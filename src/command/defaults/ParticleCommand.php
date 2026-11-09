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

use pocketmine\color\Color;
use pocketmine\math\Vector3;
use pocketmine\utils\Limits;
use quark\block\Block;
use quark\block\BlockTypeIds;
use quark\command\argument\ArgumentParseException;
use quark\command\argument\CommandEnum;
use quark\command\argument\EnumArgument;
use quark\command\argument\FloatArgument;
use quark\command\argument\IntegerArgument;
use quark\command\argument\PositionArgument;
use quark\command\argument\RelativePosition;
use quark\command\argument\StringArgument;
use quark\command\CommandSender;
use quark\item\Item;
use quark\item\StringToItemParser;
use quark\item\VanillaItems;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use quark\utils\Random;
use quark\utils\TextFormat;
use quark\world\particle\AngryVillagerParticle;
use quark\world\particle\BlockForceFieldParticle;
use quark\world\particle\BubbleParticle;
use quark\world\particle\CriticalParticle;
use quark\world\particle\DustParticle;
use quark\world\particle\EnchantmentTableParticle;
use quark\world\particle\EnchantParticle;
use quark\world\particle\EntityFlameParticle;
use quark\world\particle\ExplodeParticle;
use quark\world\particle\FlameParticle;
use quark\world\particle\HappyVillagerParticle;
use quark\world\particle\HeartParticle;
use quark\world\particle\HugeExplodeParticle;
use quark\world\particle\HugeExplodeSeedParticle;
use quark\world\particle\InkParticle;
use quark\world\particle\InstantEnchantParticle;
use quark\world\particle\ItemBreakParticle;
use quark\world\particle\LavaDripParticle;
use quark\world\particle\LavaParticle;
use quark\world\particle\Particle;
use quark\world\particle\PortalParticle;
use quark\world\particle\RainSplashParticle;
use quark\world\particle\RedstoneParticle;
use quark\world\particle\SmokeParticle;
use quark\world\particle\SonicExplosionParticle;
use quark\world\particle\SplashParticle;
use quark\world\particle\SporeParticle;
use quark\world\particle\TerrainParticle;
use quark\world\particle\WaterDripParticle;
use quark\world\particle\WaterParticle;
use function array_keys;
use function count;
use function explode;
use function microtime;
use function mt_rand;
use function preg_match;

class ParticleCommand extends VanillaCommand{
	public const MAX_COUNT = 1000;
	public const MAX_SPREAD = 256.0;

	/**
	 * Builds a particle from the optional data argument, throwing when the data does not fit the particle.
	 *
	 * @var \Closure[]
	 * @phpstan-var array<string, \Closure(string|null) : Particle>
	 */
	private array $particles;

	public function __construct(){
		parent::__construct(
			"particle",
			KnownTranslationFactory::quark_command_particle_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_PARTICLE);

		$this->particles = [
			"explode" => static fn() => new ExplodeParticle(),
			"hugeexplosion" => static fn() => new HugeExplodeParticle(),
			"hugeexplosionseed" => static fn() => new HugeExplodeSeedParticle(),
			"bubble" => static fn() => new BubbleParticle(),
			"splash" => static fn() => new SplashParticle(),
			"wake" => static fn() => new WaterParticle(),
			"water" => static fn() => new WaterParticle(),
			"crit" => static fn() => new CriticalParticle(),
			"smoke" => static fn(?string $data) => new SmokeParticle(self::int($data, 0)),
			"spell" => static fn() => new EnchantParticle(new Color(0, 0, 0, 255)), //TODO: colour support
			"instantspell" => static fn() => new InstantEnchantParticle(new Color(0, 0, 0, 255)), //TODO: colour support
			"dripwater" => static fn() => new WaterDripParticle(),
			"driplava" => static fn() => new LavaDripParticle(),
			"townaura" => static fn() => new SporeParticle(),
			"spore" => static fn() => new SporeParticle(),
			"portal" => static fn() => new PortalParticle(),
			"flame" => static fn() => new FlameParticle(),
			"lava" => static fn() => new LavaParticle(),
			"reddust" => static fn(?string $data) => new RedstoneParticle(self::int($data, 1)),
			"snowballpoof" => static fn() => new ItemBreakParticle(VanillaItems::SNOWBALL()),
			"slime" => static fn() => new ItemBreakParticle(VanillaItems::SLIMEBALL()),
			"itembreak" => static fn(?string $data) => new ItemBreakParticle(self::item($data)),
			"terrain" => static fn(?string $data) => new TerrainParticle(self::block($data)),
			"heart" => static fn(?string $data) => new HeartParticle(self::int($data, 0)),
			"ink" => static fn(?string $data) => new InkParticle(self::int($data, 0)),
			"droplet" => static fn() => new RainSplashParticle(),
			"enchantmenttable" => static fn() => new EnchantmentTableParticle(),
			"happyvillager" => static fn() => new HappyVillagerParticle(),
			"angryvillager" => static fn() => new AngryVillagerParticle(),
			"forcefield" => static fn(?string $data) => new BlockForceFieldParticle(self::int($data, 0)),
			"mobflame" => static fn() => new EntityFlameParticle(),
			"iconcrack" => static fn(?string $data) => new ItemBreakParticle(self::item($data)),
			"blockcrack" => static fn(?string $data) => new TerrainParticle(self::block($data)),
			"blockdust" => static fn(?string $data) => new DustParticle(self::color($data)),
			"sonicexplosion" => static fn() => new SonicExplosionParticle()
		];

		$this->syntax()
			->registerArguments([
				new EnumArgument("particle", CommandEnum::fromValues("Particle", array_keys($this->particles))),
				new PositionArgument("position"),
				new FloatArgument("xd", -self::MAX_SPREAD, self::MAX_SPREAD),
				new FloatArgument("yd", -self::MAX_SPREAD, self::MAX_SPREAD),
				new FloatArgument("zd", -self::MAX_SPREAD, self::MAX_SPREAD),
				new IntegerArgument("count", 1, self::MAX_COUNT, optional: true),
				new StringArgument("data", optional: true)
			])
			->handler($this->particle(...));
	}

	private static function int(?string $data, int $default) : int{
		if($data === null){
			return $default;
		}
		if(preg_match('/\A[+-]?[0-9]{1,10}\z/', $data) !== 1 || ($value = (int) $data) < Limits::INT32_MIN || $value > Limits::INT32_MAX){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidValue($data));
		}
		return $value;
	}

	private static function item(?string $data) : Item{
		$item = $data === null ? null : StringToItemParser::getInstance()->parse($data);
		if($item === null || $item->isNull()){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidValue($data ?? ""));
		}
		return $item;
	}

	private static function block(?string $data) : Block{
		$block = self::item($data)->getBlock();
		if($block->getTypeId() === BlockTypeIds::AIR){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidValue($data ?? ""));
		}
		return $block;
	}

	private static function color(?string $data) : Color{
		//red_green_blue with an optional alpha, defaulting to opaque
		$parts = $data === null ? [] : explode("_", $data, limit: 5);
		if(count($parts) < 3 || count($parts) > 4){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidValue($data ?? ""));
		}
		$channels = [];
		foreach($parts as $part){
			$channel = self::int($part, 0);
			if($channel < 0 || $channel > 255){
				throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidValue($data ?? ""));
			}
			$channels[] = $channel;
		}
		return new Color($channels[0], $channels[1], $channels[2], $channels[3] ?? 255);
	}

	private function particle(CommandSender $sender, string $name, RelativePosition $position, float $xd, float $yd, float $zd, int $count = 1, ?string $data = null) : void{
		if($sender instanceof Player){
			$base = $sender->getPosition();
			$world = $base->getWorld();
		}else{
			$base = new Vector3(0.0, 0.0, 0.0);
			$world = $sender->getServer()->getWorldManager()->getDefaultWorld() ?? throw new AssumptionFailedError("No default world");
		}

		$pos = $this->resolvePosition($sender, $position, $base);
		if($pos === null){
			return;
		}

		try{
			$particle = ($this->particles[$name] ?? throw new AssumptionFailedError("Unknown particle $name"))($data);
		}catch(ArgumentParseException $e){
			$sender->sendMessage(KnownTranslationFactory::quark_command_error_invalidArgument("data", $e->getUserMessage())->prefix(TextFormat::RED));
			return;
		}

		$sender->sendMessage(KnownTranslationFactory::commands_particle_success($name, (string) $count));

		$random = new Random((int) (microtime(true) * 1000) + mt_rand());

		for($i = 0; $i < $count; ++$i){
			$world->addParticle($pos->add(
				$random->nextSignedFloat() * $xd,
				$random->nextSignedFloat() * $yd,
				$random->nextSignedFloat() * $zd
			), $particle);
		}
	}
}
