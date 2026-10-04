<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Opciones modernas (0.6.25). Sin estilos ni scripts en linea; toda la salida escapada. Los ajustes se guardan al instante
 * por AJAX (settings.js -> task=settings.save) y la pantalla clasica de Joomla sigue como respaldo.
 */

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\PanelIcons as I;
use Akeeba\Component\Engage\Administrator\Helper\PanelUi;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Akeeba\Component\Engage\Administrator\View\Settings\HtmlView $this */

$e   = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = static fn(string $u): string => $e(Route::_($u, false));
$n   = 0;

/** Etiqueta traducida de una opcion; si no hay traduccion, el propio texto. */
$opt = static function (string $label): string {
	$t = Text::_($label);

	return $t;
};

/**
 * 0.6.27: clase de ancho de un control segmentado, segun el ANCHO REAL de sus etiquetas (no el numero de opciones; en 0.6.26 seis cifras de un
 * caracter se apilaban en dos filas desiguales). "short": suma <= 32 caracteres y ninguna > 12 (queda en la fila, compacto y en una sola fila);
 * "wide": alguna > 24 o suma > 40 (no cabe junto a la etiqueta: fila en pila, control debajo a todo el ancho); si no, "" (medio).
 * Es un criterio del servidor: no depende de JS.
 */
$segWidth = static function (string $ctl, array $options) use ($opt): string {
	if ($ctl !== 'segmented') {
		return '';
	}

	$total = 0;
	$max   = 0;

	foreach ($options as $ol) {
		$len   = mb_strlen($opt((string) $ol));
		$total += $len;
		$max   = max($max, $len);
	}

	if ($max > 24 || $total > 40) {
		return ' eg-row--wide';
	}

	return ($total <= 32 && $max <= 12) ? ' eg-row--short' : '';
};

/** Ayuda breve: texto plano (sin HTML), de hasta 200 caracteres; el resto, en "Mas informacion". */
$plain = static function (string $html): string {
	$t = str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], ' ', $html);
	$t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');

	return trim((string) preg_replace('/\s+/u', ' ', $t));
};
$shorten = static function (string $t, int $max = 200): string {
	if (mb_strlen($t) <= $max)
	{
		return $t;
	}

	$cut = mb_substr($t, 0, $max);
	$dot = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '? '));

	return $dot > 60 ? mb_substr($cut, 0, $dot + 1) : rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')), ',;:') . '…';
};

$groupTitle = ['akismet' => 'COM_ENGAGE_SET_GROUP_AKISMET', 'email' => 'COM_ENGAGE_SET_GROUP_EMAIL', 'gravatar' => 'COM_ENGAGE_SET_GROUP_GRAVATAR'];
$vals       = $this->values;
$themeVal   = (string) ($vals['com_engage']['theme'] ?? 'classic');
$cur        = static function (array $def) use ($vals): string {
	$v = $vals[$def['scope']][$def['key']] ?? $def['default'];

	if (is_bool($v))
	{
		$v = $v ? '1' : '0';
	}

	$v = (string) $v;

	return ($def['control'] === 'switch' && $v === '') ? '0' : $v;
};
$pv = static fn(string $k, string $d): string => (string) ($vals['com_engage'][$k] ?? $d);

