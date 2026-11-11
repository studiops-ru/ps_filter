<?php
namespace Opencart\Admin\Controller\Extension\PsFilter\Module;
/**
 * PS Filter — настройки, установка и удаление. Логика — PsFilter\Oc\Admin\Settings (общая для OC3 и OC4).
 */
class PsFilter extends \Opencart\System\Engine\Controller {

	public function index(): void { $this->h()->index(); }
	public function save(): void { $this->h()->save(); }
	public function repair(): void { $this->h()->repair(); }
	public function install(): void { $this->h()->install(); }
	public function uninstall(): void { $this->h()->uninstall(); }

	private function h(): \PsFilter\Oc\Admin\Settings {
		require_once DIR_EXTENSION . 'ps_filter/system/library/ps_filter/bootstrap.php';
		return new \PsFilter\Oc\Admin\Settings($this);
	}
}
