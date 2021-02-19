<?php

declare(strict_types=1);

namespace TeamBixby\DiscordVerify\command;

use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\Player;
use pocketmine\utils\TextFormat;
use pocketmine\utils\UUID;
use TeamBixby\DiscordVerify\DiscordVerify;
use function substr;

class VerifyCommand extends PluginCommand{

	public function __construct(){
		parent::__construct(DiscordVerify::getInstance()->translateString("command.name"), DiscordVerify::getInstance());
		$this->setDescription(DiscordVerify::getInstance()->translateString("command.description"));
		$this->setPermission("discordverify.command.use");
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(!$this->testPermission($sender)){
			return false;
		}
		if(!$sender instanceof Player){
			$sender->sendMessage(TextFormat::RED . "You can't use this command on console.");
			return false;
		}
		if(DiscordVerify::getInstance()->isVerified($sender->getName())){
			$sender->sendMessage(TextFormat::RED . DiscordVerify::getInstance()->translateString("command.generic.alreadyVerified"));
			return false;
		}
		DiscordVerify::getInstance()->addQueue([
			"player" => $sender->getName(),
			"random_token" => $token = substr(UUID::fromRandom()->toString(), 0, 6)
		]);
		$sender->sendMessage(TextFormat::GREEN . DiscordVerify::getInstance()->translateString("command.generic.useTokenAt", [
			"token" => $token
			]));
		return true;
	}
}