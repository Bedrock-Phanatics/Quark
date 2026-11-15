<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use pocketmine\network\mcpe\protocol\CameraAimAssistPacket;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\CameraShakePacket;
use pocketmine\network\mcpe\protocol\ClientboundControlSchemeSetPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\PlayerFogPacket;
use pocketmine\network\mcpe\protocol\SetHudPacket;
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistActionType;
use pocketmine\network\mcpe\protocol\types\ControlScheme;
use pocketmine\network\mcpe\protocol\types\hud\HudElement;
use pocketmine\network\mcpe\protocol\types\hud\HudVisibility;
use quark\entity\Entity;
use quark\math\Vector3;
use quark\player\Player;
use quark\plugin\Plugin;
use function array_values;
use function spl_object_id;

class CameraController{
	/** @var \WeakReference<Player> */
	private \WeakReference $player;
	private bool $presetsSent = false;
	private bool $aimAssistSent = false;
	private bool $closed = false;
	private bool $sending = false;
	private bool $resetPending = false;
	private bool $resettingContext = false;
	private ?int $pendingEntity = null;
	private ?CameraPlayback $playback = null;
	/** @var \WeakReference<Plugin>|null */
	private ?\WeakReference $owner = null;
	private ?string $preset = null;
	private ?int $target = null;
	private ?int $attachment = null;
	private bool $cameraChanged = false;
	private bool $fovChanged = false;
	private bool $shakeChanged = false;
	private bool $fogChanged = false;
	private bool $controlsChanged = false;
	private bool $aimChanged = false;
	/** @var array<int, HudElement> */
	private array $hiddenHud = [];
	private ?bool $clientAllowsAimAssist = null;

	public function __construct(Player $player, private CameraPresetRegistry $presets, private CameraAimAssistRegistry $aimAssist){
		$this->player = \WeakReference::create($player);
	}

	/** @internal Called after the client finishes spawning, before PlayerJoinEvent. */
	public function synchronize() : void{
		if($this->sending){
			throw new \LogicException("Cannot synchronize cameras during packet transmission");
		}
		try{
			$player = $this->player->get();
			if($this->closed || $player === null || !$player->isConnected() || !$player->spawned){
				throw new \LogicException("Camera synchronization requires a spawned player");
			}
			$this->presets->validateAimAssist($this->aimAssist);
			$this->presets->freeze();
			$this->aimAssist->freeze();
			if(!$this->presetsSent){
				$this->transmit($this->presets->createPacket());
				$this->presetsSent = true;
			}
			if(!$this->aimAssistSent){
				$this->transmit($this->aimAssist->createPacket());
				$this->aimAssistSent = true;
			}
		}finally{
			$this->flushPendingReset();
		}
	}

	public function isReady() : bool{
		$player = $this->player->get();
		return !$this->closed && $this->presetsSent && $this->aimAssistSent && $player !== null && $player->isConnected() && $player->spawned && $player->isAlive() && !$player->isSpectator();
	}

	/** Last requested preset; the client does not acknowledge camera state. */
	public function getPreset() : ?string{
		return $this->preset;
	}

	public function set(string $preset = "minecraft:free", ?Vector3 $position = null, ?float $pitch = null, ?float $yaw = null, ?Vector3 $facing = null, CameraEase $ease = CameraEase::LINEAR, float $duration = 0.0) : void{
		$this->send(CameraInstruction::set($preset, $position, $pitch, $yaw, $facing, $ease, $duration));
	}

	public function fade(float $in = 0.5, float $stay = 1.0, float $out = 0.5, float $red = 0.0, float $green = 0.0, float $blue = 0.0) : void{
		$this->send(CameraInstruction::fade($in, $stay, $out, $red, $green, $blue));
	}

	public function fov(?float $degrees, float $duration = 0.0, CameraEase $ease = CameraEase::LINEAR) : void{
		$this->send(CameraInstruction::fov($degrees, $duration, $ease));
	}

	public function track(Entity $entity, ?Vector3 $offset = null) : void{
		$this->send(CameraInstruction::track($entity, $offset));
	}

	public function attach(Entity $entity) : void{
		$this->send(CameraInstruction::attach($entity));
	}

	public function send(CameraInstruction $instruction) : void{
		$packet = $this->prepare($instruction);
		$this->playback?->cancel(false);
		$this->apply($instruction, $packet);
		$this->owner = null;
	}

