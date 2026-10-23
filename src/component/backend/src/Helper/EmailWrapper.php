<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Rich HTML wrapper of the notification emails (0.9.0). Pure functions: no Joomla, no database, no network.
 *
 * It is applied when the email is SENT (see EngageMailer): the mail templates stored in Joomla, default or customised by the administrator, are never
 * rewritten. The HTML that comes out of the template is cleaned (no scripts, styles, forms, frames, images or event handlers; only http(s) and mailto
 * links), the pieces of Engage's default templates (site name bar, legal information) are moved to the header and footer of the card, the known classes
 * of the default templates get INLINE styles (mail clients require them) and everything is put in a table layout of at most 600 px with light and dark
 * colour schemes. It loads nothing from the outside: no images, no fonts, no tracking pixels, no JavaScript, no remote requests.
 *
 * @since 0.9.0
 */
final class EmailWrapper
{
	/** Closed list of accent colours (the tones of the dashboard). All of them give at least 4.5:1 contrast with white text. */
	public const ACCENTS = [
		'blue'   => '#1d4ed8',
		'violet' => '#6d28d9',
		'green'  => '#15803d',
		'red'    => '#b91c1c',
		'orange' => '#c2410c',
		'slate'  => '#334155',
	];

	public const DEFAULT_ACCENT = 'blue';

	/** Hex colour of an accent name; anything that is not in the closed list gives the default (no free values reach the CSS). */
	public static function accent($name): string
	{
		return is_string($name) && isset(self::ACCENTS[$name]) ? self::ACCENTS[$name] : self::ACCENTS[self::DEFAULT_ACCENT];
	}

	private const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

	/** Inline styles for the classes of Engage's default templates. */
	private const CLASS_STYLES = [
		'engageemail-comment-header' => 'margin:16px 0 0 0;padding:12px 14px;background-color:#f1f5f9;color:#111827;border:1px solid #cbd5e1;border-bottom:0;border-radius:6px 6px 0 0;',
		'engageemail-comment-name'   => 'display:block;font-weight:bold;font-size:15px;',
		'engageemail-comment-email'  => 'display:block;font-size:13px;color:#475569;',
		'engageemail-comment-ip'     => 'font-size:12px;color:#475569;',
		'engageemail-comment-date'   => 'font-size:12px;color:#475569;',
		'engageemail-comment-user-agent' => 'font-size:12px;color:#475569;',
		'engageemail-comment'        => 'margin:0 0 16px 0;padding:12px 14px;border:1px solid #cbd5e1;border-radius:0 0 6px 6px;background-color:#ffffff;color:#111827;',
	];

	/**
	 * Cleans and prepares the HTML of the body of a template.
	 *
	 * @param   string  $html    The HTML of the email as the template produced it
	 * @param   string  $accent  Hex colour for the links and buttons
	 *
	 * @return  array{content:string, legal:string}  The main content and the legal block of the default templates (may be empty)
	 */
	public static function content(string $html, string $accent): array
	{
		$html = trim($html);

		if ($html === '')
		{
			return ['content' => '', 'legal' => ''];
		}

		// 0.9.1: libxml gives up on deeply nested HTML (and would return nothing, losing the buttons): such a message is not decorated, it is
		// reduced to sanitised text plus its buttons
		if (self::maxDepth($html) > self::MAX_DEPTH)
		{
			return self::fallback($html, $accent);
		}

		$res = self::contentDom($html, $accent);

		if ($res['content'] === '')
		{
			return self::fallback($html, $accent);
		}

		return $res;
	}

