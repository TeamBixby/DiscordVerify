<?php

declare(strict_types=1);

namespace TeamBixby\DiscordVerify\util;

use Exception;

class DiscordException extends Exception{

	public static function wrap(string $message) : DiscordException{
		return new DiscordException($message);
	}
}