	private function prepare(CameraInstruction $instruction) : ClientboundPacket{
		if($this->sending){
			throw new \LogicException("Camera operations cannot reenter packet transmission");
		}
		if(!$this->isReady()){
			throw new \LogicException("Camera requires a connected, spawned, living non-spectator player");
		}
		if($instruction->aimAssistPreset !== null && !$this->aimAssist->hasPreset($instruction->aimAssistPreset)){
			throw new \InvalidArgumentException("Unknown aim assist preset: $instruction->aimAssistPreset");
		}
		if($instruction->aimAssistPreset !== null && $this->clientAllowsAimAssist === false){
			throw new \LogicException("The client has disabled camera aim assist");
		}
		if($instruction->preset !== null && $this->clientAllowsAimAssist === false){
			$preset = $this->presets->get($instruction->preset);
			while(true){
				if($preset->aimAssist !== null){
					throw new \LogicException("The client has disabled camera aim assist");
				}
				if($preset->parent === ""){
					break;
				}
				$preset = $this->presets->get($preset->parent);
			}
		}
		$entity = $instruction->getEntity();
		if($instruction->requiresEntity){
			if($entity === null || !$this->canSeeEntity($entity)){
				throw new \InvalidArgumentException("Camera entity must be alive, in the player's world and spawned to the player");
			}
		}
		return $instruction->createPacket($this->presets, $entity?->getId());
	}

	private function canSeeEntity(Entity $entity) : bool{
		$player = $this->player->get();
		return $player !== null && !$entity->isClosed() && $entity->isAlive() && $entity->getId() > 0 && $entity->getWorld() === $player->getWorld() && ($entity === $player || isset($entity->getViewers()[spl_object_id($player)]));
	}

	private function apply(CameraInstruction $instruction, ClientboundPacket $packet, ?CameraPlayback $playback = null) : void{
		$this->pendingEntity = $packet instanceof CameraInstructionPacket ? ($packet->getTarget()?->getActorUniqueId() ?? $packet->getAttachToEntity()) : null;
		try{
			$this->transmit($packet, function() use ($instruction, $playback) : bool{
				$entity = $instruction->getEntity();
				return $this->isReady() && !$this->resetPending && ($playback === null || $this->playback === $playback) && (!$instruction->requiresEntity || ($entity !== null && $this->canSeeEntity($entity)));
			});
			if($this->closed){
				return;
			}
			if($packet instanceof CameraInstructionPacket){
				if($packet->getSet() !== null){
					$this->cameraChanged = true;
					$this->preset = $instruction->preset;
				}
				if($packet->getClear() === true){
					$this->cameraChanged = false;
					$this->preset = null;
				}
				if(($target = $packet->getTarget()) !== null){
					$this->target = $target->getActorUniqueId();
				}
				if($packet->getRemoveTarget() === true){
					$this->target = null;
				}
				if($packet->getAttachToEntity() !== null){
					$this->attachment = $packet->getAttachToEntity();
				}
				if($packet->getDetachFromEntity() === true){
					$this->attachment = null;
				}
				if(($fov = $packet->getFieldOfView()) !== null){
					$this->fovChanged = !$fov->getClear();
				}
			}elseif($packet instanceof CameraShakePacket){
				$this->shakeChanged = $packet->getShakeAction() === CameraShakePacket::ACTION_ADD;
			}elseif($packet instanceof PlayerFogPacket){
				$this->fogChanged = $packet->getFogLayers() !== [];
			}elseif($packet instanceof ClientboundControlSchemeSetPacket){
				$this->controlsChanged = true;
			}elseif($packet instanceof CameraAimAssistPacket){
				$this->aimChanged = $packet->getActionType() === CameraAimAssistActionType::SET;
			}elseif($packet instanceof SetHudPacket){
				foreach($packet->getHudElements() as $element){
					if($packet->getVisibility() === HudVisibility::HIDE){
						$this->hiddenHud[$element->value] = $element;
					}else{
						unset($this->hiddenHud[$element->value]);
					}
				}
			}
		}finally{
			$this->pendingEntity = null;
			$this->flushPendingReset();
		}
	}

	private function flushPendingReset() : void{
		if($this->resetPending){
			$this->resetPending = false;
			$this->resetForContext();
		}
	}

	/** @param \Closure(): bool|null $condition */
	private function transmit(ClientboundPacket $packet, ?\Closure $condition = null) : void{
		if($this->sending){
			throw new \LogicException("Camera packet transmission is already in progress");
		}
		$this->sending = true;
		try{
			$player = $this->player->get();
			if($player === null || !$player->isConnected()){
				throw new \RuntimeException("Camera packet was not queued");
			}
			$session = $player->getNetworkSession();
			if(!($condition === null ? $session->sendDataPacket($packet) : $session->sendDataPacketIf($packet, $condition))){
				throw new \RuntimeException("Camera packet was not queued");
			}
		}finally{
			$this->sending = false;
		}
	}

