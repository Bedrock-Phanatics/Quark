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

namespace quark\network\mcpe\protocol\types;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class ItemStackRequestData{
	/**
	 * @param list<array{take: TakeStackRequestAction}|array{place: PlaceStackRequestAction}|array{swap: SwapStackRequestAction}|array{drop: DropStackRequestAction}|array{destroy: DestroyStackRequestAction}|array{consume: ConsumeStackRequestAction}|array{create: CreateStackRequestAction}|array{lab_table_combine: int}|array{beacon_payment: BeaconPaymentStackRequestAction}|array{mine_block: MineBlockStackRequestAction}|array{craft_recipe: CraftRecipeStackRequestAction}|array{craft_recipe_auto: AutoCraftRecipeStackRequestAction}|array{craft_creative: CraftCreativeStackRequestAction}|array{craft_recipe_optional: CraftRecipeOptionalStackRequestAction}|array{craft_repair_and_disenchant: CraftRepairAndDisenchantStackRequestAction}|array{craft_loom: CraftLoomStackRequestAction}|array{craft_non_implemented: int}|array{craft_results: CraftResultsDeprecatedStackRequestAction}> $actions
	 * @param list<string> $stringsToFilter
	 */
	public function __construct(
		public int $clientRequestId,
		public array $actions,
		public array $stringsToFilter,
		public int $stringsToFilterOrigin,
	){}
}
