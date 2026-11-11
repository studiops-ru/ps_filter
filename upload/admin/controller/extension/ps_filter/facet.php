<?php
/**
 * PS Filter — фасеты: список, форма, значения, категории, свои фасеты.
 * Логика — PsFilter\Oc\Admin\Facets (общая для OC3 и OC4).
 */
class ControllerExtensionPsFilterFacet extends Controller {

    public function index() { $this->h()->index(); }
    public function form() { $this->h()->form(); }
    public function save() { $this->h()->save(); }
    public function bulk() { $this->h()->bulk(); }
    public function saveFacet() { $this->h()->saveFacet(); }
    public function values() { $this->h()->values(); }
    public function saveValues() { $this->h()->saveValues(); }
    public function merge() { $this->h()->merge(); }
    public function unmerge() { $this->h()->unmerge(); }
    public function addValue() { $this->h()->addValue(); }
    public function deleteValue() { $this->h()->deleteValue(); }
    public function saveCategories() { $this->h()->saveCategories(); }
    public function add() { $this->h()->add(); }
    public function delete() { $this->h()->delete(); }

    /** @return \PsFilter\Oc\Admin\Facets */
    private function h() {
        require_once DIR_SYSTEM . 'library/ps_filter/bootstrap.php';
        return new \PsFilter\Oc\Admin\Facets($this);
    }
}
