<?php

namespace App\Controller;

use App\Entity\Base;
use App\Entity\Unit;
use App\Service\Globals;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class FightSimulatorController extends AbstractController
{

	/**
	 * @Route("/api/fight/all-units-type/", name="figth_all_units_types", methods={"POST"})
	 * @param SessionInterface $session
	 * @param Globals $globals
	 * @return JsonResponse
	 */
	public function sendAllUnitTypes(SessionInterface $session, Globals $globals): JsonResponse
	{
		$units_config = $globals->getUnitsConfig();

		return new JsonResponse([
			"success" => true,
			"token" => $session->get("user")->getToken(),
			"units" => $units_config
		]);
	}

	/**
	 * method that create units of attack and defense
	 * @param string $type
	 * @param $units
	 * @return array
	 */
	private function createUnits(string $type, $units): array
	{
		$return_units = [];
		$base = new Base();
		$base->setId(1);
		if ($type === "defense") $base->setId(2);

		foreach ($units as $array_name => $number) {
			for ($i=0 ; $i<$number ; $i++) {
				$unit = new Unit();
				$unit->setName($array_name);
				$unit->setArrayName($array_name);
				$unit->setAssaultLevel(1);
				$unit->setDefenseLevel(1);
				$unit->setLife(100);
				$unit->setBase($base);
				$return_units[] = $unit;
			}
		}

		return $return_units;
	}

	/**
	 * method that put damage on unit when is in defense or get damage from a defense unit
	 * @param Globals $globals
	 * @param Unit $unit
	 * @param array $units
	 * @param string $type
	 * @return array
	 */
	private function attackOrDefendUnit(Globals $globals, Unit $unit, array $units, string $type = "attack"): array
	{
		$units_config = $globals->getUnitsConfig();
		$power = $units_config[$unit->getArrayName()][$type."_power"];
		$key = count(array_keys($units)) > 0 ? array_keys($units)[0] : null;

		if ($key !== null) {
			$units[$key]->setLife($units[$key]->getLife() - $power);

			if ($units[$key]->getLife() <= 0) {
				$delete_for_next = abs($units[$key]->getLife());
				unset($units[$key]);
				$key = count(array_keys($units)) > 0 ? array_keys($units)[0] : null;

				if ($key !== null) {
					$units[$key]->setLife($units[$key]->getLife() - $delete_for_next);
				}
			}
		}

		return $units;
	}

	/**
	 * @Route("/api/fight/simulate/", name="fight_simulate")
	 * @param SessionInterface $session
	 * @param Globals $globals
	 * @return JsonResponse
	 */
	public function simulateFight(SessionInterface $session, Globals $globals): JsonResponse
	{
		$infos = $session->get("jwt_infos");
		$base = new Base();
		$base->setId(1);
		$other_base_units = $this->createUnits("defense", $infos->defense_units);
		$base_units = $this->createUnits("attack", $infos->attack_units);

		$test = array_merge($other_base_units, $base_units);
		shuffle($test);

		foreach ($test as $unit) {
			if ($unit->getBase()->getId() === $base->getId()) {
				$other_base_units = $this->attackOrDefendUnit($globals, $unit, $other_base_units, "attack");
			} else {
				$base_units = $this->attackOrDefendUnit($globals, $unit, $base_units, "defense");
			}
		}

		return new JsonResponse();
	}
}