	public function play(CameraTimeline $timeline, Plugin $owner) : CameraPlayback{
		if($this->sending){
			throw new \LogicException("Cannot start a camera timeline during packet transmission");
		}
		$playback = new CameraPlayback($this, $timeline, $owner);
		$this->playback?->cancel(false);
		$this->playback = $playback;
		$this->owner = \WeakReference::create($owner);
		$playback->start();
		return $playback;
	}

	/** @internal */
	public function sendForPlayback(CameraInstruction $instruction, CameraPlayback $playback) : void{
		if($this->playback !== $playback){
			throw new \LogicException("Camera timeline has been replaced or cancelled");
		}
		$this->apply($instruction, $this->prepare($instruction), $playback);
	}

	/** @internal */
	public function finishPlayback(CameraPlayback $playback, bool $reset) : void{
		if($this->playback === $playback){
			$this->playback = null;
			if($reset){
				if($this->sending){
					$this->resetPending = true;
				}else{
					$this->clear();
				}
			}
		}
	}

	/** @internal */
	public function cancelForOwner(Plugin $owner) : void{
		if($this->owner?->get() === $owner){
			$this->resetForContext();
		}
	}

	public function clear() : void{
		if($this->sending){
			throw new \LogicException("Cannot reset the camera during packet transmission");
		}
		try{
			$this->playback?->cancel(false);
			if($this->closed){
				$this->owner = null;
				return;
			}
			$player = $this->player->get();
			if($player === null || !$player->isConnected() || !$this->presetsSent){
				$this->owner = null;
				return;
			}
			if($this->cameraChanged || $this->target !== null || $this->attachment !== null){
				$this->transmit(CameraInstruction::clear()->createPacket($this->presets));
				$this->cameraChanged = false;
				$this->preset = null;
				$this->target = $this->attachment = null;
			}
			if($this->fovChanged){
				$this->transmit(CameraInstruction::fov(null)->createPacket($this->presets));
				$this->fovChanged = false;
			}
			if($this->shakeChanged){
				$this->transmit(CameraInstruction::stopShake()->createPacket($this->presets));
				$this->shakeChanged = false;
			}
			if($this->fogChanged){
				$this->transmit(CameraInstruction::fog([])->createPacket($this->presets));
				$this->fogChanged = false;
			}
			if($this->controlsChanged){
				$this->transmit(CameraInstruction::controlScheme(ControlScheme::LOCKED_PLAYER_RELATIVE_STRAFE)->createPacket($this->presets));
				$this->controlsChanged = false;
			}
			if($this->aimChanged){
				$this->transmit(CameraInstruction::clearAimAssist()->createPacket($this->presets));
				$this->aimChanged = false;
			}
			if($this->hiddenHud !== []){
				$this->transmit(CameraInstruction::hud(HudVisibility::RESET, ...array_values($this->hiddenHud))->createPacket($this->presets));
				$this->hiddenHud = [];
			}
			$this->owner = null;
		}finally{
			$this->flushPendingReset();
		}
	}

	/** @internal Lifecycle cleanup must not interrupt world changes or disconnection. */
	public function resetForContext(bool $disconnect = false) : void{
		$this->playback?->cancel(false);
		if($disconnect){
			$this->owner = null;
			$this->closed = true;
			return;
		}
		if($this->resettingContext){
			return;
		}
		if($this->sending){
			$this->resetPending = true;
			return;
		}
		try{
			$this->resettingContext = true;
			$this->clear();
		}catch(\Throwable $e){
			$this->player->get()?->getServer()->getLogger()->logException($e);
		}finally{
			$this->resettingContext = false;
		}
	}

	/** @internal Called before an actor is removed from this client's world. */
	public function onEntityRemoved(int $entityId) : void{
		if($entityId === $this->target || $entityId === $this->attachment || $entityId === $this->pendingEntity){
			$this->resetForContext();
		}
	}

	/** Client preference, or null until reported; this is not a camera-state acknowledgement. */
	public function clientAllowsAimAssist() : ?bool{
		return $this->clientAllowsAimAssist;
	}

	/** @internal */
	public function onClientAimAssist(bool $allowed) : void{
		$this->clientAllowsAimAssist = $allowed;
	}
}
