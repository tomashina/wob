<?php
class ControllerExtensionModuleGlobalPriceAdjustment extends Controller {
	const ROUTE = 'extension/module/global_price_adjustment';
	const MIN_PERCENT = 0.01;
	const MAX_PERCENT = 1000.00;

	private $error = array();

	public function index() {
		$this->load->language(self::ROUTE);
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model(self::ROUTE);

		$data = $this->language->all();
		$data['identity_error'] = '';
		$data['summary'] = array(
			'total_products' => 0,
			'eligible_count' => 0,
			'excluded_total' => 0,
			'excluded_emovex' => 0,
			'excluded_manuela_picard' => 0,
			'zero_price_count' => 0,
			'special_count' => 0
		);
		$module_installed = $this->isInstalled();

		if (!$module_installed) {
			$data['identity_error'] = $this->language->get('error_not_installed');
		} else {
			try {
				$data['summary'] = array_merge($data['summary'], $this->model_extension_module_global_price_adjustment->getCurrentSummary());
			} catch (Throwable $exception) {
				$data['identity_error'] = strpos($exception->getMessage(), 'already running') !== false
					? $this->language->get('error_busy')
					: sprintf($this->language->get('error_exclusion_identity'), $exception->getMessage());
			}
		}

		$run_id = isset($this->request->get['price_run_id']) ? max(0, (int)$this->request->get['price_run_id']) : 0;
		$data['price_run'] = array();
		$data['run_items'] = array();

		if ($module_installed && !$data['identity_error'] && $run_id) {
			$data['price_run'] = $this->model_extension_module_global_price_adjustment->getRun($run_id, $this->user->getId());
			if ($data['price_run']) {
				$data['price_run']['status_text'] = $this->runStatusText($data['price_run']['status']);
				$data['run_items'] = $this->model_extension_module_global_price_adjustment->getRunSample($run_id, 50);
				foreach ($data['run_items'] as &$run_item) {
					$run_item['status_text'] = $this->itemStatusText($run_item['status']);
					$run_item['message_text'] = $this->itemMessageText($run_item['message']);
				}
				unset($run_item);
			}
		}

		$data['recent_runs'] = $module_installed && !$data['identity_error']
			? $this->model_extension_module_global_price_adjustment->getRecentRuns(10, $this->user->getId())
			: array();
		foreach ($data['recent_runs'] as &$recent_run) {
			$recent_run['status_text'] = $this->runStatusText($recent_run['status']);
			$recent_run_id = isset($recent_run['price_run_id']) ? (int)$recent_run['price_run_id'] : (isset($recent_run['run_id']) ? (int)$recent_run['run_id'] : 0);
			$recent_run['view_url'] = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'] . '&price_run_id=' . $recent_run_id, true);
		}
		unset($recent_run);
		$data['exclusions'] = array();
		if ($module_installed && !$data['identity_error']) {
			try {
				$data['exclusions'] = $this->model_extension_module_global_price_adjustment->getExclusions(100);
			} catch (Throwable $exception) {
				$data['identity_error'] = strpos($exception->getMessage(), 'already running') !== false
					? $this->language->get('error_busy')
					: sprintf($this->language->get('error_exclusion_identity'), $exception->getMessage());
			}
		}
		foreach ($data['exclusions'] as &$exclusion) {
			$rules = isset($exclusion['rule_codes']) ? $exclusion['rule_codes'] : '';
			$exclusion['reason_text'] = strpos($rules, 'emovex_') !== false
				? $this->language->get('text_exclusion_emovex')
				: $this->language->get('text_exclusion_manuela_picard');
		}
		unset($exclusion);
		$data['csrf_token'] = $this->getCsrfToken();
		$data['user_token'] = $this->session->data['user_token'];
		$data['min_percent'] = self::MIN_PERCENT;
		$data['max_percent'] = self::MAX_PERCENT;
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'], true))
		);
		$data['preview_url'] = $this->url->link(self::ROUTE . '/preview', 'user_token=' . $this->session->data['user_token'], true);
		$data['apply_url'] = $this->url->link(self::ROUTE . '/apply', 'user_token=' . $this->session->data['user_token'], true);
		$data['rollback_url'] = $this->url->link(self::ROUTE . '/rollback', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true);

		$this->addFlashMessages($data);
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	public function preview() {
		$this->load->language(self::ROUTE);

		if (!$this->validateMutation()) {
			$this->redirectWithError();
			return;
		}

		try {
			$percent = $this->normalizePercent(isset($this->request->post['percent']) ? $this->request->post['percent'] : '');
		} catch (InvalidArgumentException $exception) {
			$this->session->data['warning'] = $exception->getMessage();
			$this->redirectToModule();
			return;
		}

		$lock = $this->acquireOperationLock();
		if (!$lock) {
			$this->session->data['warning'] = $this->language->get('error_busy');
			$this->redirectToModule();
			return;
		}

		try {
			$this->load->model(self::ROUTE);
			$run_id = $this->model_extension_module_global_price_adjustment->createPreview($this->user->getId(), $percent);
			$this->session->data['success'] = sprintf($this->language->get('text_preview_created'), $percent);
			$this->releaseOperationLock($lock);
			$this->redirectToModule($run_id);
		} catch (Throwable $exception) {
			$this->releaseOperationLock($lock);
			$this->session->data['warning'] = sprintf($this->language->get('error_preview'), $exception->getMessage());
			$this->redirectToModule();
		}
	}

