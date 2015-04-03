<?php

namespace Kasha\Core;

class HttpResponse
{
	public static function dynamic401($params = array()) {
		header($_SERVER["SERVER_PROTOCOL"]." 401 Unauthorized");
		print " 401 Unauthorized";
		die();
	}

	public static function dynamic403($params = array()) {
		header($_SERVER["SERVER_PROTOCOL"]." 403 Forbidden");
		print " 403 Forbidden";
		die();
	}

	public static function dynamic404($params = array()) {
		header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
		print " 404 Not Found";
		die();
	}
}

