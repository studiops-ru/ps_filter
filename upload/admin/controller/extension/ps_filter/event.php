<?php
/**
 * PS Filter — обработчики событий админки. Логика — PsFilter\Oc\Admin\Events (общая для OC3 и OC4).
 * Обработчик, который ничего не подменяет, обязан вернуть null.
 */
class ControllerExtensionPsFilterEvent extends Controller {

    public function menu(&$route, &$data) { return $this->h()->menu($route, $data); }
    public function productTab(&$route, &$data, &$output) { return $this->h()->productTab($route, $data, $output); }
    public function productAdd(&$route, &$args, &$output) { return $this->h()->productAdd($route, $args, $output); }
    public function productEdit(&$route, &$args, &$output) { return $this->h()->productEdit($route, $args, $output); }
    public function productDelete(&$route, &$args, &$output) { return $this->h()->productDelete($route, $args, $output); }
    public function batchChanged(&$route, &$data) { return $this->h()->batchChanged($route, $data); }
    public function sitemapProviders(&$list) { return $this->h()->sitemapProviders($list); }
    public function categoryChanged(&$route, &$args, &$output) { return $this->h()->categoryChanged($route, $args, $output); }
    public function referenceChanged(&$route, &$args, &$output) { return $this->h()->referenceChanged($route, $args, $output); }
    public function customerGroupChanged(&$route, &$args, &$output) { return $this->h()->customerGroupChanged($route, $args, $output); }
    public function userGroupChanged(&$route, &$args, &$output) { return $this->h()->userGroupChanged($route, $args, $output); }

    /** @return \PsFilter\Oc\Admin\Events */
    private function h() {
        require_once DIR_SYSTEM . 'library/ps_filter/bootstrap.php';
        return new \PsFilter\Oc\Admin\Events($this);
    }
}