	public function apply() {
		$this->load->language(self::ROUTE);

		if (!$this->validateMutation()) {
			$this->redirectWithError();
			return;
		}

		$run_id = isset($this->request->post['price_run_id']) ? max(0, (int)$this->request->post['price_run_id']) : 0;
		if (!$run_id || empty($this->request->post['confirm_apply'])) {
			$this->session->data['warning'] = $this->language->get('error_confirmation');
			$this->redirectToModule($run_id);
			return;
		}

		$lock = $this->acquireOperationLock();
		if (!$lock) {
			$this->session->data['warning'] = $this->language->get('error_busy');
			$this->redirectToModule($run_id);
			return;
		}

		$model_loaded = false;
		try {
			$this->prepareLongRequest();
			$this->load->model(self::ROUTE);
			$model_loaded = true;
			$result = $this->model_extension_module_global_price_adjustment->applyRun($run_id, $this->user->getId());
			$this->cache->delete('product');
			$this->session->data['success'] = sprintf(
				$this->language->get('text_apply_success'),
				isset($result['applied_count']) ? (int)$result['applied_count'] : 0,
				isset($result['conflict_count']) ? (int)$result['conflict_count'] : 0,
				isset($result['failed_count']) ? (int)$result['failed_count'] : 0
			);
		} catch (Throwable $exception) {
			if ($model_loaded) {
				try {
					$this->model_extension_module_global_price_adjustment->failRun($run_id, $this->user->getId(), $exception->getMessage());
				} catch (Throwable $audit_exception) {
					$this->log->write('Global price audit failure: ' . $audit_exception->getMessage());
				}
			}
			$this->session->data['warning'] = sprintf($this->language->get('error_apply'), $exception->getMessage());
		}

		$this->releaseOperationLock($lock);
		$this->redirectToModule($run_id);
	}

	public function rollback() {
		$this->load->language(self::ROUTE);

		if (!$this->validateMutation()) {
			$this->redirectWithError();
			return;
		}

		$run_id = isset($this->request->post['price_run_id']) ? max(0, (int)$this->request->post['price_run_id']) : 0;
		if (!$run_id || empty($this->request->post['confirm_rollback'])) {
			$this->session->data['warning'] = $this->language->get('error_confirmation');
			$this->redirectToModule($run_id);
			return;
		}

		$lock = $this->acquireOperationLock();
		if (!$lock) {
			$this->session->data['warning'] = $this->language->get('error_busy');
			$this->redirectToModule($run_id);
			return;
		}

		$model_loaded = false;
		try {
			$this->prepareLongRequest();
			$this->load->model(self::ROUTE);
			$model_loaded = true;
			$result = $this->model_extension_module_global_price_adjustment->rollbackRun($run_id, $this->user->getId());
			$this->cache->delete('product');
			$this->session->data['success'] = sprintf(
				$this->language->get('text_rollback_success'),
				isset($result['rolled_back_count']) ? (int)$result['rolled_back_count'] : 0,
				isset($result['rollback_conflict_count']) ? (int)$result['rollback_conflict_count'] : 0
			);
		} catch (Throwable $exception) {
			if ($model_loaded) {
				try {
					$this->model_extension_module_global_price_adjustment->failRun($run_id, $this->user->getId(), $exception->getMessage());
				} catch (Throwable $audit_exception) {
					$this->log->write('Global price rollback audit failure: ' . $audit_exception->getMessage());
				}
			}
			$this->session->data['warning'] = sprintf($this->language->get('error_rollback'), $exception->getMessage());
		}

		$this->releaseOperationLock($lock);
		$this->redirectToModule($run_id);
	}

