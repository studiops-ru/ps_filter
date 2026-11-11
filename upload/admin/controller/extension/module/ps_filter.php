<?php
/**
 * PS Filter — настройки, установка и удаление. Логика — PsFilter\Oc\Admin\Settings (общая для OC3 и OC4).
 */
class ControllerExtensionModulePsFilter extends Controller {

    public function index() { $this->h()->index(); }
    public function save() { $this->h()->save(); }
    public function repair() { $this->h()->repair(); }
    public function install() { $this->h()->install(); }
    public function uninstall() { $this->h()->uninstall(); }

    /** @return \PsFilter\Oc\Admin\Settings */
    private function h() {
        require_once DIR_SYSTEM . 'library/ps_filter/bootstrap.php';
        return new \PsFilter\Oc\Admin\Settings($this);
    }
}
