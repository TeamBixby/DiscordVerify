<?php

declare(strict_types=1);

namespace TeamBixby\DiscordVerify;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\TextFormat;
use TeamBixby\DiscordVerify\command\VerifyCommand;
use TeamBixby\DiscordVerify\util\DiscordException;
use Volatile;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function strtolower;
use const PTHREADS_INHERIT_ALL;

final class DiscordVerify extends PluginBase{
	use SingletonTrait;

    public const PREFIX = "§l§8[§9Discord§fVerify§8]§r ";
    public const ACTION_VERIFIED = "verified";
    public const ACTION_UNVERIFIED = "unverified";
    public $discord_link;

    protected Volatile $inboundQueue;

    protected Volatile $outboundQueue;

	protected DiscordThread $thread;

	protected array $data = [];

	protected bool $canThreadClose = false;

	public function onLoad() : void{
		self::setInstance($this);
	}

	public function onEnable() : void{
		$this->saveDefaultConfig();
		$config = $this->getConfig();

		if($config->get("address", null) === null || $config->get("port", null) === null || $config->get("password", null) === null || $config->get("bindPort", null) === null){
			$this->getLogger()->emergency("Please fill the config correctly before use this plugin!");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		$this->discord_link = $config->get("discordLink", null) == null ? "" : " (" . $config->get("discordLink") . ")";

		$this->inboundQueue = new Volatile();
		$this->outboundQueue = new Volatile();

		try{
			$this->thread = new DiscordThread($this->getServer()->getLoader(), $config->get("address"), $config->get("port"), $config->get("bindPort"), $config->get("password"), $this->inboundQueue, $this->outboundQueue);
			$this->thread->start(PTHREADS_INHERIT_ALL);
			$this->canThreadClose = true;
		}catch(DiscordException $e){
			$this->getLogger()->emergency("Failed to run DiscordThread");
			$this->getLogger()->logException($e);
			$this->canThreadClose = false;
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		if(file_exists($file = $this->getDataFolder() . "verified_players.json")){
			$this->data = json_decode(file_get_contents($file), true);
		}

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(int $unused) : void{
			while($this->outboundQueue->count() > 0){
				$chunk = $this->outboundQueue->shift();
				$data = $chunk["data"];
				switch($chunk["action"]){
					case self::ACTION_VERIFIED:
						$player = $data["player"];
						$this->data[strtolower($player)] = $data["discordId"];
						if(($player = $this->getServer()->getPlayerExact($player)) !== null){
							$player->sendMessage(self::PREFIX.TextFormat::GREEN . "You've verified!");
						}
						break;
					case self::ACTION_UNVERIFIED:
						$player = $data["player"];
						if(isset($this->data[strtolower($player)])){
							unset($this->data[strtolower($player)]);
							if(($player = $this->getServer()->getPlayerExact($player)) !== null){
								$player->sendMessage(self::PREFIX.TextFormat::GREEN . "You've unverified!");
							}
						}
						break;
				}
			}
		}), 5);

		$this->getServer()->getCommandMap()->register("discordverify", new VerifyCommand());
	}

	public function onDisable() : void{
		if($this->canThreadClose){
			$this->thread->shutdown();
			while($this->thread->isRunning()){
				// SAFETY THREAD CLOSE
			}
		}
		file_put_contents($this->getDataFolder() . "verified_players.json", json_encode($this->data));
	}

	public function addQueue(array $data) : void{
		$this->inboundQueue[] = $data;
	}

	public function isVerified(string $player) : bool{
		return isset($this->data[strtolower($player)]);
	}
}