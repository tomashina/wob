<?php
class ControllerCommonHeader extends Controller {
	public function index() {
		// Analytics
		$this->load->model('setting/extension');

		$data['analytics'] = array();

		$analytics = $this->model_setting_extension->getExtensions('analytics');

		foreach ($analytics as $analytic) {
			if ($this->config->get('analytics_' . $analytic['code'] . '_status')) {
				$data['analytics'][] = $this->load->controller('extension/analytics/' . $analytic['code'], $this->config->get('analytics_' . $analytic['code'] . '_status'));
			}
		}

		$data['gtm_id'] = '';

		if (is_file(DIR_APPLICATION . 'model/extension/cmpltguagaf.php')) {
			$this->load->model('extension/cmpltguagaf');
			$data['gtm_id'] = $this->model_extension_cmpltguagaf->getGtmId();
		}

		if ($this->request->server['HTTPS']) {
			$server = $this->config->get('config_ssl');
		} else {
			$server = $this->config->get('config_url');
		}

		if (is_file(DIR_IMAGE . $this->config->get('config_icon'))) {
			$this->document->addLink($server . 'image/' . $this->config->get('config_icon'), 'icon');
		}

		$data['title'] = $this->document->getTitle();

		$data['base'] = $server;
		$data['description'] = $this->document->getDescription();
		$data['keywords'] = $this->document->getKeywords();
		$data['links'] = $this->document->getLinks();
		$data['styles'] = $this->document->getStyles();
		$data['scripts'] = $this->document->getScripts('header');
		$data['lang'] = $this->language->get('code');
		$data['direction'] = $this->language->get('direction');
		$data['robots'] = $this->getRobotsDirective();
		$requestUri = isset($this->request->server['REQUEST_URI']) ? $this->request->server['REQUEST_URI'] : '/';
		$data['current_url'] = rtrim($server, '/') . '/' . ltrim($requestUri, '/');
		$data['social_description'] = $data['description'] ? $data['description'] : $this->config->get('config_meta_description');
		$data['social_image'] = $server . 'image/catalog/banner-main-category/facebook-wob.png';
		$data['social_og_fallback'] = strpos($data['robots'], 'noindex') !== 0 && empty($data['opengraphs']);
		$data['social_twitter_fallback'] = strpos($data['robots'], 'noindex') !== 0 && empty($data['twittercards']);

		$data['name'] = $this->config->get('config_name');

		if (is_file(DIR_IMAGE . $this->config->get('config_logo'))) {
			$data['logo'] = $server . 'image/' . $this->config->get('config_logo');
		} else {
			$data['logo'] = '';
		}

		$this->load->language('common/header');
		$data['text_search'] = $this->language->get('text_search');
		$data['text_shopping_cart'] = $this->language->get('text_shopping_cart');
		$data['text_account'] = $this->language->get('text_account');
		$isCroatian = strpos(strtolower((string)$data['lang']), 'hr') === 0;
		$data['text_menu'] = $isCroatian ? 'Izbornik' : 'Menu';
		$data['text_close_menu'] = $isCroatian ? 'Zatvori izbornik' : 'Close menu';
		$data['text_back'] = $isCroatian ? 'Natrag' : 'Back';
		$data['text_skip_content'] = $isCroatian ? 'Preskoči na sadržaj' : 'Skip to content';
		$data['home_heading_title'] = $isCroatian
			? 'Profesionalna frizerska i kozmetička oprema za salone'
			: 'Professional hair and beauty salon equipment';

		// Wishlist
		if ($this->customer->isLogged()) {
			$this->load->model('account/wishlist');

			$data['text_wishlist'] = sprintf($this->language->get('text_wishlist'), $this->model_account_wishlist->getTotalWishlist());
		} else {
			$data['text_wishlist'] = sprintf($this->language->get('text_wishlist'), (isset($this->session->data['wishlist']) ? count($this->session->data['wishlist']) : 0));
		}

		$data['text_logged'] = sprintf($this->language->get('text_logged'), $this->url->link('account/account', '', true), $this->customer->getFirstName(), $this->url->link('account/logout', '', true));
		
		$data['home'] = $this->url->link('common/home');
		$data['wishlist'] = $this->url->link('account/wishlist', '', true);
		$data['logged'] = $this->customer->isLogged();
		$data['account'] = $this->url->link('account/account', '', true);
		$data['register'] = $this->url->link('account/register', '', true);
		$data['login'] = $this->url->link('account/login', '', true);
		$data['order'] = $this->url->link('account/order', '', true);
		$data['transaction'] = $this->url->link('account/transaction', '', true);
		$data['download'] = $this->url->link('account/download', '', true);
		$data['logout'] = $this->url->link('account/logout', '', true);
		$data['shopping_cart'] = $this->url->link('checkout/cart');
		$data['checkout'] = $this->url->link('checkout/checkout', '', true);
		$data['contact'] = $this->url->link('information/contact');
		$data['telephone'] = $this->config->get('config_telephone');
		
		$data['language'] = $this->load->controller('common/language');
		$data['currency'] = $this->load->controller('common/currency');
		$data['search'] = $this->load->controller('common/search');
		$data['cart'] = $this->load->controller('common/cart');
		$data['menu'] = $this->load->controller('common/menu');

		return $this->load->view('common/header', $data);
	}

	private function getRobotsDirective() {
		$route = isset($this->request->get['route']) ? (string) $this->request->get['route'] : 'common/home';
		$noindexPrefixes = array(
			'account/',
			'affiliate/',
			'checkout/',
			'extension/quickcheckout/',
			'product/compare',
			'product/search',
			'error/not_found'
		);

		foreach ($noindexPrefixes as $prefix) {
			if (strpos($route, $prefix) === 0) {
				return 'noindex, follow';
			}
		}

		foreach (array('sort', 'order', 'limit', 'filter', 'tracking') as $parameter) {
			if (isset($this->request->get[$parameter])) {
				return 'noindex, follow';
			}
		}

		return 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
	}
}
