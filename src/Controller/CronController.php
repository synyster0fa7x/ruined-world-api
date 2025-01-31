<?php

namespace App\Controller;

use App\Entity\Base;
use App\Entity\MessageBox;
use App\Entity\User;
use App\Entity\UserToken;
use App\Service\Barrack;
use App\Service\Building;
use App\Service\Food;
use App\Service\Globals;
use App\Service\Infirmary;
use App\Service\Market;
use App\Service\Mission;
use App\Service\Resources;
use App\Service\Unit;
use App\Service\UnitMovement;
use App\Service\Utils;
use Cron\CronExpression;
use DateTime;
use Exception;
use Swift_Mailer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Annotation\Route;

class CronController extends AbstractController
{
	/**
	 * @var Swift_Mailer
	 */
	private $mailer;

	/**
	 * @var Utils
	 */
	private $utils;

	/**
	 * @var SessionInterface
	 */
	private $session;

	/**
	 * @var Globals
	 */
	private $globals;

	/**
	 * @var Building
	 */
	private $building;

	/**
	 * @var Market
	 */
	private $market;

	/**
	 * @var Barrack
	 */
	private $barrack;

	/**
	 * @var Mission
	 */
	private $mission;

	/**
	 * @var Unit
	 */
	private $unit;

	/**
	 * @var UnitMovement
	 */
	private $unit_movement;

	/**
	 * @var Food
	 */
	private $food;

	/**
	 * @var Infirmary
	 */
	private $infirmary;

	private $crons;

	/**
	 * CronController constructor.
	 * @param Swift_Mailer $mailer
	 * @param Utils $utils
	 * @param SessionInterface $session
	 * @param Globals $globals
	 * @param Building $building
	 * @param Market $market
	 * @param Barrack $barrack
	 * @param Mission $mission
	 * @param Unit $unit
	 * @param UnitMovement $unitMovement
	 * @param Food $food
	 */
	public function __construct(Swift_Mailer $mailer, Utils $utils, SessionInterface $session, Globals $globals, Building $building, Market $market, Barrack $barrack, Mission $mission, Unit $unit, UnitMovement $unitMovement, Food $food, Infirmary $infirmary)
	{
		$this->mailer = $mailer;
		$this->utils = $utils;
		$this->session = $session;
		$this->globals = $globals;
		$this->building = $building;
		$this->market = $market;
		$this->barrack = $barrack;
		$this->mission = $mission;
		$this->unit = $unit;
		$this->unit_movement = $unitMovement;
		$this->food = $food;
		$this->infirmary = $infirmary;
	}

	/**
	 * @Route("/cron", name="cron")
	 * @param Request $request
	 * @return Response
	 * @throws Exception
	 */
	public function cron(Request $request)
	{
		$ip = $request->server->get('REMOTE_ADDR');
		$allowed_ip_external = explode(", ", $_ENV["IP_CRON_EXTERNAL"]);

		if (in_array($ip, $allowed_ip_external) || $ip === $_ENV["IP_CRON_INTERNAL"]) {
			$this->crons = $this->getParameter("cron");
			$json_exec = $this->getCronFile();
			$now = new DateTime();

			// start executing crons
			foreach ($this->crons as $key => $cron) {
				if (!array_key_exists($key, $json_exec)) {
					$this->addJsonEntry($key);
					$json_exec = $this->getCronFile();
				}

				$next_exec = $json_exec[$key]["next_execution"];
				if (method_exists($this, $key)) {
					if ($next_exec === null || in_array($ip, $allowed_ip_external)) {
						$this->$key();
					} else if ($now >= DateTime::createFromFormat("Y-m-d H:i:s", $next_exec)) {
						$this->$key();
					}

					$cron = CronExpression::factory($this->getParameter("cron")[$key]);
					$this->editJsonEntry($key, $cron->getNextRunDate()->format('Y-m-d H:i:s'));
				}
			}
		} else {
			throw new AccessDeniedHttpException("You haven't got access to this page");
		}

		return new Response();
	}

