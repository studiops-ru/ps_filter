<?php
namespace Opencart\Admin\Controller\Extension\PsFilter\Tool;
/**
 * PS Filter — посадочные страницы. Логика — PsFilter\Oc\Admin\Pages (общая для OC3 и OC4).
 */
class Page extends \Opencart\System\Engine\Controller {

	public function index(): void { $this->h()->index(); }
	public function form(): void { $this->h()->form(); }
	public function facets(): void { $this->h()->facets(); }
	public function count(): void { $this->h()->count(); }
	public function parse(): void { $this->h()->parse(); }
	public function save(): void { $this->h()->save(); }
	public function delete(): void { $this->h()->delete(); }
	public function export(): void { $this->h()->export(); }
	public function import(): void { $this->h()->import(); }

	private function h(): \PsFilter\Oc\Admin\Pages {
		require_once DIR_EXTENSION . 'ps_filter/system/library/ps_filter/bootstrap.php';
		return new \PsFilter\Oc\Admin\Pages($this);
	}
}
