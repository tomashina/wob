<?php
class ControllerExtensionModuleActiveshopImporter extends Controller {
	const ROUTE = 'extension/module/activeshop_importer';
	const SUPPLIER_CODE = 'activeshop';
	const MAX_IMPORT_ITEMS = 50;
	const MAX_TRANSLATED_IMPORT_ITEMS = 10;
	const STAGE_BATCH_SIZE = 50;
	const STAGE_BATCH_MAX_BYTES = 1048576;

	private $error = array();
	private $translator;

	public function index() {
		$this->load->language(self::ROUTE);
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model(self::ROUTE);

		$filters = $this->getProductFilters();
		$page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;
		$limit = 50;
		$filters['start'] = ($page - 1) * $limit;
		$filters['limit'] = $limit;

		$settings = $this->getSettings();
		$total = $this->model_extension_module_activeshop_importer->getTotalProducts($filters);
		$products = $this->model_extension_module_activeshop_importer->getProducts($filters);

		foreach ($products as &$product) {
			$product['calculated_price'] = $this->calculatePrice($product['feed_price'], $settings['markup']);
			$product['status_key'] = $this->resolveProductStatus($product);
			$product['status_text'] = $this->language->get('text_status_' . $product['status_key']);
			$product['status_class'] = $this->getStatusClass($product['status_key']);
			$product['can_import'] = $product['status_key'] !== 'conflict' && !empty($product['is_current']);
			$product['edit'] = !empty($product['product_id']) ? $this->url->link('catalog/product/edit', 'user_token=' . $this->session->data['user_token'] . '&product_id=' . (int)$product['product_id'], true) : '';
			$product['preview_image'] = '';

			if (!empty($product['images'])) {
				$images = is_array($product['images']) ? $product['images'] : json_decode($product['images'], true);
				if (is_array($images) && !empty($images[0])) {
					$product['preview_image'] = $images[0];
				}
			}
		}
		unset($product);

		$feed_service = $this->getFeedService();
		$data = $this->language->all();
		$data['products'] = $products;
		$data['product_total'] = $total;
		$data['status_counts'] = $this->model_extension_module_activeshop_importer->getStatusCounts();
		$data['supplier_categories'] = $this->model_extension_module_activeshop_importer->getSupplierCategories(array('is_current' => 1));
		$data['settings'] = $settings;
		$data['filters'] = $filters;
		$data['user_token'] = $this->session->data['user_token'];
		$data['csrf_token'] = $this->getCsrfToken();
		$data['active_tab'] = 'products';
		$data['feed_url'] = $feed_service->getFeedUrl();
		$data['feed_metadata'] = $this->getFeedMetadata();
		$data['recent_runs'] = $this->model_extension_module_activeshop_importer->getRecentRuns(8);

		$data['breadcrumbs'] = $this->getBreadcrumbs($this->language->get('heading_title'), self::ROUTE);
		$data['products_url'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true);
		$data['categories_url'] = $this->url->link(self::ROUTE . '/categories', 'user_token=' . $this->session->data['user_token'], true);
		$data['settings_url'] = $this->url->link(self::ROUTE . '/settings', 'user_token=' . $this->session->data['user_token'], true);
		$data['refresh_url'] = $this->url->link(self::ROUTE . '/refresh', 'user_token=' . $this->session->data['user_token'], true);
		// This URL is also embedded in a JavaScript string. Url::link() returns
		// HTML entities for ampersands, which browsers decode in href/action
		// attributes but not inside JSON/JavaScript strings.
		$data['import_url'] = html_entity_decode($this->url->link(self::ROUTE . '/import', 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(array('page')), true), ENT_QUOTES, 'UTF-8');
		$data['filter_url'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true);
		$data['new_products_url'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(array('filter_status', 'page')) . '&filter_status=new', true);
		$data['all_statuses_url'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(array('filter_status', 'page')) . '&filter_status=all', true);
		$data['cancel'] = $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true);

