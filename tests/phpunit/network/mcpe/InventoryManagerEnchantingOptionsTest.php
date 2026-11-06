<?php

declare(strict_types=1);

namespace quark\network\mcpe;

use PHPUnit\Framework\TestCase;
use quark\entity\Entity;
use quark\inventory\Hotbar;
use quark\item\enchantment\EnchantingOption;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\packet\PlayerEnchantOptionsPacket;
use quark\player\Player;

final class InventoryManagerEnchantingOptionsTest extends TestCase{
	public function testRegeneratedOptionsInvalidatePreviousRecipeIds() : void{
		$player = $this->createMock(Player::class);
		new \ReflectionProperty(Entity::class, "closed")->setValue($player, true);
		new \ReflectionProperty(Player::class, "logger")->setValue($player, $this->createMock(\Logger::class));
		$player->method("getHotbar")->willReturn(new Hotbar());
		$session = $this->createMock(NetworkSession::class);
		$recipeId = null;
		$session->method("sendDataPacket")->willReturnCallback(static function(Packet $packet) use (&$recipeId) : bool{
			if($packet instanceof PlayerEnchantOptionsPacket){
				$recipeId = $packet->options[0]->enchantNetId ?? null;
			}
			return true;
		});
		$manager = new InventoryManager($player, $session);
		$option = new EnchantingOption(1, "test", []);
		$manager->syncEnchantingTableOptions([$option]);
		self::assertNotNull($recipeId);
		$previousId = $recipeId;
		self::assertSame(0, $manager->getEnchantingTableOptionIndex($previousId));
		$manager->syncEnchantingTableOptions([$option]);
		self::assertNotSame($previousId, $recipeId);
		self::assertNull($manager->getEnchantingTableOptionIndex($previousId));
		self::assertSame(0, $manager->getEnchantingTableOptionIndex($recipeId));
		$currentId = $recipeId;
		$manager->syncEnchantingTableOptions([]);
		self::assertNull($manager->getEnchantingTableOptionIndex($currentId));
		$manager->dispose();
	}
}
