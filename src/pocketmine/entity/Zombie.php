<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____  
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \ 
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/ 
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_| 
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 * 
 *
*/

namespace pocketmine\entity;


use pocketmine\block\Transparent;
use pocketmine\block\Stair;
use pocketmine\entity\AI\RootExplorer;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\item\Item as ItemItem;
use pocketmine\level\Position;
use pocketmine\level\Level;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\Player;
use pocketmine\Server;

class Zombie extends Monster{
	const NETWORK_ID = 32;

	const VIEWABLE_RANGE = 20;
	const ATTACK_RANGE = 2;

	public $width = 0.6;
	public $length = 0.6;
	public $height = 1.8;
	public $maxhealth = 20;

	/* For AI */
	private $siJumping = false;
	private $target = null;
	private $motionCount = 100;
	private $motion = null;

	public function getName(){
		return "Zombie";
	}


	public function spawnTo(Player $player){
		$pk = new AddEntityPacket();
		$pk->eid = $this->getId();
		$pk->type = Zombie::NETWORK_ID;
		$pk->x = $this->x;
		$pk->y = $this->y;
		$pk->z = $this->z;
		$pk->speedX = $this->motionX;
		// $pk->speedY = //$this->motionY;
		$pk->speedZ = $this->motionZ;
		$pk->yaw = $this->yaw;
		$pk->pitch = $this->pitch;
		$pk->metadata = $this->dataProperties;
		$player->dataPacket($pk);
		$this->isJumping = false;
		parent::spawnTo($player);
	}

	public function getDrops(){
		$drops = [
			ItemItem::get(ItemItem::FEATHER, 0, 1)
		];
		if($this->lastDamageCause instanceof EntityDamageByEntityEvent and $this->lastDamageCause->getEntity() instanceof Player){
			if(mt_rand(0, 199) < 5){
				switch(mt_rand(0, 2)){
					case 0:
						$drops[] = ItemItem::get(ItemItem::IRON_INGOT, 0, 1);
						break;
					case 1:
						$drops[] = ItemItem::get(ItemItem::CARROT, 0, 1);
						break;
					case 2:
						$drops[] = ItemItem::get(ItemItem::POTATO, 0, 1);
						break;
				}
			}
		}

		return $drops;
	}

	public function onUpdate($currentTick) {
		parent::onUpdate($currentTick);

		if(!$this->getLevel()->getServer()->zombieai) {
			return;
		}
		
		if($this->target === null) {
			$this->searchTarget();
			if($this->target === null) {
				$this->randomWalk();
				return;
			}
		}

		$target = $this->target;

		if(($this->root ?? null) === null or $this->root->isEmpty() or $this->root->isEnd()) {
			// echo "Root...\n";			
			$this->root = new RootExplorer([intval($this->x), intval($this->y), intval($this->z)], [intval($target->x), intval($target->y), intval($target->z)], $this->level);
			$this->root->exec();

			if($this->root->isEmpty())
				return;
		}
		// echo "Execute...\n";
		
		$x = $this->x;
		$z = $this->z;

		if($this->motionCount > 0 and $this->motionCount < 5) {
			$motion = $this->motion;

			$this->x += $motion[0] * 0.2;
			// $this->y += $motion[1] * 0.1;
			$this->z += $motion[2] * 0.2;
			$this->motionCount++;			
		} else {
			$this->motionCount = 0;
			$motion = $this->motion = $this->root->getRoot();

			$this->x += $motion[0] * 0.2;
			$this->y = $this->y + $this->motion[1];
			$this->z += $motion[2] * 0.2;
			
			$block1 = $this->getNearBlock(0, 0.5, 0);
			$block2 = $this->getNearBlock(0, 1.5, 0);

			if(($block1 instanceof Transparent) and !($block1 instanceof Stair)) {
				$this->y -= 1;
			} else if(!($block2 instanceof Transparent) and !($block2 instanceof Stair)){
				$this->y += 1;
			}


		}

		$this->setRotation(rad2deg(atan2($motion[2], $motion[0])) - 90, $this->pitch);

		$x = $this->x - $this->target->x;
		$y = $this->y - $this->target->y;
		$z = $this->z - $this->target->z;

		$distance = ($x**2 + $y**2 + $z**2);

		if($distance < self::ATTACK_RANGE**2) {
			$this->target->attack(2, new EntityDamageByEntityEvent($this, $this->target, 1, 2));
		}

	}

	public function randomWalk() {
		$this->motionCount++;

		if(($this->root ?? null) === null or $this->root->isEmpty() or $this->root->isEnd()) {
			// echo "Root...\n";			
			$this->root = new RootExplorer([intval($this->x), intval($this->y), intval($this->z)], [intval($this->x+mt_rand(-2, 2)), intval($this->y+mt_rand(-1, 1)), intval($this->z+mt_rand(-2, 2))], $this->level);
			$this->root->exec();

			if($this->root->isEmpty())
				return;
		}

		if($this->motionCount < 0) {
			return;
		}
		
		$motion = $this->motion ?? [0, 0, 0];

		if($this->motionCount < 20) {
			$this->x += $motion[0] * 0.2;
			$this->y = $this->y + $this->motion[1];
			$this->z += $motion[2] * 0.2;
		} else {
			$this->motion = $this->root->getRoot();
			$this->motionCount = -10*mt_rand(0, 2);
		}

		$this->motionCount++;
		
		$block1 = $this->getNearBlock(0, 0.5, 0);
		$block2 = $this->getNearBlock(0, 1.5, 0);
		
		$this->setRotation(rad2deg(atan2($motion[2], $motion[0])) - 90, $this->pitch);

		if(($block1 instanceof Transparent) and !($block1 instanceof Stair)) {
			$this->y -= 1;
		} else if(!($block2 instanceof Transparent) and !($block2 instanceof Stair)){
			$this->y += 1;
		}
	}

	public function searchTarget() {
		$distance = self::VIEWABLE_RANGE * self::VIEWABLE_RANGE;

		$target = null;

		foreach($this->level->getPlayers() as $player) {

			$p2e_distance = ($player->x - $this->x**2 + $player->z - $this->z**2);
			if($distance > $p2e_distance and !$player->isCreative()) {
				$target = $player;
				$distance = $p2e_distance;
			}
		}

		$this->target = $target;
	}

	public function attack($damage, EntityDamageEvent $source){
		parent::attack($damage, $source);

		if($source instanceof EntityDamageByEntityEvent) {
			$entity = $source->getDamager();
			$x = $this->x - $entity->x;
			$z = $this->z - $entity->z;
			$rad =  deg2rad(rad2deg(atan2($z, $x)) + 90);
			$this->x -= cos($rad);
			$this->z -= sin($rad);
			$this->updateMovement();
			$this->root = null; //再演算
		}

	}

	public function getNearBlock($x, $y, $z) {
		return $this->level->getBlock(new Position(($this->x + $x), ($this->y + $y), ($this->z + $z)));
	}

	public function isJumping() {
		return $this->isJumping ?? false;
	}
}
