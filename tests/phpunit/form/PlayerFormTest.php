<?php

declare(strict_types=1);

namespace quark\form;

use PHPUnit\Framework\TestCase;
use quark\form\type\ModalForm;
use quark\network\mcpe\NetworkSession;
use quark\player\Player;

final class PlayerFormTest extends TestCase{
	public function testSubmitRemovesOnlySubmittedFormBeforeCallback() : void{
		$player = $this->player();
		$calls = 0;
		$reentered = false;
		$player->addForm(1, new ModalForm("", "", function(Player $submittedPlayer, bool $choice) use ($player, &$calls, &$reentered) : void{
			++$calls;
			if(!$reentered){
				$reentered = true;
				self::assertFalse($submittedPlayer->onFormSubmit(1, $choice));
			}
			self::assertTrue($submittedPlayer->hasPendingForm(2));
			$player->addForm(3, new ModalForm("", "", static function(Player $player, bool $choice) : void{}));
		}));
		$player->addForm(2, new ModalForm("", "", static function(Player $player, bool $choice) : void{}));

		self::assertTrue($player->onFormSubmit(1, true));
		self::assertSame(1, $calls);
		self::assertFalse($player->hasPendingForm(1));
		self::assertTrue($player->hasPendingForm(2));
		self::assertTrue($player->hasPendingForm(3));
	}

	public function testValidationExceptionLogsAfterSubmittedFormIsRemoved() : void{
		$player = $this->player();
		$logger = $this->createMock(\Logger::class);
		$logger->expects(self::once())->method("critical");
		$logger->expects(self::once())->method("logException")->with(self::isInstanceOf(FormValidationException::class));
		$player->setLogger($logger);
		$player->addForm(1, new ModalForm("", "", static function(Player $player, bool $choice) : void{
			throw new FormValidationException("invalid response");
		}));

		self::assertTrue($player->onFormSubmit(1, true));
		self::assertFalse($player->hasPendingForm(1));
	}

	public function testCloseClearsFormsBeforeClosingSessionAndRejectsLateResponse() : void{
		$player = $this->player();
		$calls = 0;
		$player->addForm(1, new ModalForm("", "", static function(Player $player, bool $choice) use (&$calls) : void{ ++$calls; }));
		$player->addForm(2, new ModalForm("", "", static function(Player $player, bool $choice) use (&$calls) : void{ ++$calls; }));
		$form = new ModalForm("", "", static function(Player $player, bool $choice) : void{});
		$reference = \WeakReference::create($form);
		$player->addForm(3, $form);
		unset($form);
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["onCloseAllForms"])->getMock();
		$session->expects(self::once())->method("onCloseAllForms")->willReturnCallback(function() use ($player) : void{
			self::assertFalse($player->hasPendingForm(1));
			self::assertFalse($player->hasPendingForm(2));
		});
		$player->setSession($session);

		$player->closeAllForms();
		self::assertNull($reference->get());
		self::assertFalse($player->onFormSubmit(1, true));
		self::assertFalse($player->onFormSubmit(2, true));
		self::assertSame(0, $calls);
	}

	public function testDestroyCyclesReleasesPendingFormClosures() : void{
		$player = $this->player();
		$captured = new \stdClass();
		$reference = \WeakReference::create($captured);
		$form = new ModalForm("", "", static function(Player $player, bool $choice) use ($captured) : void{ $captured->submitted = $choice; });
		$player->addForm(1, $form);
		unset($captured, $form);
		self::assertNotNull($reference->get());

		$player->destroyCyclesForTest();

		self::assertNull($reference->get());
	}

	public function testCallbackExceptionLeavesNoPendingForm() : void{
		$player = $this->player();
		$player->addForm(1, new ModalForm("", "", static function(Player $player, bool $choice) : void{
			throw new \RuntimeException("Callback failed");
		}));
		try{
			$player->onFormSubmit(1, true);
			self::fail("The callback exception was swallowed");
		}catch(\RuntimeException $e){
			self::assertSame("Callback failed", $e->getMessage());
		}
		self::assertFalse($player->hasPendingForm(1));
	}

	private function player() : PlayerFormLifecycleTestPlayer{
		$player = new \ReflectionClass(PlayerFormLifecycleTestPlayer::class)->newInstanceWithoutConstructor();
		$player->setLogger($this->createMock(\Logger::class));
		return $player;
	}
}

final class PlayerFormLifecycleTestPlayer extends Player{
	private NetworkSession $testSession;

	public function __destruct(){
	}

	public function getNetworkSession() : NetworkSession{
		return $this->testSession;
	}

	public function addForm(int $id, Form $form) : void{
		$this->forms[$id] = $form;
	}

	public function setLogger(\Logger $logger) : void{
		$this->logger = $logger;
	}

	public function setSession(NetworkSession $session) : void{
		$this->testSession = $session;
	}

	public function destroyCyclesForTest() : void{
		parent::destroyCycles();
	}
}