	/**
	 * return the json file with all crons in it. If not exist, we create it add put cron like this :
	 * key => nameOfMethodToExecute
	 * [last_execution = null]
	 * @return mixed|string
	 */
	private function getCronFile()
	{
		$file = $this->getParameter("data_directory") . "cron/cron.json";

		if (!is_file($file)) {
			$this->utils->createRecursiveDirFromRoot('data/cron');
			$fs = new Filesystem();
			$fs->touch($this->getParameter("data_directory") . "cron/cron.json");

			$crons = [];

			foreach ($this->crons as $key => $cron) {
				$crons[$key] = [
					"next_execution" => null,
				];
			}

			$fs->appendToFile($file, json_encode($crons));
		}

		$file = json_decode(file_get_contents($file), true);

		return $file;
	}

	/**
	 * method that add new entry in config cron file
	 * @param string $entry
	 */
	private function addJsonEntry(string $entry)
	{
		$file = $this->getParameter("data_directory") . "cron/cron.json";
		$crons = json_decode(file_get_contents($file), true);

		$crons[$entry] = [
			"next_execution" => null,
		];

		$this->writeJsonCron($crons);
	}

	/**
	 * method to edit an entry in json
	 * @param string $entry
	 * @param string $next_execution
	 */
	private function editJsonEntry(string $entry, string $next_execution)
	{
		$json = $this->getCronFile();

		if (array_key_exists($entry, $json)) {
			$json[$entry]["next_execution"] = $next_execution;

			$this->writeJsonCron($json);
		}
	}

	/**
	 * method that writes the cron.json when we add or edit an entry
	 * @param array $json
	 */
	private function writeJsonCron(array $json)
	{
		$fs = new Filesystem();
		$file = $this->getParameter("data_directory") . "cron/cron.json";

		$fs->dumpFile($file, json_encode($json));
	}


	// --------------------------------------- UNDER THIS, METHODS OF CRONS ----------------------------------------------------//

	/**
	 * method that update resources of a base based on resources produced by hour. This method is called every minute
	 * @throws Exception
	 */
	private function updateResources()
	{
		$em = $this->getDoctrine()->getManager();

		$bases = $em->getRepository(Base::class)->findByBaseUserNotHolidays();

		foreach ($bases as $base) {
			$this->session->set("current_base", $base);
			$this->session->set("token", $base->getUser()->getToken());

			$this->food->consumeFood();

			$resources = new Resources($em, $this->session, $this->globals);

			$now = new DateTime();
			$last_update_resources = $base->getLastUpdateResources();
			$diff = $now->getTimestamp() - $last_update_resources->getTimestamp();

			$new_elec = round(($resources->getElectricityProduction() / 3600) * $diff);
			$new_fuel = round(($resources->getFuelProduction() / 3600) * $diff);
			$new_iron = round(($resources->getIronProduction() / 3600) * $diff);
			$new_water = round(($resources->getWaterProduction() / 3600) * $diff);

			if ($new_elec > 0 || $new_fuel > 0 || $new_iron > 0 || $new_water > 0) {
				$resources->addResource("electricity", $new_elec);
				$resources->addResource("fuel", $new_fuel);
				$resources->addResource("iron", $new_iron);
				$resources->addResource("water", $new_water);

				$base->setLastUpdateResources($now);
				$em->flush();
			}

			$this->session->remove("current_base");
			$this->session->remove("token");
		}
	}

	/**
	 * method to send mail to user before their account archiving
	 * @throws Exception
	 */
	private function sendMailBeforeArchiveUser()
	{
		$em = $this->getDoctrine()->getManager();
		$users = $em->getRepository(User::class)->findByUserToArchive($this->getParameter("max_inactivation_days") - 3);

		/**
		 * @var $user User
		 */
		foreach ($users as $user) {
			$desactivation_date = $user->getLastConnection()->add(new \DateInterval("P" . $this->getParameter("max_inactivation_days") . "D"))->format("d/m/Y H:i:s");
			$message = (new \Swift_Message('Ruined World : Ta base tombe en ruine dans 3 jours'))
				->setSender("no-reply-ruined-world@anthony-pilloud.fr")
				->setFrom("no-reply-ruined-world@anthony-pilloud.fr")
				->setTo($user->getMail())
				->setBody(
					$this->renderView('before_archive_account.html.twig', ["desactivation_date" => $desactivation_date]),
					'text/html'
				);
			$this->mailer->send($message);
		}
	}

