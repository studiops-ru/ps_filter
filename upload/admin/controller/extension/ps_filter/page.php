<?php
/**
 * PS Filter — посадочные страницы. Логика — PsFilter\Oc\Admin\Pages (общая для OC3 и OC4).
 */
class ControllerExtensionPsFilterPage extends Controller {

    public function index() { $this->h()->index(); }
    public function form() { $this->h()->form(); }
    public function facets() { $this->h()->facets(); }
    public function count() { $this->h()->count(); }
    public function parse() { $this->h()->parse(); }
    public function save() { $this->h()->save(); }
    public function delete() { $this->h()->delete(); }
    public function export() { $this->h()->export(); }
    public function import() { $this->h()->import(); }

    /** @return \PsFilter\Oc\Admin\Pages */
    private function h() {
        require_once DIR_SYSTEM . 'library/ps_filter/bootstrap.php';
        return new \PsFilter\Oc\Admin\Pages($this);
    }
}
