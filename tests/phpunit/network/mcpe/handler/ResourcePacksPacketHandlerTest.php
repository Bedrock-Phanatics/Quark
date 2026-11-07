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

namespace quark\network\mcpe\handler;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkRequestPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;
use pocketmine\network\mcpe\protocol\types\ResourcePackClientResponseStatus;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\PacketHandlingException;
use quark\resourcepacks\ResourcePack;
use Ramsey\Uuid\Uuid;
use Vow\Promise;
use function array_map;
use function bin2hex;
use function str_repeat;

final class ResourcePacksPacketHandlerTest extends TestCase{
	private const PACK_ID = "0d2d6c31-3c0b-4dc3-bd80-9c5f5a3b1c21";
	private const PACK_SIZE = 300 * 1024;

	/** @var list<ClientboundPacket> */
	private array $sent = [];
	private bool $completed = false;

	/**
	 * @phpstan-return NetworkSession&MockObject
	 */
	private function session() : NetworkSession{
		$session = $this->createMock(NetworkSession::class);
		$session->method("sendDataPacket")->willReturnCallback(function(ClientboundPacket $packet) : bool{
			$this->sent[] = $packet;
			return true;
		});
		$session->method("sendDataPacketWithReceipt")->willReturnCallback(function(ClientboundPacket $packet) : Promise{
			$this->sent[] = $packet;
			return Promise::resolved(null);
		});
		return $session;
	}

	private function handler(NetworkSession $session) : ResourcePacksPacketHandler{
		$pack = $this->createMock(ResourcePack::class);
		$pack->method("getPackId")->willReturn(self::PACK_ID);
		$pack->method("getPackVersion")->willReturn("1.0.0");
		$pack->method("getPackSize")->willReturn(self::PACK_SIZE);
		$pack->method("getSha256")->willReturn(str_repeat("\xab", 32));
		$pack->method("getPackChunk")->willReturnCallback(fn(int $start, int $length) : string => "chunk at $start");

		return new ResourcePacksPacketHandler($session, [$pack], [self::PACK_ID => "key"], true, function() : void{
			$this->completed = true;
		});
	}

	public function testDownloadSequenceMatchesRecordedEncoding() : void{
		$handler = $this->handler($this->session());
		$handler->setUp();
		self::assertTrue($handler->handleResourcePackClientResponse(ResourcePackClientResponsePacket::create(ResourcePackClientResponseStatus::SEND_PACKS, [self::PACK_ID . "_1.0.0"])));
		self::assertTrue($handler->handleResourcePackChunkRequest(ResourcePackChunkRequestPacket::create(self::PACK_ID, 1)));
		self::assertTrue($handler->handleResourcePackClientResponse(ResourcePackClientResponsePacket::create(ResourcePackClientResponseStatus::HAVE_ALL_PACKS, [])));
		self::assertTrue($handler->handleResourcePackClientResponse(ResourcePackClientResponsePacket::create(ResourcePackClientResponseStatus::COMPLETED, [])));
		self::assertTrue($this->completed);

		//pack info, the data info and chunk 1 of the 512 KiB pack, then the stack with the chemistry packs
		self::assertSame(
			[
				"0601000001000000000000000000000000000000000001c34d0b3c316c2d0d211c3b5a5f9c80bd05312e302e3000b0040000000000036b6579002430643264366333312d336330622d346463332d626438302d39633566356133623163323100000000",
				"522430643264366333312d336330622d346463332d626438302d396335663561336231633231000004000200000000b004000000000020abababababababababababababababababababababababababababababababab0006",
				"532430643264366333312d336330622d346463332d626438302d3963356635613362316332310100000000000400000000000f6368756e6b20617420323632313434",
				"0700082430643264366333312d336330622d346463332d626438302d39633566356133623163323105312e302e30002462343163323738352d633531322d346134392d616635362d33613837616664343763353707312e32312e3330002461346466306362332d313762652d343136332d383864372d66636637303032623933356407312e32312e3230002464313961646666652d613265312d346230322d383433362d63613435383333363863383907312e32312e3130002438356435363033642d323832342d346232312d383034342d33346634343166346663653106312e32312e30002465393737636431332d306131312d343631382d393666622d30336466653963343336303807312e32302e3630002430363734373231632d613061612d343161312d396261382d31656433336561336537656407312e32302e3530002430666261343036332d646261312d343238312d396238392d66663933393036353335333005312e302e300007312e32362e3630000000000000",
			],
			array_map(fn(ClientboundPacket $packet) : string => bin2hex(EncodedPacket::of($packet)->getBytes()), $this->sent)
		);
	}

	public function testRejectsRepeatedMetadataRequests() : void{
		$handler = $this->handler($this->session());
		$request = ResourcePackClientResponsePacket::create(ResourcePackClientResponseStatus::SEND_PACKS, [self::PACK_ID]);
		$handler->handleResourcePackClientResponse($request);
		$this->expectException(PacketHandlingException::class);
		$handler->handleResourcePackClientResponse($request);
	}

	/**
	 * @return int[][]
	 */
	public static function invalidChunkProvider() : array{
		return [[2], [0xffffffff]];
	}

	/**
	 * @dataProvider invalidChunkProvider
	 */
	public function testDisconnectsOnOutOfBoundsChunk(int $chunk) : void{
		$session = $this->session();
		$session->expects(self::once())->method("disconnectWithError");
		self::assertFalse($this->handler($session)->handleResourcePackChunkRequest(ResourcePackChunkRequestPacket::create(self::PACK_ID, $chunk)));
		self::assertSame([], $this->sent);
	}

	public function testDisconnectsOnDuplicateChunk() : void{
		$session = $this->session();
		$session->expects(self::once())->method("disconnectWithError");
		$handler = $this->handler($session);
		self::assertTrue($handler->handleResourcePackChunkRequest(ResourcePackChunkRequestPacket::create(self::PACK_ID, 0)));
		self::assertFalse($handler->handleResourcePackChunkRequest(ResourcePackChunkRequestPacket::create(self::PACK_ID, 0)));
	}

	public function testDisconnectsOnUnknownPack() : void{
		$session = $this->session();
		$session->expects(self::once())->method("disconnectWithError");
		self::assertFalse($this->handler($session)->handleResourcePackChunkRequest(ResourcePackChunkRequestPacket::create(Uuid::NIL, 0)));
	}
}
