<?php
namespace anchor_price;

class Formatter {
	private $registry;

	public function __construct($registry) {
		$this->registry = $registry;
	}

	public function format(array $product) {
		$data = array(
			'anchor_price' => false,
			'anchor_price_date' => '',
			'anchor_price_label' => ''
		);

		$config = $this->registry->get('config');
		$customer = $this->registry->get('customer');
		$anchor_price = isset($product['anchor_price']) ? trim((string)$product['anchor_price']) : '';

		if ((!$customer->isLogged() && $config->get('config_customer_price')) || !preg_match('/^(?:[1-9][0-9]*(?:\.[0-9]+)?|0\.[0-9]*[1-9][0-9]*)$/D', $anchor_price)) {
			return $data;
		}

		$currency = $this->registry->get('currency');
		$language = $this->registry->get('language');
		$session = $this->registry->get('session');
		$tax = $this->registry->get('tax');
		$tax_class_id = isset($product['tax_class_id']) ? $product['tax_class_id'] : 0;

		$data['anchor_price'] = $currency->format(
			$tax->calculate($anchor_price, $tax_class_id, $config->get('config_tax')),
			$session->data['currency']
		);

		if (!empty($product['anchor_price_date']) && $product['anchor_price_date'] !== '0000-00-00') {
			$data['anchor_price_date'] = date($language->get('date_format_short'), strtotime($product['anchor_price_date']));
			$data['anchor_price_label'] = sprintf($language->get('text_anchor_price_date'), $data['anchor_price_date']);
		} else {
			$data['anchor_price_label'] = $language->get('text_anchor_price');
		}

		return $data;
	}
}
