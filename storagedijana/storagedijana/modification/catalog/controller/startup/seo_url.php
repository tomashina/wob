<?php
class ControllerStartupSeoUrl extends Controller {
	public function index() {
		// Add rewrite to url class
		if ($this->config->get('config_seo_url')) {
			$this->url->addRewrite($this);
		}

		// Decode URL
		if (isset($this->request->get['_route_'])) {
			$parts = explode('/', $this->request->get['_route_']);

			// remove any empty arrays from trailing
			if (utf8_strlen(end($parts)) == 0) {
				array_pop($parts);
			}

			foreach ($parts as $part) {
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "seo_url WHERE keyword = '" . $this->db->escape($part) . "' AND store_id = '" . (int)$this->config->get('config_store_id') . "'");

				if ($query->num_rows) {
					$url = explode('=', $query->row['query']);

					if ($url[0] == 'product_id') {
						$this->request->get['product_id'] = $url[1];
					}

					if ($url[0] == 'category_id') {
						if (!isset($this->request->get['path'])) {
							$this->request->get['path'] = $url[1];
						} else {
							$this->request->get['path'] .= '_' . $url[1];
						}
					}

					if ($url[0] == 'manufacturer_id') {
						$this->request->get['manufacturer_id'] = $url[1];
					}

					if ($url[0] == 'information_id') {
						$this->request->get['information_id'] = $url[1];
					}


			if ($url[0] == 'blog_id') {$this->request->get['blog_id'] = $url[1]; }
			if ($url[0] == 'blog_category_id') {
			if (!isset($this->request->get['blogpath'])) {
			$this->request->get['blogpath'] = $url[1];
				} else {
			$this->request->get['blogpath'] .= '_' . $url[1];
			}}
			

				$this->forceSetLanguage($query->row['language_id']);		
				
					if ($query->row['query'] && $url[0] != 'information_id' && $url[0] != 'manufacturer_id' && $url[0] 
			!= 'category_id' && $url[0] != 'blog_category_id' && $url[0] != 'blog_id' && $url[0] 
			 != 'product_id') {
						$this->request->get['route'] = $query->row['query'];
					}
				} else {
					$this->request->get['route'] = 'error/not_found';

					break;
				}
			}


                $this->request->get['_route_'] = rtrim($this->request->get['_route_'],'/');
				$hb_route = $this->db->query("SELECT `route`,`language_id` FROM `".DB_PREFIX."hb_url` WHERE `keyword` = '".$this->db->escape($this->request->get['_route_'])."' AND `store_id` = '".(int)$this->config->get('config_store_id')."' LIMIT 1");
    			if ($hb_route->num_rows == 1) {
    				$this->request->get['route'] = $hb_route->row['route'];

				$this->forceSetLanguage($hb_route->row['language_id']);	
				
    			}		
				

				$hb_lang_route = $this->db->query("SELECT `language_id` FROM `".DB_PREFIX."language` WHERE `code` LIKE '".$this->db->escape($this->request->get['_route_'])."%' LIMIT 1");
    			if ($hb_lang_route->num_rows == 1) {
    				$this->request->get['route'] = 'common/home';
					$this->forceSetLanguage($hb_lang_route->row['language_id']);
    			}	
				
			if (!isset($this->request->get['route'])) {
				if (isset($this->request->get['product_id'])) {
					$this->request->get['route'] = 'product/product'; 
		    		$this->session->data['redirect_route'] = 'product/product';
		        	$this->session->data['product_id'] = $this->request->get['product_id'];

			} elseif (isset($this->request->get['blog_id'])) {
			$this->request->get['route'] = 'extension/blog/blog';
			} elseif ($this->request->get['_route_'] ==  'extension_blog_home') { 
			$this->request->get['route'] = 'extension/blog/home';
			
				} elseif (isset($this->request->get['path'])) {
					$this->request->get['route'] = 'product/category';
    			$this->session->data['redirect_route'] = 'product/category';
    			$this->session->data['path'] = $this->request->get['path'];

			} elseif (isset($this->request->get['blogpath'])) {
			$this->request->get['route'] = 'extension/blog/category';
			
				} elseif (isset($this->request->get['manufacturer_id'])) {
					$this->request->get['route'] = 'product/manufacturer/info';
    			$this->session->data['redirect_route'] = 'product/manufacturer/info';
    			$this->session->data['manufacturer_id'] = $this->request->get['manufacturer_id'];
				} elseif (isset($this->request->get['information_id'])) {
					$this->request->get['route'] = 'information/information';
    			$this->session->data['redirect_route'] = 'information/information';
    			$this->session->data['information_id'] = $this->request->get['information_id'];
    			}else{
    			    $this->request->get['route'] = 'common/home';
    			    $this->session->data['redirect_route'] = 'common/home';
    			
				}
			}
		}
	}


				private function forceSetLanguage($language_id = '1') {
            		$this->session->data['language_id'] = $language_id;
            		if (isset($this->session->data['language_id'])){
            			$this->config->set('config_language_id',$this->session->data['language_id']);
            			
            			$language_code = $this->db->query("SELECT `code` FROM `".DB_PREFIX."language` WHERE language_id = '".(int)$this->session->data['language_id']."'");
            			$this->session->data['language'] = $language_code->row['code'];
            			$this->language = new Language($this->session->data['language']);
            			$this->language->load($this->session->data['language']);
            			$this->registry->set('language', $this->language);
            		}
            	}		
				
	public function rewrite($link) {
		$url_info = parse_url(str_replace('&amp;', '&', $link));

		$url = '';

		$data = array();

                if (isset($this->session->data['language_id'])){
                    $language_id = $this->session->data['language_id'];
                }else{
                    $language_query = $this->db->query("SELECT `language_id` FROM ".DB_PREFIX."language WHERE `code` = '".$this->config->get('config_language')."'");
                    $language_id = $language_query->row['language_id'];
                }
                

		parse_str($url_info['query'], $data);

		foreach ($data as $key => $value) {
			if (isset($data['route'])) {
				if (($data['route'] == 'product/product' && $key == 'product_id') || (($data['route'] == 'product/manufacturer/info' || $data['route'] == 'product/product') && $key == 'manufacturer_id') || 
			($data['route'] == 'information/information' && $key == 'information_id') || ($data['route'] == 'extension/blog/blog' && $key == 'blog_id'))
			 {
					$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "seo_url WHERE `query` = '" . $this->db->escape($key . '=' . (int)$value) . "' and language_id = '".(int)$language_id."' AND store_id = '" . (int)$this->config->get('config_store_id') . "' AND language_id = '" . (int)$this->config->get('config_language_id') . "'");

					if ($query->num_rows && $query->row['keyword']) {
						
            $url = '/' . $query->row['keyword'];
            

						unset($data[$key]);
					}

			} elseif ($key == 'blogpath') {
			$blog_categories = explode('_', $value);
			foreach ($blog_categories as $category) {
			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "seo_url WHERE `query` = 'blog_category_id=" . (int)$category . "'");
			if ($query->num_rows) {
			
            $url = '/' . $query->row['keyword'];
            
			} else {
			$url = '';
			break;
			}}
			unset($data[$key]);
			} elseif (isset($data['route']) && $data['route'] ==   'extension/blog/home') {
			$blog_home = $this->db->query("SELECT * FROM " . DB_PREFIX . "seo_url WHERE `query` = 'extension/blog/home'");
			if ($blog_home->num_rows) {
			$url .= '/' . $blog_home->row['keyword'];
			} else {
			$url = '';
			}
			

			//Remove Common/Home
			} 
			elseif ($data['route'] == 'common/home') 
			{
					$url .= '/'; 
					
					unset($data[$key]);
			//Remove Common/Home
	
				} elseif ($key == 'path') {
					$categories = explode('_', $value);

					foreach ($categories as $category) {
						$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "seo_url WHERE `query` = 'category_id=" . (int)$category . "' and language_id = '".(int)$language_id."' AND store_id = '" . (int)$this->config->get('config_store_id') . "' AND language_id = '" . (int)$this->config->get('config_language_id') . "'");

						if ($query->num_rows && $query->row['keyword']) {


                                
            $url = '/' . $query->row['keyword'];
            
                   

						} else {
							$url = '';

							break;
						}
					}

					unset($data[$key]);
				}
			}
		}


				if (isset($data['route'])) {
    				$hb_keyword = $this->db->query("SELECT `keyword` FROM `".DB_PREFIX."hb_url` WHERE `route` = '".$this->db->escape($data['route'])."' AND `language_id` = '".(int)$this->config->get('config_language_id')."' AND `store_id` = '".(int)$this->config->get('config_store_id')."' LIMIT 1");
        			if ($hb_keyword->num_rows == 1) {
        				$url .= '/'.$hb_keyword->row['keyword'];
        			}

				$default_language_code = $this->config->get('config_language');
				if (isset($this->session->data['language'])){
					$language_code = $this->session->data['language'];
				}else{
					$language_code = $default_language_code;
				}
				
				if ($language_code == $default_language_code) {
					$set_language_code = '';
				}else{
					$set_language_code = substr($language_code,0,2).'/';
				}
				
				
    				if (isset($data['route']) && $data['route'] == 'common/home') { 
    					if(isset($set_language_code)){
    					    $url .= '/'.$set_language_code;   
    					}else{
    					    $url .= '/'; 
    					}
    				} 
				}
				
		if ($url) {
			unset($data['route']);

			$query = '';

			if ($data) {
				foreach ($data as $key => $value) {
					$query .= '&' . rawurlencode((string)$key) . '=' . rawurlencode((is_array($value) ? http_build_query($value) : (string)$value));
				}

				if ($query) {
					$query = '?' . str_replace('&', '&amp;', trim($query, '&'));
				}
			}

			return $url_info['scheme'] . '://' . $url_info['host'] . (isset($url_info['port']) ? ':' . $url_info['port'] : '') . str_replace('/index.php', '', $url_info['path']) . $url . $query;
		} else {
			return $link;
		}
	}
}
