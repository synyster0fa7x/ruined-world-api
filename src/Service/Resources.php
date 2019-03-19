<?php

namespace App\Service;

use App\Entity\Base;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class Resources
{
	/**
	 * @var EntityManagerInterface
	 */
	private $em;
	
	/**
	 * @var SessionInterface
	 */
	private $session;
	
	/**
	 * @var Globals
	 */
	private $globals;
	
	/**
	 * @var Base
	 */
	private $base;
	
	/**
	 * @var int
	 */
	private $max_warehouse_storage;
	
	/**
	 * @var int
	 */
	private $max_garner_storage;
	
	/**
	 * @var int
	 */
	private $electricity_production;
	
	/**
	 * @var int
	 */
	private $fuel_production;
	
	/**
	 * @var int
	 */
	private $iron_production;
	
	/**
	 * @var int
	 */
	private $water_production;
	
	/**
	 * Resources constructor.
	 * @param EntityManagerInterface $em
	 * @param SessionInterface $session
	 * @param Globals $globals
	 */
	public function __construct(EntityManagerInterface $em, SessionInterface $session, Globals $globals)
	{
		$this->em = $em;
		$this->session = $session;
		$this->globals = $globals;
		$this->base = $globals->getCurrentBase(true);
	}
	
	/**
	 * method called to add resource
	 * @param string $resource
	 * @param int $value_to_add
	 */
	public function addResource(string $resource, int $value_to_add)
	{
		$getter = "get" . ucfirst($resource);
		$setter = "set" . ucfirst($resource);
		
		$new_resource = $this->base->$getter() + $value_to_add;
		
		if ($new_resource > $this->getWarehouseCapacity()) {
			$new_resource = $this->getWarehouseCapacity();
		}
		
		$this->base->$setter($new_resource);
		$this->em->flush();
	}
	
	/**
	 * method called to withdraw resource
	 * @param string $resource
	 * @param int $value_to_add
	 */
	public function withdrawResource(string $resource, int $value_to_add)
	{
		$getter = "get" . ucfirst($resource);
		$setter = "set" . ucfirst($resource);
		
		$new_resource = $this->base->$getter() + $value_to_add;
		
		if ($new_resource < 0) {
			$new_resource = 0;
		}
		
		$this->base->$setter($new_resource);
		$this->em->flush();
	}
	
	/**
	 * method that return maximum storage of the warehouse
	 * @return int
	 */
	public function getWarehouseCapacity(): int
	{
		return $this->getStorageCapacityOrProduction("warehouse", "max_warehouse_storage");
	}
	
	/**
	 * method that return maximum storage of the warehouse
	 * @return int
	 */
	public function getGarnerCapacity(): int
	{
		return $this->getStorageCapacityOrProduction("garner", "max_garner_storage");
	}
	
	/**
	 * method that return production per hour of electricity station
	 * @return mixed
	 */
	public function getElectricityProduction()
	{
		return $this->getStorageCapacityOrProduction("electricity_station", "electricity_production", false);
	}
	
	/**
	 * method that return production per hour of fuel station
	 * @return mixed
	 */
	public function getFuelProduction()
	{
		return $this->getStorageCapacityOrProduction("fuel_station", "fuel_production", false);
	}
	
	/**
	 * method that return production per hour of iron station
	 * @return mixed
	 */
	public function getIronProduction()
	{
		return $this->getStorageCapacityOrProduction("iron_station", "iron_production", false);
	}
	
	/**
	 * method that return production per hour of water station
	 * @return mixed
	 */
	public function getWaterProduction()
	{
		return $this->getStorageCapacityOrProduction("water_station", "water_production", false);
	}
	
	/**
	 * methd that get the maximum capacity of a specific building (like warehouse of garner)
	 * @param string $building_array_name
	 * @param string $class_property (can be max_warehouse_storage or max_garner_storage)
	 * @param bool $is_storage
	 * @return mixed
	 */
	private function getStorageCapacityOrProduction(string $building_array_name, string $class_property, bool $is_storage = true)
	{
		if ($this->$class_property === null) {
			$level = 0;
			$building = $this->em->getRepository(\App\Entity\Building::class)->findOneBy([
				"base" => $this->base,
				"array_name" => $building_array_name
			]);
			
			if ($building) $level = $building->getLevel();
			
			$max_level = $this->globals->getBuildingsConfig()[$building_array_name]["max_level"];
			
			if ($is_storage === true) {
				$default_element = $this->globals->getBuildingsConfig()[$building_array_name]["default_storage"];
				$max_element = $this->globals->getBuildingsConfig()[$building_array_name]["max_storage"];
			} else {
				$default_element = $this->globals->getBuildingsConfig()[$building_array_name]["default_production"];
				$max_element = $this->globals->getBuildingsConfig()[$building_array_name]["max_production"];
			}
			
			if ($level === 0) {
				$this->$class_property = (int)$default_element;
			} else {
				$this->$class_property = (int)round(($max_element * $level) / $max_level);
			}
		}
		
		return $this->$class_property;
	}
}