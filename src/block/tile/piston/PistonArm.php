<?php

declare(strict_types=1);

namespace pocketmine\block\tile\piston;

use pocketmine\block\Block;
use pocketmine\block\tile\Spawnable;
use pocketmine\data\bedrock\block\BlockStateNames;
use pocketmine\math\Facing;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\block\Piston;
use function assert;

class PistonArm extends Spawnable{

	private const string TAG_STATE_INTERNAL = "_state";
	private const string TAG_STATE = "State";
	private const string TAG_PROGRESS = "Progress";
	private const string TAG_STICKY = "Sticky";

	public const int BE_STATE_UNEXTENDED = 0;
	public const int BE_STATE_PUSHING = 1;
	public const int BE_STATE_EXTENDED = 2;
	public const int BE_STATE_PULLING = 3;

	public bool $sticky = false;

	/** @var Piston::STATE_* */
	public int $state = Piston::STATE_RETRACT_IDLE;

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_STICKY, $this->sticky ? 1 : 0);
		$nbt->setFloat(self::TAG_PROGRESS, match($this->state){
			Piston::STATE_RETRACT_IDLE => 0.0,
			Piston::STATE_CONTRACT_BEGIN, Piston::STATE_RETRACT_BEGIN, Piston::STATE_RETRACT_WAITING => 0.5,
			Piston::STATE_CONTRACT_IDLE => 1.0
		});
		$nbt->setByte(self::TAG_STATE, match($this->state){
			Piston::STATE_RETRACT_IDLE => self::BE_STATE_UNEXTENDED,
			Piston::STATE_CONTRACT_IDLE => self::BE_STATE_EXTENDED,
			Piston::STATE_CONTRACT_BEGIN => self::BE_STATE_PUSHING,
			Piston::STATE_RETRACT_BEGIN, Piston::STATE_RETRACT_WAITING => self::BE_STATE_PULLING
		});
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->sticky = (bool) $nbt->getByte(self::TAG_STICKY, $this->sticky ? 1 : 0);

		if($nbt->getTag(self::TAG_STATE_INTERNAL) !== null){
			$this->state = match($nbt->getByte(self::TAG_STATE_INTERNAL)){
				Piston::STATE_RETRACT_IDLE => Piston::STATE_RETRACT_IDLE,
				Piston::STATE_CONTRACT_BEGIN => Piston::STATE_CONTRACT_BEGIN,
				Piston::STATE_CONTRACT_IDLE => Piston::STATE_CONTRACT_IDLE,
				Piston::STATE_RETRACT_BEGIN => Piston::STATE_RETRACT_BEGIN,
				Piston::STATE_RETRACT_WAITING => Piston::STATE_RETRACT_WAITING,
				default => Piston::STATE_RETRACT_IDLE,
			};
			return;
		}

		if($nbt->getTag(self::TAG_STATE) !== null){
			$this->state = match($nbt->getByte(self::TAG_STATE)){
				self::BE_STATE_UNEXTENDED => Piston::STATE_RETRACT_IDLE,
				self::BE_STATE_PUSHING => Piston::STATE_CONTRACT_BEGIN,
				self::BE_STATE_EXTENDED => Piston::STATE_CONTRACT_IDLE,
				self::BE_STATE_PULLING => Piston::STATE_RETRACT_WAITING,
				default => Piston::STATE_RETRACT_IDLE,
			};
			return;
		}

		$progressTag = $nbt->getTag(self::TAG_PROGRESS);
		$this->state = $progressTag instanceof FloatTag ? match($progressTag->getValue()){
			0.0 => Piston::STATE_RETRACT_IDLE,
			0.5 => Piston::STATE_CONTRACT_BEGIN,
			1.0 => Piston::STATE_CONTRACT_IDLE,
			default => Piston::STATE_RETRACT_IDLE,
		} : Piston::STATE_RETRACT_IDLE;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_STATE_INTERNAL, $this->state);
		$nbt->setByte(self::TAG_STICKY, $this->sticky ? 1 : 0);
	}

	public function getRenderUpdateBugWorkaroundStateProperties(Block $block) : array{
		assert($block instanceof Piston);
		return [BlockStateNames::FACING_DIRECTION => new IntTag(Facing::opposite($block->getFacing()))];
	}
}