echo I::sprite();
?>
<div class="eg-admin eg-admin--settings" id="eg-admin" data-eg-view="settings">

	<header class="eg-head">
		<div class="eg-brand">
			<span class="eg-logo" aria-hidden="true"><?= I::icon('sliders') ?></span>
			<div class="eg-brand__text">
				<p class="eg-brand__name"><?= $e(Text::_('COM_ENGAGE_SET_TITLE')) ?></p>
				<p class="eg-brand__sub"><?= $e(Text::_('COM_ENGAGE_SET_SUBTITLE')) ?></p>
			</div>
		</div>
		<div class="eg-head__tools">
			<a class="eg-chip eg-chip--plain" href="<?= $url($this->classicUrl) ?>">
				<?= I::icon('wrench') ?><span><?= $e(Text::_('COM_ENGAGE_SET_CLASSIC')) ?></span>
			</a>
			<?= PanelUi::themeSwitcher() ?>
		</div>
	</header>

	<div class="eg-vh" id="eg-live" role="status" aria-live="polite" aria-atomic="true"></div>

	<div class="eg-set" data-eg-set>
		<div class="eg-set__nav" role="tablist" aria-orientation="vertical" aria-label="<?= $e(Text::_('COM_ENGAGE_SET_NAV')) ?>">
			<?php foreach ($this->sections as $sec) : $on = $sec['id'] === $this->active; ?>
				<button type="button" class="eg-navitem" role="tab" id="eg-tab-<?= $e($sec['id']) ?>" aria-controls="eg-panel-<?= $e($sec['id']) ?>"
						aria-selected="<?= $e($on ? 'true' : 'false') ?>" tabindex="<?= $e($on ? '0' : '-1') ?>" data-eg-tab="<?= $e($sec['id']) ?>">
					<span class="eg-icobox eg-icobox--sm eg-icobox--<?= $e($sec['tone']) ?>"><?= I::icon($sec['icon']) ?></span>
					<span class="eg-navitem__t"><?= $e(Text::_('COM_ENGAGE_SET_SEC_' . strtoupper($sec['id']))) ?></span>
				</button>
			<?php endforeach; ?>
		</div>

		<div class="eg-set__body">
			<?php foreach ($this->sections as $sec) : $sid = strtoupper($sec['id']); ?>
				<section class="eg-card eg-panel" role="tabpanel" id="eg-panel-<?= $e($sec['id']) ?>" aria-labelledby="eg-tab-<?= $e($sec['id']) ?>"
						 tabindex="0"<?= $e($sec['id'] === $this->active ? '' : ' hidden') ?>>
					<div class="eg-card__head">
						<h2 class="eg-h2"><?= I::icon($sec['icon']) ?><?= $e(Text::_('COM_ENGAGE_SET_SEC_' . $sid)) ?></h2>
					</div>
					<p class="eg-sec__desc"><?= $e(Text::_('COM_ENGAGE_SET_SEC_' . $sid . '_DESC')) ?></p>

					<?php if ($sec['id'] === 'design') : ?>
						<div class="eg-preview" data-eg-preview data-theme="<?= $e($themeVal) ?>" data-indent="<?= $e($pv('reply_indent', 'medium')) ?>"
							 data-quote="<?= $e($pv('reply_show_quote', '1')) ?>" data-style="<?= $e($pv('reply_style', 'line')) ?>"
							 role="img" aria-label="<?= $e(Text::_('COM_ENGAGE_SET_PREVIEW')) ?>">
							<p class="eg-preview__cap"><?= $e(Text::_('COM_ENGAGE_SET_PREVIEW')) ?> <span><?= $e(Text::_('COM_ENGAGE_SET_PREVIEW_HINT')) ?></span></p>
							<div class="eg-pv-c">
								<span class="eg-pv-av eg-pv-av--a" aria-hidden="true">A</span>
								<div class="eg-pv-b"><p class="eg-pv-n"><?= $e(Text::_('COM_ENGAGE_SET_PREVIEW_NAME1')) ?></p><p class="eg-pv-t"><?= $e(Text::_('COM_ENGAGE_SET_PREVIEW_TEXT1')) ?></p><p class="eg-pv-r"><?= $e(Text::_('COM_ENGAGE_SET_PREVIEW_REPLY')) ?></p></div>
							</div>
							<div class="eg-pv-c eg-pv-c--reply">
								<span class="eg-pv-av eg-pv-av--b" aria-hidden="true">D</span>
								<div class="eg-pv-b">
									<p class="eg-pv-q"><?= $e(Text::sprintf('COM_ENGAGE_SET_PREVIEW_REPLYTO', Text::_('COM_ENGAGE_SET_PREVIEW_NAME1'))) ?></p>
									<p class="eg-pv-n"><?= $e(Text::_('COM_ENGAGE_SET_PREVIEW_NAME2')) ?></p><p class="eg-pv-t"><?= $e(Text::_('COM_ENGAGE_SET_PREVIEW_TEXT2')) ?></p>
								</div>
							</div>
						</div>
					<?php endif; ?>

					<?php if (($sec['link'] ?? '') === 'permissions') : ?>
						<p class="eg-sec__text"><?= $e(Text::_('COM_ENGAGE_SET_PERM_TEXT')) ?></p>
						<p><a class="eg-btn eg-btn--primary" href="<?= $url($this->permissionsUrl) ?>"><?= I::icon('lock') ?><?= $e(Text::_('COM_ENGAGE_SET_PERM_BTN')) ?></a></p>
					<?php endif; ?>

					<?php foreach ($sec['groups'] as $g) : ?>
						<?php if ($g['key'] !== '') : ?>
							<h3 class="eg-group"><?= $e(Text::_($groupTitle[$g['key']] ?? 'COM_ENGAGE')) ?></h3>
							<?php if ($g['state'] && !$g['state']['enabled']) : ?>
								<p class="eg-note eg-note--warn"><?= I::icon('warn') ?><span><?= $e(Text::_('COM_ENGAGE_SET_PLUGIN_OFF')) ?>
									<a href="<?= $url('index.php?option=com_plugins&task=plugin.edit&extension_id=' . (int) $g['state']['id']) ?>"><?= $e(Text::_('COM_ENGAGE_PANEL_FIX_PLUGIN')) ?></a></span></p>
							<?php endif; ?>
						<?php endif; ?>
						<div class="eg-rows">
							<?php foreach ($g['rows'] as $def) :
								$n++;
								$fid   = 'eg-f-' . $n;
								$value = $cur($def);
								$ctl   = $def['control'];
								$options = $def['options'];

								if ($def['type'] === 'plugins')
								{
									$options = ['' => 'JGLOBAL_USE_GLOBAL'] + $options;

									foreach ($this->captcha as $el)
									{
										$tx = Text::_('PLG_CAPTCHA_' . strtoupper($el));
										$options[$el] = $tx === 'PLG_CAPTCHA_' . strtoupper($el) ? $el : $tx;
									}
								}
								elseif ($def['useglobal'])
								{
									$options = ['' => 'JGLOBAL_USE_GLOBAL'] + $options;
								}
								?>
								<div class="eg-row eg-row--<?= $e($ctl) ?><?= $e($segWidth($ctl, $options)) ?>" data-eg-row data-scope="<?= $e($def['scope']) ?>" data-key="<?= $e($def['key']) ?>"
									 data-control="<?= $e($ctl) ?>" data-value="<?= $e($value) ?>" data-eg-showon="<?= $e($def['showon']) ?>">
									<div class="eg-row__text">
										<?php if (in_array($ctl, ['text', 'number', 'textarea', 'select'], true)) : ?>
											<label class="eg-row__label" id="<?= $e($fid) ?>-l" for="<?= $e($fid) ?>"><?= $e(Text::_($def['label'])) ?></label>
										<?php else : ?>
											<span class="eg-row__label" id="<?= $e($fid) ?>-l"><?= $e(Text::_($def['label'])) ?></span>
										<?php endif; ?>
										<?php $full = $plain(Text::_($def['desc'])); $short = $shorten($full); ?>
										<p class="eg-row__help" id="<?= $e($fid) ?>-h"><?= $e($short) ?></p>
										<?php if ($short !== $full) : ?>
											<details class="eg-more"><summary><?= $e(Text::_('COM_ENGAGE_SET_MORE')) ?></summary><p><?= $e($full) ?></p></details>
										<?php endif; ?>
										<p class="eg-row__err" id="<?= $e($fid) ?>-e" role="alert" hidden></p>
									</div>
									<div class="eg-row__ctl">
										<?php if ($ctl === 'switch') : ?>
											<button type="button" class="eg-switch" role="switch" id="<?= $e($fid) ?>" aria-checked="<?= $e($value === '1' ? 'true' : 'false') ?>"
													aria-labelledby="<?= $e($fid) ?>-l" aria-describedby="<?= $e($fid) ?>-h"><span class="eg-switch__knob" aria-hidden="true"></span></button>
										<?php elseif ($ctl === 'segmented') : ?>
											<div class="eg-seg" role="radiogroup" aria-labelledby="<?= $e($fid) ?>-l" aria-describedby="<?= $e($fid) ?>-h">
												<?php foreach ($options as $ov => $ol) : $ov = (string) $ov; ?>
													<button type="button" class="eg-seg__btn" role="radio" aria-checked="<?= $e($ov === $value ? 'true' : 'false') ?>"
															tabindex="<?= $e($ov === $value ? '0' : '-1') ?>" data-value="<?= $e($ov) ?>"><?= $e($opt($ol)) ?></button>
												<?php endforeach; ?>
											</div>
										<?php elseif ($ctl === 'cards') : ?>
											<div class="eg-cards" role="radiogroup" aria-labelledby="<?= $e($fid) ?>-l" aria-describedby="<?= $e($fid) ?>-h">
												<?php foreach ($options as $ov => $ol) : $ov = (string) $ov; ?>
													<button type="button" class="eg-themecard" role="radio" aria-checked="<?= $e($ov === $value ? 'true' : 'false') ?>"
															tabindex="<?= $e($ov === $value ? '0' : '-1') ?>" data-value="<?= $e($ov) ?>">
														<span class="eg-mini eg-mini--<?= $e($ov) ?>" aria-hidden="true"><span class="eg-mini__av"></span><span class="eg-mini__l1"></span><span class="eg-mini__l2"></span><span class="eg-mini__b"></span></span>
														<span class="eg-themecard__t"><?= $e($opt($ol)) ?></span>
													</button>
												<?php endforeach; ?>
											</div>
										<?php elseif ($ctl === 'select') : ?>
											<select class="eg-input eg-input--select" id="<?= $e($fid) ?>" aria-describedby="<?= $e($fid) ?>-h <?= $e($fid) ?>-e">
												<?php foreach ($options as $ov => $ol) : $ov = (string) $ov; ?>
													<option value="<?= $e($ov) ?>"<?= $e($ov === $value ? ' selected' : '') ?>><?= $e($opt($ol)) ?></option>
												<?php endforeach; ?>
											</select>
										<?php elseif ($ctl === 'number') : ?>
											<input class="eg-input eg-input--number" type="number" inputmode="numeric" id="<?= $e($fid) ?>" value="<?= $e($value) ?>" min="<?= (int) $def['min'] ?>"
												   max="<?= (int) min($def['max'], 2147483647) ?>" step="<?= (int) $def['step'] ?>" aria-describedby="<?= $e($fid) ?>-h <?= $e($fid) ?>-e">
										<?php elseif ($ctl === 'textarea') : ?>
											<textarea class="eg-input eg-input--area" id="<?= $e($fid) ?>" rows="4" maxlength="<?= (int) ($def['maxlength'] ?: 4000) ?>" spellcheck="false"
													  aria-describedby="<?= $e($fid) ?>-h <?= $e($fid) ?>-e"><?= $e($value) ?></textarea>
										<?php else : ?>
											<input class="eg-input" type="text" id="<?= $e($fid) ?>" value="<?= $e($value) ?>" maxlength="<?= (int) ($def['maxlength'] ?: 255) ?>"
												   placeholder="<?= $e($def['hint']) ?>" autocomplete="off" spellcheck="false" aria-describedby="<?= $e($fid) ?>-h <?= $e($fid) ?>-e">
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>

					<?php if ($sec['id'] === 'advanced') : ?>
						<p class="eg-sec__text"><?= $e(Text::_('COM_ENGAGE_SET_CLASSIC_HINT')) ?></p>
						<p><a class="eg-btn" href="<?= $url($this->classicUrl) ?>"><?= I::icon('wrench') ?><?= $e(Text::_('COM_ENGAGE_SET_CLASSIC')) ?></a></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="eg-toast" id="eg-toast" hidden aria-hidden="true"></div>
</div>
