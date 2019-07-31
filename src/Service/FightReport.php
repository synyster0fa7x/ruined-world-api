<?php

namespace App\Service;

use App\Entity\Base;
use App\Entity\Message;
use App\Entity\MessageBox;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class FightReport
{
	private $attack_units;

	private $defend_units;

	private $end_attack_units;

	private $end_defend_units;

	/**
	 * @var EntityManagerInterface
	 */
	private $em;

	/**
	 * @var SessionInterface
	 */
	private $session;

	/**
	 * FightReport constructor.
	 * @param EntityManagerInterface $em
	 * @param SessionInterface $session
	 */
	public function __construct(EntityManagerInterface $em, SessionInterface $session)
	{
		$this->em = $em;
		$this->session = $session;
	}

	/**
	 * @param $attack_units
	 */
	public function setStartAttackUnits($attack_units)
	{
		$start_attack = [];
		if (count($attack_units) > 0) {
			$start_attack = clone $attack_units;
		}

		$this->attack_units = $start_attack;
	}

	/**
	 * @param $defend_units
	 */
	public function setStartDefendUnits($defend_units)
	{
		$start_defend = $defend_units;
		$this->defend_units = $start_defend;
	}

	/**
	 * @param $attack_units
	 */
	public function setEndAttackUnits($attack_units)
	{
		$this->end_attack_units = $attack_units;
	}

	/**
	 * @param $defend_units
	 */
	public function setEndDefendUnits($defend_units)
	{
		$this->end_defend_units = $defend_units;
	}

	/**
	 * @param $type
	 * @return array
	 */
	private function getUnitsNumberSentAndReturned($type): array
	{
		$var = $type === "attack" ? "attack_units" : "defend_units";
		$endvar = $type === "attack" ? "end_attack_units" : "end_defend_units";
		$units = [];

		foreach ($this->$var as $unit) {
			if (array_key_exists($unit->getArrayName(), $units)) {
				$units[$unit->getArrayName()]["number"]++;
			} else {
				$units[$unit->getArrayName()] = [
					"name" => $unit->getName(),
					"number" => 1,
					"return_number" => 0,
				];
			}
		}
		foreach ($this->$endvar as $unit) {
			$units[$unit->getArrayName()]["return_number"]++;
		}

		return $units;
	}

	/**
	 * @param $unitMovement
	 * @param $attack_units
	 * @param $defend_units
	 * @param string $type
	 * @return string
	 */
	private function createTextForReport($unitMovement, $attack_units, $defend_units, string $type): string
	{
		$text = $type === "attack" ? "<h2>rapport des unités envoyées</h2>" : "<h2>rapport des unités qui ont attaquées</h2>";
		foreach ($attack_units as $attack_unit) {
			$text .= $attack_unit["name"] . " qui ont survécus  : " . $attack_unit["return_number"] . " / " . $attack_unit["number"] . "<br>";
		}

		$text .= "<h2>rapport des unités attaquées</h2>";
		foreach ($defend_units as $defend_unit) {
			$text .= $defend_unit["name"] . " tués  : " . $defend_unit["return_number"] . " / " . $defend_unit["number"] . "<br>";
		}

		$text .= "<h2>Ressources volées</h2>";
		$text .= "<ul>";
		$text .= "<li>Electricité : " . $unitMovement->getElectricity() . "</li>";
		$text .= "<li>Fer : " . $unitMovement->getIron() . "</li>";
		$text .= "<li>Fuel : " . $unitMovement->getFuel() . "</li>";
		$text .= "<li>Eau : " . $unitMovement->getWater() . "</li>";
		$text .= "</ul>";

		return $text;
	}

	/**
	 * @param \App\Entity\UnitMovement $unit_movement
	 * @throws Exception
	 */
	public function createReport(\App\Entity\UnitMovement $unit_movement)
	{
		$types = ["attack", "defend"];
		$attack_units = $this->getUnitsNumberSentAndReturned("attack");
		$defend_units = $this->getUnitsNumberSentAndReturned("defend");

		foreach ($types as $type) {
			$message = new Message();

			if ($type === "attack") {
				$user = $unit_movement->getBase()->getUser();
			} else {
				$base_dest = $this->em->getRepository(Base::class)->find($unit_movement->getTypeId());
				if ($base_dest) {
					$user = $base_dest->getUser();
				}
			}

			if (!$user) {
				continue;
			}

			$text = $this->createTextForReport($unit_movement, $attack_units, $defend_units, $type);

			$message->setSubject("rapport de combat");
			$message->setMessage($text);
			$message->setSendAt(new DateTime());
			$message->setUser($user);
			$this->em->persist($message);

			$message_box = new MessageBox();
			$message_box->setUser($user);
			$message_box->setMessage($message);
			$message_box->setType(MessageBox::FIGHT_REPORT);
			$this->em->persist($message_box);
		}

		$this->em->flush();
	}
}