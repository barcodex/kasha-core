<?php

namespace Kasha\Core;

use Temple\Util;
use Temple\Processor;

class Page
{
	private $blocks = array(
		'meta' => '',
		'og' => '',
		'cssFiles' => '',
		'styles' => '',
		'headJsFiles' => '',
		'bodyJsFiles' => '',
		'headScripts' => '',
		'bodyScripts' => ''
	);
	private $styles = array();
	private $cssFiles = array();
	private $headScripts = array();
	private $bodyScripts = array();
	private $headJsFiles = array();
	private $bodyJsFiles = array();
	private $meta = array();
	private $og = array(); // Open Graph tags, which we keep distinct from usual meta

	public $masterTemplate = '';

	public function __construct(
		$masterTemplatePath = ''
	) {
		// Set up master template
		$this->setMasterTemplate($masterTemplatePath);
	}

	/**
	 * Sets path (in "module:filename" format ) to master template
	 *
	 * @param string $masterTemplatePath
	 */
	public function setMasterTemplate($masterTemplatePath = '')
	{
		if ($masterTemplatePath != '') {
			$this->masterTemplate = $masterTemplatePath;
		}
	}

	/**
	 * Adds block to the page.
	 * Repeated calls with the same $block value overwrite previous assignments unless explicitly allowed to concatenate
	 *
	 * @param string $block
	 * @param string $content
	 * @param bool $overwrite
	 */
	public function add(
		$block,
		$content,
		$overwrite = false
	) {
		$this->blocks[$block] = ($overwrite ? '' : Util::lavnn($block, $this->blocks, '')) . $content;
	}

	/**
	 * Adds stylesheet referenced by the file name to an internal collection
	 *
	 * @param string $filename
	 */
	public function addStylesheetFile($filename)
	{
		$this->cssFiles[$filename] = $filename; // using key will prevent adding the same file more than once
	}

	/**
	 * Adds javascript referenced by the file name to an internal collection
	 *
	 * @param string $filename
	 * @param bool $isBody
	 */
	public function addJavascriptFile($filename, $isBody = false)
	{
		if ($isBody) {
			$this->bodyJsFiles[] = $filename;
		} else {
			$this->headJsFiles[] = $filename;
		}
	}

	/**
	 * Adds style snippet to an internal collection
	 *
	 * @param string $styleSnippet
	 */
	public function addStyleSnippet($styleSnippet)
	{
		// TODO obfuscate/minimize
		$this->styles[] = $styleSnippet; // using key will prevent adding the same snippet more than once
	}

	/**
	 * Adds script snippet to an internal collection
	 *
	 * @param string $codeSnippet
	 * @param bool $isBody
	 */
	public function addScriptSnippet($codeSnippet, $isBody = false)
	{
		// TODO obfuscate/minimize
		if ($isBody) {
			$this->bodyScripts[] = $codeSnippet; // using key will prevent adding the same snippet more than once
		} else {
			$this->headScripts[] = $codeSnippet; // using key will prevent adding the same snippet more than once
		}
	}

	public function checkMultilingualSetup($multilingual = false)
	{
		$multilingual = true; // we emulate multilingual in any way to make sure that language code is written in URLs
		$templateName = 'scripts.' . ($multilingual ? 'polyglot' : 'monoglot') . '.js';
		$fileName = __DIR__ . "/Templates/$templateName.html";
		$this->addScriptSnippet(Processor::doText(file_get_contents($fileName)), true);
	}

	public function hasMeta($key)
	{
		return array_key_exists($key, $this->meta);
	}

	/**
	 * Adds a meta tag for a page
	 *
	 * @param string $key
	 * @param string $value
	 */
	public function addMeta($key, $value)
	{
		$this->meta[$key] = $value;
	}

	/**
	 * Adds an Open Graph tag for a page
	 *
	 * @param string $property
	 * @param string $content
	 */
	public function addOpenGraphTag($property, $content)
	{
		$this->og[$property] = $content;
	}

