<?php

namespace Tempetas\Outposts;

use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\level\Level;
use pocketmine\level\particle\DustParticle as ColoredParticle;
use pocketmine\level\particle\GenericParticle;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;

class Outpost extends Entity {

	public const NETWORK_ID = self::ENDERMAN;

	public $width = 0.1;
	public $height = 0.1;
	public $canCollide = false;

	public $captureProgress = 0;
	public $faction;
	public $factionCapturing;
	private $players = [];

	private $rewardProgress;
	private $rewardInterval;
	private $rewardAmount;

	private $factionsPro;
	private $economyApi;

	private $messages;
	private $fullCapture;

	private $radius = 5;

	public function __construct(Level $level, CompoundTag $nbt) {
		parent::__construct($level, $nbt);
		$this->setGenericFlag(self::DATA_FLAG_AFFECTED_BY_GRAVITY, false);
		$this->setScale(0.0001);
		$this->setNameTagAlwaysVisible(true);
		$this->setNameTagVisible(true);
		if (!is_null($this->namedtag->getTag('faction'))) {
			$this->faction = $this->namedtag->getTag('faction')->getValue();
			$this->factionCapturing = $this->namedtag->getTag('factionCapturing')->getValue();
			$this->captureProgress = $this->namedtag->getTag('captureProgress')->getValue();
			$this->rewardProgress = $this->namedtag->getTag('rewardProgress')->getValue();
		}
		$cfg = $this->server->getPluginManager()->getPlugin('Outposts')->getConfig();
		$this->fullCapture = $cfg->get('minutes') * 60;
		$this->rewardInterval = $cfg->get('reward-interval') * 60;
		$this->rewardAmount = $cfg->get('reward-amount');
		$this->messages = $cfg->get('messages');
		$this->factionsPro = $this->server->getPluginManager()->getPlugin('FactionsPro');
		$this->economyApi = $this->server->getPluginManager()->getPlugin('EconomyAPI');
	}

	public function saveNBT(): void {
		parent::saveNBT();
		$this->namedtag->setString('faction', (string)$this->faction);
		$this->namedtag->setString('factionCapturing', (string)$this->factionCapturing);
		$this->namedtag->setInt('captureProgress', (int)$this->captureProgress);
		$this->namedtag->setInt('rewardProgress', (int)$this->rewardProgress);
	}

	public function entityBaseTick(int $tickDiff = 1): bool {
		//Cancel any motion
		$this->motion->setComponents(0, 0, 0);
		//Particle effects
		$this->particleSphere();
		$this->particleRay();
		//Run once a second
		if ($this->server->getTick() % 20 == 0) {
			$players = $this->server->getOnlinePlayers();
			//Get all players in the outpost
			$playersFound = [];
			foreach ($players as $player) {
				if ($this->getPosition()->distanceSquared($player->getPosition()) < pow($this->radius, 2)) {
					//Reset size and disable flight
					if ($player->getScale() != 1) {
						$player->setScale(1);
					}
					$player->setFlying(false);
					$name = $player->getName();
					$playersFound[] = $name;
				}
			}
			$this->players = $playersFound;
			$this->capture();
		}

		return parent::entityBaseTick($tickDiff);
	}

	//Couldn`t think of a better function name-
	private function capture() {
		if (is_null($this->factionCapturing)) {
			$this->setNameTag($this->formatString($this->messages['captured']));
		} else {
			$this->setNameTag($this->formatString($this->messages['capturing']));
		}
		if (!is_null($this->faction)) {
			$this->rewardProgress++;
		}
		if ($this->rewardProgress == $this->rewardInterval) {
			$this->factionsPro->addFactionMoney($this->faction, $this->rewardAmount);
			$this->rewardProgress = 0;
			$this->announce($this->formatString($this->messages['reward-msg']));
		}
		foreach ($this->players as $player) {
			if ($this->factionsPro->isInFaction($player)) {
				$fac = $this->factionsPro->getPlayerFaction($player);
				if (!is_null($this->factionCapturing)) {
					$check = $this->checkFactionsInside();
					if ($this->factionCapturing != $fac) {
						if (0 == $check) {
							--$this->captureProgress;
						}
						if (0 > $this->captureProgress) {
							$this->factionCapturing = null;
							$this->captureProgress = 0;
							$this->setNameTag($this->formatString($this->messages['captured']));
						}
						$this->setNameTag($this->formatString($this->messages['capturing']));

						return;
					}
					if (floor($this->fullCapture / 2) == $this->captureProgress || floor($this->fullCapture / 4) == $this->captureProgress) {
						$this->announce($this->formatString($this->messages['half-captured-msg']));
					}
					if ($this->fullCapture == $this->captureProgress) {
						$this->faction = $this->factionCapturing;
						$this->factionCapturing = null;
						$this->captureProgress = 0;
						$this->rewardProgress = 0;
						$this->announce($this->formatString($this->messages['captured-msg']));
						$this->setNameTag($this->formatString($this->messages['captured']));
					} else {
						if (1 != $check) {
							++$this->captureProgress;
							$this->setNameTag($this->formatString($this->messages['capturing']));
						}
					}
				} elseif ($fac != $this->faction) {
					$this->factionCapturing = $fac;
				}
			}
		}
	}

	private function formatString(string $str) {
		return strtr($str, ['{x}' => floor($this->x), '{z}' => floor($this->z), '{faction}' => $this->faction, '{factionCapturing}' => $this->factionCapturing, '{progress}' => floor(($this->captureProgress * 100) / $this->fullCapture)]);
	}

	//Neither could I think of a good one here-

	private function checkFactionsInside() {
		$capturers = 0;
		$others = 0;
		foreach ($this->players as $player) {
			if ($this->factionsPro->isInFaction($player)) {
				$fac = $this->factionsPro->getPlayerFaction($player);
				if ($fac == $this->factionCapturing) {
					++$capturers;
				} else {
					++$others;
				}
			}
		}
		if (0 == $capturers && 0 != $others) {
			return 0;
		} elseif (0 != $others) {
			return 1;
		}
	}

	private function particleSphere() {
		$vec = self::getRandomVector()->multiply($this->radius);
		//Magenta colored particles
		$this->level->addParticle(new ColoredParticle($this->add($vec->x, $vec->y, $vec->z), 157, 3, 252));
	}

	private static function getRandomVector(): Vector3 {
		$vec = new Vector3(0, 0, 0);
		$vec->x = rand() / getrandmax() * 2 - 1;
		$vec->y = rand() / getrandmax() * 2 - 1;
		$vec->z = rand() / getrandmax() * 2 - 1;

		return $vec->normalize();
	}

	private function particleRay() {
		//White particle ray above the outpost to show its center
		for ($y = $this->y; $y < $this->y + 5; $y += 0.5) {
			$this->level->addParticle(new GenericParticle(new Vector3($this->x, $y + 2, $this->z), 51));
		}
	}

	public function flagForDespawn($forced = false): void {
		if ($forced) {
			$this->needsDespawn = true;
			$this->scheduleUpdate();
			$this->close();
		}
	}

	private function announce(string $msg) {
		$players = $this->server->getOnlinePlayers();
		foreach ($players as $player) {
			$player->sendMessage($msg);
		}
	}

	public function attack(EntityDamageEvent $source): void {
		return;
	}
}
