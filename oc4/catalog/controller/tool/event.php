<?php
namespace Opencart\Catalog\Controller\Extension\PsFilter\Tool;
/**
 * PS Filter — обработчики событий витрины. Логика — PsFilter\Oc\Catalog\Events (общая для OC3 и OC4).
 * OC4 игнорирует ответы обработчиков: подмена идет сменой &$route (на output() или на модель модуля).
 */
class Event extends \Opencart\System\Engine\Controller {

	public function notFound(&$route, &$data) { $this->h()->notFound($route, $data); }
	public function routeBefore(&$route, &$data) { $this->h()->routeBefore($route, $data); }
	public function getProducts(&$route, &$args) { $this->h()->getProducts($route, $args); }
	public function getTotal(&$route, &$args) { $this->h()->getTotal($route, $args); }
	public function categoryCount(&$route, &$args) { $this->h()->categoryCount($route, $args); }
	public function viewBefore(&$route, &$data) { $this->h()->viewBefore($route, $data); }
	public function header(&$route, &$data) { $this->h()->header($route, $data); }
	public function headerAfter(&$route, &$data, &$output) { $this->h()->headerAfter($route, $data, $output); }
	public function suppress(&$route, &$data) { $this->h()->suppress($route, $data); }
	public function fragmentAfter(&$route, &$data, &$output) { $this->h()->fragmentAfter($route, $data, $output); }
	public function orderHistory(&$route, &$args, &$output) { $this->h()->orderHistory($route, $args, $output); }

	/**
	 * Пустое действие вместо подавленного контроллера: обвязка страницы в AJAX-ответе
	 * или вся страница, когда ответ (JSON счетчиков) уже записан в response.
	 */
	public function output(): string {
		return '';
	}

	public function sitemapProviders(&$list) { $this->h()->sitemapProviders($list); }

	private function h(): \PsFilter\Oc\Catalog\Events {
		require_once DIR_EXTENSION . 'ps_filter/system/library/ps_filter/bootstrap.php';
		return new \PsFilter\Oc\Catalog\Events($this);
	}
}
