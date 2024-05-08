<?php

namespace encritary\boat\item;

use pocketmine\item\BoatType;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier as IID;
use pocketmine\item\ItemTypeIds;
use pocketmine\utils\CloningRegistryTrait;

/**
 * This doc-block is generated automatically, do not modify it manually.
 * This must be regenerated whenever registry members are added, removed or changed.
 * @see build/generate-registry-annotations.php
 * @generate-registry-docblock
 *
 * @method static ChestBoat ACACIA_CHEST_BOAT()
 * @method static ChestBoat BIRCH_CHEST_BOAT()
 * @method static ChestBoat DARK_OAK_CHEST_BOAT()
 * @method static ChestBoat JUNGLE_CHEST_BOAT()
 * @method static ChestBoat MANGROVE_CHEST_BOAT()
 * @method static ChestBoat OAK_CHEST_BOAT()
 * @method static ChestBoat SPRUCE_CHEST_BOAT()
 */
final class ChestBoatItems{
	use CloningRegistryTrait;

	private function __construct(){
		//NOOP
	}

	protected static function register(string $name, Item $item) : void{
		self::_registryRegister($name, $item);
	}

	/**
	 * @return ChestBoat[]
	 * @phpstan-return array<string, ChestBoat>
	 */
	public static function getAll() : array{
		//phpstan doesn't support generic traits yet :(
		/** @var ChestBoat[] $result */
		$result = self::_registryGetAll();
		return $result;
	}

	protected static function setup() : void{
		foreach(BoatType::getAll() as $type){
			self::register($type->name() . "_chest_boat", new ChestBoat(
				new IID(ItemTypeIds::newId()),
				$type->getDisplayName() . " ChestBoat", $type
			));
		}
	}
}