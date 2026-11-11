<?php
namespace Opencart\Catalog\Model\Extension\PsFilter\Module;
/**
 * PS Filter — подмена выборки товаров в OC4.
 *
 * Обработчик model/catalog/product.getProducts/before считает страницу движком фильтра,
 * кладет результат в runtime ядра и переводит вызов сюда (смена &$route): OC4 не дает
 * обработчику вернуть результат вместо модели.
 */
class PsFilter extends \Opencart\System\Engine\Model {

	public function getProducts(array $data = []): array {
		return (array)$this->runtime()->products;
	}

	public function getTotalProducts(array $data = []): int {
		return (int)$this->runtime()->total;
	}

	private function runtime(): \stdClass {
		require_once DIR_EXTENSION . 'ps_filter/system/library/ps_filter/bootstrap.php';
		return ps_filter_boot($this->registry)->runtime();
	}
}
