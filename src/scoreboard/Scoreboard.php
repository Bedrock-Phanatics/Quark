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
use function is_array;
use function is_string;
use function ksort;
use function mb_check_encoding;
use function strlen;
use function strpbrk;

final class Scoreboard{
	public const int MAX_LINES = 15;
	public const int MAX_TITLE_BYTES = 256;
	public const int MAX_LINE_BYTES = 1024;
	public const int MAX_ROW_NAME_BYTES = 64;

	private const string OBJECTIVE_NAME = "quark:sidebar";

	/** @var \WeakReference<Player> */
	private \WeakReference $player;
	private string $title = "Scoreboard";
	/** @var array<int, string> */
	private array $lines = [];
	/** @var array<string, int> */
	private array $lineNames = [];
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

	public function getLine(int|string $line) : ?string{
		$line = $this->resolveLine($line);
		return $this->lines[$line] ?? null;
	}

	public function isVisible() : bool{
		return !$this->closed && $this->sentTitle !== null && ($this->player->get()?->isConnected() ?? false);
	}

	/**
	 * @param string|array<int|string, string>|null $title Title, or named rows to patch.
	 * @param array<int|string, string>|null        $lines Full layout in top-to-bottom order; null keeps the layout.
	 */
	public function update(string|array|null $title = null, ?array $lines = null) : void{
		$player = $this->getPlayerForUpdate();
		if(is_array($title)){
			if($lines !== null || count($title) > self::MAX_LINES){
				throw new \InvalidArgumentException("Named updates accept at most 15 rows and cannot replace the layout");
			}
			$updated = $this->lines;
			foreach($title as $name => $text){
				if(!is_string($name) || !is_string($text)){
					throw new \InvalidArgumentException("Named updates must map row names to strings");
				}
				$line = $this->resolveLine($name);
				if(($updated[$line] ?? null) !== $text){
					self::validateText($text, self::MAX_LINE_BYTES);
					$updated[$line] = $text;
				}
			}
			if($updated !== $this->lines){
				ksort($updated);
				$this->lines = $updated;
			}
			$this->synchronize($player);
			return;
		}
		if($title !== null && $title !== $this->title){
			self::validateText($title, self::MAX_TITLE_BYTES);
		}
		if($lines !== null && ($lines !== $this->lines || !array_is_list($lines) || $this->lineNames !== [])){
			[$this->lines, $this->lineNames] = self::validateLines($lines);
		}
		if($title !== null){
			$this->title = $title;
		}
		$this->synchronize($player);
	}

	public function setTitle(string $title) : void{
		$this->update(title: $title);
	}

	/** @param array<int|string, string> $lines Top to bottom; string keys name rows for later updates. */
	public function setLines(array $lines) : void{
		$this->update(lines: $lines);
	}

	public function setLine(int|string $line, string $text) : void{
		$player = $this->getPlayerForUpdate();
		$line = $this->resolveLine($line);
		if(($this->lines[$line] ?? null) !== $text){
			self::validateText($text, self::MAX_LINE_BYTES);
			$this->lines[$line] = $text;
			ksort($this->lines);
		}
		$this->synchronize($player);
	}

	public function removeLine(int|string $line) : void{
		$player = $this->getPlayerForUpdate();
		$line = $this->resolveLine($line);
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
		$this->lineNames = [];
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

	private function resolveLine(int|string $line) : int{
		if(is_string($line)){
			self::validateText($line, self::MAX_ROW_NAME_BYTES);
			return $this->lineNames[$line] ?? throw new \InvalidArgumentException("Unknown scoreboard row: $line");
		}
		if($line < 0 || $line >= self::MAX_LINES){
			throw new \InvalidArgumentException("Scoreboard row index must be between 0 and 14");
		}
		return $line;
	}

	/**
	 * @param array<int|string, string> $lines
	 * @return array{list<string>, array<string, int>}
	 */
	private static function validateLines(array $lines) : array{
		if(count($lines) > self::MAX_LINES){
			throw new \InvalidArgumentException("Scoreboards support at most 15 rows");
		}
		$result = [];
		$names = [];
		foreach($lines as $name => $text){
			if(!is_string($text)){
				throw new \InvalidArgumentException("Scoreboard lines must be strings");
			}
			self::validateText($text, self::MAX_LINE_BYTES);
			if(is_string($name)){
				if($name === ""){
					throw new \InvalidArgumentException("Scoreboard row names cannot be empty");
				}
				self::validateText($name, self::MAX_ROW_NAME_BYTES);
				$names[$name] = count($result);
			}
			$result[] = $text;
		}
		if($names === [] && !array_is_list($lines)){
			throw new \InvalidArgumentException("Unnamed scoreboard rows must be a list");
		}
		return [$result, $names];
	}

	private static function validateText(string $text, int $maxBytes) : void{
		if(strlen($text) > $maxBytes || strpbrk($text, "\r\n\0") !== false || !mb_check_encoding($text, "UTF-8")){
			throw new \InvalidArgumentException("Scoreboard text must be single-line UTF-8 of at most $maxBytes bytes without NUL bytes");
		}
	}
}
