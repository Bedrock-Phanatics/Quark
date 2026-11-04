<?php

declare(strict_types=1);

namespace quark\block\custom;

use Bedwire\Nbt;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;
use quark\data\bedrock\block\BlockStateData;
use function array_keys;
use function count;
use function implode;
use function sort;
use const SORT_STRING;

/**
 * Builds short Molang conditions selecting the states that share one derived component value.
 * Only the states the value actually depends on are tested.
 * @internal
 */
final class StateConditions{
	/** @var list<string> */
	private array $names;

	/**
	 * @param list<BlockStateData>    $data
	 * @param array<int, CompoundTag> $values component value by state index, absent where the component is unset
	 */
	public function __construct(private array $data, array $values){
		$names = array_keys($data[0]->getStates());
		sort($names, SORT_STRING);
		$keys = [];
		foreach($data as $i => $_){
			$keys[$i] = isset($values[$i]) ? Nbt::write(new TreeRoot($values[$i]), Nbt::NETWORK) : "";
		}
		foreach($names as $k => $name){
			$without = $names;
			unset($without[$k]);
			if($this->determines($without, $keys)){
				$names = $without;
			}
		}
		$this->names = [...$names];
	}

	/**
	 * @param array<int, string> $names
	 * @param array<int, string> $keys
	 */
	private function determines(array $names, array $keys) : bool{
		$seen = [];
		foreach($this->data as $i => $state){
			$projection = $this->project($state, $names);
			if(isset($seen[$projection]) && $seen[$projection] !== $keys[$i]){
				return false;
			}
			$seen[$projection] = $keys[$i];
		}
		return true;
	}

	/** @param array<int, string> $names */
	private function project(BlockStateData $state, array $names) : string{
		$terms = [];
		foreach($names as $name){
			$terms[] = "query.block_state('$name') == " . self::literal($state->getState($name) ?? throw new \LogicException("Missing state $name"));
		}
		return implode(" && ", $terms);
	}

	private static function literal(Tag $tag) : string{
		return match(true){
			$tag instanceof ByteTag => $tag->getValue() !== 0 ? "true" : "false",
			$tag instanceof IntTag => (string) $tag->getValue(),
			$tag instanceof StringTag => "'" . $tag->getValue() . "'",
			default => throw new \LogicException("Unexpected state tag")
		};
	}

	/** @param list<int> $indexes */
	public function forStates(array $indexes) : string{
		$terms = [];
		foreach($indexes as $i){
			$terms[$this->project($this->data[$i], $this->names)] = true;
		}
		$terms = array_keys($terms);
		if(count($terms) === 1){
			return $terms[0] === "" ? "true" : $terms[0];
		}
		return "(" . implode(") || (", $terms) . ")";
	}
}
