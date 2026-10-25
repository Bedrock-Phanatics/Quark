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

namespace quark\network\mcpe\protocol;

use Bedwire\PacketException;
use Bedwire\Protocol;

/**
 * A game packet held as its encoded bytes, its protocol-zig fields, or both. One form is only derived from the other
 * when it is asked for, so a packet that passes through unchanged is never decoded or encoded again.
 *
 * Fields are the arrays Bedwire\Protocol decodes to and encodes from. Packets has a typed factory and a field shape for
 * every packet.
 */
final class Packet{

	/**
	 * @param array<string, mixed>|null $fields
	 */
	private function __construct(
		private int $id,
		private ?array $fields,
		private ?string $encoded
	){}

	/**
	 * @param array<string, mixed> $fields
	 */
	public static function fromFields(int $id, array $fields) : self{
		return new self($id, $fields, null);
	}

	/**
	 * @throws PacketException if the packet header is malformed
	 */
	public static function fromEncoded(string $encoded) : self{
		return new self(Protocol::packetId($encoded), null, $encoded);
	}

	/**
	 * @internal Used by sessions which decode received packets themselves.
	 *
	 * @param array<string, mixed> $fields
	 */
	public static function decoded(int $id, string $encoded, array $fields) : self{
		return new self($id, $fields, $encoded);
	}

	public function getId() : int{
		return $this->id;
	}

	public function getName() : string{
		return Protocol::name($this->id) ?? "unknown_$this->id";
	}

	/**
	 * @return array<string, mixed>
	 * @throws PacketException if the encoded packet is malformed or has an ID this protocol does not define
	 */
	public function getFields() : array{
		if($this->fields === null){
			$this->fields = Protocol::decode($this->encoded ?? "") ?? throw new PacketException("Unknown packet ID $this->id");
		}
		return $this->fields;
	}

	/**
	 * Replaces the packet's fields. The packet is encoded again from them when it is sent.
	 *
	 * @param array<string, mixed> $fields
	 */
	public function setFields(array $fields) : void{
		$this->fields = $fields;
		$this->encoded = null;
	}

	/**
	 * Returns whether the encoded bytes are known, which is false once the fields were replaced.
	 */
	public function hasEncoded() : bool{
		return $this->encoded !== null;
	}

	/**
	 * @throws \ValueError if the fields do not fit the packet
	 */
	public function getEncoded() : string{
		return $this->encoded ??= Protocol::encode($this->id, $this->fields ?? []);
	}

	/**
	 * @internal Returns the packet as Bedwire\Protocol::encodeBatch() takes it, without encoding it here.
	 *
	 * @return string|array{int, array<string, mixed>}
	 */
	public function toBatchEntry() : string|array{
		return $this->encoded ?? [$this->id, $this->fields ?? []];
	}
}