	/**
	 * method to archive a user that hasn't connected to the game for a certain time
	 * this will archive all his bases too
	 * @throws Exception
	 */
	private function archiveUsers()
	{
		$em = $this->getDoctrine()->getManager();
		$users = $em->getRepository(User::class)->findByUserToArchive($this->getParameter("max_inactivation_days"));

		/**
		 * @var $user User
		 */
		foreach ($users as $user) {
			$user->setArchived(true);
			$user->setHolidays(false);
			$bases = $user->getBases();

			foreach ($user->getSentMessages() as $message) {
				$messages_box = $em->getRepository(MessageBox::class)->findBy(["message" => $message]);

				/** @var MessageBox $message_box */
				foreach ($messages_box as $message_box) {
					$message_box->setArchivedSent(true);
					$em->persist($message_box);
				}
			}

			/** @var MessageBox $message */
			foreach ($user->getMessagesBox() as $message) {
				$message->setArchived(true);
				$em->persist($message);
			}

			foreach ($user->getTokens() as $token) {
				$user->removeToken($token);
				$em->persist($user);
				$em->remove($token);
			}

			/**
			 * @var $base Base
			 */
			foreach ($bases as $base) {
				$base->setArchived(true);
				$em->persist($base);
			}

			$em->persist($user);
		}

		$message = (new \Swift_Message('Rapport du cron des comptes à archiver'))
			->setFrom("no-reply-ruined-world@anthony-pilloud.fr")
			->setTo("pilloud.anthony@gmail.com")
			->setBody(
				$this->renderView('archived_account.html.twig', ["users" => $users]),
				'text/html'
			);
		$this->mailer->send($message);

		$em->flush();
	}

	/**
	 * method to disable holidays mode of user and set last connection date to today
	 * @throws Exception
	 */
	private function disableHolidaysMode()
	{
		$em = $this->getDoctrine()->getManager();
		$users = $em->getRepository(User::class)->findByUserEndHolidays($this->getParameter("max_holidays_days"));

		/**
		 * @var $user User
		 */
		foreach ($users as $user) {
			$user->setHolidays(false);
			$user->setLastConnection(new DateTime());
			$em->persist($user);
		}

		$em->flush();
	}

	/**
	 * method to finish all construction that end date was before current date
	 */
	private function endConstructions()
	{
		$em = $this->getDoctrine()->getManager();
		$bases = $em->getRepository(Base::class)->findBy(["archived" => false]);

		foreach ($bases as $base) {
			$this->session->set("current_base", $base);
			$this->session->set("token", $base->getUser()->getToken());

			$this->building->endConstructionBuildingsInBase();
		}
	}

	/**
	 * method that update market movements of each base
	 * @throws Exception
	 */
	private function updateMarketMovement()
	{
		$em = $this->getDoctrine()->getManager();
		$bases = $em->getRepository(Base::class)->findBy(["archived" => false]);

		/** @var Base $base */
		foreach ($bases as $base) {
			$this->session->set("current_base", $base);
			$this->session->set("token", $base->getUser()->getToken());

			$this->market->updateMarketMovement($base);
		}
	}

	/**
	 * method to finish all construction that end date was before current date
	 */
	private function endRecruitmentUnits()
	{
		$em = $this->getDoctrine()->getManager();
		$bases = $em->getRepository(Base::class)->findBy(["archived" => false]);

		foreach ($bases as $base) {
			$this->session->set("current_base", $base);
			$this->session->set("token", $base->getUser()->getToken());

			$this->barrack->endRecruitmentUnitsInBase();
		}
	}

