<?php

namespace encritary\boat\entity;

use encritary\boat\EventListener;
use pocketmine\block\Water;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\BoatType;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\player\Player;

class Boat extends Entity{

	protected const BASE_OFFSET = 0.375;

	private const SINKING_DEPTH = 0.07;
	private const SINKING_SPEED = 0.0005;
	private const SINKING_MAX_SPEED = 0.005;

	public const TAG_WOOD_ID = "WoodID";

	public static function parseBoatType(CompoundTag $nbt) : BoatType{
		return match ($woodID = $nbt->getByte(self::TAG_WOOD_ID, 0)){
			0 => BoatType::OAK(),
			1 => BoatType::SPRUCE(),
			2 => BoatType::BIRCH(),
			3 => BoatType::JUNGLE(),
			4 => BoatType::ACACIA(),
			5 => BoatType::DARK_OAK(),
			6 => BoatType::MANGROVE(),
			default => throw new \InvalidArgumentException("Unknown woodID value: $woodID")
		};
	}

	protected BoatType $boatType;
	protected bool $sinking = false;

	protected int $rollingAmplitude = 0;
	protected bool $rollingDirection = false;

	protected ?Player $rider = null;
	protected ?Player $passenger = null;

	protected float $paddleTimeLeft = 0.0;
	protected float $paddleTimeRight = 0.0;

	public function __construct(Location $location, BoatType $boatType, ?CompoundTag $nbt = null){
		$this->boatType = $boatType;
		parent::__construct($location, $nbt);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->networkPropertiesDirty = true;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();

		$nbt->setByte(self::TAG_WOOD_ID, $this->getVariant());
		return $nbt;
	}

	protected function getVariant() : int{
		return match ($this->boatType->id()){
			BoatType::OAK()->id() => 0,
			BoatType::SPRUCE()->id() => 1,
			BoatType::BIRCH()->id() => 2,
			BoatType::JUNGLE()->id() => 3,
			BoatType::ACACIA()->id() => 4,
			BoatType::DARK_OAK()->id() => 5,
			BoatType::MANGROVE()->id() => 6
		};
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setInt(EntityMetadataProperties::VARIANT, $this->getVariant());
		$properties->setByte(EntityMetadataProperties::CONTROLLING_RIDER_SEAT_NUMBER, 0);

		$properties->setGenericFlag(EntityMetadataFlags::STACKABLE, true);

		$properties->setByte(EntityMetadataProperties::IS_BUOYANT, 1);
		$properties->setString(EntityMetadataProperties::BUOYANCY_DATA, "{\"apply_gravity\":true,\"base_buoyancy\":1.0,\"big_wave_probability\":0.03,\"big_wave_speed\":10.0,\"drag_down_on_buoyancy_removed\":0.0,\"liquid_blocks\":[\"minecraft:water\",\"minecraft:flowing_water\"],\"simulate_waves\":true}");

		$properties->setInt(EntityMetadataProperties::HURT_TIME, $this->rollingAmplitude);
		$properties->setInt(EntityMetadataProperties::HURT_DIRECTION, $this->rollingDirection ? 1 : -1);

		$properties->setFloat(EntityMetadataProperties::PADDLE_TIME_LEFT, $this->paddleTimeLeft);
		$properties->setFloat(EntityMetadataProperties::PADDLE_TIME_RIGHT, $this->paddleTimeRight);

		$properties->setInt(EntityMetadataProperties::HEALTH, (int) ($this->getMaxHealth() - $this->getHealth()));
	}

	protected function performHurtAnimation() : void{
		$this->rollingAmplitude = 9;
		$this->rollingDirection = !$this->rollingDirection;
		$this->networkPropertiesDirty = true;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			if($damager instanceof Player && $damager->isCreative()){
				$source->setBaseDamage(1000); // insta-kill
			}
		}

		parent::attack($source);

