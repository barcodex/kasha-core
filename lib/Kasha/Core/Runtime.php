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
		$fileName = $this->config['folders']['app'] . 'modules/'.$this->moduleName.'/actions/_epilogue.php';
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
	 * Routes HTTP request to proper action file
	 *
	 * @param array $request
	 */
	public function route($request)
	{
        $config = Config::getInstance();
		$f = Util::lavnn('f', $request, '');
		$i = Util::lavnn('i', $request, '');
		$json = Util::lavnn('json', $request, '');
		$pdf = Util::lavnn('pdf', $request, '');
		$p = Util::lavnn('p', $request, '');
		$cron = Util::lavnn('cron', $request, '');
		if ($f != '' && $fileName = $this->checkAction($f)) {
			$this->prologueAction('f', $f);
			$this->addProfilerMessage("Started to include action $f");
			require $fileName;
			$this->addProfilerMessage("Finished to include action $f");
			$this->sendWarnings();
			exit();
		} elseif ($i != '' && $fileName = $this->checkAction($i)) {
			$this->prologueAction('i', $i);
			print $this->renderAjaxFlashMessage();
			$this->addProfilerMessage("Started to include action $i");
			require $fileName;
			$this->addProfilerMessage("Finished to include action $i");
			$this->sendWarnings();
			exit();
		} elseif ($json != '') {
			$this->prologueAction('json', $json);
			// JSON is a special case. We need to ensure that output is at least empty array.
			//  Therefore, we cannot rely on default behaviour if request is not routed.
			//  So, we still serve some output if action is not found.
			//  Consumer is then responsible to check the structure of returned JSON
			$this->addProfilerMessage("Started to include action $json");
			$output = array();
			//@TODO write docs about json actions setting up $output array
			if ($fileName = $this->checkAction($json)) {
				require $fileName;
			}
			print json_encode($output);
			$this->addProfilerMessage("Finished to include action $json");
			$this->sendWarnings('none'); // do not mess with json
			exit();
		} elseif ($pdf != ''&& $fileName = $this->checkAction($pdf)) {
			$this->prologueAction('pdf', $pdf);
			require $fileName;
			exit();
		} elseif ($p != '' && $fileName = $this->checkAction($p)) {
			$this->prologueAction('p', $p);
			// in some cases, sent url could not contain hash, so it was encoded with "_hash" parameter
			$urlHash = Util::lavnn('_hash', $_REQUEST, '');
			if ($urlHash != '') {
				$url = self::getNextUrl();
				self::redirect($url);
			}
			$this->addProfilerMessage("Started to include action $p");
			$page = new Page(); // introduce $page variable for action
			$page->checkMultilingualSetup($this->isMultilingual());

			// Add assets that are common for all p-actions
			$page->addCommonAssets();
			// Add assets that are common for all p-actions of given module
			$page->addModuleAssets($this->moduleName);

			// Assets which are actions-specific, should be explicitly included by those actions
			$page->add('lastPage', $this->getNextUrl());
			$isAdmin = (isset($_SESSION['user']) && $_SESSION['user']['is_admin'] == 1);
			if (!$isAdmin && !isset($_SESSION['user']['impersonator']) && !self::isTilpyIP()) {
				$page->add('analytics', TextProcessor::doTemplate('main', 'index.analytics'));
			}
			$page->add('envStickyOverlay', TextProcessor::doTemplate('main', 'envStickyOverlay.' . strtolower($config['ENV'])));
			require $fileName;
			// add flash messages after action was included, because it can add things there
			$page->add('flashMessages', $this->renderFlashMessage());
			$this->epilogueAction('p', array('page' => &$page));
			$this->addProfilerMessage("Ready to render full page...");
			try {
				print $page->render();
				$this->addProfilerMessage("...done. Finished to include action $p");
				if (!$this->muted) {
					$this->sendWarnings();
					Profiler::getInstance()->sendReport();
				}
			} catch(\Exception $ex) {
				$this->addWarning("...failed. Exception: " . $ex->getMessage());
				if (!$this->muted) {
					$this->sendWarnings();
					Profiler::getInstance()->sendReport();
				}
			}
			exit();
		} elseif ($cron != '' && $fileName = $this->checkAction($cron)) {
			$this->prologueAction('cron', $cron); // this will also set special executionContext
			$this->addProfilerMessage("Started to include action $cron");
			if ($fileName = $this->checkAction($cron)) {
				require $fileName;
			}
			$this->addProfilerMessage("Finished to include action $cron");
			$this->sendWarnings('none');
			exit();
		}
		HttpResponse::dynamic404();
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
		$fileName = '';

		list($module, $action) = explode('/', trim($fullAction), 2);
		$this->moduleName = $module;
		$this->actionName = $action;
		$this->sharedModule = false;
		if ($module != '' && $action != '') {
			$fileName = $this->config['folders']['app'] . "modules/$module/actions/$action.php";
			if (!file_exists($fileName)) {
				// module is not defined for the application => search shared space
				$fileName = $this->config['folders']['shared'] . "modules/$module/actions/$action.php";
				if (file_exists($fileName)) {
					$this->sharedModule = true;
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
	 * Wraps Runtime::mail() to send a mail to administrator
	 *
	 * @param $subj
	 * @param $body
	 * @param array $options
	 */
	public static function mailAdmin($subj, $body)
	{
		$adminEmail = Config::getInstance()->get('adminEmail');
		if ($adminEmail != '') {
			self::queueMail($adminEmail, $subj, $body);
		}
	}

	/**
	 * Takes care about errors that prevent further running of the script
	 *
	 * @param string $error
	 */
	public function fatalError($error)
	{
		$config = $this->config;
		$mailMessage = '';
		$forceAdminEmail = false;

		switch ($error) {
			case 'REDIRECT_LOOP':
				$mailMessage .= 'server: ' . print_r($_SERVER, 1) .
					'request: ' . print_r($_REQUEST, 1) .
					'config: ' . print_r($this->config, 1);
				break;
			default:
				$mailMessage .= print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1);
				break;
		}

		$errorPage = Util::lavnn($error, $config['errorPages'], '');
		$errorPagePath = $config['folders']['public'] . $errorPage;
		$errorPageExists = file_exists($errorPagePath);
		if ($errorPage == '' || !$errorPageExists || $forceAdminEmail) {
			Runtime::mail(
				$config['adminEmail'],
				$config['ENV'] . ': Fatal error happened',
				$mailMessage
			);
		}
		if ($errorPageExists) {
			print file_get_contents($errorPagePath);
		} else {
			HttpResponse::dynamic404();
		}
		die();
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
	 * Renders ajax flash message (informational or error) and cleans up session
	 *
	 * @return string
	 */
	public function renderAjaxFlashMessage()
	{
		$output = '';

		if (array_key_exists('ajaxFlash', $_SESSION)) {
			$output .= TextProcessor::doTemplate('framework', '_ajaxFlash', array('flash' => $_SESSION['ajaxFlash']));
			unset($_SESSION['ajaxFlash']);
		}
		if (array_key_exists('ajaxError', $_SESSION)) {
			$output .= TextProcessor::doTemplate('framework', '_ajaxError', array('error' => $_SESSION['ajaxError']));
			unset($_SESSION['ajaxError']);
		}

		return $output;
	}

	/**
	 * Renders page flash message (informational or error) and cleans up session
	 *
	 * @return string
	 */
	public function renderFlashMessage()
	{
		$flash = Util::lavnn('flash', $_SESSION, '');
		$error = Util::lavnn('error', $_SESSION, '');
		$output = '';
		if ($flash != '') {
			$output .= TextProcessor::doTemplate('framework', '_flash', array('flash' => $flash));
			unset($_SESSION['flash']);
		}
		if ($error != '') {
			$output .= TextProcessor::doTemplate('framework', '_error', array('error' => $error));
			unset($_SESSION['error']);
		}

		return $output;
	}

	/**
	 * Get value for a given setting from the config
	 *
	 * @param $setting
	 * @param string $default
	 *
	 * @return mixed
	 */
	public function getSetting(
		$setting,
		$default = ''
	) {
		return Util::lavnn($setting, $this->config, $default);
	}

//region Shortcuts for database methods
	public static function s2r(
		$moduleName,
		$templateName,
		$params = array()
	) {
		return Database::getInstance()->sql2row($moduleName, $templateName, $params);
	}

	public static function s2a(
		$moduleName,
		$templateName,
		$params = array()
	) {
		return Database::getInstance()->sql2array($moduleName, $templateName, $params);
	}

	public static function spreview(
		$moduleName,
		$templateName,
		$params = array()
	) {
		return Database::getInstance()->preview($moduleName, $templateName, $params);
	}

	public static function sinsert(
		$moduleName,
		$templateName,
		$params = array()
	) {
		Database::getInstance()->insert($moduleName, $templateName, $params);
	}

	public static function sdelete(
		$moduleName,
		$templateName,
		$params = array()
	) {
		Database::getInstance()->delete($moduleName, $templateName, $params);
	}

	public static function supdate(
		$moduleName,
		$templateName,
		$params = array()
	) {
		Database::getInstance()->update($moduleName, $templateName, $params);
	}

//endregion

//region Shortcuts for text processing
	public function dot(
		$moduleName,
		$templateName,
		$params = array()
	) {
		return TextProcessor::doTemplate($moduleName, $templateName, $params);
	}

	public function loopt(
		$moduleName,
		$templateName,
		$rows = array()
	) {
		return TextProcessor::loopTemplate($moduleName, $templateName, $rows);
	}

//endregion

	/**
	 * @TODO check if this function is not obsolete after we introduced Runtime::mail()
	 * @param $subj
	 * @param $body
	 * @param null $to
	 * @param null $headers
	 *
	 * @return bool
	 */
	public function sendMail($subj, $body, $to = null, $headers = null) {
		if ($headers == null) {
			$headers = array();
		}
		$headers = array_merge($headers, array('MIME-Version: 1.0',  'Content-type: text/html; charset=utf-8'));
		if ($to == null) {
			$to = $this->config['adminEmail'];
		}
		return @mail($to, $subj, $body, join(PHP_EOL, $headers));
	}

	/**
	 * Send accumulated warnings to site administrator by email (if allowed in the config)
	 */
	private function sendWarnings($channel = '')
	{
		if ($this->muted) {
			$channel = 'none';
		} else {
			$env = $this->config['ENV'];
			if ($channel == '') {
				$channel = $this->config['envConfig'][$env]['sendWarnings'];
			}
		}
		if (count($this->warnings) > 0) {
			switch ($channel) {
				case 'dump':
					d($this->warnings);
					break;
				case 'hidden':
					dh($this->warnings);
					break;
				case 'email':
					// TODO prepare pretty mail message for warnings
					Runtime::mail(
						$this->config['adminEmail'],
						'warnings',
						' warnings: ' . print_r($this->warnings, 1) .
							' server: ' . print_r($_SERVER, 1) .
							' request: ' . print_r($_REQUEST, 1)
					);
					break;
				case 'none':
					// fall through to default
				default:
					// do nothing
					break;
			}
		}
	}

	/**
	 * @param string $requestMethod
	 * @param string $requestLoad
	 * @param string $resourceName
	 */
	public function routeApiAction(
		$requestMethod,
		$requestLoad,
		$resourceName
	) {
		/** @var $r Runtime */
		$r = $this;

		// @TODO this method would not work if used, fix it
		if ($resourceName != '' && $fileName = $this->checkApiAction($resourceName, $requestMethod)) {
			$this->addProfilerMessage("Started to include $requestMethod API action for $resourceName");
			$response = new ApiResponse();
			require $fileName;
			$response->send();
			$this->addProfilerMessage("Finished to include $requestMethod API action for $resourceName");
			// TODO save the log somewhere. preferably, asynchronously
		}
		exit();
	}

	/**
	 * @param $roleCode
	 *
	 * @return User
	 */
	public static function getUserModel($roleCode)
	{
		$className = Model::getClassName($roleCode);
		if ($className != '') {
			$user = new $className();
		} else {
			$user = new User();
		}

		return $user;
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

	public static function isTilpyIP()
	{
		return in_array(
			$_SERVER['REMOTE_ADDR'],
			array(
				 '84.226.105.219', // tilpy@hq
				 '82.192.245.21', // az@home
				 '195.216.72.2', // az@c
				 '83.77.130.144' // maki@home
			)
		);
	}
}
