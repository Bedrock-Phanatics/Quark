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

namespace quark\event\player;

use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\item\WritableBookBase;
use quark\player\Player;

class PlayerEditBookEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public const ACTION_REPLACE_PAGE = 0;
	public const ACTION_ADD_PAGE = 1;
	public const ACTION_DELETE_PAGE = 2;
	public const ACTION_SWAP_PAGES = 3;
	public const ACTION_SIGN_BOOK = 4;

	/**
	 * @param int[] $modifiedPages
	 */
	public function __construct(
		Player $player,
		private WritableBookBase $oldBook,
		private WritableBookBase $newBook,
		private int $action,
		private array $modifiedPages
	){
		$this->player = $player;
	}

	/**
	 * Returns the action of the event.
	 */
	public function getAction() : int{
		return $this->action;
	}

	/**
	 * Returns the book before it was modified.
	 */
	public function getOldBook() : WritableBookBase{
		return $this->oldBook;
	}

	/**
	 * Returns the book after it was modified.
	 * The new book may be a written book, if the book was signed.
	 */
	public function getNewBook() : WritableBookBase{
		return $this->newBook;
	}

	/**
	 * Sets the new book as the given instance.
	 */
	public function setNewBook(WritableBookBase $book) : void{
		$this->newBook = $book;
	}

	/**
	 * Returns an array containing the page IDs of modified pages.
	 *
	 * @return int[]
	 */
	public function getModifiedPages() : array{
		return $this->modifiedPages;
	}
}
