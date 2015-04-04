<?php

namespace Kasha\Core;

interface RuntimeReporterInterface
{
	/**
	 * @param Runtime
	 * @param string $channel
	 *
	 * @return mixed
	 */
	public function send($runtime, $channel = '');

	/**
	 * @param array $warnings
	 *
	 * @return mixed
	 */
	public function format($warnings = array());
}
