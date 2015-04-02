<?php

namespace Kasha\Core;

use Symfony\Component\HttpFoundation\Session\Session as SymfonySession;

class Session
{
	/**	@var SymfonySession */
	private static $instance = null;

	public static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new SymfonySession();
		}

		return self::$instance;
	}

	public static function set($name, $value)
	{
		self::getInstance()->set(str_replace('.', '/', $name), $value);
	}

	public static function get($name, $default = null)
	{
		return self::getInstance()->get(str_replace('.', '/', $name), $default);
	}
}
