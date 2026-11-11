<?php
namespace Opencart\Admin\Controller\Extension\PsFilter\Tool;
/**
 * PS Filter — фасеты: список, форма, значения, категории, свои фасеты.
 * Логика — PsFilter\Oc\Admin\Facets (общая для OC3 и OC4).
 */
class Facet extends \Opencart\System\Engine\Controller {

	public function index(): void { $this->h()->index(); }
	public function form(): void { $this->h()->form(); }
	public function save(): void { $this->h()->save(); }
	public function saveFacet(): void { $this->h()->saveFacet(); }
	public function values(): void { $this->h()->values(); }
	public function saveValues(): void { $this->h()->saveValues(); }
	public function merge(): void { $this->h()->merge(); }
	public function unmerge(): void { $this->h()->unmerge(); }
	public function addValue(): void { $this->h()->addValue(); }
	public function deleteValue(): void { $this->h()->deleteValue(); }
	public function saveCategories(): void { $this->h()->saveCategories(); }
	public function add(): void { $this->h()->add(); }
	public function delete(): void { $this->h()->delete(); }

	public function bulk(): void { $this->h()->bulk(); }

	private function h(): \PsFilter\Oc\Admin\Facets {
		require_once DIR_EXTENSION . 'ps_filter/system/library/ps_filter/bootstrap.php';
		return new \PsFilter\Oc\Admin\Facets($this);
	}
}
