<?php
/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 *
 * Panel de control (0.6.24). Sin estilos en linea, sin scripts en linea, sin recursos externos; toda la salida escapada.
 */

defined('_JEXEC') or die;

use Akeeba\Component\Engage\Administrator\Helper\PanelData;
use Akeeba\Component\Engage\Administrator\Helper\PanelHealth;
use Akeeba\Component\Engage\Administrator\Helper\PanelIcons as I;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Akeeba\Component\Engage\Administrator\View\Controlpanel\HtmlView $this */

$e   = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = static fn(string $u): string => $e(Route::_($u, false));

$s      = $this->stats;
$chart  = $this->chart;
$health = $this->health;
$can    = $this->can;
$es     = str_starts_with((string) Factory::getApplication()->getLanguage()->getTag(), 'es');
$n      = static fn($v): string => number_format((int) $v, 0, $es ? ',' : '.', $es ? '.' : ',');

$panelUrl      = 'index.php?option=com_engage&view=controlpanel';
$commentsUrl   = 'index.php?option=com_engage&view=comments';
$settingsUrl   = 'index.php?option=com_config&view=component&component=com_engage&return=' . base64_encode($panelUrl);
$healthText    = ['ok' => Text::_('COM_ENGAGE_PANEL_LEVEL_OK'), 'warn' => Text::_('COM_ENGAGE_PANEL_LEVEL_WARN'), 'bad' => Text::_('COM_ENGAGE_PANEL_LEVEL_BAD')];
$stateInfo     = [
	1  => ['ok', 'COM_ENGAGE_PANEL_STATE_PUBLISHED'],
	0  => ['warn', 'COM_ENGAGE_PANEL_STATE_PENDING'],
	-3 => ['bad', 'COM_ENGAGE_PANEL_STATE_SPAM'],
];

$fixUrl = function (array $fix) use ($settingsUrl): string {
	switch ($fix['kind'] ?? '')
	{
		case 'plugin':
			return 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . (int) $fix['id'];
		case 'updatesites':
			return 'index.php?option=com_installer&view=updatesites';
		case 'settings':
			return $settingsUrl;
	}

	return '';
};

$summaryKey = ['ok' => 'COM_ENGAGE_PANEL_HEALTH_SUMMARY_OK', 'warn' => 'COM_ENGAGE_PANEL_HEALTH_SUMMARY_WARN', 'bad' => 'COM_ENGAGE_PANEL_HEALTH_SUMMARY_BAD'][$health['level']];
$quickToken = HTMLHelper::_('form.token');

