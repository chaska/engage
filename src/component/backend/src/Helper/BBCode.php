<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Engage\Administrator\Helper;

defined('_JEXEC') or die;

final class BBCode
{
	/** Maximum number of «[» characters in a comment that is parsed as BBCode (the rest is shown as plain text). */
	public const MAX_BRACKETS = 500;

	/**
	 * Convert BBcode to HTML
	 *
	 * @param   string  $text
	 * @param   int     $nestingLevel
	 *
	 * @return   string
	 * @since    1.0.0
	 */
	static public function parseBBCode(string $text, int $nestingLevel = 0): string
	{
		// Convert linebreaks (the CODE tag will revert them)
		$text = nl2br($text);

		// 0.9.12: the parser is quadratic in the number of brackets. More than MAX_BRACKETS is not legitimate BBCode: literal text (the input is already escaped).
		if ($nestingLevel === 0 && substr_count($text, '[') > self::MAX_BRACKETS)
		{
			return str_replace('[', '&#91;', $text);
		}

		// Parse the following tags: size, color, b, i, list, *, img
		$bracketStart = strpos($text, '[');

		while ($bracketStart !== false)
		{
			$bracketEnd = strpos($text, ']', $bracketStart);

			if ($bracketEnd === false)
			{
				break;
			}

			$tagData  = substr($text, $bracketStart + 1, $bracketEnd - $bracketStart - 1);
			$tagData  = trim($tagData);
			$tagParts = explode('=', $tagData, 2);
			$tag      = strtoupper($tagParts[0]);
			$tagParam = (count($tagParts) > 1) ? $tagParts[1] : null;

			// When I have processed the tag, I'll set this to true
			$processedTag = false;

			if (in_array($tag, ['SIZE', 'COLOR', 'B', 'I', 'U', 'S', 'LIST', 'IMG', 'URL', 'CODE', 'QUOTE']))
			{
				// Find the end tag
				[$endTagStart, $endTagEnd] = self::findEndTag($tag, $text, $bracketEnd);

				// Exceptions where an end tag is not expected
				$exception = false;

				if ($tag == '*')
				{
					$exception = true;
				}

				if (($tag == 'IMG') && is_null($tagData))
				{
					$exception = true;
				}

				if (($endTagStart != false) && !$exception && ($nestingLevel <= 15))
				{
					$innerText = substr($text, $bracketEnd + 1, $endTagStart - $bracketEnd - 1);

					switch ($tag)
					{
						case 'SIZE':
							$processedTag = true;
							$fsize        = (int) $tagParam;

							if ($fsize <= 50)
							{
								$fsize = 50;
							}
							elseif ($fsize > 200)
							{
								$fsize = 200;
							}

							$newText = '<span style="font-size: ' . $fsize . '%">' . self::parseBBCode($innerText, $nestingLevel + 1) . '</span>';
							break;

						case 'COLOR':
							if (empty($tagParam))
							{
								$tagParam = '';
							}

							$color          = strtolower(trim($tagParam));
							$acceptedColors = [
								'aqua',
								'black',
								'blue',
								'fuchsia',
								'gray',
								'grey',
								'green',
								'lime',
								'maroon',
								'navy',
								'olive',
								'purple',
								'red',
								'silver',
								'teal',
								'white',
								'yellow',
								'beige',
								'brown',
								'chocolate',
								'cyan',
								'darkblue',
								'darkcyan',
								'darkgray',
								'darkgrey',
								'darkorange',
								'darkred',
								'dimgray',
								'dimgrey',
								'gold',
								'goldenrod',
								'greenyellow',
								'indigo',
								'ivory',
								'khaki',
								'lavender',
								'lightblue',
								'lightcyan',
								'lightgray',
								'lightgrey',
								'lightgreen',
								'lightyellow',
								'navy',
								'orangered',
								'pink',
								'plum',
								'skyblue',
								'slateblue',
								'snow',
								'tan',
								'tomato',
								'whitesmoke',
							];

							if (!in_array($color, $acceptedColors))
							{
								// Is it a hex code?
								if ((substr($color, 0, 1) == '#') && in_array(strlen($color), [4, 7]))
								{
									$hexValue = dechex(hexdec(substr($color, 1)));
									$color    = '#' . strtoupper($hexValue);
								}
								else
								{
									$color = '';
								}
							}

							$newText = self::parseBBCode($innerText, $nestingLevel + 1);

							if (!empty($color))
							{
								$newText = '<span style="color: ' . $color . '">' . $newText . '</span>';
							}

							$processedTag = true;
							break;

						case 'B':
							$newText      = '<strong>' . self::parseBBCode($innerText, $nestingLevel + 1) . '</strong>';
							$processedTag = true;
							break;

						case 'I':
							$newText      = '<em>' . self::parseBBCode($innerText, $nestingLevel + 1) . '</em>';
							$processedTag = true;
							break;

						case 'U':
							$newText      = '<span style="text-decoration: underline">' . self::parseBBCode($innerText, $nestingLevel + 1) . '</span>';
							$processedTag = true;
							break;

						case 'S':
							$newText      = '<span style="text-decoration: line-through">' . self::parseBBCode($innerText, $nestingLevel + 1) . '</span>';
							$processedTag = true;
							break;

						case 'CODE':
							$innerText = str_replace('<br />', "", $innerText);
							//$innerText = htmlentities($innerText, ENT_QUOTES, 'UTF-8');
							$newText      = '<pre>' . $innerText . '</pre>';
							$processedTag = true;
							break;

						case 'LIST':
							// Do I have an ordered or unordered list?
							if (empty($tagParam))
							{
								$element = 'ul';
								$start   = '';
							}
							else
							{
								$element = 'ol';

								if ((int) $tagParam > 0)
								{
									$start = ' start = "' . (int) $tagParam . '"';
								}
								else
								{
									$start = '';
								}
							}

							// Break the innertext on [*] elements
							if ($innerText)
							{
								$innerText  = trim(str_replace('<br />', '', $innerText));
								$innerParts = explode('[*]', $innerText);

								if (count($innerParts))
								{
									$items = [];

									foreach ($innerParts as $item)
									{
										$item = trim($item);

										if (empty($item))
										{
											continue;
										}

										$items[] = self::parseBBCode($item, $nestingLevel + 1);
									}

									$innerText = '<li>' . implode('</li><li>', $items) . '</li>';
									$newText   = '<' . $element . $start . '>' . $innerText . '</' . $element . '>';
								}
								else
								{
									$newText = self::parseBBCode($innerText, $nestingLevel + 1);
								}

							}
							else
							{
								$newText = '';
							}
							$processedTag = true;
							break;

						case 'IMG':
							$newText = '';
							// 0.8.3: only http and https (whatever the filter mode: in "joomla" mode nothing else cleaned this)
							$imgURL  = self::safeUrl(trim($innerText), ['http', 'https']);

							if ($imgURL !== null)
							{
								$newText = '<img src="' . htmlspecialchars($imgURL, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
							}

							$processedTag = true;
							break;

						case 'URL':
							if (!is_null($tagParam))
							{
								$url = trim($tagParam);
							}
							else
							{
								$url = trim($innerText);
							}

							// 0.8.3: only http, https and mailto (or a relative address), whatever the filter mode
							$safe = self::safeUrl($url, ['http', 'https', 'mailto']);

							if ($safe !== null)
							{
								$anchorText = trim($innerText);

								if (empty($anchorText))
								{
									$anchorText = htmlspecialchars($safe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
								}

								// Do not encode the anchor text. This leads to double encoding of the anchor text, ending up showing things like &mu; instead of μ.
								// $anchorText = htmlentities($anchorText, ENT_QUOTES);
								$newText = '<a rel="nofollow ugc noreferrer" href="' . htmlspecialchars($safe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $anchorText . '</a>';
							}
							elseif (trim($innerText) !== '')
							{
								// A link with a protocol that is not allowed (javascript:, data:, vbscript:...) is NOT a link: only its text remains, as inert text
								$newText = htmlspecialchars(trim($innerText), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
							}
							else
							{
								$newText = '';
							}

							$processedTag = true;
							break;

						case 'QUOTE':
							$cite = '';

							if (!is_null($tagParam))
							{
								$cite = ' cite="' . htmlentities($tagParam, ENT_COMPAT) . '"';
							}

							$newText      = '<blockquote' . $cite . '>' . self::parseBBCode($innerText, $nestingLevel + 1) . '</blockquote>';
							$processedTag = true;

							break;
						default:
							// Invalid tag. Strip it.

							$newText      = self::parseBBCode($innerText, $nestingLevel + 1);
							$processedTag = true;
							break;
					}

					if ($processedTag)
					{
						$text = self::replace($bracketStart, $endTagEnd, $newText, $text);
					}
				}
			}

			if (!$processedTag)
			{
				$anotherOpeningBracket = strpos($text, '[', $bracketStart + 1);

				if (($anotherOpeningBracket !== false) && ($anotherOpeningBracket < $bracketEnd))
				{
					// Opening brace inside the text; only replace until that point
					$allText      = substr($text, $bracketStart, $bracketEnd - $bracketStart + 1);
					$anotherStart = strpos($allText, '[', 2);
					$newString    = htmlentities(substr($allText, 0, $anotherStart - 1));
					$newString    = str_replace('[', '&#91;', $newString);
					$rest         = substr($allText, $anotherStart);
					$newString    .= $rest;

					while (strpos($newString, '[['))
					{
						$newString = str_replace('[[', '&#93;[', $newString);
					}
				}
				else
				{
					// No opening brace; replace brackets with HTML entities
					$newString = '&#91;' . htmlentities(substr($text, $bracketStart + 1, $bracketEnd - $bracketStart));
				}

				$text = self::replace($bracketStart, $bracketEnd, $newString, $text);
			}

			$bracketStart = strpos($text, '[');
		} // end while $bracketStart !== false

		// Finally, return the converted text
		return $text;
	}

	/**
	 * Validates a URL typed by the commenter (0.8.3). Returns the URL ready for an attribute (entities decoded, control characters removed) or
	 * NULL when it must not be used.
	 *
	 * The scheme is read the way a browser reads it: HTML entities are decoded (`&#106;avascript:`, `&colon;`), and tabs, line breaks and every other
	 * control character or space are discarded wherever they are (`java<TAB>script:`), also at the start. Only the listed schemes pass; a URL without
	 * a scheme (relative: `/x`, `x.html`, `//host/x`) passes only if it has no colon before the first `/`, `?` or `#`. Anything with a quote, angle
	 * bracket or backslash is refused.
	 *
	 * @param   string    $url      The URL as typed
	 * @param   string[]  $schemes  Allowed schemes, in lowercase
	 *
	 * @return  string|null
	 * @since   0.8.3
	 */
	public static function safeUrl(string $url, array $schemes): ?string
	{
		// Entities can be nested (&amp;#106;): decode until nothing changes (at most 5 rounds, then refuse)
		for ($i = 0; $i < 5; $i++)
		{
			$decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			// Browsers also decode numeric references WITHOUT the closing semicolon (&#106avascript:); PHP does not
			$decoded = preg_replace_callback('/&#(?:x([0-9a-f]{1,6})|([0-9]{1,7}));?/i', static function (array $m): string {
				$cp = ($m[1] !== '') ? hexdec($m[1]) : (int) ($m[2] ?? 0);

				return (($cp > 0) && ($cp <= 0x10ffff) && !($cp >= 0xd800 && $cp <= 0xdfff)) ? mb_chr($cp, 'UTF-8') : '';
			}, $decoded) ?? $decoded;

			if ($decoded === $url)
			{
				break;
			}

			$url = $decoded;
		}

		if (($i === 5) && (html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $url))
		{
			return null;
		}

		// Control characters, spaces, DEL, NBSP-like invisible characters and the BOM are not part of any URL: a browser drops the tabs and breaks
		$url = preg_replace('/[\x00-\x20\x7f-\x9f\x{00a0}\x{1680}\x{2000}-\x{200f}\x{2028}-\x{202f}\x{205f}-\x{206f}\x{3000}\x{feff}]+/u', '', $url);

		if (!is_string($url) || ($url === '') || (strlen($url) > 2048) || preg_match('/["\'<>\\\\`]/', $url))
		{
			return null;
		}

		if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $m))
		{
			return in_array(strtolower($m[1]), $schemes, true) ? $url : null;
		}

		// No scheme: a colon before the first / ? # would still be read as a scheme by a browser
		$head = preg_split('/[\/?#]/', $url, 2)[0];

		return (strpos($head, ':') === false) ? $url : null;
	}

	/**
	 * Replace a substring inside a string
	 *
	 * @param   int     $from  Starting position
	 * @param   int     $to    Ending position
	 * @param   string  $new   Text to replace with
	 * @param   string  $old   Text to find and replace with $new
	 *
	 * @return   string
	 * @since    1.0.0
	 */
	private static function replace(int $from, int $to, string $new, string $old): string
	{
		if ($from > $to)
		{
			$x    = $from;
			$from = $to;
			$to   = $x;
		}

		if ($from == 0)
		{
			return $new . substr($old, $to + 1);
		}
		else
		{
			return substr($old, 0, $from) . $new . substr($old, $to + 1);
		}
	}

	/**
	 * Find the closing BBcode tag.
	 *
	 * @param   string  $tag     The tag to look for.
	 * @param   string  $text    The text to look the end tag in.
	 * @param   int     $offset  Offset from the beginning $text the search will take place.
	 *
	 * @return   array|false[]
	 * @since    1.0.0
	 */
	private static function findEndTag(string $tag, string $text, int $offset = 0)
	{
		$ret = [false, false];

		$rest         = substr($text, $offset + 1);
		$bracketStart = strpos($text, '[');

		while ($bracketStart !== false)
		{
			$bracketEnd = strpos($text, ']', $bracketStart);

			if ($bracketEnd === false)
			{
				break;
			}

			$tagData  = substr($text, $bracketStart + 1, $bracketEnd - $bracketStart - 1);
			$tagData  = trim($tagData);
			$tagParts = explode('=', $tagData, 2);
			$newTag   = strtoupper($tagParts[0]);

			// Check if we have /TAG
			$found = false;

			if (substr($newTag, 0, 1) == '/')
			{
				$tagName = trim(substr($newTag, 1));
				$found   = strtoupper($tag) == $tagName;
			}

			if ($found)
			{
				$ret          = [$bracketStart, $bracketEnd];
				$bracketStart = false;
			}
			else
			{
				$bracketStart = strpos($text, '[', $bracketStart + 1);
			}
		}

		return $ret;
	}
}
