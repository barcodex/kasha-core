<?php

namespace Kasha\Core;

/**
 * Universal cache based on file system.
 * All items are stored as files in cache folder - its location is set in the config.
 *
 * User: johnny
 * Date: 01.02.2013
 * Time: 10:43 AM
 */

class Cache
{
	/** @var Cache */
	private static $instance;

	/** @var string */
	public $rootPath = '';

	/** @var bool */
	private $isValid = false;

	/** @var string */
	protected $mode = 'filesystem'; //@TODO make this configurable and use it!

	/** @var array */
	protected $modelMetadata = array(); // each item stored in this array is also an array

	/** @var array */
	protected $models = array(); // each item stored in this array is also an array

	/** @var array */
	protected $templates = array(); // each item stored in this array is a string

	/** @var array */
	protected $dictionaries = array(); // each item stored in this array is also an array

	protected $settings = array();

	public function __construct()
	{
		$this->rootPath = Config::getInstance()->get('folders.cache');
		if (!file_exists($this->rootPath)) {
			@mkdir($this->rootPath, 0777);
		}
		if (file_exists($this->rootPath)) {
			$this->isValid = true;
		}
		$this->settings = Config::getInstance()->getEnvSetting('caching');

		return $this;
	}

	/**
	 * Static constructor of a singleton object
	 *
	 * @return Cache
	 */
	public static function createInstance()
	{
		self::$instance = new Cache();

		return self::$instance;
	}

	/**
	 * Cache class is a singleton - reuse the same instance of an object
	 *
	 * @return Cache|null
	 * @throws \Exception
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = self::createInstance();
		}

		return self::$instance;
	}

	/**
	 * Gets value from the cache by id
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public static function get($key)
	{
		$instance = self::$instance;
		if (!$instance->isValid) return false;

		$fileName = $instance->rootPath . $key . '.txt';

		return file_exists($fileName) ? file_get_contents($fileName) : false;
	}

	/**
	 * Sets value to the cache using the key
	 *
	 * @param string $key
	 * @param string $value
	 */
	public static function set(
		$key,
		$value
	) {
		$instance = self::$instance;
		if (!$instance->isValid) return;

		$fileName = $instance->rootPath . $key . '.txt';
		try {
			// check if all folders in the path exist for the $key
			$pathFolders = explode('/', $key);
			if (count($pathFolders) > 1) {
				$pureKeyName = array_pop($pathFolders); // do not create folder for the last element
				$path = $instance->rootPath;
				foreach ($pathFolders as $folderName) {
					$path .= ($folderName . '/');
					if (!file_exists($path)) {
						mkdir($path);
					}
				}
			}
			// safely write out the cache item
			file_put_contents($fileName, $value);
		} catch(\Exception $ex) {
			self::$instance->r->addProfilerMessage("Failed to store value for key='$key' to the cache");
		}
	}

	/**
	 * Deletes value from the cache using key
	 *
	 * @param string $key
	 */
	public static function delete($key)
	{
		$instance = self::$instance;
		if (!$instance->isValid) return;

		$fileName = $instance->rootPath . $key . '.txt';
		try {
			unlink($fileName);
		} catch(\Exception $ex) {
			$instance->r->addProfilerMessage("Failed to delete value for key='$key' from the cache");
		}
	}

	/**
	 * Checks if given key exists in the cache
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public static function hasKey($key)
	{
		$instance = self::$instance;
		if (!$instance->isValid) return false;

		$fileName = self::$instance->rootPath . $key . '.txt';

		return file_exists($fileName);
	}

	/**
	 * Enumerates all keys that have specific prefix
	 *
	 * @param string $prefix
	 *
	 * @return array
	 */
	public static function listKeysByPrefix($prefix = null)
	{
		$instance = self::$instance;
		if (!$instance->isValid) return array();

		return glob(self::$instance->rootPath . "$prefix*");
	}

