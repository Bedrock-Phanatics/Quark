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

namespace quark\network\mcpe\protocol\packet;

use Bedwire\PacketId;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\types\BookEditActionAddPage;
use quark\network\mcpe\protocol\types\BookEditActionFinalize;
use quark\network\mcpe\protocol\types\BookEditActionReplacePage;
use quark\network\mcpe\protocol\types\BookEditActionSwapPages;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class BookEditPacket extends Packet{
	public const int ID = PacketId::BOOK_EDIT;

	public const int TYPE_REPLACE_PAGE = 0;
	public const int TYPE_ADD_PAGE = 1;
	public const int TYPE_DELETE_PAGE = 2;
	public const int TYPE_SWAP_PAGES = 3;
	public const int TYPE_SIGN_BOOK = 4;

	/**
	 * @param array{replace_page: BookEditActionReplacePage}|array{add_page: BookEditActionAddPage}|array{delete_page: int}|array{swap_pages: BookEditActionSwapPages}|array{finalize: BookEditActionFinalize} $operation
	 */
	public function __construct(
		public int $bookSlot,
		public array $operation,
	){}

	public function getId() : int{
		return self::ID;
	}
}
