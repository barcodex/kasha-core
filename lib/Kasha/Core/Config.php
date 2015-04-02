<?php

namespace Kasha\Core;

use Temple\ArrayUtil;
use Temple\Processor;
use Temple\Util;

class Config
{
	/** @var Config */
	private static $instance = null;

	private $config = array();

	public static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new Config();
		}

		return self::$instance;
	}

	public function get($name, $default = null) {
		return \Temple\Util::lavnn($name, $this->config, $default);
	}

	public function set($name, $value) {
		return $this->config[$name] = $value;
	}

	/**
	 * Returns the whole envConfig section for currently active environment
	 *
	 * @return mixed
	 */
	public function getEnvConfig()
	{
		return Util::lavnn($this->config['ENV'], $this->config['envConfig'], array());
	}

	/**
	 * Returns config value for an environment-specific key. For any other key, use get() function
	 *
	 * @param $name
	 *
	 * @return mixed
	 */
	public function getEnvSetting($name)
	{
		return Processor::getTagValue($name, $this->getEnvConfig());
	}

	public function setPaths($paths)
	{
		foreach($paths as $key => $path) {
			$this->setFolderPath($key, $path);
		}
	}

	public function setFolderPath($key, $value)
	{
		$this->config['folders'][$key] = $value;
		// set related folder keys
		if ($key == 'app') {
			$this->config['folders']['imagesHidden'] = $value . 'images/';
			$this->config['folders']['cache'] = $value . 'cache/';
			$this->config['folders']['data'] = $value . 'data/';
		} elseif ($key == 'public') {
			$this->config['folders']['imagesPublic'] = $value . 'images/';
		}
	}

	/**
	 * Returns a path to a folder.
	 *
	 * This is actually a well-named shortcut for get('folders.'.$key)
	 *
	 * @param $key
	 *
	 * @return mixed
	 */
	public function getFolderPath($key)
	{
		return $this->config['folders'][$key];
	}

	public static function getDefaultCurrency()
	{
		$config = Config::getInstance();
		$currencyCode = Config::getInstance()->getEnvSetting('defaultCurrency');
		if ($currencyCode == '') {
			$currencyCode = $config->get('defaultCurrency');
		}
		if ($currencyCode == '') {
			$currencyCode = 'EUR';
		}

		return $currencyCode;
	}

	public static function getDefaultLanguage()
	{
		$config = Config::getInstance();
		// first, take a look at env-specific value
		$languageCode = Config::getInstance()->getEnvSetting('defaultLanguage');
		// if not found, use non-env-specific value
		if ($languageCode == '') {
			$languageCode = $config->get('defaultLanguage');
		}
		// if still not found, go with English
		if ($languageCode == '') {
			$languageCode = 'en';
		}

		return $languageCode;
	}

	// @TODO looking up file system should go to Locator class
	public function loadModules()
	{
		if ($modulesConfig = Cache::get('settings:modulesConfig')) {
			$this->config['modules'] = json_decode($modulesConfig, true);
		} else {
			// $config variable can be modified withing both shared and app modules.
			//  we store the configurations by module names, thus enabling overriding
			foreach (glob($this->config['folders']['shared'] . 'modules/*') as $module) {
				if (file_exists("$module/settings/config.php")) {
					$config = array();
					include "$module/settings/config.php";
					$this->config['modules'][basename($module)] = $config;
				}
			}
			// app modules are overwriting settings from shared modules
			foreach (glob($this->config['folders']['app'] . 'modules/*') as $module) {
				$moduleName = basename($module);
				if (file_exists("$module/settings/config.php")) {
					$config = Util::lavnn($moduleName, $this->config['modules'], array());
					include "$module/settings/config.php"; // app modules can override the settings
					$this->config['modules'][$moduleName] = $config;
				}
			}
			Cache::set('settings:modulesConfig', json_encode($this->config['modules'], JSON_UNESCAPED_UNICODE));
		}
	}


}