	public static function getModelMetadata($modelName)
	{
		if (array_key_exists($modelName, self::$instance->modelMetadata)) {
			// we already have de-serialized version in cache (it means it was already used by the Runtime)
			$metadata = self::$instance->modelMetadata[$modelName];
		} else {
			// try to get serialized model from the cache
			$modelSerialized = (self::hasKey('metadata/' . $modelName)) ? self::get('metadata/' . $modelName) : false;
			$metadata = $modelSerialized ? json_decode($modelSerialized, true) : false;
			self::$instance->modelMetadata[$modelName] = $metadata;
		}

		return $metadata;
	}

	public static function setModelMetadata($modelName, $metadata)
	{
		if (lavnn('metadata', self::$instance->settings, false)) {
			self::$instance->modelMetadata[$modelName] = $metadata;
			self::set('metadata/' . $modelName, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
		}
	}

	public static function deleteModelMetadata($modelName)
	{
		if (isset(self::$instance->modelMetadata[$modelName])) {
			unset(self::$instance->modelMetadata[$modelName]);
		}
		// global cache might have the key even if dictionary cache does not -> delete it
		self::delete('metadata/' . $modelName);
	}

	public static function getTemplate($templateName)
	{
		if (array_key_exists($templateName, self::$instance->templates)) {
			// we already have read template into the cache (it means it was already used by the Runtime)
			$template = self::$instance->templates[$templateName];
		} else {
			// try to get serialized model from the cache
			$template = (self::hasKey('template/' . $templateName)) ? self::get('template/' . $templateName) : false;
			self::$instance->templates[$templateName] = $template;
		}

		return $template;
	}

	public static function setTemplate($templateName, $template)
	{
		if (lavnn('templates', self::$instance->settings, false)) {
			self::$instance->templates[$templateName] = $template;
			self::set('template/' . $templateName, $template);
		}
	}

	public static function deleteTemplate($templateName)
	{
		if (isset(self::$instance->templates[$templateName])) {
			unset(self::$instance->templates[$templateName]);
		}
		// global cache might have the key even if dictionary cache does not -> delete it
		self::delete('template/' . $templateName);
	}

	public static function getDictionary($dictionaryName)
	{
		$timeStarted = Profiler::microtimeFloat();
		if (array_key_exists($dictionaryName, self::$instance->dictionaries)) {
			// we already have de-serialized version in cache (it means it was already used by the Runtime)
			$dictionary = self::$instance->dictionaries[$dictionaryName];
			Runtime::getInstance()->addProfilerMessage('read dictionary ' . $dictionaryName. ' from memory', $timeStarted);
		} else {
			// try to get serialized model from the cache
			$dictionarySerialized = (self::hasKey('dictionary/' . $dictionaryName)) ? self::get('dictionary/' . $dictionaryName) : false;
			$dictionary = $dictionarySerialized ? json_decode($dictionarySerialized, true) : false;
			self::$instance->dictionaries[$dictionaryName] = $dictionary;
			Runtime::getInstance()->addProfilerMessage('read dictionary ' . $dictionaryName. ' from cache', $timeStarted);
		}

		return $dictionary;
	}

	public static function setDictionary($dictionaryName, $dictionary)
	{
		if (lavnn('dictionaries', self::$instance->settings, false)) {
			self::$instance->dictionaries[$dictionaryName] = $dictionary;
			self::set('dictionary/' . $dictionaryName, json_encode($dictionary, JSON_UNESCAPED_UNICODE + JSON_PRETTY_PRINT));
		}
	}

	public static function deleteDictionary($dictionaryName)
	{
		if (isset(self::$instance->dictionaries[$dictionaryName])) {
			unset(self::$instance->dictionaries[$dictionaryName]);
		}
		// global cache might have the key even if dictionary cache does not -> delete it
		self::delete('dictionary/' . $dictionaryName);
	}

	public static function deleteByPrefix($prefix)
	{
		return count(array_map("unlink", glob(self::$instance->rootPath."$prefix*")));
	}

	public static function invalidateEverything()
	{
		return array(
            'templates' => self::invalidateAllTemplates(),
            'dictionaries' => self::invalidateAllDictionaries(),
            'metadata' => self::invalidateAllMetadata(),
            'models' => self::invalidateAllModels(),
            'settings' => self::invalidateSettings()
        );
	}

	public static function invalidateAllTemplates($moduleName = '')
	{
		$prefix = 'template/' . ($moduleName != '' ? $moduleName.':' : '');
		return self::deleteByPrefix($prefix);
	}

	public static function invalidateAllDictionaries($moduleName = '')
	{
		$prefix = 'dictionary/' . ($moduleName != '' ? $moduleName.':' : '');
		return self::deleteByPrefix($prefix);
	}

	public static function invalidateAllMetadata()
	{
		self::delete('settings:modelMapping');
		$prefix = 'metadata/';
		return self::deleteByPrefix($prefix);
	}

	public static function invalidateAllModels()
	{
		$cnt = 0;
		foreach (glob(self::$instance->rootPath."models/*") as $modelFolder) {
			$cnt += count(array_map("unlink", glob("$modelFolder/*")));
			@rmdir($modelFolder);
		}
		return $cnt;
	}

	public static function invalidateModel($modelName)
	{
		return self::deleteByPrefix('models/' . $modelName . '/');
	}

	public static function invalidateSettings()
	{
		$prefix = 'settings:';
		return self::deleteByPrefix($prefix);
	}

	public static function getStats()
	{
		return array(
			'templates' => count(self::listKeysByPrefix('template/')),
			'dictionaries' => count(self::listKeysByPrefix('dictionary/')),
			'models' => count(self::listKeysByPrefix('metadata/'))
		);
	}

	/**
	 * @param $model Model
	 */
	public static function getModelItem($model, $id = null)
	{
		$instance = self::getInstance();
		$tableName = $model->getTableName();
		if (isset($instance->models[$tableName][$id])) {
			// we already have de-serialized version in cache (it means it was already used by the Runtime)
			$data = $instance->models[$tableName][$id];
		} else {
			$key = 'models/' . $tableName . '/' . ($id === null ? $model->getID() : $id);
			$modelDataJson = $instance->get($key);
			$data = $modelDataJson ? json_decode($modelDataJson, true) : array();
		}

		return $data;
	}

	/**
	 * @param $model Model
	 * @param $data array
	 */
	public static function setModelItem($model, $data)
	{
		if (lavnn('models', self::$instance->settings, false)) {
			$tableName = $model->getTableName();
			$id = $model->getID();
			// save the value in cache
			$key = 'models/' . $tableName . '/' . $id;
			self::$instance->set($key, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
			// also save in memory
			self::$instance->models[$tableName][$id] = $data;
		}
	}

	public static function deleteModelItem($tableName, $id)
	{
		$key = 'models/' . $tableName . '/' . $id;
		Cache::delete($key);
	}

	public static function getTemplates()
	{
		$output = array();

		foreach (self::listKeysByPrefix('template/') as $templateFile) {
			list($module, $name, $language) = explode(':', basename($templateFile), 3);
			$language = str_replace('.txt', '', $language);
			$output[] = array(
				'path' => $templateFile,
				'module' => $module,
				'name' => $name,
				'language' => $language
			);
		}

		return $output;
	}

	public static function getDictionaries()
	{
		$output = array();

		foreach (self::listKeysByPrefix('dictionary/') as $dictionaryFile) {
			list($module, $name, $language) = explode(':', basename($dictionaryFile), 3);
			$language = str_replace('.txt', '', $language);
			$output[] = array(
				'path' => $dictionaryFile,
				'module' => $module,
				'name' => $name,
				'language' => $language
			);
		}

		return $output;
	}

	public static function getMetadata()
	{
		$output = array();

		foreach (self::listKeysByPrefix('metadata/') as $metadataFile) {
			$name = str_replace('.txt', '', basename($metadataFile));
			$output[] = array(
				'path' => $metadataFile,
				'name' => $name
			);
		}

		return $output;
	}

	public static function getModels()
	{
		$output = array();
		$basePath = self::$instance->rootPath . 'models/';

		foreach (self::listKeysByPrefix('models/*/') as $modelFile) {
			list($model, $id) = explode('/', str_replace($basePath, '', $modelFile), 2);
			$id = str_replace('.txt', '', $id);
			$output[] = array(
				'path' => $modelFile,
				'name' => $model,
				'id' => $id
			);
		}

		return $output;
	}

}