	/**
	 * method to update missions of the base
	 */
	private function updateMissionsForBase()
	{
		$em = $this->getDoctrine()->getManager();
		$bases = $em->getRepository(Base::class)->findBy(["archived" => false]);

		/** @var Base $base */
		foreach ($bases as $base) {
			$this->session->set("current_base", $base);
			$this->session->set("token", $base->getUser()->getToken());

			$this->mission->setAleatoryMissionsForBase();
		}
	}

	/**
	 * method that update market movements of each base
	 * @throws Exception
	 */
	private function updateUnitMovement()
	{
		$em = $this->getDoctrine()->getManager();
		$bases = $em->getRepository(Base::class)->findBy(["archived" => false]);

		/** @var Base $base */
		foreach ($bases as $base) {
			$this->session->set("current_base", $base);
			$this->session->set("token", $base->getUser()->getToken());

			$this->unit_movement->updateUnitMovement($base);
		}
	}

	/**
	 * method to finish all treatments that end date was before current date
	 */
	private function endTreatmentUnits()
	{
		$em = $this->getDoctrine()->getManager();
		$bases = $em->getRepository(Base::class)->findBy(["archived" => false]);

		foreach ($bases as $base) {
			$this->session->set("current_base", $base);
			$this->session->set("token", $base->getUser()->getToken());

			$this->infirmary->endTreatmentUnitsInBase();
		}
	}

	/**
	 * method to remove unused token
	 * @throws Exception
	 */
	private function removeUnusedTokens()
	{
		$em = $this->getDoctrine()->getManager();
		$user_tokens = $em->getRepository(UserToken::class)->findByExpiredToken($this->getParameter("max_inactivation_days"));

		foreach ($user_tokens as $user_token) {
			$em->remove($user_token);
		}
	}

	/**
	 * method to disable all finished premium advantages
	 * @throws Exception
	 */
	private function disableFinishedPremiumAdvantages()
	{
		$em = $this->getDoctrine()->getManager();
		$users = $em->getRepository(User::class)->findAll();

		/** @var User $user */
		foreach ($users as $user) {
			if ($user->isPremiumFavoriteDestinationFinished()) {
				$user->removePremiumFavoriteDestination();
			}
			if ($user->isPremiumFullStorageFinished()) {
				$user->removePremiumFullStorage();
			}
			if ($user->isPremiumUpgradeBuildingFinished()) {
				$user->removePremiumUpgradeBuilding();
			}
			if ($user->isPremiumWaitingLineFinished()) {
				$user->removePremiumWaitingLine();
			}

			$em->persist($user);
		}

		$em->flush();
	}

	/**
	 * method to archive message after a given time
	 * @throws Exception
	 */
	private function archiveMessages()
	{
		$em = $this->getDoctrine()->getManager();
		$max_keep_messages = $this->getParameter("max_keep_messages");
		$date = new DateTime();
		$date->sub(new \DateInterval("P".$max_keep_messages."D"));
		$messages_box = $em->getRepository(MessageBox::class)->findByMessagesToArchive($date);

		/** @var MessageBox $message_box */
		foreach ($messages_box as $message_box) {
			$message_box->setArchived(true);
			$message_box->setArchivedSent(true);
			$em->persist($message_box);
		}

		$em->flush();
	}

	/**
	 * method to delete archived message (sent and received)
	 * @throws Exception
	 */
	private function deleteArchivedMessages()
	{
		$em = $this->getDoctrine()->getManager();
		$max_keep_messages = $this->getParameter("max_keep_messages")*2;
		$date = new DateTime();
		$date->sub(new \DateInterval("P".$max_keep_messages."D"));
		$messages_box = $em->getRepository(MessageBox::class)->findByArchivedMessages($date);

		/** @var MessageBox $message_box */
		foreach ($messages_box as $message_box) {
			$em->remove($message_box->getMessage());
			$em->remove($message_box);
		}

		$em->flush();
	}
}