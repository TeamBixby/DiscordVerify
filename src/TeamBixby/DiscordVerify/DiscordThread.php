<?php

declare(strict_types=1);

namespace TeamBixby\DiscordVerify;

use ClassLoader;
use pocketmine\utils\MainLogger;
use TeamBixby\DiscordVerify\util\DiscordException;
use Thread;
use Throwable;
use Volatile;
use function get_class;
use function gettype;
use function is_array;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_last_error;
use function socket_recvfrom;
use function socket_sendto;
use function socket_set_nonblock;
use function socket_strerror;
use function trim;
use const PHP_INT_MAX;
use const SOCKET_EWOULDBLOCK;

class DiscordThread extends Thread{

	public const KEY_ACTION = "action";

	public const KEY_DATA = "data";

	protected ClassLoader $classLoader;

	protected string $targetHost;

	protected int $targetPort;

	protected int $bindPort;

	protected string $password;

	protected Volatile $inboundQueue;

	protected Volatile $outboundQueue;

	protected bool $shutdown = true;

	public function __construct(ClassLoader $loader, string $targetHost, int $targetPort, int $bindPort, string $password, Volatile $inboundQueue, Volatile $outboundQueue){
		$this->classLoader = $loader;
		$this->targetHost = $targetHost;
		$this->targetPort = $targetPort;
		$this->bindPort = $bindPort;
		$this->password = $password;
		$this->inboundQueue = $inboundQueue;
		$this->outboundQueue = $outboundQueue;
	}

	public function run() : void{
		$this->classLoader->register();

		try{
			$this->shutdown = false;

			$sendSock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			$recvSock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

			socket_set_nonblock($recvSock);

			if($sendSock === false || $recvSock === false){
				throw DiscordException::wrap("Failed to create socket");
			}

			if(@socket_bind($recvSock, "0.0.0.0", $this->bindPort) === false){
				throw DiscordException::wrap("Failed to bind port");
			}

			socket_set_nonblock($recvSock);

			while(!$this->shutdown){
				$this->receiveData($recvSock);
				$this->sendData($sendSock);
			}
			socket_close($recvSock);
			socket_close($sendSock);
		}catch(Throwable $e){
			MainLogger::getLogger()->logException($e);
		}
	}

	/**
	 * @param resource $recvSock
	 *
	 * @throws DiscordException
	 */
	private function receiveData($recvSock) : void{
		$buffer = "";
		if(@socket_recvfrom($recvSock, $buffer, 65535, 0, $source, $port) === false){
			$errno = socket_last_error($recvSock);
			if($errno === SOCKET_EWOULDBLOCK){
				return;
			}
			throw new DiscordException("Failed to recv (errno $errno): " . trim(socket_strerror($errno)), $errno);
			//return;
		}
		if($buffer !== null && $buffer !== ""){
			$data = json_decode($buffer, true);
			if(!is_array($data)){
				throw DiscordException::wrap("Expected array, got " . (is_object($data) ? get_class($data) : gettype($data)));
			}
			$password = $data["password"] ?? null;
			if(!is_string($password)){
				MainLogger::getLogger()->notice("Bad socket received from $source:$port");
				MainLogger::getLogger()->notice("Password does not exist on response body");
				return;
			}
			if($password !== $this->password){
				MainLogger::getLogger()->notice("Bad socket received from $source:$port");
				MainLogger::getLogger()->notice("Password does not match");
				return;
			}
			$action = $data["action"];
			$actionData = $data["data"];

			$this->outboundQueue[] = [
				self::KEY_ACTION => $action,
				self::KEY_DATA => $actionData
			];
		}
	}

	/**
	 * @param resource $sendSock
	 *
	 * @throws DiscordException
	 */
	private function sendData($sendSock) : void{
		while($this->inboundQueue->count() > 0){
			$chunk = $this->inboundQueue->shift();
			$data = [
				"data" => $chunk,
				"password" => $this->password
			];
			if(@socket_sendto($sendSock, json_encode($data), PHP_INT_MAX, 0, $this->targetHost, $this->targetPort) === false){
				$errno = socket_last_error($sendSock);
				if($errno === SOCKET_EWOULDBLOCK){
					return;
				}
				throw DiscordException::wrap("Failed to send socket: " . trim(socket_strerror($errno)));
			}
		}
	}

	public function shutdown() : void{
		$this->shutdown = true;
	}
}