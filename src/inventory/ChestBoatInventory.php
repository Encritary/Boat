<?php

namespace encritary\boat\inventory;

use encritary\boat\entity\ChestBoat;
use pocketmine\inventory\SimpleInventory;

class ChestBoatInventory extends SimpleInventory{

	protected ChestBoat $boat;

	public function __construct(ChestBoat $boat){
		$this->boat = $boat;
		parent::__construct(27);
	}

	public function getBoat() : ChestBoat{
		return $this->boat;
	}
}