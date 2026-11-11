<?php
/**
 * PS Filter — обработчики событий витрины. Логика — PsFilter\Oc\Catalog\Events (общая для OC3 и OC4).
 * Обработчик, который ничего не подменяет, обязан вернуть null.
 */
class ControllerExtensionPsFilterEvent extends Controller {

    public function notFound(&$route, &$data) { return $this->h()->notFound($route, $data); }
    public function routeBefore(&$route, &$data) { return $this->h()->routeBefore($route, $data); }
    public function languageBefore(&$route, &$key) { return $this->h()->languageBefore($route, $key); }
    public function getProducts(&$route, &$args) { return $this->h()->getProducts($route, $args); }
    public function getTotal(&$route, &$args) { return $this->h()->getTotal($route, $args); }
    public function categoryCount(&$route, &$args) { return $this->h()->categoryCount($route, $args); }
    public function viewBefore(&$route, &$data) { return $this->h()->viewBefore($route, $data); }
    public function header(&$route, &$data) { return $this->h()->header($route, $data); }
    public function headerAfter(&$route, &$data, &$output) { return $this->h()->headerAfter($route, $data, $output); }
    public function suppress(&$route, &$data) { return $this->h()->suppress($route, $data); }
    public function fragmentAfter(&$route, &$data, &$output) { return $this->h()->fragmentAfter($route, $data, $output); }
    public function sitemapAfter(&$route, &$data, &$output) { return $this->h()->sitemapAfter($route, $data, $output); }
    public function orderHistory(&$route, &$args, &$output) { return $this->h()->orderHistory($route, $args, $output); }
    public function sitemapProviders(&$list) { return $this->h()->sitemapProviders($list); }

    /** @return \PsFilter\Oc\Catalog\Events */
    private function h() {
        require_once DIR_SYSTEM . 'library/ps_filter/bootstrap.php';
        return new \PsFilter\Oc\Catalog\Events($this);
    }
}
