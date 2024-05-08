<?php

namespace encritary\boat;

use encritary\boat\entity\Boat as BoatEntity;
use encritary\boat\entity\ChestBoat as ChestBoatEntity;
use encritary\boat\inventory\ChestBoatInventory;
use encritary\boat\item\ChestBoat as ChestBoatItem;
use pocketmine\block\Water;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\Boat as BoatItem;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;

class EventListener implements Listener{

	public static function dismountFromBoat(Player $player) : void{
		foreach($player->getWorld()->getNearbyEntities($player->getBoundingBox()->expandedCopy(2, 2, 2)) as $boat){
			if($boat instanceof BoatEntity){
				if($boat->getRider() === $player){
					$boat->dismountRider();
				}
				if($boat->getPassenger() === $player){
					$boat->dismountPassenger();
				}
			}
		}
	}

	public function __construct(){}

	public function onPlayerJoin(PlayerJoinEvent $event) : void{
		$event->getPlayer()->getNetworkSession()->getInvManager()->getContainerOpenCallbacks()->add(
			function(int $id, Inventory $inv) : ?array{
				if($inv instanceof ChestBoatInventory){
					return [ContainerOpenPacket::entityInv($id, WindowTypes::CONTAINER, $inv->getBoat()->getId())];
				}
				return null;
			}
		);
	}

	public function onPlayerInteract(PlayerInteractEvent $event) : void{
		if($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){
			$item = $event->getItem();
			if($item instanceof BoatItem){
				$boatType = $item->getType();

				$face = $event->getFace();
				$blockClicked = $event->getBlock();
				$blockReplace = $blockClicked->getSide($face);

				if($face !== Facing::UP || $blockReplace instanceof Water){
					$event->cancel();
					return;
				}

				$player = $event->getPlayer();

				$dy = $blockClicked instanceof Water ? -0.0625 : 0;
				$position = $blockReplace->getPosition()->add(0.5, $dy, 0.5);
				$yaw = fmod($player->getLocation()->yaw + 90, 360);

				if($item instanceof ChestBoatItem){
					$entity = new ChestBoatEntity(Location::fromObject($position, $player->getWorld(), $yaw, 0), $boatType);
				}else{
					$entity = new BoatEntity(Location::fromObject($position, $player->getWorld(), $yaw, 0), $boatType);
				}

				if($item->hasCustomName()){
					$entity->setNameTag($item->getCustomName());
				}
				if($player->isSurvival()){
					$item->pop();
					$player->getInventory()->setItemInHand($item);
				}
				$entity->spawnToAll();

				$event->cancel();
			}
		}
	}

	public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
		$packet = $event->getPacket();
		$player = $event->getOrigin()->getPlayer();

		if($packet instanceof InteractPacket){
			$entity = $player->getWorld()->getEntity($packet->targetActorRuntimeId);

			if($packet->action === InteractPacket::ACTION_LEAVE_VEHICLE){
				if($entity instanceof BoatEntity){
					if($entity->getRider() === $player){
						$entity->dismountRider();
						$event->cancel();
					}elseif($entity->getPassenger() === $player){
						$entity->dismountPassenger();
						$event->cancel();
					}
				}
			}elseif($packet->action === InteractPacket::ACTION_OPEN_INVENTORY){
				if($entity instanceof ChestBoatEntity && $entity->getRider() === $player){
					$entity->openInventory($player);
				}
			}
		}elseif($packet instanceof MoveActorAbsolutePacket){
			$entity = $player->getWorld()->getEntity($packet->actorRuntimeId);
			if($entity instanceof BoatEntity && $entity->getRider() === $player){
				$entity->riderMove($packet->position, $packet->yaw, $packet->pitch);
				$event->cancel();
			}
		}elseif($packet instanceof AnimatePacket){
			foreach($player->getWorld()->getNearbyEntities($player->getBoundingBox()->expandedCopy(2, 2, 2)) as $boat){
				if($boat instanceof BoatEntity){
					if($boat->getRider() === $player){
						if($packet->action === AnimatePacket::ACTION_ROW_LEFT){
							$boat->setPaddleTimeLeft($packet->float);
							$event->cancel();
						}elseif($packet->action === AnimatePacket::ACTION_ROW_RIGHT){
							$boat->setPaddleTimeRight($packet->float);
							$event->cancel();
						}

						break;
					}
				}
			}
		}
	}

	public function onPlayerQuit(PlayerQuitEvent $event) : void{
		self::dismountFromBoat($event->getPlayer());
	}

	/**
	 * @param EntityTeleportEvent $event
	 * @priority MONITOR
	 */
	public function onEntityTeleport(EntityTeleportEvent $event) : void{
		$player = $event->getEntity();
		if($player instanceof Player){
			self::dismountFromBoat($player);
		}
	}
}