		$pagination = new Pagination();
		$pagination->total = $total;
		$pagination->page = $page;
		$pagination->limit = $limit;
		$pagination->url = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(array('page')) . '&page={page}', true);
		$data['pagination'] = $pagination->render();
		$data['results'] = sprintf($this->language->get('text_pagination'), $total ? (($page - 1) * $limit) + 1 : 0, min($page * $limit, $total), $total, $total ? ceil($total / $limit) : 1);

		$this->addFlashMessages($data);
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	public function refresh() {
		$this->load->language(self::ROUTE);

		if (!$this->validateMutation()) {
			$this->redirectWithErrors(self::ROUTE);
			return;
		}

		$operation_lock = $this->acquireOperationLock();
		if (!$operation_lock) {
			$this->session->data['warning'] = $this->language->get('error_busy');
			$this->response->redirect($this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true));
			return;
		}

		$run_id = 0;
		$in_transaction = false;
		try {
			$this->load->model(self::ROUTE);
			$this->model_extension_module_activeshop_importer->recoverRunningRefreshRuns();
			$settings = $this->getSettings();
			$run_id = $this->model_extension_module_activeshop_importer->beginRun(array(
				'type' => 'refresh',
				'user_id' => $this->user->getId(),
				'markup' => $settings['markup'],
				'settings' => $settings
			));
			$this->prepareLongRequest();
			$feed = $this->getFeedService();
			$cache_file = $this->getFeedCacheFile();
			$metadata = $feed->refreshCache($cache_file);
			$feed_token = hash('sha256', $metadata['hash'] . '|' . microtime(true));
			$this->db->query('START TRANSACTION');
			$in_transaction = true;
			$staged = $this->stageFeedItemsInBatches($feed->iterate($cache_file), $feed_token);

			$this->model_extension_module_activeshop_importer->finishFeedRefresh($feed_token);
			$eligibility = $this->model_extension_module_activeshop_importer->getCurrentFeedEligibilityCounts();
			$importable = isset($eligibility['importable']) ? (int)$eligibility['importable'] : 0;
			$excluded_invalid = isset($eligibility['excluded_invalid']) ? (int)$eligibility['excluded_invalid'] : 0;
			$matched = $this->model_extension_module_activeshop_importer->reconcileExistingProducts();
			$category_mapping = $this->model_extension_module_activeshop_importer->autoMapSupplierCategories();
			$counts = array(
				'selected' => $staged,
				'created' => 0,
				'updated' => 0,
				'skipped' => $excluded_invalid,
				'failed' => 0,
				'staged' => $staged,
				'importable' => $importable,
				'excluded_invalid' => $excluded_invalid,
				'skipped_invalid' => $excluded_invalid,
				'feed_items' => $staged,
				'categories_mapped' => isset($category_mapping['mapped']) ? (int)$category_mapping['mapped'] : 0
			);
			$this->model_extension_module_activeshop_importer->finishRun($run_id, $counts, 'completed');
			$this->db->query('COMMIT');
			$in_transaction = false;
			$matched_total = (isset($matched['matched']) ? (int)$matched['matched'] : 0) + (isset($matched['linked']) ? (int)$matched['linked'] : 0);
			$this->session->data['success'] = sprintf($this->language->get('text_refresh_success'), $staged, $importable, $excluded_invalid, $matched_total, isset($matched['conflicts']) ? (int)$matched['conflicts'] : 0);
		} catch (Throwable $e) {
			if ($in_transaction) {
				try {
					$this->db->query('ROLLBACK');
				} catch (Throwable $rollback_error) {
					// Preserve the original refresh error; the next locked run will
					// recover an audit row left in the running state if necessary.
				}
				$in_transaction = false;
			}
			if ($run_id) {
				try {
					$this->model_extension_module_activeshop_importer->finishRun($run_id, array('failed' => 1), 'failed', $e->getMessage());
				} catch (Throwable $audit_error) {
					// The operation lock is still released in finally. A later locked
					// refresh will terminalize this orphaned running audit row.
				}
			}
			$this->session->data['warning'] = sprintf($this->language->get('error_refresh'), $e->getMessage());
		} finally {
			$this->releaseOperationLock($operation_lock);
		}

		$this->response->redirect($this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true));
	}

	private function stageFeedItemsInBatches($items, $feed_token) {
		$batch = array();
		$batch_bytes = 0;
		$staged = 0;

		foreach ($items as $item) {
			$item_bytes = $this->estimateStageItemBytes($item);

			if ($batch && (count($batch) >= self::STAGE_BATCH_SIZE || $batch_bytes + $item_bytes > self::STAGE_BATCH_MAX_BYTES)) {
				$this->model_extension_module_activeshop_importer->stageFeedItems($batch, $feed_token);
				$staged += count($batch);
				$batch = array();
				$batch_bytes = 0;
			}

			$batch[] = $item;
			$batch_bytes += $item_bytes;
		}

		if ($batch) {
			$this->model_extension_module_activeshop_importer->stageFeedItems($batch, $feed_token);
			$staged += count($batch);
		}

		return $staged;
	}

	private function estimateStageItemBytes($item) {
		$options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		$payload = json_encode($item, $options);
		$images = json_encode(isset($item['images']) && is_array($item['images']) ? $item['images'] : array(), $options);
		$dimensions = json_encode(isset($item['dimensions']) && is_array($item['dimensions']) ? $item['dimensions'] : array(), $options);

		if ($payload === false || $images === false || $dimensions === false) {
			return self::STAGE_BATCH_MAX_BYTES;
		}

		// Payload, images and dimensions are escaped into separate SQL values.
		// Doubling their byte size safely covers worst-case addslashes growth;
		// the fixed allowance covers scalar columns and statement syntax.
		return 4096 + (2 * (strlen($payload) + strlen($images) + strlen($dimensions)));
	}

	public function import() {
		$this->load->language(self::ROUTE);
		$wants_json = $this->wantsJsonResponse();

		if (!$this->validateMutation()) {
			$this->respondToImportError(isset($this->error['warning']) ? $this->error['warning'] : $this->language->get('error_permission'), $wants_json);
			return;
		}

		$selected = isset($this->request->post['selected']) ? array_values(array_unique(array_filter(array_map('intval', (array)$this->request->post['selected'])))) : array();

		if (!$selected) {
			$this->respondToImportError($this->language->get('error_selected'), $wants_json);
			return;
		}

		if ($wants_json && count($selected) !== 1) {
			$this->respondToImportError($this->language->get('error_ajax_single_item'), true);
			return;
		}

		if (!$wants_json && count($selected) > self::MAX_IMPORT_ITEMS) {
			$this->respondToImportError(sprintf($this->language->get('error_import_limit'), self::MAX_IMPORT_ITEMS), false);
			return;
		}

		try {
			$markup = $this->normalizeMarkup(isset($this->request->post['markup']) ? $this->request->post['markup'] : 0);
		} catch (InvalidArgumentException $e) {
			$this->respondToImportError($e->getMessage(), $wants_json);
			return;
		}

		$existing_action = isset($this->request->post['existing_action']) ? $this->request->post['existing_action'] : 'skip';
		if (!in_array($existing_action, array('skip', 'price_quantity'), true)) {
			$existing_action = 'skip';
		}

		$operation_lock = $this->acquireOperationLock();
		if (!$operation_lock) {
			if ($wants_json) {
				$this->sendJson(array(
					'success' => false,
					'retryable' => true,
					'error_code' => 'busy',
					'error' => $this->language->get('error_busy')
				));
			} else {
				$this->session->data['warning'] = $this->language->get('error_busy');
				$this->redirectToProducts();
			}
			return;
		}

		$counts = array('selected' => count($selected), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0);
		$run_id = 0;
		$item_error = '';

		try {
			$this->load->model(self::ROUTE);
			$this->load->model('catalog/product');
			$settings = $this->getSettings();
			$default_category_id = (int)$settings['default_category_id'];
			$this->model_extension_module_activeshop_importer->reconcileExistingProducts($selected);
			$staged_products = $this->model_extension_module_activeshop_importer->getStagedProductsByIds($selected);
			$products_by_id = array();

			foreach ($staged_products as $staged_product) {
				$products_by_id[(int)$staged_product['supplier_product_id']] = $staged_product;
			}

			$new_product_count = 0;
			foreach ($products_by_id as $staged_product) {
				if ($this->resolveProductStatus($staged_product) === 'new') {
					$new_product_count++;
				}
			}

			if ($new_product_count > self::MAX_TRANSLATED_IMPORT_ITEMS) {
				$this->respondToImportError(sprintf($this->language->get('error_translation_batch_limit'), self::MAX_TRANSLATED_IMPORT_ITEMS), $wants_json);
				return;
			}

			$run_id = $this->model_extension_module_activeshop_importer->beginRun(array(
				'type' => 'import',
				'user_id' => $this->user->getId(),
				'markup' => $markup,
				'settings' => array_merge($settings, array('existing_action' => $existing_action, 'default_category_id' => $default_category_id))
			));
			$feed = $this->getFeedService();
			$this->prepareLongRequest();

			foreach ($selected as $supplier_product_id) {
				if (empty($products_by_id[$supplier_product_id])) {
					$item_error = $this->language->get('error_staged_missing');
					$counts['failed']++;
					$this->model_extension_module_activeshop_importer->logRunItem($run_id, array(
						'supplier_product_id' => $supplier_product_id,
						'action' => 'import',
						'status' => 'failed',
						'message' => $item_error
					));
					continue;
				}

				$row = $products_by_id[$supplier_product_id];
				try {
					$result = $this->importOneProduct($row, $markup, $existing_action, $default_category_id, $settings, $feed);
					$counts[$result['count_key']]++;
					$this->model_extension_module_activeshop_importer->logRunItem($run_id, array(
						'supplier_product_id' => $supplier_product_id,
						'external_id' => $row['external_id'],
						'product_id' => $result['product_id'],
						'action' => $result['action'],
						'status' => $result['status'],
						'before' => $result['before'],
						'after' => $result['after'],
						'message' => $result['message']
					));
				} catch (Throwable $e) {
					$item_error = $e->getMessage();
					$counts['failed']++;
					$this->model_extension_module_activeshop_importer->logRunItem($run_id, array(
						'supplier_product_id' => $supplier_product_id,
						'external_id' => $row['external_id'],
						'product_id' => !empty($row['product_id']) ? (int)$row['product_id'] : 0,
						'action' => 'import',
						'status' => 'failed',
						'message' => $item_error
					));
				}
			}

			$status = $counts['failed'] ? 'completed_with_errors' : 'completed';
			$this->model_extension_module_activeshop_importer->finishRun($run_id, $counts, $status);
			$success_message = sprintf($this->language->get('text_import_success'), $counts['created'], $counts['updated'], $counts['skipped'], $counts['failed']);

			if ($wants_json) {
				$this->sendJson(array(
					'success' => true,
					'item_success' => !$counts['failed'],
					'counts' => $counts,
					'error' => $item_error,
					'message' => $success_message,
					'redirect' => $this->getProductsRedirectUrl()
				));
			} else {
				$this->session->data['success'] = $success_message;
				if ($counts['failed']) {
					$this->session->data['warning'] = $this->language->get('text_import_has_errors');
				}
			}
		} catch (Throwable $e) {
			if ($run_id) {
				try {
					$this->model_extension_module_activeshop_importer->finishRun($run_id, $counts, 'failed', $e->getMessage());
				} catch (Throwable $audit_error) {
					// Preserve the original exception in the response or flash message.
				}
			}

			$this->respondToImportError(sprintf($this->language->get('error_import'), $e->getMessage()), $wants_json);
		} finally {
			$this->releaseOperationLock($operation_lock);
		}

		if (!$wants_json && !$this->response->getOutput()) {
			$this->redirectToProducts();
		}
	}

	public function categories() {
		$this->load->language(self::ROUTE);
		$this->document->setTitle($this->language->get('heading_categories'));
		$this->load->model(self::ROUTE);

		$filter_search = isset($this->request->get['filter_search']) ? trim($this->request->get['filter_search']) : '';
		$page = isset($this->request->get['page']) ? max(1, (int)$this->request->get['page']) : 1;
		$limit = 50;
		$filters = array('filter_search' => $filter_search, 'is_current' => 1, 'start' => ($page - 1) * $limit, 'limit' => $limit);
		$total = $this->model_extension_module_activeshop_importer->getTotalSupplierCategories($filters);
		$categories = $this->model_extension_module_activeshop_importer->getSupplierCategories($filters);
		$paths = array();
		foreach ($categories as $category) {
			$paths[] = $category['category_path'];
		}
		$mappings = $this->model_extension_module_activeshop_importer->getCategoryMappings($paths);

		foreach ($categories as &$category) {
			$mapping = isset($mappings[$category['category_path']]) ? $mappings[$category['category_path']] : array();
			$category['category_id'] = !empty($mapping['category_id']) ? (int)$mapping['category_id'] : 0;
			$category['local_category_name'] = !empty($mapping['category_name']) ? $mapping['category_name'] : '';
		}
		unset($category);

		$data = $this->language->all();
		$data['categories'] = $categories;
		$data['filter_search'] = $filter_search;
		$data['csrf_token'] = $this->getCsrfToken();
		$data['user_token'] = $this->session->data['user_token'];
		$data['active_tab'] = 'categories';
		$data['breadcrumbs'] = $this->getBreadcrumbs($this->language->get('heading_categories'), self::ROUTE . '/categories');
		$data['products_url'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true);
		$data['categories_url'] = $this->url->link(self::ROUTE . '/categories', 'user_token=' . $this->session->data['user_token'], true);
		$data['settings_url'] = $this->url->link(self::ROUTE . '/settings', 'user_token=' . $this->session->data['user_token'], true);
		$data['save_url'] = $this->url->link(self::ROUTE . '/saveCategoryMappings', 'user_token=' . $this->session->data['user_token'] . '&filter_search=' . rawurlencode($filter_search) . '&page=' . $page, true);
		$data['filter_url'] = $this->url->link(self::ROUTE . '/categories', 'user_token=' . $this->session->data['user_token'], true);
		$data['category_autocomplete_url'] = html_entity_decode($this->url->link('catalog/category/autocomplete', 'user_token=' . $this->session->data['user_token'] . '&filter_name=', true), ENT_QUOTES, 'UTF-8');
		$data['cancel'] = $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true);

		$pagination = new Pagination();
		$pagination->total = $total;
		$pagination->page = $page;
		$pagination->limit = $limit;
		$pagination->url = $this->url->link(self::ROUTE . '/categories', 'user_token=' . $this->session->data['user_token'] . '&filter_search=' . rawurlencode($filter_search) . '&page={page}', true);
		$data['pagination'] = $pagination->render();
		$data['results'] = sprintf($this->language->get('text_pagination'), $total ? (($page - 1) * $limit) + 1 : 0, min($page * $limit, $total), $total, $total ? ceil($total / $limit) : 1);

		$this->addFlashMessages($data);
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view(self::ROUTE . '_categories', $data));
	}

	public function saveCategoryMappings() {
		$this->load->language(self::ROUTE);

		if (!$this->validateMutation()) {
			$this->redirectWithErrors(self::ROUTE . '/categories');
			return;
		}

		$map = array();
		$rows = isset($this->request->post['mapping']) ? (array)$this->request->post['mapping'] : array();
		foreach ($rows as $row) {
			$path = isset($row['path']) ? trim($row['path']) : '';
			if ($path === '') {
				continue;
			}
			$map[$path] = isset($row['category_id']) ? (int)$row['category_id'] : 0;
		}

		$this->load->model(self::ROUTE);
		$this->model_extension_module_activeshop_importer->saveCategoryMappings($map);
		$this->session->data['success'] = $this->language->get('text_category_map_success');
		$this->response->redirect($this->url->link(self::ROUTE . '/categories', 'user_token=' . $this->session->data['user_token'] . $this->buildCategoryFilterUrl(), true));
	}

	public function settings() {
		$this->load->language(self::ROUTE);
		$this->document->setTitle($this->language->get('heading_settings'));
		$this->load->model(self::ROUTE);
		$this->load->model('localisation/tax_class');
		$this->load->model('localisation/stock_status');
		$this->load->model('localisation/weight_class');

		$feed_service = $this->getFeedService();
		$data = $this->language->all();
		$data['settings'] = $this->getSettings();
		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();
		$data['stock_statuses'] = $this->model_localisation_stock_status->getStockStatuses();
		$data['weight_classes'] = $this->model_localisation_weight_class->getWeightClasses();
		$data['default_category_name'] = $this->getCategoryName($data['settings']['default_category_id']);
		$data['feed_url'] = $feed_service->getFeedUrl();
		$cron_key = trim((string)$this->config->get('module_activeshop_importer_cron_key'));
		$data['can_manage_cron'] = $this->user->hasPermission('modify', self::ROUTE);
		$data['cron_url'] = $data['can_manage_cron'] && strlen($cron_key) >= 32
			? HTTPS_CATALOG . 'update.php?key=' . rawurlencode($cron_key) . '&mode=live'
			: '';
		$data['feed_metadata'] = $this->getFeedMetadata();
		$data['csrf_token'] = $this->getCsrfToken();
		$data['user_token'] = $this->session->data['user_token'];
		$data['active_tab'] = 'settings';
		$data['breadcrumbs'] = $this->getBreadcrumbs($this->language->get('heading_settings'), self::ROUTE . '/settings');
		$data['products_url'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true);
		$data['categories_url'] = $this->url->link(self::ROUTE . '/categories', 'user_token=' . $this->session->data['user_token'], true);
		$data['settings_url'] = $this->url->link(self::ROUTE . '/settings', 'user_token=' . $this->session->data['user_token'], true);
		$data['save_url'] = $this->url->link(self::ROUTE . '/saveSettings', 'user_token=' . $this->session->data['user_token'], true);
		$data['category_autocomplete_url'] = html_entity_decode($this->url->link('catalog/category/autocomplete', 'user_token=' . $this->session->data['user_token'] . '&filter_name=', true), ENT_QUOTES, 'UTF-8');
		$data['cancel'] = $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true);

		$this->addFlashMessages($data);
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view(self::ROUTE . '_settings', $data));
	}

	public function saveSettings() {
		$this->load->language(self::ROUTE);

		if (!$this->validateMutation()) {
			$this->redirectWithErrors(self::ROUTE . '/settings');
			return;
		}

		try {
			$markup = $this->normalizeMarkup(isset($this->request->post['markup']) ? $this->request->post['markup'] : 0);
		} catch (InvalidArgumentException $e) {
			$this->session->data['warning'] = $e->getMessage();
			$this->response->redirect($this->url->link(self::ROUTE . '/settings', 'user_token=' . $this->session->data['user_token'], true));
			return;
		}

		$existing_action = isset($this->request->post['existing_action']) ? $this->request->post['existing_action'] : 'skip';
		if (!in_array($existing_action, array('skip', 'price_quantity'), true)) {
			$existing_action = 'skip';
		}

		$settings = array(
			'module_activeshop_importer_status' => 1,
			'module_activeshop_importer_markup' => $markup,
			'module_activeshop_importer_default_category_id' => isset($this->request->post['default_category_id']) ? max(0, (int)$this->request->post['default_category_id']) : 0,
			'module_activeshop_importer_tax_class_id' => isset($this->request->post['tax_class_id']) ? max(0, (int)$this->request->post['tax_class_id']) : 0,
			'module_activeshop_importer_stock_status_id' => isset($this->request->post['stock_status_id']) ? max(0, (int)$this->request->post['stock_status_id']) : 0,
			'module_activeshop_importer_weight_class_id' => isset($this->request->post['weight_class_id']) ? max(0, (int)$this->request->post['weight_class_id']) : 0,
			'module_activeshop_importer_new_product_status' => !empty($this->request->post['new_product_status']) ? 1 : 0,
			'module_activeshop_importer_import_images' => !empty($this->request->post['import_images']) ? 1 : 0,
			'module_activeshop_importer_existing_action' => $existing_action,
			'module_activeshop_importer_cron_key' => $this->getOrCreateCronKey()
		);

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('module_activeshop_importer', $settings);
		$this->session->data['success'] = $this->language->get('text_settings_success');
		$this->response->redirect($this->url->link(self::ROUTE . '/settings', 'user_token=' . $this->session->data['user_token'], true));
	}

	public function menu(&$route, &$data) {
		if (!$this->user->hasPermission('access', self::ROUTE)) {
			return;
		}

		$this->load->language(self::ROUTE);
		foreach ($data['menus'] as $menu) {
			if (isset($menu['id']) && $menu['id'] === 'menu-suppliers') {
				return;
			}
		}

		$data['menus'][] = array(
			'id' => 'menu-suppliers',
			'icon' => 'fa-truck',
			'name' => $this->language->get('text_suppliers_menu'),
			'href' => '',
			'children' => array(array(
				'name' => $this->language->get('heading_title'),
				'href' => $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true),
				'children' => array()
			))
		);
	}

	public function install() {
		if (!$this->user->hasPermission('modify', 'extension/extension/module')) {
			return;
		}

		$this->load->model(self::ROUTE);
		$this->model_extension_module_activeshop_importer->install();

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('module_activeshop_importer', array(
			'module_activeshop_importer_status' => 1,
			'module_activeshop_importer_markup' => 0,
			'module_activeshop_importer_default_category_id' => $this->model_extension_module_activeshop_importer->getDefaultCategoryId(),
			'module_activeshop_importer_tax_class_id' => $this->existingId('tax_class', 'tax_class_id', 11),
			'module_activeshop_importer_stock_status_id' => $this->existingId('stock_status', 'stock_status_id', 5),
			'module_activeshop_importer_weight_class_id' => $this->existingId('weight_class', 'weight_class_id', 1),
			'module_activeshop_importer_new_product_status' => 0,
			'module_activeshop_importer_import_images' => 1,
			'module_activeshop_importer_existing_action' => 'skip',
			'module_activeshop_importer_cron_key' => $this->generateCronKey()
		));

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('wob_activeshop_importer_menu');
		$this->model_setting_event->addEvent('wob_activeshop_importer_menu', 'admin/view/common/column_left/before', self::ROUTE . '/menu');

		$this->load->model('setting/extension');
		// Remove the legacy dashboard card. The shortcut is rendered as a full-width
		// strip by common/dashboard so it cannot disturb the dashboard grid.
		$this->model_setting_extension->uninstall('dashboard', 'activeshop_importer');
	}

	public function uninstall() {
		if (!$this->user->hasPermission('modify', 'extension/extension/module')) {
			return;
		}

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('wob_activeshop_importer_menu');

		$this->load->model('setting/extension');
		$this->model_setting_extension->uninstall('dashboard', 'activeshop_importer');

		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('module_activeshop_importer');
		// Supplier mappings and audit logs are intentionally retained.
	}

	private function importOneProduct($row, $markup, $existing_action, $default_category_id, $settings, $feed) {
		$status_key = $this->resolveProductStatus($row);
		if ($status_key === 'conflict') {
			throw new RuntimeException($this->language->get('error_product_conflict'));
		}
		if (empty($row['is_current'])) {
			throw new RuntimeException($this->language->get('error_product_missing_feed'));
		}

		$payload = !empty($row['payload']) && is_array($row['payload']) ? $row['payload'] : json_decode(isset($row['payload']) ? $row['payload'] : '', true);
		if (!is_array($payload)) {
			throw new RuntimeException($this->language->get('error_payload'));
		}

		$sku = trim(isset($payload['sku']) ? $payload['sku'] : '');
		$name = trim(isset($payload['name']) ? $payload['name'] : '');
		$feed_price = isset($payload['feed_price']) ? (float)$payload['feed_price'] : 0;
		if ($sku === '' || $name === '' || $feed_price <= 0) {
			throw new RuntimeException($this->language->get('error_required_feed_fields'));
		}

		$price = $feed->calculatePrice($feed_price, $markup);
		$quantity = max(0, isset($payload['quantity']) ? (int)$payload['quantity'] : 0);
		$category_path = $this->categoryPathToString(isset($payload['category_path']) ? $payload['category_path'] : '');
		$category_id = $this->resolveCategoryId($category_path, $default_category_id);
		if (!$category_id) {
			throw new RuntimeException($this->language->get('error_category_required'));
		}

		$source = array(
			'feed_price' => $feed_price,
			'markup' => $markup,
			'calculated_price' => $price,
			'quantity' => $quantity,
			'ean' => isset($payload['ean']) ? $payload['ean'] : '',
			'category_path' => $category_path
		);

		if (!empty($row['product_id'])) {
			$product_id = (int)$row['product_id'];
			$before = array(
				'price' => isset($row['local_price']) ? (float)$row['local_price'] : null,
				'quantity' => isset($row['local_quantity']) ? (int)$row['local_quantity'] : null
			);
			if ($existing_action === 'skip') {
				return array(
					'count_key' => 'skipped', 'product_id' => $product_id, 'action' => 'link', 'status' => 'skipped',
					'before' => $before, 'after' => $before, 'message' => $this->language->get('text_existing_skipped')
				);
			}

			$this->model_extension_module_activeshop_importer->updateExistingProductTargeted($product_id, array(
				'price' => $price,
				'quantity' => $quantity,
				'stock_status_id' => (int)$settings['stock_status_id']
			));
			$this->model_extension_module_activeshop_importer->linkProduct((int)$row['supplier_product_id'], $product_id, $source);
			return array(
				'count_key' => 'updated', 'product_id' => $product_id, 'action' => 'update', 'status' => 'completed',
				'before' => $before, 'after' => array('price' => $price, 'quantity' => $quantity), 'message' => ''
			);
		}

		$localized_content = $this->translateNewProductContent($payload, $feed);

		$images = array();
		if (!empty($settings['import_images']) && !empty($payload['images']) && is_array($payload['images'])) {
			$images = $feed->downloadImages($payload['images'], $sku, DIR_IMAGE);
		}

		$manufacturer_id = $this->model_extension_module_activeshop_importer->ensureManufacturer($feed->sanitizePlainText(isset($payload['brand']) ? $payload['brand'] : ''));
		$product_data = $this->buildNewProductData($payload, $price, $quantity, $category_id, $manufacturer_id, $images, $settings, $feed, $localized_content);
		$product_id = (int)$this->model_catalog_product->addProduct($product_data);
		if (!$product_id) {
			throw new RuntimeException($this->language->get('error_product_create'));
		}

		$this->model_extension_module_activeshop_importer->linkProduct((int)$row['supplier_product_id'], $product_id, $source);
		return array(
			'count_key' => 'created', 'product_id' => $product_id, 'action' => 'create', 'status' => 'completed',
			'before' => array(), 'after' => array('price' => $price, 'quantity' => $quantity), 'message' => ''
		);
	}

	private function buildNewProductData($payload, $price, $quantity, $category_id, $manufacturer_id, $images, $settings, $feed, $localized_content) {
		$name = $localized_content['en']['name'];
		$product_description = array();

		foreach ($this->model_extension_module_activeshop_importer->getLanguages() as $language) {
			$language_code = strtolower(str_replace('_', '-', isset($language['code']) ? $language['code'] : ''));
			$content = strpos($language_code, 'hr') === 0 ? $localized_content['hr'] : $localized_content['en'];
			$product_description[(int)$language['language_id']] = array(
				'name' => $content['name'],
				'description' => $content['description'],
				'tag' => '',
				'meta_title' => $content['name'],
				'meta_description' => '',
				'meta_keyword' => ''
			);
		}

		$product_images = array();
		foreach ($images as $index => $image) {
			if ($index === 0) {
				continue;
			}
			$product_images[] = array('image' => $image, 'sort_order' => $index - 1);
		}

		$dimensions = isset($payload['dimensions']) && is_array($payload['dimensions']) ? $payload['dimensions'] : array();
		$sku = $this->truncate($feed->sanitizePlainText($payload['sku']), 64);

		return array(
			'model' => $sku,
			'sku' => $sku,
			'upc' => '',
			'ean' => isset($payload['ean']) ? $this->truncate($feed->sanitizePlainText($payload['ean']), 14) : '',
			'jan' => '',
			'isbn' => '',
			'mpn' => '',
			'location' => 'ActiveShop',
			'quantity' => $quantity,
			'minimum' => 1,
			'subtract' => 1,
			'stock_status_id' => (int)$settings['stock_status_id'],
			'date_available' => date('Y-m-d'),
			'manufacturer_id' => (int)$manufacturer_id,
			'shipping' => 1,
			'price' => $price,
			'points' => 0,
			'weight' => max(0, isset($payload['weight']) ? (float)$payload['weight'] : 0),
			'weight_class_id' => (int)$settings['weight_class_id'],
			'length' => max(0, isset($dimensions['length']) ? (float)$dimensions['length'] : 0),
			'width' => max(0, isset($dimensions['width']) ? (float)$dimensions['width'] : 0),
			'height' => max(0, isset($dimensions['height']) ? (float)$dimensions['height'] : 0),
			'length_class_id' => 2,
			'status' => (int)$settings['new_product_status'],
			'tax_class_id' => (int)$settings['tax_class_id'],
			'sort_order' => 0,
			'image' => !empty($images[0]) ? $images[0] : '',
			'product_description' => $product_description,
			'product_store' => array(0),
			'product_category' => array($category_id),
			'product_image' => $product_images,
			'product_attribute' => array(),
			'product_filter' => array(),
			'product_related' => array(),
			'product_download' => array(),
			'product_layout' => array(),
			'product_seo_url' => $this->model_extension_module_activeshop_importer->buildUniqueSeoUrls($name, $sku)
		);
	}

	private function translateNewProductContent($payload, $feed) {
		$english_name = $this->truncate($feed->sanitizePlainText(isset($payload['name']) ? $payload['name'] : ''), 255);
		$english_description = $feed->sanitizeDescription(isset($payload['description']) ? $payload['description'] : '');

		if ($english_name === '') {
			throw new RuntimeException($this->language->get('error_required_feed_fields'));
		}

		try {
			$translated = $this->getTranslator()->translateProduct($english_name, $english_description, 'en', 'hr');
			$croatian_name = $this->truncate($feed->sanitizePlainText($translated['name']), 255);
			$croatian_description = $feed->sanitizeDescription($translated['description']);
		} catch (Throwable $exception) {
			throw new RuntimeException(sprintf($this->language->get('error_translation'), $exception->getMessage()), 0, $exception);
		}

		if ($croatian_name === '' || ($english_description !== '' && $croatian_description === '')) {
			throw new RuntimeException(sprintf($this->language->get('error_translation'), $this->language->get('error_translation_empty')));
		}

		return array(
			'en' => array('name' => $english_name, 'description' => $english_description),
			'hr' => array('name' => $croatian_name, 'description' => $croatian_description)
		);
	}

	private function getTranslator() {
		if ($this->translator) {
			return $this->translator;
		}

		$file = DIR_SYSTEM . 'library/wob_supplier/free_google_translate.php';
		if (!is_file($file)) {
			throw new RuntimeException('Free Google translation helper is missing.');
		}

		require_once $file;
		$this->translator = new WobSupplierFreeGoogleTranslate(DIR_STORAGE . 'cache/activeshop/translations');

		return $this->translator;
	}

	private function resolveCategoryId($path, $default_category_id) {
		$mappings = $this->model_extension_module_activeshop_importer->getCategoryMappings(array($path));
		if ($path !== '' && !empty($mappings[$path]['category_id'])) {
			$mapped_category_id = (int)$mappings[$path]['category_id'];
			if ($this->existingId('category', 'category_id', $mapped_category_id)) {
				return $mapped_category_id;
			}
		}
		$default_category_id = (int)$default_category_id;
		return $default_category_id && $this->existingId('category', 'category_id', $default_category_id) ? $default_category_id : 0;
	}

	private function getSettings() {
		$this->load->model(self::ROUTE);
		$defaults = array(
			'markup' => 0,
			'default_category_id' => $this->model_extension_module_activeshop_importer->getDefaultCategoryId(),
			'tax_class_id' => 11,
			'stock_status_id' => 5,
			'weight_class_id' => 1,
			'new_product_status' => 0,
			'import_images' => 1,
			'existing_action' => 'skip'
		);

		foreach ($defaults as $key => $default) {
			$value = $this->config->get('module_activeshop_importer_' . $key);
			$defaults[$key] = $value === null ? $default : $value;
		}

		$defaults['markup'] = (float)$defaults['markup'];
		return $defaults;
	}

	private function getProductFilters() {
		$filter_status = isset($this->request->get['filter_status']) ? trim($this->request->get['filter_status']) : 'new';
		if ($filter_status === 'all' || $filter_status === '') {
			$filter_status = '';
		} elseif (!in_array($filter_status, array('new', 'existing', 'imported', 'conflict'), true)) {
			$filter_status = 'new';
		}

		return array(
			'filter_search' => isset($this->request->get['filter_search']) ? trim($this->request->get['filter_search']) : '',
			'filter_status' => $filter_status,
			'filter_category' => isset($this->request->get['filter_category']) ? trim($this->request->get['filter_category']) : '',
			'filter_brand' => isset($this->request->get['filter_brand']) ? trim($this->request->get['filter_brand']) : '',
			'is_current' => 1,
			'sort' => isset($this->request->get['sort']) ? $this->request->get['sort'] : 'name',
			'order' => isset($this->request->get['order']) && strtoupper($this->request->get['order']) === 'DESC' ? 'DESC' : 'ASC'
		);
	}

	private function resolveProductStatus($product) {
		if (empty($product['is_current'])) {
			return 'missing';
		}
		if (!empty($product['match_status']) && strpos($product['match_status'], 'conflict') === 0) {
			return 'conflict';
		}
		if (!empty($product['product_id']) && !empty($product['last_imported'])) {
			return 'imported';
		}
		if (!empty($product['product_id'])) {
			return 'existing';
		}
		return 'new';
	}

	private function getStatusClass($status) {
		$classes = array('new' => 'info', 'existing' => 'warning', 'imported' => 'success', 'conflict' => 'danger', 'missing' => 'default');
		return isset($classes[$status]) ? $classes[$status] : 'default';
	}

	private function calculatePrice($feed_price, $markup) {
		return round((float)$feed_price * (1 + ((float)$markup / 100)), 2);
	}

	private function normalizeMarkup($value) {
		$value = str_replace(',', '.', trim((string)$value));
		if ($value === '' || !is_numeric($value)) {
			throw new InvalidArgumentException($this->language->get('error_markup'));
		}
		$value = (float)$value;
		if ($value < 0 || $value > 1000) {
			throw new InvalidArgumentException($this->language->get('error_markup_range'));
		}
		return round($value, 4);
	}

	private function getOrCreateCronKey() {
		$key = trim((string)$this->config->get('module_activeshop_importer_cron_key'));
		return strlen($key) >= 32 ? $key : $this->generateCronKey();
	}

	private function generateCronKey() {
		return bin2hex(random_bytes(32));
	}

	private function validateMutation() {
		if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->error['warning'] = $this->language->get('error_method');
		} elseif (!$this->user->hasPermission('modify', self::ROUTE)) {
			$this->error['warning'] = $this->language->get('error_permission');
		} elseif (empty($this->request->post['csrf_token']) || empty($this->session->data['activeshop_importer_csrf']) || !hash_equals($this->session->data['activeshop_importer_csrf'], (string)$this->request->post['csrf_token'])) {
			$this->error['warning'] = $this->language->get('error_csrf');
		}
		return !$this->error;
	}

	private function getCsrfToken() {
		if (empty($this->session->data['activeshop_importer_csrf'])) {
			$this->session->data['activeshop_importer_csrf'] = bin2hex(random_bytes(32));
		}
		return $this->session->data['activeshop_importer_csrf'];
	}

	private function getFeedService() {
		require_once DIR_SYSTEM . 'library/wob_supplier/activeshop_feed.php';
		return new WobSupplierActiveShopFeed();
	}

	private function getFeedCacheFile() {
		return rtrim(DIR_CACHE, '/\\') . '/activeshop-importer/feed.xml';
	}

	private function getFeedMetadata() {
		try {
			$metadata = $this->getFeedService()->getCacheMetadata($this->getFeedCacheFile());
			$metadata['exists'] = true;
			return $metadata;
		} catch (Throwable $e) {
			return array('exists' => false, 'error' => $e->getMessage());
		}
	}

	private function getCategoryName($category_id) {
		if (!(int)$category_id) {
			return '';
		}
		$this->load->model('catalog/category');
		$category = $this->model_catalog_category->getCategory((int)$category_id);
		return !empty($category['name']) ? $category['name'] : '';
	}

	private function getBreadcrumbs($title, $route) {
		return array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)),
			array('text' => $title, 'href' => $this->url->link($route, 'user_token=' . $this->session->data['user_token'], true))
		);
	}

	private function addFlashMessages(&$data) {
		$data['error_warning'] = '';
		$data['success'] = '';
		if (!empty($this->session->data['warning'])) {
			$data['error_warning'] = $this->session->data['warning'];
			unset($this->session->data['warning']);
		}
		if (!empty($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		}
	}

	private function redirectWithErrors($route) {
		$this->session->data['warning'] = isset($this->error['warning']) ? $this->error['warning'] : $this->language->get('error_permission');
		$this->response->redirect($this->url->link($route, 'user_token=' . $this->session->data['user_token'], true));
	}

	private function wantsJsonResponse() {
		return isset($this->request->post['ajax']) && (string)$this->request->post['ajax'] === '1';
	}

	private function sendJson($data) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

	private function respondToImportError($message, $wants_json) {
		if ($wants_json) {
			$this->sendJson(array(
				'success' => false,
				'item_success' => false,
				'retryable' => false,
				'error' => (string)$message
			));
			return;
		}

		$this->session->data['warning'] = (string)$message;
		$this->redirectToProducts();
	}

	private function getProductsRedirectUrl() {
		return html_entity_decode($this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'] . $this->buildFilterUrl(), true), ENT_QUOTES, 'UTF-8');
	}

	private function redirectToProducts() {
		$this->response->redirect($this->getProductsRedirectUrl());
	}

	private function buildFilterUrl($exclude = array()) {
		$url = '';
		$keys = array('filter_search', 'filter_status', 'filter_category', 'filter_brand', 'sort', 'order', 'page');
		foreach ($keys as $key) {
			if (!in_array($key, $exclude, true) && isset($this->request->get[$key]) && $this->request->get[$key] !== '') {
				$url .= '&' . $key . '=' . rawurlencode($this->request->get[$key]);
			}
		}
		return $url;
	}

	private function buildCategoryFilterUrl() {
		$url = '';
		foreach (array('filter_search', 'page') as $key) {
			if (isset($this->request->get[$key]) && $this->request->get[$key] !== '') {
				$url .= '&' . $key . '=' . rawurlencode($this->request->get[$key]);
			}
		}
		return $url;
	}

	private function prepareLongRequest() {
		if (function_exists('set_time_limit')) {
			@set_time_limit(300);
		}

		$current_memory_limit = ini_get('memory_limit');
		if ($current_memory_limit !== false && trim((string)$current_memory_limit) !== '-1' && $this->iniSizeToBytes($current_memory_limit) < 268435456) {
			@ini_set('memory_limit', '256M');
		}
	}

	private function iniSizeToBytes($value) {
		$value = trim((string)$value);
		if ($value === '') {
			return 0;
		}

		$unit = strtolower(substr($value, -1));
		$bytes = (float)$value;
		if ($unit === 'g') {
			$bytes *= 1024;
			$unit = 'm';
		}
		if ($unit === 'm') {
			$bytes *= 1024;
			$unit = 'k';
		}
		if ($unit === 'k') {
			$bytes *= 1024;
		}

		return (int)$bytes;
	}

	private function acquireOperationLock() {
		$directory = rtrim(DIR_CACHE, '/\\') . '/activeshop-importer';
		if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
			return false;
		}

		$handle = @fopen($directory . '/operation.lock', 'c');
		if ($handle === false) {
			return false;
		}
		if (!@flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			return false;
		}
		return $handle;
	}

	private function releaseOperationLock($handle) {
		if (is_resource($handle)) {
			@flock($handle, LOCK_UN);
			@fclose($handle);
		}
	}

	private function categoryPathToString($path) {
		if (is_array($path)) {
			$clean = array();
			foreach ($path as $part) {
				$part = trim((string)$part);
				if ($part !== '') {
					$clean[] = $part;
				}
			}
			return implode(' > ', $clean);
		}
		return trim((string)$path);
	}

	private function existingId($table, $column, $preferred) {
		$query = $this->db->query("SELECT `" . $this->db->escape($column) . "` AS id FROM `" . DB_PREFIX . $this->db->escape($table) . "` WHERE `" . $this->db->escape($column) . "` = '" . (int)$preferred . "' LIMIT 1");
		return $query->num_rows ? (int)$query->row['id'] : 0;
	}

	private function truncate($value, $length) {
		return utf8_strlen($value) > $length ? utf8_substr($value, 0, $length) : $value;
	}
}