    /**
	 * Adds several Open Graph tags for a page in one call
	 *
	 * @param array $tags
	 */
	public function addOpenGraphTags($tags = array())
	{
        if (is_array($tags) && count($tags) > 0) {
            foreach ($tags as $property => $content) {
                if ($property != '' && $content != '') {
                    $this->og[$property] = $content;
                }
            }
        }
	}

	public function addModuleAssets($module)
	{
		$config = Config::getInstance();
		$env = $config->get('ENV');

		// Add here your custom code for adding module-specific assets
	}

	public function addCommonAssets()
	{
		$config = Config::getInstance();
		$env = $config->get('ENV');

		// Add jQuery and bootstrap, serve local version only on MAMP, use CDN on TEST/PROD
		if ($env == 'MAMP') {
			$this->addStylesheetFile('/css/bootstrap.min.css');
			$this->addJavascriptFile('/js/jquery-1.10.2.min.js', true);
			$this->addJavascriptFile('/js/bootstrap.min.js', true);
		} else {
			$this->addStylesheetFile('https://maxcdn.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap.min.css');
			$this->addJavascriptFile('https://code.jquery.com/jquery-1.10.2.min.js', true);
			$this->addJavascriptFile('https://maxcdn.bootstrapcdn.com/bootstrap/3.2.0/js/bootstrap.min.js', true);
		}

		// Add more common assets in your own implementation of Page class
	}

	public function generateCaptchaImage(
		$captchaText,
		$imageFileName,
		$options
	) {
		//region parse settings from $options or use default values
		$width = Util::lavnn('width', $options, 180); // width of resulting captcha image
		$height = Util::lavnn('height', $options, 100); // height of resulting captcha image
		$paletteSize = Util::lavnn('paletteSize', $options, 7); // from how many random colors to choose
		$linePosition = mt_rand(30, 80); // the top margin for a text
		$letterPosition = mt_rand(10, 50); // coordinate of each rendered letter
		$fontFile = $this->r->config['folders']['fonts'] . Util::lavnn('font', $options, 'DejaVuSansMono') . '.ttf';
		//endregion

		$captcha = imagecreate($width, $height);
		$backgroundColor = ImageColorAllocate($captcha, 255, 255, 255);

		//region Allocate colors, providing 150 as max value (colors should not be too close to white)
		$palette = array();
		for ($i = 0; $i < $paletteSize; $i++) {
			$palette[] = ImageColorAllocate($captcha, mt_rand(0, 150), mt_rand(0, 150), mt_rand(0, 150));
		}
		//endregion

		//region Add text that user should read
		foreach (mb_str_split($captchaText) as $letterCharacter) {
			$letterAngle = mt_rand(-18, 18); // each letter can be randomly inclined a little bit
			$letterSize = mt_rand(16, 28); // each letter can be of slightly different size
			$letterImageInfo = imagettftext(
				$captcha,
				$letterSize,
				$letterAngle,
				$letterPosition,
				$linePosition,
				$palette[mt_rand(0, $paletteSize - 1)],
				$fontFile,
				$letterCharacter
			);
			$topRightX = $letterImageInfo[2];
			$bottomRightX = $letterImageInfo[4];
			// Calculate position for the next character
			$letterPosition = max($topRightX, $bottomRightX) + 5;
		}
		//endregion

		//region Add random lines and other graphical noise
		for ($i = 0; $i < $width * $height * .001; $i++) {
			imagesetthickness($captcha, 1);
			imageline(
				$captcha,
				mt_rand(0, $width),
				mt_rand(0, $height),
				mt_rand(0, $width),
				mt_rand(0, $height),
				$palette[mt_rand(0, $paletteSize - 1)]
			);
		}
		for ($i = 0; $i < $width * $height * mt_rand(5, 10) / 100; $i++) {
			imagesetpixel(
				$captcha,
				mt_rand(1, $width),
				mt_rand(1, $height),
				$palette[mt_rand(0, $paletteSize - 1)]
			);
		}
		//endregion

		header('Content-Type: image/png');
		imagepng($captcha, $imageFileName);
		imagedestroy($captcha);
	}

}
