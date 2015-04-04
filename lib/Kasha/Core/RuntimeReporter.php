<?php

namespace Kasha\Core;

class RuntimeReporter implements  RuntimeReporterInterface
{

	/**
	 * @param Runtime $runtime
	 * @param string $channel
	 *
	 * @return mixed
	 */
	public function send($runtime, $channel = '')
	{
		$warnings = $runtime->getWarnings();
		if (count($warnings) > 0) {
			switch ($channel) {
				case 'dump':
					print $this->format($warnings);
					break;
				case 'hidden':
					print '<!--' . $this->format($warnings) . '-->';
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
	 * @param array $warnings
	 *
	 * @return mixed
	 */
	public function format($warnings = array())
	{
		return print_r($warnings, 1);
	}

}
