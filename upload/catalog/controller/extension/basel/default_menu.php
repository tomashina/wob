<?php
class ControllerExtensionBaselDefaultMenu extends Controller {
	public function index() {
		$this->load->language('common/menu');

		$cache_enabled = !$this->config->get('config_product_count');
		$cache_key = 'category.menu.basel_default.'
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
					
				$grandchildren_data = array();

				// Level 3
				$grandchildren = $this->model_catalog_category->getCategories($child['category_id']);
				foreach ($grandchildren as $grandchild) {
					
					if ($this->config->get('config_product_count')) {
					$total = ' (' . $this->model_catalog_product->getTotalProducts(array('filter_category_id'  => $grandchild['category_id'])). ')';
					} else {
					$total = '';
					}
					
					$grandchildren_data[] = array(
					'name' => $grandchild['name'] . $total,
					'href' => $this->url->link('product/category', 'path=' . $category['category_id'] . '_' . $child['category_id'] . '_' . $grandchild['category_id'])
					);
				 }
				
					$filter_data = array(
						'filter_category_id'  => $child['category_id'],
						'filter_sub_category' => true
					);
					
					if ($this->config->get('config_product_count')) {
					$total = ' (' . $this->model_catalog_product->getTotalProducts($filter_data) . ')';
					} else {
					$total = '';
					}

					$children_data[] = array(
						'name'  => $child['name'] . $total,
						'href'  => $this->url->link('product/category', 'path=' . $category['category_id'] . '_' . $child['category_id']),
						'grandchildren'	=> $grandchildren_data,
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
