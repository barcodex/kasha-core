<?php

namespace Kasha\Core;

use Temple\Util;
use Kasha\Templar\Locator;
use Kasha\Templar\TextProcessor;

class Runtime
{
	/** @var array */
	private $context = array();

	/**
	 * Set to true to force no errors, warnings or other framework-specific output
	 * @var bool
	 */
	private $muted = false;

	private $warnings = array();

	/**
	 * @param boolean $muted
	 */
	public function setMuted($muted)
	{
		$this->muted = $muted;
	}

	// variables to hold current action context
	public $moduleName;
	public $actionName;
	public $isSharedModule = false;

	/** @var null Runtime */
	private static $instance = null;

	private $allCurrencies = array();
	private $allSiteCurrencies = array();

	private $allLanguages = array();
	private $allSiteLanguages = array();
	private $translatableLanguages = array();

	public function __construct()
	{
		$this->checkMaintenance();

		return $this;
	}

	/**
	 * Static constructor of a singleton object
	 *
	 * @return Runtime
	 */
	public static function createInstance()
	{
		self::$instance = new Runtime();

		return self::$instance;
	}

	/**
	 * Runtime class is a singleton - reuse the same instance of an object
	 *
	 * @return Runtime|null
	 * @throws \Exception
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = self::createInstance();
		}

		return self::$instance;
	}

	public function getAllCurrencies()
	{
		if (count($this->allCurrencies) == 0) {
			$this->allCurrencies = Model::getInstance('currency')->getList();
		}

		return $this->allCurrencies;
	}

	public function getAllSiteCurrencies()
	{
		if (count($this->allSiteCurrencies) == 0) {
			$this->allSiteCurrencies = Model::getInstance('currency')->getList(array('is_enabled' => 1));
		}

		return $this->allSiteCurrencies;
	}

	public function getAllLanguages()
	{
		if (count($this->allLanguages) == 0) {
			$this->allLanguages = Model::getInstance('human_language')->getList();
		}

		return $this->allLanguages;
	}

	public function getAllSiteLanguages()
	{
		if (count($this->allSiteLanguages) == 0) {
			$this->allSiteLanguages = Model::getInstance('human_language')->getList(array('is_enabled' => 1));
		}

		return $this->allSiteLanguages;
	}

	public function getTranslatableLanguages()
	{
		if (count($this->translatableLanguages) == 0) {
			$query = file_get_contents(__DIR__ . "/sql/ListTranslatableLanguages.sql");
			$this->translatableLanguages = Database::getInstance()->getArray($query);
		}

		return $this->translatableLanguages;
	}

	/**
	 * @param $scope - 'all', 'translatable' or 'site'
	 *
	 * @return array
	 */
	public function getLanguages($scope)
	{
		switch($scope) {
			case 'all':
				return $this->getAllLanguages();
				break;
			case 'translatable':
				return $this->getTranslatableLanguages();
				break;
			case 'site':
			default:
				return $this->getAllSiteLanguages();
				break;
		}
	}

	public function getCurrencies($scope)
	{
		switch($scope) {
			case 'all':
				return $this->getAllCurrencies();
				break;
			case 'site':
			default:
				return $this->getAllSiteCurrencies();
				break;
		}
	}

	public function getLanguagesMap($excludeCodes = array())
	{
		$languagesMap = array();
		foreach($this->getAllSiteLanguages() as $languageInfo) {
			if (!in_array($languageInfo['code'], $excludeCodes)) {
				$languagesMap[$languageInfo['code']] = $languageInfo;
			}
		}

		return $languagesMap;
	}

	public function getCurrenciesMap($excludeCodes = array())
	{
		$currenciesMap = array();
		foreach($this->getAllSiteCurrencies() as $currencyInfo) {
			if (!in_array($currencyInfo['code'], $excludeCodes)) {
				$currenciesMap[$currencyInfo['code']] = $currencyInfo;
			}
		}

		return $currenciesMap;
	}

	public function hasContextItem($key)
	{
		return array_key_exists($key, $this->context);
	}

	public function getContextItem($key, $default = '')
	{
		return Util::lavnn($key, $this->context, $default);
	}

	public function setContextItem($key, $value)
	{
		$this->context[$key] = $value;
	}

