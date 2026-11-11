<?php
/**
 * PS Filter — экран индексации. Логика — PsFilter\Oc\Admin\Index (общая для OC3 и OC4).
 */
class ControllerExtensionPsFilterIndex extends Controller {

    public function index() { $this->h()->index(); }
    public function start() { $this->h()->start(); }
    public function step() { $this->h()->step(); }
    public function pages() { $this->h()->pages(); }
    public function wizard() { $this->h()->wizard(); }
    public function clear() { $this->h()->clear(); }
    public function cronkey() { $this->h()->cronkey(); }
    public function manticore() { $this->h()->manticore(); }

    /** @return \PsFilter\Oc\Admin\Index */
    private function h() {
        require_once DIR_SYSTEM . 'library/ps_filter/bootstrap.php';
        return new \PsFilter\Oc\Admin\Index($this);
    }
}
