<?php

namespace encritary\boat\entity;

use encritary\boat\EventListener;
use encritary\boat\inventory\ChestBoatInventory;
use encritary\boat\item\ChestBoatItems;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\inventory\Inventory;
use pocketmine\item\BoatType;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;

class ChestBoat extends Boat{

	protected const TAG_ITEMS = "Items";

	protected Inventory $inventory;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->inventory = new ChestBoatInventory($this);

		$inventoryTag = $nbt->getTag(self::TAG_ITEMS);
		if($inventoryTag instanceof ListTag && $inventoryTag->getTagType() === NBT::TAG_Compound){
			$listeners = $this->inventory->getListeners()->toArray();
			$this->inventory->getListeners()->remove(...$listeners); //prevent any events being fired by initialization

			$newContents = [];
			/** @var CompoundTag $itemNBT */
			foreach($inventoryTag as $itemNBT){
				try{
					$newContents[$itemNBT->getByte(SavedItemStackData::TAG_SLOT)] = Item::nbtDeserialize($itemNBT);
				}catch(SavedDataLoadingException $e){
					//TODO: not the best solution
					\GlobalLogger::get()->logException($e);
					continue;
				}
			}
			$this->inventory->setContents($newContents);

			$this->inventory->getListeners()->add(...$listeners);
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setByte(EntityMetadataProperties::CONTAINER_TYPE, 10);
		$properties->setInt(EntityMetadataProperties::CONTAINER_BASE_SIZE, $this->inventory->getSize());
		$properties->setInt(EntityMetadataProperties::CONTAINER_EXTRA_SLOTS_PER_STRENGTH, 0);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();

		$items = [];
		foreach($this->inventory->getContents() as $slot => $item){
			$items[] = $item->nbtSerialize($slot);
		}

		$nbt->setTag(self::TAG_ITEMS, new ListTag($items, NBT::TAG_Compound));
		return $nbt;
	}

	public function openInventory(Player $player) : void{
		$player->setCurrentWindow($this->inventory);
	}

	public function kill() : void{
		parent::kill();

		foreach($this->inventory->getContents() as $item){
			$this->getWorld()->dropItem($this->location, $item);
		}
	}

	protected function getDropItem() : Item{
		return match ($this->boatType) {
			BoatType::OAK() => ChestBoatItems::OAK_CHEST_BOAT(),
			BoatType::SPRUCE() => ChestBoatItems::SPRUCE_CHEST_BOAT(),
			BoatType::BIRCH() => ChestBoatItems::BIRCH_CHEST_BOAT(),
			BoatType::JUNGLE() => ChestBoatItems::JUNGLE_CHEST_BOAT(),
			BoatType::DARK_OAK() => ChestBoatItems::DARK_OAK_CHEST_BOAT(),
			BoatType::MANGROVE() => ChestBoatItems::MANGROVE_CHEST_BOAT()
		};
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($this->rider === null){
			EventListener::dismountFromBoat($player);
			$this->setRider($player);
		}
		// no passenger
		return true;
	}

	public function setPassenger(Player $player) : void{
		throw new \BadFunctionCallException("Can't set passenger on chest boat");
	}

	public function dismountPassenger() : void{
		throw new \BadFunctionCallException("Can't dismount passenger on chest boat");
	}

	public static function getNetworkTypeId() : string{
		return EntityIds::CHEST_BOAT;
	}
}