	/**
	 * Checks if site is closed for maintenance and reports corresponding error
	 */
	public function checkMaintenance()
	{
		if (Config::getInstance()->get('CLOSED') == 1) {
			$this->fatalError('CLOSED_FOR_MAINTENANCE');
			exit();
		}
	}

	public function isMultilingual()
	{
		return count($this->getAllSiteLanguages()) > 1;
	}

	public function addProfilerMessage($text, $activityStarted = null)
	{
		Profiler::getInstance()->addMessage($text, $activityStarted);
	}

	/**
	 * Redirects to another url of the local server.
	 *  Takes mod_rewrite rules into consideration.
	 *  If we expect action to be routed by our app, then $action should be formed "type/module/name", e.g. "p/main/home"
	 *  $params are just added to action name, mod_rewrite will make a well-formed url out of it
	 *
	 * @param $localUrl
	 * @param array $params
	 * @param bool $secure
	 */
	public static function redirect($localUrl, $params = array(), $secure = false)
	{
		$url = self::getBaseUrl() . '/' . self::getUrlLanguagePrefix()  . $localUrl;
		if (count($params) > 0) {
			$url .= ('?'  .http_build_query($params));
		}
		header("Location: $url");
		exit();
	}

	/**
	 * Get the base url, consisting from the server name, optional port number, and the the protocol
	 *
	 * @return string
	 */
	public static function getBaseUrl()
	{
        $executionContext = Runtime::getInstance()->getContextItem('executionContext', 'cli');
        if ($executionContext != 'cli') {
            $protocol = 'http';
            if ($_SERVER['SERVER_PORT'] == 443 || (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on')) {
                $protocol .= 's';
                $protocol_port = $_SERVER['SERVER_PORT'];
            } else {
                $protocol_port = 80;
            }

            $port = $_SERVER['SERVER_PORT'];
            $host = str_replace(':'.$port, '', $_SERVER['HTTP_HOST']);

            $baseUrl = $protocol . '://' . $host . ($port == $protocol_port ? '' : ':' . $port);
        } else {
			// Since CLI does not know server contest, let's get it from the config
            $protocol = Config::getInstance()->getEnvSetting('serverProtocol');
            $host = Config::getInstance()->getEnvSetting('serverName');
            $baseUrl = "$protocol://$host";
        }

        return $baseUrl;
	}

	public static function getUrlLanguagePrefix()
	{
		$langCode = Util::lavnn('code', $_SESSION['language'], Config::getDefaultLanguage());

        return $langCode . '/'; // always return a prefix even if site is monoglot
	}

	/**
	 * Prepare language-independent URL from $_SERVER['REQUEST_URI']
	 *
	 * @param $defaultUrl
	 *
	 * @return string
	 */
	public static function getNextUrl($defaultUrl = '')
	{
		$nextUrl = $defaultUrl;
		if (isset($_REQUEST['p'])) {
			$currentUrl = $_SERVER['REQUEST_URI'];
			$languagePrefix = self::getUrlLanguagePrefix();
			// $_SERVER['REQUEST_URI'] starts with /, but our prefix does not have it => count from 1st symbol, not 0th
			if ($languagePrefix != '' && substr($currentUrl, 1, strlen($languagePrefix)) == $languagePrefix) {
				$nextUrl = substr($currentUrl, strlen($languagePrefix) + 1);
			}
			// fake the hash part of the url
			$hashPart = Util::lavnn('_hash', $_REQUEST, '');
			if ($hashPart != '') {
				$nextUrl = str_replace('_hash=' . $hashPart, '', $nextUrl);
				$nextUrl .= "#$hashPart";
				$nextUrl = str_replace('&#', '#', $nextUrl);
				$nextUrl = str_replace('?#', '#', $nextUrl);
				unset($_REQUEST['_hash']);
			}
		}
		Runtime::getInstance()->setContextItem('nextUrl', $nextUrl);

		return $nextUrl;
	}

	/**
	 * Reroutes to another action of the same application.
	 *  To redirect to an external link, use go() instead.
	 *
	 * @param string $actionPath
	 * @param string $actionType
	 * @param array $params
	 * @param bool $secure
	 */
	public static function reroute($actionPath = '', $actionType = 'p', $params = array(),  $secure = false)
	{
		/** @var $r Runtime */
		$r = self::$instance;

		if ($actionPath == '') {
			$actionType = 'p';
			$actionPath = $r->getSetting('DEFAULT_PAGE');
		}

		$protocol = $secure ? 'https://' : 'http://';
		$url = $protocol . $_SERVER['HTTP_HOST'];
		if ($r->isMultilingual() || 1 == 1) { // always use language code
			$url .= ('/' . $_SESSION['language']['code'] . "/$actionType/$actionPath");
			if (array_key_exists($actionType, $params)) {
				unset($params[$actionType]);
			}
		} else {
			$url .= $_SERVER['PHP_SELF'];
			$params[$actionType] = $actionPath;
		}
		if (count($params) > 0) {
			$url .= ('?'.http_build_query($params));
		}

		header("Location: $url");
		exit();
	}

	/**
	 * Includes prologue action (if it exists for current module)
	 *
	 * @param $actionType
	 * @param array $context
	 */
	public function prologueAction($actionType, $context = array())
	{
		if ($actionType == 'cron' || PHP_SAPI === 'cli') {
			$this->setContextItem('executionContext', 'cli');
		} else {
			$this->setContextItem('executionContext', 'web');
		}
		// $actionType and $actionPath can be used inside of prologue action for more fine-grained access control or whatever.
		$fileName =  Locator::getAppModuleFilePath($this->moduleName, 'actions/_prologue.php');
		$fileName = Config::getInstance()->getFolderPath('app') . 'modules/'.$this->moduleName.'/actions/_prologue.php';
		if ($fileName != '' && file_exists($fileName)) {
			require $fileName;
		}
	}

	/**
	 * Includes epilogue action (if it exists for current module)
	 *
	 * @param $actionType
	 * @param array $context
	 */
	public function epilogueAction($actionType, $context = array())
	{
		if ($actionType == 'cron' || PHP_SAPI === 'cli') {
			$this->setContextItem('executionContext', 'cli');
		} else {
			$this->setContextItem('executionContext', 'web');
		}
		// $actionType and $actionPath can be used inside of prologue action for more fine-grained access control or whatever.
		$fileName = Locator::getAppModulePath($this->moduleName, "actions/_epilogue.php");
		if ($fileName != '' && file_exists($fileName)) {
			if ($actionType == 'p') {
				$page = $context['page'];
				require $fileName;
			}
		}
	}

    /**
     * Forces inclusion of another action without rerouting/redirecting (should preserve the original url)
     *
     * @param $actionPath
     * @param $params // added to $_REQUEST, overwriting the keys
     */
    public function includeAction($actionPath, $params = array())
    {
        // include action if it exists
        if ($actionPath != '' && $fileName = $this->checkAction($actionPath)) {
            // save the original request and current action
			$currentModule = $this->moduleName;
			$currentAction = $this->actionName;
			$originalRequest = $_REQUEST;
			// alter the request
            foreach($params as $key => $value) {
                $_REQUEST[$key] = $value;
            }
			$r = Runtime::getInstance();
			if ($r->hasContextItem('page')) {
				$page = $r->getContextItem('page');
			}
            require $fileName;
            // restore original request and action
            $_REQUEST = $originalRequest;
			$this->moduleName = $currentModule;
			$this->actionName = $currentAction;
        } else {
			// @TODO send an error to admin that action is missing
			die($actionPath . ' not found');
		}
    }

	/**
	 * Renders page flash message (informational or error) and cleans up session
	 *  Extend this function in your implementation of the framework!
	 *
	 * @return string
	 */
	public function renderFlashMessage()
	{
		$flash = Util::lavnn('flash', $_SESSION, '');
		$error = Util::lavnn('error', $_SESSION, '');
		$output = '';
		if ($flash != '') {
			// rendering code to be injected here
			unset($_SESSION['flash']);
		}
		if ($error != '') {
			// rendering code to be injected here
			unset($_SESSION['error']);
		}

		return $output;
	}

	/**
	 * Renders ajax flash message (informational or error) and cleans up session.
	 *  Extend this function in your implementation of the framework!
	 *
	 * @return string
	 */
	public function renderAjaxFlashMessage()
	{
		$output = '';

		if (array_key_exists('ajaxFlash', $_SESSION)) {
			// rendering code to be injected here
			unset($_SESSION['ajaxFlash']);
		}
		if (array_key_exists('ajaxError', $_SESSION)) {
			// rendering code to be injected here
			unset($_SESSION['ajaxError']);
		}

		return $output;
	}

	protected function routeFormAction($f, $fileName)
	{
		$this->prologueAction('f', $f);
		require $fileName;
	}

	protected function routeInlineAction($i, $fileName)
	{
		$this->prologueAction('i', $i);
		print $this->renderAjaxFlashMessage();
		require $fileName;
	}

	protected function routeJsonAction($json)
	{
		$this->prologueAction('json', $json);
		if ($fileName = $this->checkAction($json)) {
			require $fileName;
		}
	}

	protected function routePdfAction($pdf, $fileName)
	{
		$this->prologueAction('pdf', $pdf);
		require $fileName;
	}

	protected function routePageAction($p, $fileName)
	{
		$this->prologueAction('p', $p);
		// in some cases, sent url could not contain hash, so it was encoded with "_hash" parameter
		$urlHash = Util::lavnn('_hash', $_REQUEST, '');
		if ($urlHash != '') {
			$url = self::getNextUrl();
			self::redirect($url);
		}

		// create a new Page object
		/** @var $page Page */
		$page = $this->createPage();
		// Assets which are actions-specific, should be explicitly included by those actions

		require $fileName;
		// add flash messages after action was included, because it can add things there
		$page->add('flashMessages', $this->renderFlashMessage());
		$this->epilogueAction('p', array('page' => &$page));

		try {
			print $page->render();
			$this->sendWarnings();
		} catch(\Exception $ex) {
			$this->sendWarnings();
		}
	}

	protected function routeCronAction($cron, $fileName)
	{
		$this->prologueAction('cron', $cron); // this will also set special executionContext
		if ($fileName = $this->checkAction($cron)) {
			require $fileName;
		}
		$this->sendWarnings('none');
	}

	/**
	 * Routes HTTP request to proper action file
	 *
	 * @param array $request
	 */
	public function route($request)
	{
		$f = Util::lavnn('f', $request, '');
		$i = Util::lavnn('i', $request, '');
		$json = Util::lavnn('json', $request, '');
		$pdf = Util::lavnn('pdf', $request, '');
		$p = Util::lavnn('p', $request, '');
		$cron = Util::lavnn('cron', $request, '');
		if ($f != '' && $fileName = $this->checkAction($f)) {
			$this->routeFormAction($f, $fileName);
			exit();
		} elseif ($i != '' && $fileName = $this->checkAction($i)) {
			$this->routeInlineAction($i, $fileName);
			exit();
		} elseif ($json != '') {
			// JSON is a special case. We need to ensure that output is at least empty array.
			//  Therefore, we cannot rely on default behaviour if request is not routed.
			//  So, we still serve some output if action is not found.
			//  Consumer is then responsible to check the structure of returned JSON
			$output = array();
			$this->routeJsonAction($json);
			print json_encode($output);
			exit();
		} elseif ($pdf != ''&& $fileName = $this->checkAction($pdf)) {
			$this->routePdfAction($pdf, $fileName);
			exit();
		} elseif ($p != '' && $fileName = $this->checkAction($p)) {
			$this->routePageAction($p, $fileName);
			exit();
		} elseif ($cron != '' && $fileName = $this->checkAction($cron)) {
			$this->routeCronAction($cron, $fileName);
			exit();
		}

		// Nothing was matched!
		HttpResponse::dynamic404();
	}

	/**
	 * Create a new page.
	 *  Extend this method if standard Page class is not enough
	 *
	 * @return Page
	 */
	public function createPage()
	{
		$page = new Page(); // introduce $page variable for action
		$page->checkMultilingualSetup($this->isMultilingual());

		// log the last viewed page in the Page object itself
		$page->add('lastPage', $this->getNextUrl());

		// Add assets that are common for all p-actions
		$page->addCommonAssets();
		// Add assets that are common for all p-actions of given module
		$page->addModuleAssets($this->moduleName);

		return $page;
	}

	/**
	 * Checks if given action exists and is accessible
	 *
	 * @param string $fullAction
	 *
	 * @return boolean
	 */
	public function checkAction($fullAction)
	{
		$config = Config::getInstance();

		$fileName = '';
		list($module, $action) = explode('/', trim($fullAction), 2);
		$this->moduleName = $module;
		$this->actionName = $action;
		$this->isSharedModule = false;
		if ($module != '' && $action != '') {

			$appFolder = $config->getFolderPath('app');
			$sharedFolder = $config->getFolderPath('shared');

			$fileName = Locator::getAppModuleFilePath($module, "actions/$action.php");
			if (!file_exists($fileName)) {
				// module is not defined for the application => search shared space
				$fileName = Locator::getSharedModuleFilePath($module, "actions/$action.php");
				if (file_exists($fileName)) {
					$this->isSharedModule = true;
				} else {
					$fileName = '';
				}
				$this->addProfilerMessage("Did not find the file for action $fullAction");
			}
			// Check unauthorized requests
			if (!$this->checkAuthorization($module, $action)) {
				$this->addProfilerMessage("Authorization check failed for action $fullAction");
				$fileName = '';
			}
		} else {
			$this->addProfilerMessage("Malformed action $fullAction");
		}

		return $fileName == '' ? false : $fileName;
	}

	public function checkAuthorization($moduleName, $actionName) {
		// TODO implement logic that restricts routing if action is not white-listed
		return true;
	}

	/**
	 * Redirects to external location (without even checking it)
	 *  To redirect to another action of the same application, use reroute() instead.
	 *  To redirect to another url of the same site, use redirect() instead.
	 *
	 * @param string $uri
	 */
	public static function go($uri)
	{
		header("Location:$uri");
		exit(); // prevent other code from executing
	}

	/**
	 * Registers warning
	 *
	 * @param string $warning
	 */
	public function addWarning($warning)
	{
		$debugInfo = debug_backtrace();
		if (count($debugInfo) > 0) {
			$caller = $debugInfo[0]['class'] . ':' . $debugInfo[0]['function'];
			$codeLine = $debugInfo[0]['file'] . ':' . $debugInfo[0]['line'];
			$warning = 'Error in ' . $caller . ' at ' . $codeLine . ' with warning:' . $warning;
		}
		$this->warnings[] = $warning;
	}

	/**
	 * Send accumulated warnings to site administrator by email (if allowed in the config)
	 *
	 * @param string $channel
	 */
	private function sendWarnings($channel = '')
	{
		if ($this->muted) {
			$channel = 'none';
		} elseif ($channel == '') {
			$channel = Config::getInstance()->getEnvSetting('sendWarnings');
		}
		if (count($this->warnings) > 0) {
			switch ($channel) {
				case 'dump':
					d($this->warnings);
					break;
				case 'hidden':
					dh($this->warnings);
					break;
				case 'none':
					// fall through to default
				default:
					// do nothing
					break;
			}
		}
	}

	public static function forceDownload($fileName, $outputFileName = '', $deleteAfterUse = false)
	{
		// do not do anything if requested file is not valid
		if (!file_exists($fileName)) {
			exit();
		}

		if (trim($outputFileName) == '') {
			$outputFileName = $fileName;
		}

		// decide mime type based on file's extension
		switch (strtolower(substr(strrchr($fileName, '.'), 1))) {
			case 'pdf':
				$mimeType = 'application/pdf';
				break;
			case 'zip':
				$mimeType = 'application/zip';
				break;
			case 'jpeg':
			case 'jpg':
				$mimeType = 'image/jpg';
				break;
			default:
				$mimeType = 'application/force-download';
		}

		// send all the headers
		header('Pragma: public'); // required
		header('Expires: 0'); // no cache
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($fileName)) . ' GMT');
		header('Cache-Control: private', false);
		header('Content-Type: ' . $mimeType);
		header('Content-Disposition: attachment; filename="' . basename($outputFileName) . '"');
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: ' . filesize($fileName)); // provide file size
		header('Connection: close');

		// send the file content
		readfile($fileName); // push it out
		if ($deleteAfterUse) {
			unlink($fileName);
		}
		exit();
	}

}
