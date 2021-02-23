<?php

declare(strict_types=1);

namespace Tempetas\Outposts;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase {

	public function onLoad() {
		$this->saveDefaultConfig();
		Entity::registerEntity(Outpost::class, true);
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if ('outpost' == $command->getName()) {
			if (!$sender instanceof Player) {
				$sender->sendMessage('§cPlease, run this command in-game!');

				return false;
			}
			$outpost = new Outpost($sender->getLevel(), Entity::createBaseNBT($sender->getPosition()->floor()->add(new Vector3(0.5, 0, 0.5)), null, 0));
			$outpost->spawnToAll();
			$sender->sendMessage('§aOutpost created successfully!');
		}

		if ('routpost' == $command->getName()) {
			if (!$sender instanceof Player) {
				$sender->sendMessage('§cPlease, run this command in-game!');

				return false;
			}
			foreach ($this->getServer()->getLevels() as $level) {
				foreach ($level->getEntities() as $entity) {
					if ($entity instanceof Outpost) {
						//Remove all outposts in a 3 block radius around the player
						if ($sender->distanceSquared($entity) < 9) {
							$entity->flagForDespawn(true);
						}
					}
				}
			}
			$sender->sendMessage('§aOutpost removed successfully!');
		}

		return true;
	}
}
