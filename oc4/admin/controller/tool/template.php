<?php
namespace Opencart\Admin\Controller\Extension\PsFilter\Tool;
/**
 * PS Filter — SEO-шаблоны. Логика — PsFilter\Oc\Admin\Templates (общая для OC3 и OC4).
 */
class Template extends \Opencart\System\Engine\Controller {

	public function index(): void { $this->h()->index(); }
	public function form(): void { $this->h()->form(); }

	private function h(): \PsFilter\Oc\Admin\Templates {
		require_once DIR_EXTENSION . 'ps_filter/system/library/ps_filter/bootstrap.php';
		return new \PsFilter\Oc\Admin\Templates($this);
	}
}