		if(!$source->isCancelled() && $this->isAlive()){
			$this->performHurtAnimation();
		}
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($this->rider === null){
			EventListener::dismountFromBoat($player);
			$this->setRider($player);
		}elseif($this->passenger === null){
			EventListener::dismountFromBoat($player);
			$this->setPassenger($player);
		}
		return true;
	}

	public function setRider(Player $player) : void{
		$this->dismountRider();

		$this->rider = $player;

		$playerProps = $player->getNetworkProperties();
		$playerProps->setGenericFlag(EntityMetadataFlags::RIDING, true);
		$playerProps->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, new Vector3(0.2, 1.02, 0));
		$playerProps->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, 1);
		$playerProps->setFloat(EntityMetadataProperties::RIDER_MIN_ROTATION, 0);
		$playerProps->setFloat(EntityMetadataProperties::RIDER_MAX_ROTATION, 90);
		$playerProps->setFloat(EntityMetadataProperties::RIDER_SEAT_ROTATION_OFFSET, -90);

		$this->setMotion(Vector3::zero());
		$this->keepMovement = true;

		NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [SetActorLinkPacket::create(
			new EntityLink($this->id, $this->rider->id, EntityLink::TYPE_RIDER, true, true)
		)]);
	}

	public function getRider() : ?Player{
		return $this->rider;
	}

	public function dismountRider() : void{
		if($this->rider !== null){
			$playerProps = $this->rider->getNetworkProperties();
			$playerProps->setGenericFlag(EntityMetadataFlags::RIDING, false);
			$playerProps->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, new Vector3(0, 0, 0));
			$playerProps->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, 0);

			NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [SetActorLinkPacket::create(
				new EntityLink($this->id, $this->rider->id, EntityLink::TYPE_REMOVE, true, true)
			)]);

			if($this->passenger !== null){
				$passenger = $this->passenger;

				$this->dismountPassenger();
				$this->setRider($passenger);
			}else{
				$this->keepMovement = false;
				$this->rider = null;
			}
		}
	}

	public function setPassenger(Player $player) : void{
		$this->dismountPassenger();

		$this->passenger = $player;

		$playerProps = $player->getNetworkProperties();
		$playerProps->setGenericFlag(EntityMetadataFlags::RIDING, true);
		$playerProps->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, new Vector3(-0.6, 1.02, 0));
		$playerProps->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, 1);
		$playerProps->setFloat(EntityMetadataProperties::RIDER_MIN_ROTATION, 0);
		$playerProps->setFloat(EntityMetadataProperties::RIDER_MAX_ROTATION, 90);
		$playerProps->setFloat(EntityMetadataProperties::RIDER_SEAT_ROTATION_OFFSET, -90);

		NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [SetActorLinkPacket::create(
			new EntityLink($this->id, $this->passenger->id, EntityLink::TYPE_PASSENGER, true, false)
		)]);
	}

	public function getPassenger() : ?Player{
		return $this->passenger;
	}

	public function dismountPassenger() : void{
		if($this->passenger !== null){
			$playerProps = $this->passenger->getNetworkProperties();
			$playerProps->setGenericFlag(EntityMetadataFlags::RIDING, false);
			$playerProps->setVector3(EntityMetadataProperties::RIDER_SEAT_POSITION, new Vector3(0, 0, 0));
			$playerProps->setByte(EntityMetadataProperties::RIDER_ROTATION_LOCKED, 0);

			NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [SetActorLinkPacket::create(
				new EntityLink($this->id, $this->passenger->id, EntityLink::TYPE_REMOVE, true, false)
			)]);
			$this->passenger = null;
		}
	}

	public function kill() : void{
		parent::kill();

		$drop = true;
		if($this->lastDamageCause instanceof EntityDamageByEntityEvent){
			$damager = $this->lastDamageCause->getDamager();
			if($damager instanceof Player && $damager->isCreative()){
				$drop = false;
			}
		}

		if($drop){
			$this->getWorld()->dropItem($this->location, $this->getDropItem());
		}
	}

	protected function getDropItem() : Item{
		return match ($this->boatType) {
			BoatType::OAK() => VanillaItems::OAK_BOAT(),
			BoatType::SPRUCE() => VanillaItems::SPRUCE_BOAT(),
			BoatType::BIRCH() => VanillaItems::BIRCH_BOAT(),
			BoatType::JUNGLE() => VanillaItems::JUNGLE_BOAT(),
			BoatType::DARK_OAK() => VanillaItems::DARK_OAK_BOAT(),
			BoatType::MANGROVE() => VanillaItems::MANGROVE_BOAT()
		};
	}

	public function onUpdate(int $currentTick) : bool{
		if($this->rollingAmplitude > 0){
			--$this->rollingAmplitude;
			$this->networkPropertiesDirty = true;
		}

		return parent::onUpdate($currentTick);
	}

	protected function tryChangeMovement() : void{
		if($this->rider !== null){
			return;
		}
		$mY = $this->motion->y;

		$waterDiff = $this->getWaterLevel();
		if($this->rider === null){
			if($waterDiff > self::SINKING_DEPTH && !$this->sinking){
				$this->sinking = true;
			}elseif($waterDiff < -self::SINKING_DEPTH && $this->sinking){
				$this->sinking = false;
			}

			if($waterDiff < -self::SINKING_DEPTH){
				$mY = min(0.05, $mY + 0.005);
			}elseif($waterDiff < 0 || !$this->sinking){
				$mY = $mY > self::SINKING_MAX_SPEED ? max($mY - 0.02, self::SINKING_MAX_SPEED) : $mY + self::SINKING_SPEED;
			}
		}

		$this->checkObstruction($this->location->x, $this->location->y, $this->location->z);

		if($this->rider === null){
			if($waterDiff > self::SINKING_DEPTH || $this->sinking){
				$mY = $waterDiff > 0.5 ? $mY - $this->gravity : ($mY - self::SINKING_SPEED < -self::SINKING_MAX_SPEED ? $mY : $mY - self::SINKING_SPEED);
			}
		}

		$friction = 1 - $this->drag;
		if($this->onGround){
			$friction *= $this->getWorld()->getBlockAt((int) floor($this->location->x), (int) floor($this->location->y - 1), (int) floor($this->location->z))->getFrictionFactor();
		}

		$this->motion = new Vector3($this->motion->x * $friction, $mY, $this->motion->z * $friction);
	}

	protected function getWaterLevel() : float{
		$maxY = $this->boundingBox->minY + self::BASE_OFFSET;

		$diffY = INF;
		foreach($this->getBlocksAroundWithEntityInsideActions() as $block){
			if($block instanceof Water){
				$level = ($block->getPosition()->getY() + 1) - ($block->getFluidHeightPercent() - 0.1111111);
				$diffY = min($maxY - $level, $diffY);
			}
		}

		return $diffY;
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.455, 1.4);
	}

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3->add(0, self::BASE_OFFSET, 0);
	}

	protected function sendSpawnPacket(Player $player) : void{
		$session = $player->getNetworkSession();

		$session->sendDataPacket(AddActorPacket::create(
			$this->getId(),
			$this->getId(),
			static::getNetworkTypeId(),
			$this->getOffsetPosition($this->location),
			$this->getMotion(),
			$this->location->pitch,
			$this->location->yaw,
			$this->location->yaw, // head yaw
			$this->location->yaw, // body yaw
			array_map(function(Attribute $attr) : NetworkAttribute{
				return new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue(), []);
			}, $this->attributeMap->getAll()),
			$this->getAllNetworkData(),
			new PropertySyncData([], []),
			[]
		));

		if($this->rider !== null){
			$session->sendDataPacket(SetActorLinkPacket::create(
				new EntityLink($this->id, $this->rider->getId(), EntityLink::TYPE_RIDER, true, true)
			));
		}
		if($this->passenger !== null){
			$session->sendDataPacket(SetActorLinkPacket::create(
				new EntityLink($this->id, $this->passenger->getId(), EntityLink::TYPE_PASSENGER, true, false)
			));
		}
	}

	public function onCollideWithPlayer(Player $player) : void{
		if($this->rider !== null || $player === $this->passenger){
			return;
		}

		if($player->isSpectator() || !$player->boundingBox->intersectsWith($this->boundingBox->expandedCopy(0.2, -0.1, 0.2))){
			return;
		}

		$diffX = $player->location->x - $this->location->x;
		$diffZ = $player->location->z - $this->location->z;

		$forceSquared = max(abs($diffX), abs($diffZ));
		if($forceSquared >= 0.01){
			$force = sqrt($forceSquared);
			$diffX /= $force;
			$diffZ /= $force;

			$multiplier = min(1 / $force, 1) * 0.05;

			$diffX *= $multiplier;
			$diffZ *= $multiplier;

			$this->setMotion(new Vector3($this->motion->x - $diffX, $this->motion->y, $this->motion->z - $diffZ));
		}
	}

	public function riderMove(Vector3 $position, ?float $yaw = null, ?float $pitch = null) : void{
		$this->setPositionAndRotation(
			$position->subtract(0, self::BASE_OFFSET, 0),
			$yaw ?? $this->location->yaw, $pitch ?? $this->location->pitch
		);
		$this->updateMovement();
	}

	public function setPaddleTimeLeft(float $value) : void{
		$this->paddleTimeLeft = $value;
		$this->networkPropertiesDirty = true;
	}

	public function setPaddleTimeRight(float $value) : void{
		$this->paddleTimeRight = $value;
		$this->networkPropertiesDirty = true;
	}

	public function setNoClientPredictions(bool $value = true) : void{
		// NOOP
	}

	protected function getInitialDragMultiplier() : float{
		return 0.1;
	}

	protected function getInitialGravity() : float{
		return 0.04;
	}

	public static function getNetworkTypeId() : string{
		return EntityIds::BOAT;
	}
}