echo I::sprite();
?>
<div class="eg-admin" id="eg-admin" data-eg-view="controlpanel">

	<header class="eg-head">
		<div class="eg-brand">
			<span class="eg-logo" aria-hidden="true"><?= I::icon('chat') ?></span>
			<div class="eg-brand__text">
				<p class="eg-brand__name"><?= $e(Text::_('COM_ENGAGE')) ?></p>
				<p class="eg-brand__sub"><?= $e(Text::_('COM_ENGAGE_PANEL_SUBTITLE')) ?></p>
			</div>
		</div>
		<div class="eg-head__tools">
			<a class="eg-chip eg-chip--<?= $e($health['level']) ?>" href="#eg-health">
				<?= I::icon($health['level']) ?>
				<span><?= $e(Text::sprintf($summaryKey, $health['counts']['warn'], $health['counts']['bad'])) ?></span>
			</a>
			<div class="eg-theme" role="radiogroup" aria-label="<?= $e(Text::_('COM_ENGAGE_PANEL_THEME_LABEL')) ?>">
				<?php foreach (['light' => 'sun', 'mid' => 'dim', 'dark' => 'moon', 'auto' => 'half'] as $t => $icon) : ?>
					<button type="button" class="eg-theme__btn" role="radio" aria-checked="false" tabindex="-1"
							data-eg-theme-set="<?= $e($t) ?>">
						<?= I::icon($icon) ?>
						<span><?= $e(Text::_('COM_ENGAGE_PANEL_THEME_' . strtoupper($t))) ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
	</header>

	<div class="eg-vh" id="eg-live" role="status" aria-live="polite" aria-atomic="true"></div>

	<!-- Accesos rapidos -->
	<section class="eg-section" aria-labelledby="eg-h-quick">
		<h2 class="eg-vh" id="eg-h-quick"><?= $e(Text::_('COM_ENGAGE_PANEL_QUICK')) ?></h2>
		<ul class="eg-tiles">
			<li>
				<a class="eg-tile" href="<?= $url($commentsUrl) ?>">
					<span class="eg-icobox eg-icobox--blue"><?= I::icon('chat') ?></span>
					<span class="eg-tile__label"><?= $e(Text::_('COM_ENGAGE_TITLE_COMMENTS')) ?></span>
					<span class="eg-tile__hint"><?= $e(Text::_('COM_ENGAGE_PANEL_TILE_COMMENTS_HINT')) ?></span>
					<?php if ($s['pending'] > 0 || $s['spam'] > 0) : ?>
						<span class="eg-badges">
							<?php if ($s['pending'] > 0) : ?>
								<span class="eg-badge eg-badge--warn"><span class="eg-vh"><?= $e(Text::_('COM_ENGAGE_PANEL_STATE_PENDING')) ?>: </span><?= $e($n($s['pending'])) ?></span>
							<?php endif; ?>
							<?php if ($s['spam'] > 0) : ?>
								<span class="eg-badge eg-badge--bad"><span class="eg-vh"><?= $e(Text::_('COM_ENGAGE_PANEL_STATE_SPAM')) ?>: </span><?= $e($n($s['spam'])) ?></span>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				</a>
			</li>
			<li>
				<a class="eg-tile" href="<?= $url('index.php?option=com_engage&view=emailtemplates') ?>">
					<span class="eg-icobox eg-icobox--violet"><?= I::icon('mail') ?></span>
					<span class="eg-tile__label"><?= $e(Text::_('COM_ENGAGE_TITLE_EMAILTEMPLATES')) ?></span>
					<span class="eg-tile__hint"><?= $e(Text::_('COM_ENGAGE_PANEL_TILE_EMAIL_HINT')) ?></span>
				</a>
			</li>
			<?php if ($can['admin']) : ?>
				<li>
					<a class="eg-tile" href="<?= $url($settingsUrl) ?>">
						<span class="eg-icobox eg-icobox--green"><?= I::icon('sliders') ?></span>
						<span class="eg-tile__label"><?= $e(Text::_('COM_ENGAGE_PANEL_TILE_SETTINGS')) ?></span>
						<span class="eg-tile__hint"><?= $e(Text::_('COM_ENGAGE_PANEL_TILE_SETTINGS_HINT')) ?></span>
					</a>
				</li>
				<li>
					<a class="eg-tile" href="<?= $url($settingsUrl . '#permissions') ?>">
						<span class="eg-icobox eg-icobox--orange"><?= I::icon('lock') ?></span>
						<span class="eg-tile__label"><?= $e(Text::_('COM_ENGAGE_PANEL_TILE_PERMISSIONS')) ?></span>
						<span class="eg-tile__hint"><?= $e(Text::_('COM_ENGAGE_PANEL_TILE_PERMISSIONS_HINT')) ?></span>
					</a>
				</li>
			<?php endif; ?>
			<li>
				<a class="eg-tile" href="#eg-about">
					<span class="eg-icobox eg-icobox--slate"><?= I::icon('info') ?></span>
					<span class="eg-tile__label"><?= $e(Text::_('COM_ENGAGE_PANEL_TILE_ABOUT')) ?></span>
					<span class="eg-tile__hint"><?= $e(Text::sprintf('COM_ENGAGE_PANEL_TILE_ABOUT_HINT', $this->installedVersion ?: '3.4.2.1')) ?></span>
				</a>
			</li>
		</ul>
	</section>

	<div class="eg-cols">
		<div class="eg-col eg-col--main">

			<!-- Numeros y grafico -->
			<section class="eg-card" aria-labelledby="eg-h-stats">
				<div class="eg-card__head">
					<h2 class="eg-h2" id="eg-h-stats"><?= I::icon('chart') ?><?= $e(Text::_('COM_ENGAGE_PANEL_STATS')) ?></h2>
				</div>
				<dl class="eg-stats">
					<?php
					$cards = [
						['total', 'COM_ENGAGE_PANEL_STAT_TOTAL', $s['total'], 'blue'],
						['published', 'COM_ENGAGE_PANEL_STAT_PUBLISHED', $s['published'], 'green'],
						['pending', 'COM_ENGAGE_PANEL_STAT_PENDING', $s['pending'], 'orange'],
						['spam', 'COM_ENGAGE_PANEL_STAT_SPAM', $s['spam'], 'red'],
						['last7', 'COM_ENGAGE_PANEL_STAT_LAST7', $s['last7'], 'violet'],
						['last30', 'COM_ENGAGE_PANEL_STAT_LAST30', $s['last30'], 'slate'],
					];
					foreach ($cards as [$id, $key, $val, $tone]) : ?>
						<div class="eg-stat eg-stat--<?= $e($tone) ?>">
							<dt><?= $e(Text::_($key)) ?></dt>
							<dd><?= $e($n($val)) ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>

				<figure class="eg-chart" id="eg-chart">
					<figcaption class="eg-chart__cap">
						<span class="eg-chart__title" id="eg-chart-title"><?= $e(Text::_('COM_ENGAGE_PANEL_CHART_TITLE')) ?></span>
						<span class="eg-chart__sum"><?= $e(Text::sprintf('COM_ENGAGE_PANEL_CHART_SUMMARY', $n($chart['total']), $n($chart['peak']))) ?></span>
					</figcaption>
					<div class="eg-chart__plot">
						<svg class="eg-chart__svg" viewBox="0 0 <?= (int) $chart['width'] ?> <?= (int) $chart['height'] ?>" role="img"
							 aria-labelledby="eg-chart-title eg-chart-desc" preserveAspectRatio="xMidYMid meet" focusable="false">
							<desc id="eg-chart-desc"><?= $e(Text::sprintf('COM_ENGAGE_PANEL_CHART_DESC', $n($chart['total']), $n($chart['peak']))) ?></desc>
							<?php foreach ($chart['grid'] as $g) : ?>
								<line class="eg-chart__grid<?= $e($g['v'] === 0 ? ' eg-chart__grid--base' : '') ?>" x1="0" x2="<?= (int) $chart['width'] ?>" y1="<?= $e($g['y']) ?>" y2="<?= $e($g['y']) ?>"/>
							<?php endforeach; ?>
							<?php foreach ($chart['bars'] as $b) : ?>
								<g class="eg-chart__col" data-date="<?= $e(HTMLHelper::_('date', $b['date'] . ' 12:00:00', Text::_('DATE_FORMAT_LC4'), false)) ?>" data-n="<?= (int) $b['n'] ?>">
									<rect class="eg-chart__hit" x="<?= $e($b['hitX']) ?>" y="0" width="<?= $e($b['hitW']) ?>" height="<?= (int) $chart['height'] ?>"/>
									<?php if ($b['path'] !== '') : ?>
										<path class="eg-chart__bar" d="<?= $e($b['path']) ?>"/>
									<?php endif; ?>
								</g>
							<?php endforeach; ?>
						</svg>
						<div class="eg-chart__tip" id="eg-chart-tip" hidden></div>
					</div>
					<div class="eg-chart__axis" aria-hidden="true">
						<span><?= $e(HTMLHelper::_('date', $chart['bars'][0]['date'] . ' 12:00:00', Text::_('DATE_FORMAT_LC4'), false)) ?></span>
						<span><?= $e(Text::sprintf('COM_ENGAGE_PANEL_CHART_MAX', $n($chart['max']))) ?></span>
						<span><?= $e(Text::_('COM_ENGAGE_PANEL_CHART_TODAY')) ?></span>
					</div>
					<details class="eg-chart__table">
						<summary><?= $e(Text::_('COM_ENGAGE_PANEL_CHART_TABLE')) ?></summary>
						<table class="eg-table">
							<caption class="eg-vh"><?= $e(Text::_('COM_ENGAGE_PANEL_CHART_TITLE')) ?></caption>
							<thead><tr><th scope="col"><?= $e(Text::_('COM_ENGAGE_PANEL_CHART_COL_DATE')) ?></th><th scope="col"><?= $e(Text::_('COM_ENGAGE_PANEL_CHART_COL_N')) ?></th></tr></thead>
							<tbody>
								<?php foreach (array_reverse($chart['bars']) as $b) : ?>
									<tr><td><?= $e(HTMLHelper::_('date', $b['date'] . ' 12:00:00', Text::_('DATE_FORMAT_LC4'), false)) ?></td><td><?= $e($n($b['n'])) ?></td></tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				</figure>
			</section>

			<!-- Ultimos comentarios -->
			<section class="eg-card" aria-labelledby="eg-h-latest">
				<div class="eg-card__head">
					<h2 class="eg-h2" id="eg-h-latest"><?= I::icon('chat') ?><?= $e(Text::_('COM_ENGAGE_PANEL_LATEST')) ?></h2>
					<a class="eg-link" href="<?= $url($commentsUrl) ?>"><?= $e(Text::_('COM_ENGAGE_PANEL_LATEST_ALL')) ?><?= I::icon('chevron') ?></a>
				</div>
				<?php if (!$this->latest) : ?>
					<p class="eg-empty"><?= $e(Text::_('COM_ENGAGE_PANEL_LATEST_EMPTY')) ?></p>
				<?php else : ?>
					<ul class="eg-feed">
						<?php foreach ($this->latest as $c) :
							[$tone, $stateKey] = $stateInfo[$c['enabled']] ?? ['warn', 'COM_ENGAGE_PANEL_STATE_OTHER'];
							$who      = $c['author'] !== '' ? $c['author'] : Text::_('COM_ENGAGE_PANEL_ANON');
							$initial  = mb_strtoupper(mb_substr(trim($who), 0, 1)) ?: '?';
							$when     = HTMLHelper::_('date.relative', $c['created']);
							$whenFull = HTMLHelper::_('date', $c['created'], Text::_('DATE_FORMAT_LC6'));
							$article  = $c['title'] !== '' ? $c['title'] : Text::_('COM_ENGAGE_PANEL_NO_TITLE');
							?>
							<li class="eg-feed__item">
								<span class="eg-avatar eg-avatar--<?= $e($tone) ?>" aria-hidden="true"><?= $e($initial) ?></span>
								<div class="eg-feed__main">
									<p class="eg-feed__meta">
										<strong><?= $e($who) ?></strong>
										<span class="eg-pill eg-pill--<?= $e($tone) ?>"><?= $e(Text::_($stateKey)) ?></span>
										<time datetime="<?= $e($c['created']) ?>" title="<?= $e($whenFull) ?>"><?= $e($when) ?></time>
									</p>
									<p class="eg-feed__text"><?= $e($c['excerpt']) ?></p>
									<p class="eg-feed__art">
										<a href="<?= $url($commentsUrl . '&filter[asset_id]=' . (int) $c['asset_id']) ?>"><?= $e($article) ?></a>
									</p>
								</div>
								<?php if ($can['state'] || $can['delete']) : ?>
									<div class="eg-feed__actions" role="group" aria-label="<?= $e(Text::sprintf('COM_ENGAGE_PANEL_ACTIONS_FOR', $who)) ?>">
										<?php if ($can['state'] && $c['enabled'] !== 1) : ?>
											<button type="button" class="eg-iconbtn eg-iconbtn--ok" data-eg-act="publish" data-eg-id="<?= (int) $c['id'] ?>" data-eg-who="<?= $e($who) ?>" data-eg-excerpt="<?= $e($c['excerpt']) ?>">
												<?= I::icon('check') ?><span class="eg-vh"><?= $e(Text::sprintf('COM_ENGAGE_PANEL_ACT_PUBLISH_FOR', $who)) ?></span>
											</button>
										<?php endif; ?>
										<?php if ($can['state'] && $c['enabled'] !== -3) : ?>
											<button type="button" class="eg-iconbtn eg-iconbtn--warn" data-eg-act="spam" data-eg-id="<?= (int) $c['id'] ?>" data-eg-who="<?= $e($who) ?>" data-eg-excerpt="<?= $e($c['excerpt']) ?>">
												<?= I::icon('flag') ?><span class="eg-vh"><?= $e(Text::sprintf('COM_ENGAGE_PANEL_ACT_SPAM_FOR', $who)) ?></span>
											</button>
										<?php endif; ?>
										<?php if ($can['delete']) : ?>
											<button type="button" class="eg-iconbtn eg-iconbtn--bad" data-eg-act="delete" data-eg-id="<?= (int) $c['id'] ?>" data-eg-who="<?= $e($who) ?>" data-eg-excerpt="<?= $e($c['excerpt']) ?>">
												<?= I::icon('trash') ?><span class="eg-vh"><?= $e(Text::sprintf('COM_ENGAGE_PANEL_ACT_DELETE_FOR', $who)) ?></span>
											</button>
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		</div>

		<div class="eg-col eg-col--side">

			<!-- Estado y recomendaciones -->
			<section class="eg-card" aria-labelledby="eg-h-health" id="eg-health">
				<div class="eg-card__head">
					<h2 class="eg-h2" id="eg-h-health"><?= I::icon('shield') ?><?= $e(Text::_('COM_ENGAGE_PANEL_HEALTH')) ?></h2>
				</div>
				<p class="eg-health__sum eg-health__sum--<?= $e($health['level']) ?>">
					<?= I::icon($health['level']) ?>
					<span><?= $e(Text::sprintf($summaryKey, $health['counts']['warn'], $health['counts']['bad'])) ?></span>
				</p>
				<ul class="eg-health">
					<?php foreach ($health['items'] as $it) :
						$id    = strtoupper($it['id']);
						$args  = $it['args'];
						$state = strtoupper((string) ($args['state'] ?? 'ok'));
						unset($args['state']);
						$text  = Text::sprintf('COM_ENGAGE_PANEL_H_' . $id . '_' . $state, ...array_values($args));
						$fix   = $it['fix'] ? $fixUrl($it['fix']) : '';
						?>
						<li class="eg-health__item eg-health__item--<?= $e($it['level']) ?>">
							<span class="eg-light eg-light--<?= $e($it['level']) ?>"><?= I::icon($it['level']) ?><span class="eg-vh"><?= $e($healthText[$it['level']]) ?>: </span></span>
							<div class="eg-health__body">
								<p class="eg-health__title"><?= $e(Text::_('COM_ENGAGE_PANEL_H_' . $id . '_TITLE')) ?></p>
								<p class="eg-health__text"><?= $e($text) ?></p>
								<?php if ($fix !== '') : ?>
									<a class="eg-btn eg-btn--small" href="<?= $url($fix) ?>">
										<?= $e(Text::_('COM_ENGAGE_PANEL_FIX_' . strtoupper($it['fix']['kind']))) ?><?= I::icon('chevron') ?>
									</a>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<!-- Mas comentados -->
			<section class="eg-card" aria-labelledby="eg-h-top">
				<div class="eg-card__head">
					<h2 class="eg-h2" id="eg-h-top"><?= I::icon('heart') ?><?= $e(Text::_('COM_ENGAGE_PANEL_TOP')) ?></h2>
				</div>
				<?php if (!$s['top']) : ?>
					<p class="eg-empty"><?= $e(Text::_('COM_ENGAGE_PANEL_TOP_EMPTY')) ?></p>
				<?php else : ?>
					<ol class="eg-top">
						<?php foreach ($s['top'] as $t) : ?>
							<li>
								<a href="<?= $url($commentsUrl . '&filter[asset_id]=' . (int) $t['asset_id']) ?>"><?= $e($t['title'] !== '' ? $t['title'] : Text::_('COM_ENGAGE_PANEL_NO_TITLE')) ?></a>
								<span class="eg-count"><?= $e($n($t['n'])) ?><span class="eg-vh"> <?= $e(Text::_('COM_ENGAGE_PANEL_TOP_COUNT')) ?></span></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</section>
		</div>
	</div>

	<!-- Acerca de -->
	<section class="eg-card eg-about" id="eg-about" aria-labelledby="eg-h-about">
		<div class="eg-card__head">
			<h2 class="eg-h2" id="eg-h-about"><?= I::icon('info') ?><?= $e(Text::_('COM_ENGAGE_PANEL_ABOUT')) ?></h2>
		</div>
		<dl class="eg-about__facts">
			<div><dt><?= $e(Text::_('COM_ENGAGE_PANEL_ABOUT_PACKAGE')) ?></dt><dd><?= $e($this->installedVersion ?: '3.4.2.1') ?></dd></div>
			<div><dt><?= $e(Text::_('COM_ENGAGE_PANEL_ABOUT_FORK')) ?></dt><dd><?= $e(PanelData::FORK_VERSION) ?></dd></div>
			<div><dt><?= $e(Text::_('COM_ENGAGE_PANEL_ABOUT_LICENSE')) ?></dt><dd><?= $e(Text::_('COM_ENGAGE_PANEL_ABOUT_LICENSE_VALUE')) ?></dd></div>
			<div><dt><?= $e(Text::_('COM_ENGAGE_PANEL_ABOUT_AUTHOR')) ?></dt><dd><?= $e(Text::_('COM_ENGAGE_PANEL_ABOUT_AUTHOR_VALUE')) ?></dd></div>
		</dl>
		<p class="eg-about__note"><?= $e(Text::_('COM_ENGAGE_PANEL_ABOUT_NOTICE')) ?></p>
		<p class="eg-about__note"><?= I::icon('eye') ?><span><?= $e(Text::_('COM_ENGAGE_PANEL_ABOUT_PRIVACY')) ?></span></p>
		<ul class="eg-links">
			<li><a class="eg-btn eg-btn--ghost" href="<?= $e(PanelData::REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= I::icon('code') ?><?= $e(Text::_('COM_ENGAGE_PANEL_LINK_REPO')) ?><span class="eg-vh"> <?= $e(Text::_('COM_ENGAGE_PANEL_LINK_NEWTAB')) ?></span></a></li>
			<li><a class="eg-btn eg-btn--ghost" href="<?= $e(PanelData::CHANGELOG_URL) ?>" target="_blank" rel="noopener noreferrer"><?= I::icon('log') ?><?= $e(Text::_('COM_ENGAGE_PANEL_LINK_CHANGELOG')) ?><span class="eg-vh"> <?= $e(Text::_('COM_ENGAGE_PANEL_LINK_NEWTAB')) ?></span></a></li>
			<li><a class="eg-btn eg-btn--ghost" href="<?= $e(PanelData::DOCS_URL) ?>" target="_blank" rel="noopener noreferrer"><?= I::icon('book') ?><?= $e(Text::_('COM_ENGAGE_PANEL_LINK_DOCS')) ?><span class="eg-vh"> <?= $e(Text::_('COM_ENGAGE_PANEL_LINK_NEWTAB')) ?></span></a></li>
			<li><a class="eg-btn eg-btn--ghost" href="<?= $e(PanelData::UPSTREAM_URL) ?>" target="_blank" rel="noopener noreferrer"><?= I::icon('external') ?><?= $e(Text::_('COM_ENGAGE_PANEL_LINK_UPSTREAM')) ?><span class="eg-vh"> <?= $e(Text::_('COM_ENGAGE_PANEL_LINK_NEWTAB')) ?></span></a></li>
		</ul>
	</section>

	<!-- Formulario unico para las acciones rapidas: usa las tareas existentes de CommentsController, con token y permisos -->
	<form id="eg-quick" method="post" action="<?= $url('index.php?option=com_engage') ?>" class="eg-vh">
		<input type="hidden" name="task" value="">
		<input type="hidden" name="cid[]" value="">
		<input type="hidden" name="return" value="<?= $e(base64_encode($panelUrl)) ?>">
		<?= $quickToken ?>
	</form>

	<dialog class="eg-dialog" id="eg-dialog" aria-labelledby="eg-dialog-title" aria-describedby="eg-dialog-text">
		<form method="dialog" class="eg-dialog__box">
			<span class="eg-dialog__icon" id="eg-dialog-icon" aria-hidden="true"></span>
			<h2 class="eg-dialog__title" id="eg-dialog-title"></h2>
			<p class="eg-dialog__text" id="eg-dialog-text"></p>
			<blockquote class="eg-dialog__quote" id="eg-dialog-quote"></blockquote>
			<div class="eg-dialog__actions">
				<button type="submit" value="cancel" class="eg-btn eg-btn--ghost" id="eg-dialog-cancel"><?= $e(Text::_('JCANCEL')) ?></button>
				<button type="submit" value="ok" class="eg-btn eg-btn--primary" id="eg-dialog-ok"></button>
			</div>
		</form>
	</dialog>
</div>