	/** Deepest nesting of non-void elements (a quick scan, no DOM). */
	public static function maxDepth(string $html): int
	{
		$void = ['meta' => 1, 'br' => 1, 'hr' => 1, 'img' => 1, 'input' => 1, 'link' => 1, 'col' => 1, 'area' => 1, 'base' => 1, 'wbr' => 1, 'source' => 1, 'embed' => 1, 'param' => 1, 'track' => 1];
		$d    = 0;
		$max  = 0;

		if (preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9]*)(?:[^>"\']|"[^"]*"|\'[^\']*\')*?(/?)>#', $html, $m, PREG_SET_ORDER) === false)
		{
			return PHP_INT_MAX;
		}

		foreach ($m as [$all, $close, $name, $self])
		{
			if ($self === '/' || isset($void[strtolower($name)]))
			{
				continue;
			}

			$d = $close === '/' ? max(0, $d - 1) : $d + 1;
			$max = max($max, $d);
		}

		return $max;
	}

	public const MAX_DEPTH = 64;

	/** Reduction of a message that cannot be processed as a DOM: sanitised text and the moderation buttons found by a flat scan. Never empty. */
	private static function fallback(string $html, string $accent): array
	{
		$noCode = preg_replace('#<(script|style|head|title|template|noscript)\b.*?</\1\s*>#is', ' ', $html) ?? $html;
		$noCode = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $noCode) ?? $noCode;
		$text   = trim((string) preg_replace("/[ \t]*\n[ \t\n]*/", "\n", html_entity_decode(strip_tags($noCode), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		$text   = mb_substr($text, 0, 20000);
		$out    = '<p style="margin:0 0 12px 0;">' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
		$btn    = '';

		if (preg_match_all('#<a\b([^>]*)>(.*?)</a\s*>#is', $html, $m, PREG_SET_ORDER) !== false)
		{
			$n = 0;

			foreach ($m as [$all, $attrs, $label])
			{
				if ($n >= 12 || preg_match('/\bclass\s*=\s*["\'][^"\']*\bengageemail-button\b([^"\']*)["\']/i', $attrs, $cm) !== 1 || preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $hm) !== 1)
				{
					continue;
				}

				$href = trim(html_entity_decode($hm[1] !== '' ? $hm[1] : ($hm[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

				if (!self::safeHref($href))
				{
					continue;
				}

				$classes = preg_split('/\s+/', 'engageemail-button ' . $cm[1], -1, PREG_SPLIT_NO_EMPTY) ?: [];
				$txt     = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
				$btn    .= '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="' . self::buttonStyle($classes, $accent) . '">' . htmlspecialchars(mb_substr($txt, 0, 80), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a> ';
				$n++;
			}
		}

		return ['content' => $out . ($btn !== '' ? '<div>' . trim($btn) . '</div>' : ''), 'legal' => ''];
	}

	/** The DOM based cleaning. */
	private static function contentDom(string $html, string $accent): array
	{
		$dom = new DOMDocument('1.0', 'UTF-8');
		$prev = libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="engage-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xp   = new DOMXPath($dom);
		$root = $dom->getElementById('engage-root') ?? ($xp->query('//*[@id="engage-root"]')->item(0));

		if (!$root instanceof DOMElement)
		{
			return ['content' => '', 'legal' => ''];
		}

		// 1. Elements that never go to an email
		foreach (['script', 'style', 'link', 'meta', 'base', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'video', 'audio', 'source', 'track', 'canvas', 'title', 'head', 'img', 'picture', 'noscript', 'template', 'xmp', 'plaintext', 'noembed', 'noframes', 'area', 'map', 'param', 'marquee', 'listing', 'dialog', 'portal', 'slot', 'frame'] as $tag)
		{
			foreach (iterator_to_array($xp->query('.//' . $tag, $root)) as $n)
			{
				$n->parentNode->removeChild($n);
			}
		}

		// Comments and processing instructions
		foreach (iterator_to_array($xp->query('.//comment() | .//processing-instruction()', $root)) as $n)
		{
			$n->parentNode->removeChild($n);
		}

		// 2. Pieces of Engage's default templates that the card replaces: the site name bar; the legal information goes to the footer
		foreach (iterator_to_array($xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " engageemail-sitename ")]', $root)) as $n)
		{
			$n->parentNode->removeChild($n);
		}

		$legal = '';

		foreach (iterator_to_array($xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " engageemail-legalinfo ")]', $root)) as $n)
		{
			$legal .= self::innerHtml($n);
			$n->parentNode->removeChild($n);
		}

		// The message block of the default templates is the content; a custom template is used as it is
		$msg = $xp->query('.//*[contains(concat(" ", normalize-space(@class), " "), " engageemail-message ")]', $root)->item(0);
		$box = $msg instanceof DOMElement ? $msg : $root;

		// 3. Attributes and links
		foreach (iterator_to_array($xp->query('.//*', $box)) as $el)
		{
			if (!$el instanceof DOMElement)
			{
				continue;
			}

			self::cleanElement($el, $accent);
		}

		$out = self::innerHtml($box);

		// The legal block, cleaned in the same way
		if ($legal !== '')
		{
			$legal = self::cleanFragment($legal, $accent);
		}

		return ['content' => trim($out), 'legal' => trim($legal)];
	}

	/** Cleans a fragment (the legal block). */
	private static function cleanFragment(string $html, string $accent): string
	{
		$res = self::content('<div class="engageemail-message">' . $html . '</div>', $accent);

		return $res['content'];
	}

	private static function innerHtml(DOMNode $node): string
	{
		$s = '';

		foreach ($node->childNodes as $c)
		{
			$s .= $node->ownerDocument->saveHTML($c);
		}

		return $s;
	}

	/** Allowed attributes, safe hrefs, inline styles for the known classes and the buttons. */
	private static function cleanElement(DOMElement $el, string $accent): void
	{
		$classes = preg_split('/\s+/', trim($el->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$style   = '';

		foreach (iterator_to_array($el->attributes) as $attr)
		{
			$name = strtolower($attr->nodeName);

			if (!in_array($name, ['href', 'title', 'style', 'colspan', 'rowspan', 'width', 'height', 'align', 'valign', 'dir', 'lang', 'class'], true) || ($name === 'href' && strtolower($el->nodeName) !== 'a'))
			{
				$el->removeAttribute($attr->nodeName);
			}
		}

		// The classes are only used to choose the inline styles; they are not kept
		$el->removeAttribute('class');

		if ($el->hasAttribute('style'))
		{
			$clean = self::cleanStyle($el->getAttribute('style'));

			if ($clean === '')
			{
				$el->removeAttribute('style');
			}
			else
			{
				$el->setAttribute('style', $clean);
			}
		}

		foreach ($classes as $c)
		{
			if (isset(self::CLASS_STYLES[$c]))
			{
				$style .= self::CLASS_STYLES[$c];
			}
		}

		$tag = strtolower($el->nodeName);

		if ($tag === 'a')
		{
			$href = trim(html_entity_decode($el->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

			if (!self::safeHref($href))
			{
				// Not a web or mail address (empty, #, javascript:, data:…): a button disappears, any other link is replaced by its text
				if ($el->parentNode !== null)
				{
					if (in_array('engageemail-button', $classes, true))
					{
						$el->parentNode->removeChild($el);

						return;
					}

					while ($el->firstChild !== null)
					{
						$el->parentNode->insertBefore($el->firstChild, $el);
					}

					$el->parentNode->removeChild($el);
				}

				return;
			}

			$el->setAttribute('href', $href);

			if (in_array('engageemail-button', $classes, true))
			{
				$el->setAttribute('style', self::buttonStyle($classes, $accent));

				return;
			}

			$style .= 'color:' . $accent . ';text-decoration:underline;';
			$el->setAttribute('rel', 'noopener nofollow ugc');
		}

		if ($style !== '')
		{
			$el->setAttribute('style', ($el->hasAttribute('style') ? rtrim($el->getAttribute('style'), '; ') . ';' : '') . $style);
		}

		// Own marker classes only for the dark colour scheme (the media query of the head overrides the inline colours of these boxes)
		if (in_array('engageemail-comment-header', $classes, true))
		{
			$el->setAttribute('class', 'eg-ch');
		}
		elseif (in_array('engageemail-comment', $classes, true))
		{
			$el->setAttribute('class', 'eg-cb');
		}
	}

	/** Inline style of a button of Engage's templates, by its classes. */
	public static function buttonStyle(array $classes, string $accent): string
	{
		$bg = $accent;

		if (in_array('engageemail-button-success', $classes, true))
		{
			$bg = self::ACCENTS['green'];
		}
		elseif (in_array('engageemail-button-warning', $classes, true))
		{
			$bg = self::ACCENTS['orange'];
		}
		elseif (in_array('engageemail-button-danger', $classes, true))
		{
			$bg = self::ACCENTS['red'];
		}

		return 'display:inline-block;margin:4px 6px 4px 0;padding:10px 16px;border-radius:6px;background-color:' . $bg . ';color:#ffffff;font-size:14px;font-weight:bold;line-height:1.2;text-decoration:none;text-align:center;';
	}

	/** Properties that an inline style of a template or of a comment may keep (everything else is dropped). */
	private const STYLE_PROPS = [
		'color', 'background-color', 'font-weight', 'font-style', 'font-size', 'font-family', 'text-decoration', 'text-align', 'line-height', 'vertical-align',
		'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
		'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-width', 'border-style', 'border-radius',
		'width', 'max-width', 'height', 'display', 'white-space', 'letter-spacing',
	];

	/**
	 * Strict whitelist for the `style` attribute (0.9.1): only the properties of STYLE_PROPS, with values made of letters, digits, `#`, `%`, `.`, `,`, `-`,
	 * spaces and the colour functions rgb()/rgba()/hsl()/hsla(); anything else (url(), image-set(), src(), cursor, position, expression, var(), attr()…)
	 * is dropped declaration by declaration. Same idea as HtmlFilter::restrictStyles for the comments.
	 */
	public static function cleanStyle(string $style): string
	{
		if (strlen($style) > 1000 || preg_match('/[<>\\\\\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $style) === 1)
		{
			return '';
		}

		$out = [];

		foreach (explode(';', $style) as $decl)
		{
			if (!str_contains($decl, ':'))
			{
				continue;
			}

			[$prop, $val] = array_map('trim', explode(':', $decl, 2));
			$prop = strtolower($prop);
			$val  = preg_replace('/\s*!important\s*$/i', '', $val) ?? '';

			if (!in_array($prop, self::STYLE_PROPS, true) || $val === '' || strlen($val) > 120)
			{
				continue;
			}

			$probe = preg_replace('/\b(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/-]+\)/i', '', $val) ?? '';

			if (preg_match('/^[A-Za-z0-9#%.,\- \'"]*$/', $probe) !== 1 || preg_match('/[()\'"]/', $probe) === 1 && $prop !== 'font-family')
			{
				continue;
			}

			if (preg_match('/url|image|src|expression|attr|var|env|calc|javascript|behaviou?r|binding|import/i', $val) === 1)
			{
				continue;
			}

			if ($prop === 'display' && !in_array(strtolower($val), ['block', 'inline', 'inline-block', 'none'], true))
			{
				continue;
			}

			$out[] = $prop . ':' . $val;
		}

		return $out ? implode(';', $out) . ';' : '';
	}

	/** Only http(s) and mailto addresses, without control characters or spaces, of reasonable length. */
	public static function safeHref(string $href): bool
	{
		return $href !== '' && strlen($href) <= 2048 && preg_match('/[\x00-\x20\x7f"\'<>\\\\`]/', $href) !== 1 && preg_match('~^(?:https?://[^/\s]+|mailto:[^\s@]+@[^\s@]+)~i', $href) === 1;
	}

	/**
	 * The complete HTML document.
	 *
	 * @param   array  $o  [
	 *     'lang' => 'es-ES', 'accent' => hex, 'sitename' => string, 'siteurl' => string (trusted base or ''), 'heading' => string,
	 *     'content' => cleaned content, 'legal' => cleaned legal block, 'confidential' => bool, 't' => texts (see texts()),
	 * ]
	 */
	public static function html(array $o): string
	{
		$e       = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$accent  = preg_match('/^#[0-9a-f]{6}$/', (string) ($o['accent'] ?? '')) === 1 ? $o['accent'] : self::accent(self::DEFAULT_ACCENT);
		$lang    = preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/', (string) ($o['lang'] ?? '')) === 1 ? $o['lang'] : 'en-GB';
		$t       = (array) ($o['t'] ?? []);
		$site    = (string) ($o['sitename'] ?? '');
		$siteUrl = (string) ($o['siteurl'] ?? '');
		$font    = self::FONT;

		$siteHtml = $e($site);

		if ($siteUrl !== '' && self::safeHref($siteUrl))
		{
			$siteHtml = '<a href="' . $e($siteUrl) . '" style="color:#ffffff;text-decoration:none;">' . $siteHtml . '</a>';
		}

		$legal = '';

		if (($o['legal'] ?? '') !== '')
		{
			$legal = '<div class="eg-muted" style="margin:0 0 12px 0;font-size:12px;line-height:1.5;color:#475569;">' . $o['legal'] . '</div>';
		}

		$conf = '';

		if (!empty($o['confidential']))
		{
			$conf = self::confidentialHtml($t, $e);
		}

		$footer = '<p class="eg-muted" style="margin:0 0 12px 0;font-size:12px;line-height:1.5;color:#475569;">' . $e($t['sent_by'] ?? '') . '</p>';

		return '<!DOCTYPE html>' . "\n"
			. '<html lang="' . $e($lang) . '" xmlns="http://www.w3.org/1999/xhtml"><head>'
			. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
			. '<title>' . $e($site) . '</title>'
			. '<style type="text/css">:root{color-scheme:light dark;supported-color-schemes:light dark}'
			. '@media (prefers-color-scheme: dark){.eg-bg{background-color:#0b1220!important}.eg-card{background-color:#1e293b!important;color:#e5e7eb!important}.eg-card p,.eg-card li,.eg-card span,.eg-card div{color:#e5e7eb!important}'
			. '.eg-card a{color:#93c5fd!important}.eg-card a[style*="display:inline-block"]{color:#ffffff!important}.eg-foot{background-color:#0b1220!important}.eg-muted,.eg-muted *{color:#cbd5e1!important}.eg-ch,.eg-cb{background-color:#0f172a!important;border-color:#475569!important}.eg-conf{background-color:#111827!important;border-color:#475569!important}.eg-conf *{color:#cbd5e1!important}}'
			. '@media only screen and (max-width:620px){.eg-wrap{width:100%!important}.eg-pad{padding:16px!important}}'
			. '</style></head>'
			. '<body class="eg-bg" style="margin:0;padding:0;background-color:#f1f5f9;">'
			. '<table role="presentation" class="eg-bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#f1f5f9;"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" class="eg-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;">'
			. '<tr><td class="eg-pad" style="background-color:' . $accent . ';color:#ffffff;padding:20px 24px;border-radius:8px 8px 0 0;font-family:' . $font . ';">'
			. '<div style="font-size:20px;font-weight:bold;line-height:1.3;color:#ffffff;">' . $siteHtml . '</div>'
			. ((string) ($o['heading'] ?? '') !== '' ? '<div style="font-size:14px;line-height:1.4;color:#ffffff;margin-top:2px;">' . $e($o['heading']) . '</div>' : '')
			. '</td></tr>'
			. '<tr><td class="eg-card eg-pad" style="background-color:#ffffff;color:#111827;padding:24px;font-family:' . $font . ';font-size:15px;line-height:1.55;">' . ($o['content'] ?? '') . '</td></tr>'
			. '<tr><td class="eg-foot eg-pad" style="background-color:#e2e8f0;color:#475569;padding:16px 24px;border-radius:0 0 8px 8px;font-family:' . $font . ';">'
			. $legal . $footer . $conf
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/** The confidentiality notice alone (for emails sent without the rich design). */
	public static function notice(array $t): string
	{
		$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return '<div style="margin-top:16px;font-family:' . self::FONT . ';">' . self::confidentialHtml($t, $e) . '</div>';
	}

	/** The confidentiality notice, HTML. */
	private static function confidentialHtml(array $t, callable $e): string
	{
		$p = 'margin:0 0 8px 0;font-size:11px;line-height:1.5;color:#475569;';
		$s = '<div class="eg-conf" style="margin-top:4px;padding:12px;border:1px solid #94a3b8;border-radius:6px;background-color:#f8fafc;">'
			. '<p style="' . $p . 'font-weight:bold;">' . $e($t['conf_title'] ?? '') . '</p>'
			. '<p style="' . $p . '">' . $e($t['conf_body'] ?? '') . '</p>'
			. '<p style="' . $p . '">' . $e($t['conf_privacy'] ?? '') . '</p>';

		if (($t['unsub_text'] ?? '') !== '')
		{
			$s .= '<p style="' . $p . '">' . $e($t['unsub_text']);

			if (($t['unsub_url'] ?? '') !== '' && self::safeHref((string) $t['unsub_url']))
			{
				// 0.9.6: a button with text («Unsubscribe»), never the address as the visible text
				$s .= '</p><p style="' . $p . '"><a href="' . $e($t['unsub_url']) . '" style="display:inline-block;padding:8px 14px;border-radius:6px;background-color:#1d4ed8;color:#ffffff;font-size:13px;font-weight:bold;line-height:1.2;text-decoration:none;" rel="noopener nofollow">' . $e(($t['unsub_button'] ?? '') !== '' ? $t['unsub_button'] : ($t['unsub_label'] ?? '')) . '</a>';
			}

			$s .= '</p>';
		}

		return $s . '</div>';
	}

	/**
	 * The confidentiality notice for the plain text part (and the lines «sent by»), to be appended to it.
	 *
	 * @param   array  $t  Texts
	 */
	public static function plainFooter(array $t, bool $confidential, bool $rich = true, string $already = ''): string
	{
		$lines   = [];
		$lines[] = '';
		$lines[] = '--';

		if ($rich && ($t['sent_by'] ?? '') !== '')
		{
			$lines[] = $t['sent_by'];
		}

		if ($confidential)
		{
			$lines[] = '';
			$lines[] = (string) ($t['conf_title'] ?? '');
			$lines[] = (string) ($t['conf_body'] ?? '');
			$lines[] = (string) ($t['conf_privacy'] ?? '');

			if (($t['unsub_text'] ?? '') !== '')
			{
				$lines[] = trim((string) $t['unsub_text']);

				if (($t['unsub_url'] ?? '') !== '' && self::safeHref((string) $t['unsub_url']) && !str_contains($already, (string) $t['unsub_url']))
				{
					$lines[] = (string) $t['unsub_url'];
				}
			}
		}

		return implode("\n", $lines) . "\n";
	}

	/**
	 * Resolves the texts of the footer from language strings.
	 *
	 * @param   callable  $tr          fn(string $key, ...$args): string  — the translator for the language of the email (falls back to en-GB)
	 * @param   string    $sitename    Name of the site
	 * @param   string    $contact     Contact address of the site (Joomla's mailfrom), '' if none
	 * @param   string    $unsubUrl    Signed unsubscribe link, '' if none
	 * @param   bool      $subscriber  The recipient is a subscriber (author or user replied to): the unsubscribe sentence is added
	 * @param   bool      $alreadyHasUnsub  The content already carries the unsubscribe link
	 */
	public static function texts(callable $tr, string $sitename, string $contact, string $unsubUrl, bool $subscriber, bool $alreadyHasUnsub): array
	{
		$contact = preg_match('/^[^\s@<>"\'\\\\,;]+@[^\s@<>"\'\\\\,;]+\.[^\s@<>"\'\\\\,;]+$/', $contact) === 1 ? $contact : '';
		$unsubText = '';

		if ($subscriber && !$alreadyHasUnsub)
		{
			$unsubText = $unsubUrl !== '' ? (string) $tr('COM_ENGAGE_MAILWRAP_UNSUB') : (string) $tr('COM_ENGAGE_MAILWRAP_UNSUB_NOLINK');
		}

		return [
			'sent_by'      => (string) $tr('COM_ENGAGE_MAILWRAP_SENT_BY', $sitename),
			'conf_title'   => (string) $tr('COM_ENGAGE_MAILWRAP_CONF_TITLE'),
			'conf_body'    => (string) $tr('COM_ENGAGE_MAILWRAP_CONF_BODY'),
			'conf_privacy' => $contact !== '' ? (string) $tr('COM_ENGAGE_MAILWRAP_CONF_PRIVACY_CONTACT', $sitename, $contact) : (string) $tr('COM_ENGAGE_MAILWRAP_CONF_PRIVACY', $sitename),
			'unsub_text'   => $unsubText,
			'unsub_label'  => (string) $tr('COM_ENGAGE_MAILWRAP_UNSUB_LABEL'),
			'unsub_url'    => $unsubUrl,
			// 0.9.6: the unsubscribe BUTTON of the subscriber emails (the address is never printed in the HTML)
			'unsub_button' => (string) $tr('COM_ENGAGE_MAILWRAP_UNSUB_BUTTON'),
		];
	}

	/**
	 * 0.9.6: the actions of the plain text part: [[label, url], ...] with the translated label and a safe address (a web address, nothing else).
	 *
	 * @param   array     $actions     [[language key of the label, url], ...] (empty urls are dropped: «include links» off)
	 * @param   callable  $tr          Translator
	 * @param   string    $unsubUrl    Signed unsubscribe address
	 * @param   bool      $subscriber  Add «Unsubscribe» (always kept, also with links off)
	 *
	 * @return  array<int,array{0:string,1:string}>
	 */
	public static function plainActions(array $actions, callable $tr, string $unsubUrl, bool $subscriber): array
	{
		$out = [];

		foreach ($actions as $a)
		{
			if (is_array($a) && isset($a[0], $a[1]) && is_string($a[0]) && is_string($a[1]) && preg_match('/^COM_ENGAGE_MAILWRAP_ACT_[A-Z_]+$/', $a[0]) === 1)
			{
				$url = str_replace('&amp;', '&', trim($a[1]));

				if ($url !== '' && self::safeHref($url))
				{
					$out[] = [(string) $tr($a[0]), $url];
				}
			}
		}

		if ($subscriber && $unsubUrl !== '' && self::safeHref($unsubUrl))
		{
			$out[] = [(string) $tr('COM_ENGAGE_MAILWRAP_ACT_UNSUB'), $unsubUrl];
		}

		return $out;
	}

	/** The lines «Label:\naddress» of the actions whose address is not yet in the text (each address on its own line). */
	private static function missingActions(string $text, array $actions): string
	{
		$lines = [];

		foreach ($actions as [$label, $url])
		{
			if (!str_contains($text, $url))
			{
				$lines[] = $label . ':';
				$lines[] = $url;
				$lines[] = '';
			}
		}

		return $lines ? "\n" . implode("\n", $lines) : '';
	}

	/**
	 * 0.9.6: the comment (already purified HTML) as clean plain text for the text part of the email: paragraphs and line breaks kept, lists as «- item»,
	 * links as «text (address)», no tags, entities decoded once, no wrapping of lines. Never raw tags, never double escapes.
	 */
	public static function htmlToText(string $html): string
	{
		$t = preg_replace('~<\s*(script|style)\b.*?<\s*/\s*\1\s*>~is', '', $html) ?? '';
		$t = preg_replace_callback('~<\s*a\b[^>]*?\shref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>(.*?)<\s*/\s*a\s*>~is', static function (array $m): string {
			$url  = html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

			return (preg_match('~^https?://~i', $url) === 1 && $text !== '' && $text !== $url) ? $text . ' (' . $url . ')' : ($text !== '' ? $text : $url);
		}, $t) ?? '';
		$t = preg_replace('~<\s*br\s*/?>~i', "\n", $t) ?? '';
		$t = preg_replace('~<\s*li\b[^>]*>~i', "\n- ", $t) ?? '';
		$t = preg_replace('~<\s*/\s*(p|div|blockquote|pre|h[1-6]|ul|ol|tr|table)\s*>~i', "\n\n", $t) ?? '';
		$t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$t = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $t);
		$t = preg_replace('/[ \t]+\n/', "\n", $t) ?? '';
		$t = preg_replace('/\n{3,}/', "\n\n", $t) ?? '';

		return trim($t);
	}

	/**
	 * The decoration of a message: returns the new [Body, AltBody]. Used by EngageMailer (real emails) and by the preview of the dashboard.
	 *
	 * @param   array   $ctx      [
	 *     'rich' => bool, 'confidential' => bool, 'accent' => name, 'lang' => tag, 'key' => template key, 'sitename' => string, 'siteurl' => trusted base or '',
	 *     'contact' => mailfrom or '', 'unsubscribe' => signed url or '', 'subscriber' => bool, 'tr' => callable translator,
	 * ]
	 * @param   string  $body     Body as the template produced it
	 * @param   string  $altBody  Plain text alternative ('' if none)
	 * @param   bool    $isHtml   Is $body HTML?
	 *
	 * @return  array{0:string,1:string}
	 */
	public static function compose(array $ctx, string $body, string $altBody, bool $isHtml): array
	{
		$rich = !empty($ctx['rich']);
		$conf = !empty($ctx['confidential']);

		if (!$rich && !$conf)
		{
			return [$body, $altBody];
		}

		$tr       = $ctx['tr'];
		$unsub    = (string) ($ctx['unsubscribe'] ?? '');
		$hasUnsub = $unsub !== '' && (str_contains($body, $unsub) || str_contains($body, htmlspecialchars($unsub, ENT_QUOTES, 'UTF-8')) || str_contains(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $unsub));
		$texts    = self::texts($tr, (string) ($ctx['sitename'] ?? ''), (string) ($ctx['contact'] ?? ''), $unsub, !empty($ctx['subscriber']), $hasUnsub);

		// 0.9.6: the plain text part lists every action (view, moderation, unsubscribe) as «Label: address», each on its own line, unless the template already has it
		$actions = self::plainActions((array) ($ctx['actions'] ?? []), $tr, $unsub, !empty($ctx['subscriber']));

		if ($isHtml)
		{
			if ($rich)
			{
				$accent = self::accent($ctx['accent'] ?? '');
				$parts  = self::content($body, $accent);
				$key    = strtoupper(str_replace('com_engage.', '', (string) ($ctx['key'] ?? '')));

				$body = self::html([
					'lang'         => (string) ($ctx['lang'] ?? 'en-GB'),
					'accent'       => $accent,
					'sitename'     => (string) ($ctx['sitename'] ?? ''),
					'siteurl'      => (string) ($ctx['siteurl'] ?? ''),
					'heading'      => $key !== '' ? (string) $tr('COM_ENGAGE_MAILWRAP_HEAD_' . $key) : '',
					'content'      => $parts['content'],
					'legal'        => $parts['legal'],
					'confidential' => $conf,
					't'            => $texts,
				]);
			}
			else
			{
				$body .= self::notice($texts);
			}

			if ($altBody !== '')
			{
				$extra   = self::missingActions($altBody, $actions);
				$altBody = rtrim($altBody) . "\n" . $extra . self::plainFooter($texts, $conf, $rich, $altBody . $extra);
			}

			return [$body, $altBody];
		}

		$extra = self::missingActions($body, $actions);

		return [rtrim($body) . "\n" . $extra . self::plainFooter($texts, $conf, $rich, $body . $extra), $altBody];
	}
}
