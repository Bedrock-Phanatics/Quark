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

namespace quark\network\mcpe\protocol\types\login\clientdata;

/**
 * Model class for LoginPacket JSON data for JsonMapper
 */
final class ClientDataPersonaSkinPiece{
	/** @required */
	public string $PieceId;

	/** @required */
	public string $PieceType;

	/** @required */
	public string $PackId;

	/** @required */
	public bool $IsDefault;

	/** @required */
	public string $ProductId;
}
