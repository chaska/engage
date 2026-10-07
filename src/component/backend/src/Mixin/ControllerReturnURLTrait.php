<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Mixin;

defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;

trait ControllerReturnURLTrait
{
	/** @inheritdoc */
	protected function getRedirectToItemAppend($recordId = null, $urlVar = 'id')
	{
		$returnUrl = $this->getReturnUrl();

		if ($returnUrl)
		{
			$this->input->set('return', base64_encode($returnUrl));
		}

		return parent::getRedirectToItemAppend($recordId, $urlVar);
	}

	/** @inheritdoc */
	protected function getRedirectToListAppend()
	{
		$returnUrl = $this->getReturnUrl();

		if ($returnUrl)
		{
			$this->input->set('return', base64_encode($returnUrl));
		}

		return parent::getRedirectToListAppend();
	}

	/**
	 * Redirects to return URL, if one is defined and internal to this site.
	 *
	 * You need to set up the appropriate `onAfterTaskname` events to call this method.
	 *
	 * @since  3.0.0
	 * @see    self::getReturnUrl
	 */
	protected function applyReturnUrl(): void
	{
		$returnUrl = $this->getReturnUrl();

		if (is_null($returnUrl))
		{
			return;
		}

		$this->setRedirect($returnUrl);
	}

	/**
	 * Gets the decoded return URL based on the base64–encoded `returnurl` query string parameter.
	 *
	 * @return  string|null  The URL. NULL if there is none or if it's not an internal URL to this site.
	 *
	 * @since   3.0.0
	 */
	private function getReturnUrl(): ?string
	{
		$returnEncoded = $this->input->getBase64('return', '');
		$returnEncoded = $this->input->getBase64('returnurl', $returnEncoded);

		if (empty($returnEncoded))
		{
			return null;
		}

		$returnUrl = (string) \base64_decode($returnEncoded);

		// 0.8.1: the forms print a RELATIVE return URL (`/path?query`, validated), because the page may be shared through the cache and an
		// absolute one would carry the Host of whoever asked first. It is made absolute here with the host of THIS request.
		if (preg_match('~^/(?![/\\\\])[\x21-\x7E]*$~D', $returnUrl) === 1)
		{
			$returnUrl = Uri::getInstance()->toString(['scheme', 'host', 'port']) . $returnUrl;
		}

		// Only http(s) (or no scheme at all): `javascript:index.php;...` would pass the path check of isInternal()
		if (!in_array(strtolower((string) Uri::getInstance($returnUrl)->getScheme()), ['', 'http', 'https'], true) || !Uri::isInternal($returnUrl))
		{
			return null;
		}

		return $returnUrl;
	}
}