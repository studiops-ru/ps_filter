<?php
namespace Opencart\Admin\Controller\Extension\PsFilter\Tool;
/**
 * PS Filter — экран индексации. Логика — PsFilter\Oc\Admin\Index (общая для OC3 и OC4).
 */
class Index extends \Opencart\System\Engine\Controller {

	public function index(): void { $this->h()->index(); }
	public function start(): void { $this->h()->start(); }
	public function step(): void { $this->h()->step(); }
	public function pages(): void { $this->h()->pages(); }
	public function wizard(): void { $this->h()->wizard(); }
	public function clear(): void { $this->h()->clear(); }
	public function cronkey(): void { $this->h()->cronkey(); }
	public function manticore(): void { $this->h()->manticore(); }

	private function h(): \PsFilter\Oc\Admin\Index {
		require_once DIR_EXTENSION . 'ps_filter/system/library/ps_filter/bootstrap.php';
		return new \PsFilter\Oc\Admin\Index($this);
	}
}
