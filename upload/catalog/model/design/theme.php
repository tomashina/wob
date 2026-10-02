<?php
class ModelDesignTheme extends Model {
	private $themes = array();

	public function getTheme($route, $theme) {
		$store_id = (int)$this->config->get('config_store_id');
		$cache_key = $store_id . '.' . $theme;

		if (!isset($this->themes[$cache_key])) {
			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "theme WHERE store_id = '" . $store_id . "' AND theme = '" . $this->db->escape($theme) . "'");
			$this->themes[$cache_key] = array();

			foreach ($query->rows as $theme_info) {
				if (!isset($this->themes[$cache_key][$theme_info['route']])) {
					$this->themes[$cache_key][$theme_info['route']] = $theme_info;
				}
			}
		}

		return isset($this->themes[$cache_key][$route]) ? $this->themes[$cache_key][$route] : array();
	}
}