	public function menu(&$route, &$data) {
		if (!$this->user->hasPermission('access', self::ROUTE)) {
			return;
		}

		$this->load->language(self::ROUTE);
		foreach ($data['menus'] as $menu) {
			if (isset($menu['id']) && $menu['id'] === 'menu-global-prices') {
				return;
			}
		}

		$data['menus'][] = array(
			'id' => 'menu-global-prices',
			'icon' => 'fa-percent',
			'name' => $this->language->get('text_prices_menu'),
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
		$this->model_extension_module_global_price_adjustment->install();
		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('module_global_price_adjustment', array(
			'module_global_price_adjustment_status' => 1
		));
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('wob_global_price_adjustment_menu');
		$this->model_setting_event->addEvent('wob_global_price_adjustment_menu', 'admin/view/common/column_left/before', self::ROUTE . '/menu');
	}

	public function uninstall() {
		if (!$this->user->hasPermission('modify', 'extension/extension/module')) {
			return;
		}

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('wob_global_price_adjustment_menu');
		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('module_global_price_adjustment');
		// Audit history and permanent supplier exclusions are intentionally retained.
	}

	private function normalizePercent($value) {
		$value = str_replace(',', '.', trim((string)$value));
		if ($value === '' || !is_numeric($value)) {
			throw new InvalidArgumentException($this->language->get('error_percent'));
		}

		$percent = round((float)$value, 4);
		if ($percent < self::MIN_PERCENT || $percent > self::MAX_PERCENT) {
			throw new InvalidArgumentException(sprintf($this->language->get('error_percent_range'), self::MIN_PERCENT, self::MAX_PERCENT));
		}

		return $percent;
	}

	private function runStatusText($status) {
		$key = 'text_status_' . preg_replace('/[^a-z_]/', '', strtolower((string)$status));
		$text = $this->language->get($key);
		return $text === $key ? (string)$status : $text;
	}

	private function itemStatusText($status) {
		$key = 'text_item_status_' . preg_replace('/[^a-z_]/', '', strtolower((string)$status));
		$text = $this->language->get($key);
		return $text === $key ? (string)$status : $text;
	}

	private function itemMessageText($message) {
		$keys = array(
			'Product no longer exists.' => 'text_item_message_product_missing',
			'Product became excluded after preview.' => 'text_item_message_became_excluded',
			'Supplier changed after preview.' => 'text_item_message_supplier_changed',
			'Already at the target price; recovered idempotently.' => 'text_item_message_target_recovered',
			'Target price was reached outside this run before its update attempt.' => 'text_item_message_target_external',
			'Price changed after preview.' => 'text_item_message_price_changed',
			'CAS update prepared.' => 'text_item_message_update_prepared',
			'Compare-and-swap update was not applied.' => 'text_item_message_update_not_applied',
			'Update completed before an error response; recovered idempotently.' => 'text_item_message_update_recovered',
			'Product is now permanently excluded.' => 'text_item_message_now_excluded',
			'Already restored; recovered idempotently.' => 'text_item_message_restore_recovered',
			'Price was already changed outside this rollback.' => 'text_item_message_rollback_external',
			'Current price differs from the price written by this run.' => 'text_item_message_current_differs',
			'CAS rollback prepared.' => 'text_item_message_rollback_prepared',
			'Compare-and-swap rollback was not applied.' => 'text_item_message_rollback_not_applied',
			'Rollback completed before an error response; recovered idempotently.' => 'text_item_message_rollback_recovered',
			'Product disappeared while the interrupted update was being reconciled.' => 'text_item_message_reconcile_missing',
			'Interrupted update completed; reconciled from the catalog price.' => 'text_item_message_reconcile_updated',
			'Interrupted before the catalog price changed.' => 'text_item_message_reconcile_before',
			'Catalog price changed during the interrupted update.' => 'text_item_message_reconcile_changed'
		);

		if (isset($keys[(string)$message])) {
			return $this->language->get($keys[(string)$message]);
		}

		return (string)$message;
	}

	private function validateMutation() {
		if (!isset($this->request->server['REQUEST_METHOD']) || $this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->error['warning'] = $this->language->get('error_method');
		} elseif (!$this->isInstalled()) {
			$this->error['warning'] = $this->language->get('error_not_installed');
		} elseif (!$this->user->hasPermission('modify', self::ROUTE)) {
			$this->error['warning'] = $this->language->get('error_permission');
		} elseif (empty($this->request->post['csrf_token']) || empty($this->session->data['global_price_adjustment_csrf']) || !hash_equals($this->session->data['global_price_adjustment_csrf'], (string)$this->request->post['csrf_token'])) {
			$this->error['warning'] = $this->language->get('error_csrf');
		}

		return !$this->error;
	}

	private function isInstalled() {
		$query = $this->db->query("SELECT `extension_id` FROM `" . DB_PREFIX . "extension` WHERE `type` = 'module' AND `code` = 'global_price_adjustment' LIMIT 1");
		return (bool)$query->num_rows;
	}

	private function getCsrfToken() {
		if (empty($this->session->data['global_price_adjustment_csrf'])) {
			$this->session->data['global_price_adjustment_csrf'] = bin2hex(random_bytes(32));
		}
		return $this->session->data['global_price_adjustment_csrf'];
	}

	private function acquireOperationLock() {
		$directory = rtrim(DIR_CACHE, '/\\') . '/wob-price-adjustment';
		if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
			return false;
		}
		$handle = @fopen($directory . '/operation.lock', 'c');
		if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
			if ($handle) {
				fclose($handle);
			}
			return false;
		}
		return $handle;
	}

	private function releaseOperationLock($handle) {
		if (is_resource($handle)) {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	private function prepareLongRequest() {
		if (function_exists('set_time_limit')) {
			@set_time_limit(300);
		}
		@ini_set('memory_limit', '256M');
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

	private function redirectWithError() {
		$this->session->data['warning'] = isset($this->error['warning']) ? $this->error['warning'] : $this->language->get('error_permission');
		$this->redirectToModule();
	}

	private function redirectToModule($run_id = 0) {
		$url = 'user_token=' . $this->session->data['user_token'];
		if ((int)$run_id > 0) {
			$url .= '&price_run_id=' . (int)$run_id;
		}
		$this->response->redirect($this->url->link(self::ROUTE, $url, true));
	}
}
