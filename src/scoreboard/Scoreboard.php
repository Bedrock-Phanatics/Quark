<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\scoreboard;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;
use quark\player\Player;
use quark\utils\TextFormat;
use function array_is_list;
use function count;
use function dechex;
use function is_string;
use function ksort;
use function mb_check_encoding;
use function strlen;
use function strpbrk;

final class Scoreboard{
	public const int MAX_LINES = 15;
	public const int MAX_TITLE_BYTES = 256;
	public const int MAX_LINE_BYTES = 1024;

	private const string OBJECTIVE_NAME = "quark:sidebar";

	/** @var \WeakReference<Player> */
	private \WeakReference $player;
	private string $title = "Scoreboard";
	/** @var array<int, string> */
	private array $lines = [];
	private bool $visible = true;
	private ?string $sentTitle = null;
	/** @var array<int, string> */
	private array $sentLines = [];
	private bool $sending = false;
	private bool $closed = false;

	/** @internal Use Player::getScoreboard(). */
	public function __construct(Player $player){
		$this->player = \WeakReference::create($player);
	}

	public function getTitle() : string{
		return $this->title;
	}

	/** @return array<int, string> Zero-based row indexes, with gaps after removeLine(). */
	public function getLines() : array{
		return $this->lines;
	}

	public function getLine(int $line) : ?string{
		self::validateLine($line);
		return $this->lines[$line] ?? null;
	}

	public function isVisible() : bool{
		return !$this->closed && $this->sentTitle !== null && ($this->player->get()?->isConnected() ?? false);
	}

	/** @param list<string> $lines Top to bottom; hidden scoreboards stay hidden. */
	public function update(string $title, array $lines) : void{
		$player = $this->getPlayerForUpdate();
		if($title !== $this->title){
			self::validateText($title, self::MAX_TITLE_BYTES);
		}
		if($lines !== $this->lines || !array_is_list($lines)){
			$this->lines = self::validateLines($lines);
		}
		$this->title = $title;
		$this->synchronize($player);
	}

	public function setTitle(string $title) : void{
		$player = $this->getPlayerForUpdate();
		if($title !== $this->title){
			self::validateText($title, self::MAX_TITLE_BYTES);
		}
		$this->title = $title;
		$this->synchronize($player);
	}

	/** @param list<string> $lines Top to bottom. */
	public function setLines(array $lines) : void{
		$player = $this->getPlayerForUpdate();
		if($lines !== $this->lines || !array_is_list($lines)){
			$this->lines = self::validateLines($lines);
		}
		$this->synchronize($player);
	}

	public function setLine(int $line, string $text) : void{
		$player = $this->getPlayerForUpdate();
		self::validateLine($line);
		if(($this->lines[$line] ?? null) !== $text){
			self::validateText($text, self::MAX_LINE_BYTES);
			$this->lines[$line] = $text;
			ksort($this->lines);
		}
		$this->synchronize($player);
	}

	public function removeLine(int $line) : void{
		$player = $this->getPlayerForUpdate();
		self::validateLine($line);
		unset($this->lines[$line]);
		$this->synchronize($player);
	}

	public function clear() : void{
		$this->setLines([]);
	}

	public function hide() : void{
		$player = $this->getPlayerForUpdate();
		$this->visible = false;
		$this->synchronize($player);
	}

	public function show() : void{
		$player = $this->getPlayerForUpdate();
		$this->visible = true;
		$this->synchronize($player);
	}

	/** @internal Discards session state without sending packets. */
	public function close() : void{
		$this->closed = true;
		$this->title = "";
		$this->lines = [];
		$this->sentTitle = null;
		$this->sentLines = [];
	}

	private function getPlayerForUpdate() : Player{
		if($this->sending){
			throw new \LogicException("Scoreboard operations cannot reenter packet transmission");
		}
		$player = $this->player->get();
		if($this->closed || $player === null || !$player->isConnected() || !$player->spawned){
			throw new \LogicException("Scoreboards require a connected, spawned player");
		}
		return $player;
	}

	private function synchronize(Player $player) : void{
		$this->sending = true;
		try{
			if($this->sentTitle !== null && (!$this->visible || $this->sentTitle !== $this->title)){
				// Bedrock requires recreating the objective to change its title.
				$this->transmit($player, RemoveObjectivePacket::create(self::OBJECTIVE_NAME));
				$this->sentTitle = null;
				$this->sentLines = [];
			}
			if(!$this->visible){
				return;
			}
			if($this->sentTitle === null){
				$this->transmit($player, SetDisplayObjectivePacket::create(
					SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR, self::OBJECTIVE_NAME,
					$this->title, "dummy", SetDisplayObjectivePacket::SORT_ORDER_DESCENDING
				));
				$this->sentTitle = $this->title;
			}
			if($this->sentLines === $this->lines){
				return;
			}
			$entries = [];
			foreach($this->sentLines as $line => $text){
				if(($this->lines[$line] ?? null) !== $text){
					$entries[] = self::entry($line, ScorePacketEntryAction::REMOVE);
				}
			}
			foreach($this->lines as $line => $text){
				if(($this->sentLines[$line] ?? null) !== $text){
					$entries[] = self::entry($line, ScorePacketEntryAction::CHANGE_FAKE_PLAYER, $text);
				}
			}
			if($entries !== []){
				$this->transmit($player, SetScorePacket::create($entries));
				$this->sentLines = $this->lines;
			}
		}finally{
			$this->sending = false;
		}
	}

	private function transmit(Player $player, ClientboundPacket $packet) : void{
		if(!$player->getNetworkSession()->sendDataPacketIf($packet, fn() : bool => !$this->closed && $player->isConnected() && $player->spawned) || $this->closed){
			throw new \RuntimeException("Scoreboard packet was rejected or the player disconnected");
		}
	}

	private static function entry(int $line, ScorePacketEntryAction $action, ?string $text = null) : ScorePacketEntry{
		$entry = new ScorePacketEntry();
		$entry->action = $action;
		$entry->objectiveName = self::OBJECTIVE_NAME;
		// Bedrock reserves -1 for invalid scoreboard IDs.
		$entry->scoreboardId = -($line + 2);
		$entry->score = self::MAX_LINES - $line;
		// Distinct invisible prefixes keep duplicate text and blank rows separate.
		$entry->customName = $text === null ? null : TextFormat::ESCAPE . dechex($line) . TextFormat::RESET . $text;
		return $entry;
	}

	private static function validateLine(int $line) : void{
		if($line < 0 || $line >= self::MAX_LINES){
			throw new \InvalidArgumentException("Scoreboard row index must be between 0 and 14");
		}
	}

	/**
	 * @param list<string> $lines
	 * @return list<string>
	 */
	private static function validateLines(array $lines) : array{
		if(!array_is_list($lines) || count($lines) > self::MAX_LINES){
			throw new \InvalidArgumentException("Scoreboard lines must be a list of at most 15 strings");
		}
		$result = [];
		foreach($lines as $text){
			if(!is_string($text)){
				throw new \InvalidArgumentException("Scoreboard lines must be strings");
			}
			self::validateText($text, self::MAX_LINE_BYTES);
			$result[] = $text;
		}
		return $result;
	}

	private static function validateText(string $text, int $maxBytes) : void{
		if(strlen($text) > $maxBytes || strpbrk($text, "\r\n\0") !== false || !mb_check_encoding($text, "UTF-8")){
			throw new \InvalidArgumentException("Scoreboard text must be single-line UTF-8 of at most $maxBytes bytes without NUL bytes");
		}
	}
}
