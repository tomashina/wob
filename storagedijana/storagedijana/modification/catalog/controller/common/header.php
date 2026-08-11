<?php
class ControllerCommonHeader extends Controller {
	public function index() {

				$mssConfig = $this->config->get( 'msmart_search_s' );
				$mssConfigLf = (array) $this->config->get( 'msmart_search_lf' );
				$mssVer = ! empty( $mssConfig['minify_support'] ) ? '' : '?v' .$this->config->get( 'msmart_search_version' );
				$mssFiles = array(
					'js' => array( 'js_params.js', 'bloodhound.min.js', 'typeahead.jquery.min.js', 'live_search.min.js' ),
					'css' => array( 'style.css', 'style-2.css' ),
				);
				
				foreach( $mssFiles as $mssType => $mssFiles2 ) {
					$mssPath = $mssType == 'js' ? 'catalog/view/javascript/mss/' : 'catalog/view/theme/default/stylesheet/mss/';
					
					foreach( $mssFiles2 as $mssFile ) {
						$this->document->{'add'.($mssType == 'js' ? 'Script' : 'Style')}( $mssPath . $mssFile . $mssVer . ( $mssVer && $mssFile == 'js_params.js' ? '_'.time() : '' ) );
					}
				}
				
				$data['mss_lang_direction'] = $this->language->get('direction');
				
				require_once DIR_SYSTEM . 'library/msmart_search_mobile.php';

				/* @var $mobile Mobile_Detect_MSS */
				$mssMobile = new Mobile_Detect_MSS();

				$data['mss_mode'] = empty( $mssConfigLf['mode'] ) || $mssMobile->isMobile() ? 'standard' : $mssConfigLf['mode'];
			
		// Analytics
		$this->load->model('setting/extension');

		$data['analytics'] = array();
if(file_exists('catalog/model/extension/cmpltguagaf.php')) { 
				$this->load->model('extension/cmpltguagaf');
				$data['analytics'][] = $this->model_extension_cmpltguagaf->pageview();
			}

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

				if (is_array($this->document->getOpengraph())) { 
				   $data['opengraphs'] = $this->document->getOpengraph();
				}else{
					$data['opengraphs'] = '';
				}
				if (is_array($this->document->getTwittercard())) { 
				   $data['twittercards'] = $this->document->getTwittercard();
				}else{
					$data['twittercards'] = '';
				}
				
				if (is_array($this->document->getStructureddata())) { 
				    $data['jsonld_data'] = $this->document->getStructureddata();
				}else{
				    $data['jsonld_data'] = '';
				}
				

			if ($this->config->get('theme_default_directory') == 'basel') {
			include(DIR_APPLICATION . 'controller/extension/basel/header_helper.php');
			}
			


			/*start gdpr 28-07-2018*/
  			/*mpgdpr starts*/
  			$data['mpgdpr_status'] = $this->config->get('mpgdpr_status');
  			$data['mpgdpr_cbstatus'] = $this->config->get('mpgdpr_cbstatus');
  			/*mpgdpr ends*/
  			/*end gdpr 28-07-2018*/
			

			    $data['alternate'] = '';
		    	$this->load->model('localisation/language');
				$languages = $this->model_localisation_language->getLanguages();
				    if (isset($this->request->get['route'])) {
						if ($this->request->get['route'] == 'product/product') {
						    foreach($languages as $lang) {
						        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "seo_url` where `query` = CONCAT('product_id=', CAST(".$this->request->get['product_id']." as CHAR)) and language_id = '".(int)$lang['language_id']."' AND store_id = '" . (int)$this->config->get('config_store_id') . "' LIMIT 1");
								if ($query->num_rows) {
									$seo_alias = $query->row['keyword'];
									$data['alternate'] .='<link rel="alternate" hreflang="'.$lang['code'].'" href="'.$server.$seo_alias.'" />';
								}
						    }
						}
						if ($this->request->get['route'] == 'product/category') {
						    $split_path = explode('_', $this->request->get['path']); 
						    $catrgy_id = end($split_path);
						    foreach($languages as $lang) {
						        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "seo_url` where `query` = CONCAT('category_id=', CAST(".$catrgy_id." as CHAR)) and language_id = '".(int)$lang['language_id']."'  AND store_id = '" . (int)$this->config->get('config_store_id') . "' LIMIT 1");
								if ($query->num_rows) {
									$seo_alias = $query->row['keyword'];
									$data['alternate'] .='<link rel="alternate" hreflang="'.$lang['code'].'" href="'.$server.$seo_alias.'" />';
								}
						    }
						}
						if ($this->request->get['route'] == 'product/manufacturer/info') {
						    foreach($languages as $lang) {
						        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "seo_url` where `query` = CONCAT('manufacturer_id=', CAST(".$this->request->get['manufacturer_id']." as CHAR)) and language_id = '".(int)$lang['language_id']."' AND store_id = '" . (int)$this->config->get('config_store_id') . "'  LIMIT 1");
								if ($query->num_rows) {
									$seo_alias = $query->row['keyword'];
									$data['alternate'] .='<link rel="alternate" hreflang="'.$lang['code'].'" href="'.$server.$seo_alias.'" />';
								}
						    }
						}
						if ($this->request->get['route'] == 'information/information') {
						    foreach($languages as $lang) {
						        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "seo_url` where `query` = CONCAT('information_id=', CAST(".$this->request->get['information_id']." as CHAR)) and language_id = '".(int)$lang['language_id']."' AND store_id = '" . (int)$this->config->get('config_store_id') . "'  LIMIT 1");
								if ($query->num_rows) {
									$seo_alias = $query->row['keyword'];
									$data['alternate'] .='<link rel="alternate" hreflang="'.$lang['code'].'" href="'.$server.$seo_alias.'" />';
								}
						    }
						}
						if ($this->request->get['route'] == 'common/home') {
    					    foreach($languages as $lang) {
                                $home_langcode = substr($lang['code'],0,2);
                                $default_language_code = substr($this->config->get('config_language'),0,2);
                                if ($home_langcode == $default_language_code){
                                    $home_langcode = '';
                                }else{
                                    $home_langcode = $home_langcode.'/';
                                }
    							$data['alternate'] .='<link rel="alternate" hreflang="'.$lang['code'].'" href="'.$server.$home_langcode.'" />';
    					    }
    					}
    					$hb_keywords = $this->db->query("SELECT * FROM `".DB_PREFIX."hb_url` a, `".DB_PREFIX."language` b WHERE a.language_id = b.language_id AND `route` = '".$this->db->escape($this->request->get['route'])."'  AND a.store_id = '" . (int)$this->config->get('config_store_id') . "' ");
            			if ($hb_keywords->num_rows > 1) {
            			    foreach ($hb_keywords->rows as $lang) {
            			        $data['alternate'] .='<link rel="alternate" hreflang="'.$lang['code'].'" href="'.$server.$lang['keyword'].'" />';
            			    }
            			}
					}
					if (!isset($this->request->get['route'])) {
					    foreach($languages as $lang) {
                            $home_langcode = substr($lang['code'],0,2);
                            $default_language_code = substr($this->config->get('config_language'),0,2);
                            if ($home_langcode == $default_language_code){
                                $home_langcode = '';
                            }else{
                                $home_langcode = $home_langcode.'/';
                            }
							$data['alternate'] .='<link rel="alternate" hreflang="'.$lang['code'].'" href="'.$server.$home_langcode.'" />';
					    }
					}
			
			
		$data['base'] = $server;
		$data['description'] = $this->document->getDescription();
		$data['keywords'] = $this->document->getKeywords();
		$data['links'] = $this->document->getLinks();
		$data['styles'] = $this->document->getStyles();
		$data['scripts'] = $this->document->getScripts('header');
		$data['lang'] = $this->language->get('code');
		$data['direction'] = $this->language->get('direction');

            if (isset($this->request->get['route'])) {
                $hb_meta_routes = $this->db->query("SELECT * FROM  `".DB_PREFIX."hb_route_meta` WHERE `route` = '".$this->db->escape($this->request->get['route'])."' AND `store_id` = '".(int)$this->config->get('config_store_id')."' AND `language_id` = '".(int)$this->config->get('config_language_id')."' LIMIT 1");
                if ($hb_meta_routes->num_rows == 1) {
                    if (isset($this->request->get['page'])) {
                        $hb_page_number = ' ['.(int)$this->request->get['page'].']';
                    } else {
                        $hb_page_number = '';
                    }
                    $data['title'] = $hb_meta_routes->row['meta_title'].$hb_page_number;
                    $data['description'] = $hb_meta_routes->row['meta_description'].$hb_page_number;
                    $data['keywords'] = $hb_meta_routes->row['meta_keyword'].$hb_page_number;
                }
            }
            

		$data['name'] = $this->config->get('config_name');

		if (is_file(DIR_IMAGE . $this->config->get('config_logo'))) {
			$data['logo'] = $server . 'image/' . $this->config->get('config_logo');
		} else {
			$data['logo'] = '';
		}

		$this->load->language('common/header');

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
}
