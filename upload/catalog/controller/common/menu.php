<?php
class ControllerCommonMenu extends Controller {
	public function index() {
		$this->load->language('common/menu');

		$cache_enabled = !$this->config->get('config_product_count');
		$cache_key = 'category.menu.common.'
			. (int)$this->config->get('config_store_id') . '.'
			. (int)$this->config->get('config_language_id') . '.'
			. (!empty($this->request->server['HTTPS']) ? '1' : '0');

		$cached_menu = $cache_enabled ? $this->cache->get($cache_key) : false;

		if (is_string($cached_menu) && $cached_menu !== '') {
			return $cached_menu;
		}

		// Menu
		$this->load->model('catalog/category');

		$this->load->model('catalog/product');

		$data['categories'] = array();

		$categories = $this->model_catalog_category->getCategories(0);

		foreach ($categories as $category) {
			if ($category['top']) {
				// Level 2
				$children_data = array();

				$children = $this->model_catalog_category->getCategories($category['category_id']);

				foreach ($children as $child) {
					$filter_data = array(
						'filter_category_id'  => $child['category_id'],
						'filter_sub_category' => true
					);

					$children_data[] = array(
						'name'  => $child['name'] . ($this->config->get('config_product_count') ? ' (' . $this->model_catalog_product->getTotalProducts($filter_data) . ')' : ''),
						'href'  => $this->url->link('product/category', 'path=' . $category['category_id'] . '_' . $child['category_id'])
					);
				}

				// Level 1
				$data['categories'][] = array(
					'name'     => $category['name'],
					'children' => $children_data,
					'column'   => $category['column'] ? $category['column'] : 1,
					'href'     => $this->url->link('product/category', 'path=' . $category['category_id'])
				);
			}
		}

		$output = $this->load->view('common/menu', $data);

		if ($cache_enabled) {
			$this->cache->set($cache_key, $output);
		}

		return $output;
	}
}
