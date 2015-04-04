<?php

namespace Kasha\Core;

class PageRenderer
{
	public function render(Page $page) {
		return '';
	}

	protected function getTemplate($templateName)
	{
		$fileName = __DIR__ . '/Templates/' . $templateName . '.html';

		return file_exists($fileName) ? file_get_contents($fileName) : '';
	}
}
