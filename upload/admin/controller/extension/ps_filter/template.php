<?php
/**
 * PS Filter — SEO-шаблоны. Логика — PsFilter\Oc\Admin\Templates (общая для OC3 и OC4).
 */
class ControllerExtensionPsFilterTemplate extends Controller {

    public function index() { $this->h()->index(); }
    public function form() { $this->h()->form(); }

    /** @return \PsFilter\Oc\Admin\Templates */
    private function h() {
        require_once DIR_SYSTEM . 'library/ps_filter/bootstrap.php';
        return new \PsFilter\Oc\Admin\Templates($this);
    }
}
