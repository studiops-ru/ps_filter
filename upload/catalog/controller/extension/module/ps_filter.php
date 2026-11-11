<?php
/**
 * PS Filter — панель фильтра на витрине, cron и карта сайта.
 * Логика — PsFilter\Oc\Catalog\Panel (общая для OC3 и OC4).
 */
class ControllerExtensionModulePsFilter extends Controller {

    public function index($setting = []) {
        if (!$this->config->get('module_ps_filter_status')) {
            return '';
        }
        return $this->h()->index($setting);
    }

    public function cron() { $this->h()->cron(); }
    public function sitemap() { $this->h()->sitemap(); }

    /** @return \PsFilter\Oc\Catalog\Panel */
    private function h() {
        require_once DIR_SYSTEM . 'library/ps_filter/bootstrap.php';
        return new \PsFilter\Oc\Catalog\Panel($this);
    }
}
