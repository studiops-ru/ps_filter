<?php
namespace Opencart\Admin\Controller\Extension\PsFilter\Tool;
/**
 * PS Filter — обработчики событий админки. Логика — PsFilter\Oc\Admin\Events (общая для OC3 и OC4).
 * OC4 игнорирует ответы обработчиков: все меняется через аргументы по ссылке.
 */
class Event extends \Opencart\System\Engine\Controller {

	public function menu(&$route, &$data) { $this->h()->menu($route, $data); }
	public function productTab(&$route, &$data, &$output) { $this->h()->productTab($route, $data, $output); }
	public function productAdd(&$route, &$args, &$output) { $this->h()->productAdd($route, $args, $output); }
	public function productEdit(&$route, &$args, &$output) { $this->h()->productEdit($route, $args, $output); }
	public function variantsEdit(&$route, &$args, &$output) { $this->h()->variantsEdit($route, $args, $output); }
	public function productDelete(&$route, &$args, &$output) { $this->h()->productDelete($route, $args, $output); }
	public function categoryChanged(&$route, &$args, &$output) { $this->h()->categoryChanged($route, $args, $output); }
	public function referenceChanged(&$route, &$args, &$output) { $this->h()->referenceChanged($route, $args, $output); }
	public function customerGroupChanged(&$route, &$args, &$output) { $this->h()->customerGroupChanged($route, $args, $output); }

	public function sitemapProviders(&$list) { $this->h()->sitemapProviders($list); }

	private function h(): \PsFilter\Oc\Admin\Events {
		require_once DIR_EXTENSION . 'ps_filter/system/library/ps_filter/bootstrap.php';
		return new \PsFilter\Oc\Admin\Events($this);
	}
}
