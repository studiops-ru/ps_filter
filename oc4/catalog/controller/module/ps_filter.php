<?php
namespace Opencart\Catalog\Controller\Extension\PsFilter\Module;
/**
 * PS Filter — панель фильтра на витрине, cron и карта сайта.
 * Логика — PsFilter\Oc\Catalog\Panel (общая для OC3 и OC4).
 *
 * cron:    index.php?route=extension/ps_filter/module/ps_filter.cron&key=…&task=…
 * sitemap: index.php?route=extension/ps_filter/module/ps_filter.sitemap
 */
class PsFilter extends \Opencart\System\Engine\Controller {

	public function index(array $setting = []): string {
		if (!$this->config->get('module_ps_filter_status')) {
			return '';
		}
		return (string)$this->h()->index($setting);
	}

	public function cron(): void { $this->h()->cron(); }
	public function sitemap(): void { $this->h()->sitemap(); }

	private function h(): \PsFilter\Oc\Catalog\Panel {
		require_once DIR_EXTENSION . 'ps_filter/system/library/ps_filter/bootstrap.php';
		return new \PsFilter\Oc\Catalog\Panel($this);
	}
}
