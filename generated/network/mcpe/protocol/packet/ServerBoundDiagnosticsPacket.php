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
use quark\network\mcpe\protocol\types\EntityDiagnosticTimingInfo;
use quark\network\mcpe\protocol\types\MemoryCategoryCounter;
use quark\network\mcpe\protocol\types\SystemCategory;
use quark\network\mcpe\protocol\types\SystemDiagnosticTimingInfo;
use quark\network\mcpe\protocol\types\WhiskerScopeDataSummary;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class ServerBoundDiagnosticsPacket{
	public const int ID = PacketId::SERVER_BOUND_DIAGNOSTICS;

	/**
	 * @param list<MemoryCategoryCounter> $memoryCategoryValues
	 * @param list<EntityDiagnosticTimingInfo> $entityDiagnostics
	 * @param list<SystemDiagnosticTimingInfo> $systemDiagnostics
	 * @param list<SystemCategory> $systemCategories
	 * @param list<WhiskerScopeDataSummary> $whiskerScopes
	 */
	public function __construct(
		public float $avgFps,
		public float $avgServerSimTickTimeMs,
		public float $avgClientSimTickTimeMs,
		public float $avgBeginFrameTimeMs,
		public float $avgInputTimeMs,
		public float $avgRenderTimeMs,
		public float $avgEndFrameTimeMs,
		public float $avgRemainderTimePercent,
		public float $avgUnaccountedTimePercent,
		public array $memoryCategoryValues,
		public array $entityDiagnostics,
		public array $systemDiagnostics,
		public array $systemCategories,
		public array $whiskerScopes,
	){}
}
