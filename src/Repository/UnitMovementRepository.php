<?php

namespace App\Repository;

use App\Entity\Base;
use App\Entity\UnitMovement;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityRepository;
use Exception;

class UnitMovementRepository extends EntityRepository
{

	/**
	 * method to find units that are currently in movement
	 * @param UnitMovement $unitMovement
	 * @return array
	 */
	public function findByUnitsInMovement(UnitMovement $unitMovement): array
	{
		$query = $this->getEntityManager()->createQuery("SELECT u.id, u.name, u.array_name, count(u) as number FROM App:Unit u
			WHERE u.unitMovement = :unit_movement
			GROUP BY u.array_name
		");
		$query->setParameter("unit_movement", $unitMovement, Type::OBJECT);

		$results = $query->getResult();
		$return_results = [];

		foreach ($results as $result) {
			$return_results[] = [
				"id" => $result["id"],
				"name" => $result["name"],
				"array_name" => $result["array_name"],
				"number" => $result["number"]
			];
		}

		return $return_results;
	}

	/**
	 * method that return all ended movements
	 * @param Base $base
	 * @return mixed
	 * @throws \Exception
	 */
	public function findByMovementEnded(Base $base)
	{
		$query = $this->getEntityManager()->createQuery("SELECT um FROM App:UnitMovement um
			WHERE um.base = :base AND um.end_date <= :now
		");

		$query->setParameter("base", $base, Type::OBJECT);
		$query->setParameter("now", new \DateTime(), Type::DATETIME);

		return $query->getResult();
	}
}