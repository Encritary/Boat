<?php

declare(strict_types=1);

namespace encritary\boat;

use encritary\boat\entity\Boat;
use encritary\boat\entity\ChestBoat;
use encritary\boat\item\ChestBoatItems;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\plugin\PluginBase;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\World;

class Loader extends PluginBase{

	public function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents(new EventListener(), $this);

		EntityFactory::getInstance()->register(Boat::class, function(World $world, CompoundTag $nbt) : Boat{
			return new Boat(EntityDataHelper::parseLocation($nbt, $world), Boat::parseBoatType($nbt), $nbt);
		}, ['Boat', 'minecraft:boat']);

		EntityFactory::getInstance()->register(ChestBoat::class, function(World $world, CompoundTag $nbt) : ChestBoat{
			return new ChestBoat(EntityDataHelper::parseLocation($nbt, $world), Boat::parseBoatType($nbt), $nbt);
		}, ['ChestBoat', 'minecraft:chest_boat']);

		foreach(ChestBoatItems::getAll() as $item){
			$id = $item->getType()->name() . "_chest_boat";
			self::registerSimpleItem("minecraft:" . $id, $item, [$id]);
		}
	}

	private static function registerSimpleItem(string $id, Item $item, array $stringToItemParserNames) : void{
		GlobalItemDataHandlers::getDeserializer()->map($id, fn() => clone $item);
		GlobalItemDataHandlers::getSerializer()->map($item, fn() => new SavedItemData($id));

		foreach($stringToItemParserNames as $name){
			StringToItemParser::getInstance()->register($name, fn() => clone $item);
		}
	}
}
