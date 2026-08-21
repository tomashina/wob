<?php
class ModelExtensionShippingFree extends Model {
	private $installed = null;

	public function getQuote($address) {
		$progress = $this->getThresholdProgress(is_array($address) ? $address : array());

		$method_data = array();

		if ($progress['reached']) {
			$this->load->language('extension/shipping/free');

			$quote_data = array();

			$quote_data['free'] = array(
				'code'         => 'free.free',
				'title'        => $this->language->get('text_description'),
				'cost'         => 0.00,
				'tax_class_id' => 0,
				'text'         => $this->currency->format(0.00, $this->session->data['currency'])
			);

			$method_data = array(
				'code'       => 'free',
				'title'      => $this->language->get('text_title'),
				'quote'      => $quote_data,
				'sort_order' => $this->config->get('shipping_free_sort_order'),
				'error'      => false
			);
		}

		return $method_data;
	}

	/**
	 * Return the single source of truth used by the quote and storefront notice.
	 *
	 * The threshold is evaluated against the gross merchandise total. Cart
	 * products already contain the active regular, discount or special price;
	 * coupons, rewards, order totals and shipping are deliberately excluded.
	 * Passing an address also applies the configured geo-zone. Omitting it keeps
	 * the helper useful while the storefront only knows the current cart.
	 *
	 * @param array|null $address
	 * @return array
	 */
	public function getThresholdProgress($address = null) {
		$decimal_place = $this->getCurrencyDecimalPlace();
		$threshold = round((float)$this->config->get('shipping_free_total'), $decimal_place);
		$cart_total = round($this->getGrossProductTotal(), $decimal_place);
		$enabled = $this->isInstalled() && (bool)$this->config->get('shipping_free_status') && $threshold > 0;

		if ($enabled && is_array($address)) {
			$enabled = $this->isAddressEligible($address);
		}

		$remaining = round(max(0, $threshold - $cart_total), $decimal_place);

		return array(
			'enabled'    => $enabled,
			'threshold'  => $threshold,
			'cart_total' => $cart_total,
			'remaining'  => $remaining,
			'reached'    => $enabled && $cart_total >= $threshold
		);
	}

	public function isInstalled() {
		if ($this->installed === null) {
			$query = $this->db->query("SELECT `extension_id` FROM `" . DB_PREFIX . "extension` WHERE `type` = 'shipping' AND `code` = 'free' LIMIT 1");
			$this->installed = (bool)$query->num_rows;
		}

		return $this->installed;
	}

	private function getGrossProductTotal() {
		$total = 0;

		foreach ($this->cart->getProducts() as $product) {
			$price = isset($product['price']) ? (float)$product['price'] : 0;
			$quantity = isset($product['quantity']) ? (float)$product['quantity'] : 0;
			$tax_class_id = isset($product['tax_class_id']) ? (int)$product['tax_class_id'] : 0;

			$total += $this->tax->calculate($price, $tax_class_id, true) * $quantity;
		}

		return $total;
	}

	private function getCurrencyDecimalPlace() {
		$currency_code = $this->config->get('config_currency');
		$decimal_place = $this->currency->getDecimalPlace($currency_code);

		return is_numeric($decimal_place) ? max(0, (int)$decimal_place) : 2;
	}

	private function isAddressEligible($address) {
		$geo_zone_id = (int)$this->config->get('shipping_free_geo_zone_id');

		if (!$geo_zone_id) {
			return true;
		}

		$country_id = isset($address['country_id']) ? (int)$address['country_id'] : 0;
		$zone_id = isset($address['zone_id']) ? (int)$address['zone_id'] : 0;
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . $geo_zone_id . "' AND country_id = '" . $country_id . "' AND (zone_id = '" . $zone_id . "' OR zone_id = '0') LIMIT 1");

		return (bool)$query->num_rows;
	}
}
