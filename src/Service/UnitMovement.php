<?php

namespace App\Service;

use App\Entity\Base;
use DateInterval;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

class UnitMovement
{
	/**
	 * @var EntityManagerInterface
	 */
	private $em;

	/**
	 * @var Globals
	 */
	private $globals;

	/**
	 * @var Mission
	 */
	private $mission;

	/**
	 * @var Fight
	 */
	private $fight;

	/**
	 * UnitMovement constructor.
	 * @param EntityManagerInterface $em
	 * @param Globals $globals
	 * @param Mission $mission
	 * @param Fight $fight
	 */
	public function __construct(EntityManagerInterface $em, Globals $globals, Mission $mission, Fight $fight)
	{
		$this->em = $em;
		$this->globals = $globals;
		$this->mission = $mission;
		$this->fight = $fight;
	}

	/**
	 * method that get entity of current movement based on the type and type id of it
	 * @param int $type
	 * @param int $type_id
	 * @return \App\Entity\Mission|Base|null
	 */
	private function getEntityOfTypeMovement(int $type, int $type_id)
	{
		$entity = null;
		if ($type === \App\Entity\UnitMovement::TYPE_MISSION) {
			$entity = \App\Entity\Mission::class;
		} else if ($type === \App\Entity\UnitMovement::TYPE_ATTACK) {
			$entity = Base::class;
		}

		if (!$entity) {
			return null;
		}

		return $this->em->getRepository($entity)->find($type_id);
	}

	/**
	 * method that create a unit movement
	 * @param int $type
	 * @param int $type_id
	 * @param int $movement_type
	 * @param int|null $config_id
	 * @return \App\Entity\UnitMovement
	 * @throws Exception
	 */
	public function create(int $type, int $type_id, int $movement_type, int $config_id = null):\App\Entity\UnitMovement
	{
		$now = new DateTime();
		if ($type === \App\Entity\UnitMovement::TYPE_MISSION) {
			$mission_config = $this->globals->getMissionsConfig()[$config_id];
			$duration = $mission_config["duration"];
		} else {
			$base_dest = $this->em->getRepository(Base::class)->find($type_id);
			$duration = $this->globals->getTimeToTravel($this->globals->getCurrentBase(), $base_dest);
		}

		$unit_movement = new \App\Entity\UnitMovement();
		$unit_movement->setBase($this->globals->getCurrentBase());
		$unit_movement->setDuration($duration);
		$unit_movement->setEndDate($now->add(new DateInterval("PT". $duration ."S")));
		$unit_movement->setType($type);
		$unit_movement->setTypeId($type_id);
		$unit_movement->setMovementType($movement_type);
		$this->em->persist($unit_movement);
		$this->em->flush();

		return $unit_movement;
	}

	/**
	 * method that return current units movements in base
	 */
	public function getCurrentMovementsInBase()
	{
		$unit_movements = $this->em->getRepository(\App\Entity\UnitMovement::class)->findBy([
			"base" => $this->globals->getCurrentBase()
		]);
		$return_movements = [];

		foreach ($unit_movements as $unit_movement) {
			$entity_type = $this->getEntityOfTypeMovement($unit_movement->getType(), $unit_movement->getTypeId());

			$return_movements[] = [
				"duration" => $unit_movement->getDuration(),
				"end_date" => $unit_movement->getEndDate()->getTimestamp(),
				"type" => $unit_movement->getType(),
				"string_type" => $unit_movement->getStringType(),
				"entity_type" => $entity_type,
				"movement_type" => $unit_movement->getType(),
				"units" => $this->em->getRepository(\App\Entity\UnitMovement::class)->findByUnitsInMovement($unit_movement)
			];
		}

		return $return_movements;
	}

	/**
	 * method called to update unit movements of the base
	 * @param Base $base
	 * @throws Exception
	 */
	public function updateUnitMovement(Base $base)
	{
		$em = $this->em;
		$unit_movements_ended = $em->getRepository(\App\Entity\UnitMovement::class)->findByMovementEnded($base);

		/** @var \App\Entity\UnitMovement $unit_movement */
		foreach ($unit_movements_ended as $unit_movement) {
			if ($unit_movement->getType() === \App\Entity\UnitMovement::TYPE_ATTACK && $unit_movement->getMovementType() === \App\Entity\UnitMovement::MOVEMENT_TYPE_GO) {
				$this->fight->attackBase($base, $unit_movement, $this->getEntityOfTypeMovement($unit_movement->getType(), $unit_movement->getTypeId()));
			} else if ($unit_movement->getType() === \App\Entity\UnitMovement::TYPE_ATTACK && $unit_movement->getMovementType() === \App\Entity\UnitMovement::MOVEMENT_TYPE_RETURN) {
				$this->em->remove($unit_movement);
			} else if ($unit_movement->getType() === \App\Entity\UnitMovement::TYPE_MISSION) {
				$this->mission->endMission($base, $unit_movement, $this->getEntityOfTypeMovement($unit_movement->getType(), $unit_movement->getTypeId()));
			}
		}
	}
}