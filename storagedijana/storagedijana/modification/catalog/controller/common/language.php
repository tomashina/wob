<?php
class ControllerCommonLanguage extends Controller {
	public function index() {
		$this->load->language('common/language');

		$data['action'] = $this->url->link('common/language/language', '', $this->request->server['HTTPS']);

		$data['code'] = $this->session->data['language'];

		$this->load->model('localisation/language');

		$data['languages'] = array();

		$results = $this->model_localisation_language->getLanguages();

		foreach ($results as $result) {
			if ($result['status']) {
				$data['languages'][] = array(
					'name' => $result['name'],
					'code' => $result['code']
				);
			}
		}

		if (!isset($this->request->get['route'])) {
			$data['redirect'] = $this->url->link('common/home');
		} else {
			$url_data = $this->request->get;

                $additional_url_data = $url_data;
                unset($additional_url_data['route'],$additional_url_data['_route_'],$additional_url_data['product_id'],$additional_url_data['path'],$additional_url_data['manufacturer_id'],$additional_url_data['information_id']);
    			$this->session->data['additional_url_data'] = $additional_url_data;
			    

			unset($url_data['_route_']);

			$route = $url_data['route'];

			unset($url_data['route']);

			$url = '';

			if ($url_data) {
				$url = '&' . urldecode(http_build_query($url_data, '', '&'));
			}

			$data['redirect'] = $this->url->link($route, $url, $this->request->server['HTTPS']);
		}

		return $this->load->view('common/language', $data);
	}

	public function language() {
		if (isset($this->request->post['code'])) {
			$this->session->data['language'] = $this->request->post['code'];

                $language_query = $this->db->query("SELECT `language_id` FROM ".DB_PREFIX."language WHERE `code` = '".$this->session->data['language']."'");
			    $this->session->data['language_id'] = $language_query->row['language_id'];
			    $this->config->set('config_language_id', $language_query->row['language_id']);
			    
		}

		if (isset($this->request->post['redirect'])) {
			
                if (isset($this->session->data['redirect_route'])){
                    if (isset($this->session->data['additional_url_data'])){
                        $additional_url_data = '&' . urldecode(http_build_query($this->session->data['additional_url_data'], '', '&'));;
                    }else{
                        $additional_url_data = '';
                    }
                    
                    if ($this->session->data['redirect_route'] == 'product/product'){
                        $this->response->redirect($this->url->link('product/product', 'product_id=' . $this->session->data['product_id'].$additional_url_data,$this->request->server['HTTPS']));
                    }
                    if ($this->session->data['redirect_route'] == 'product/category'){
                        $this->response->redirect($this->url->link('product/category', 'path=' . $this->session->data['path'].$additional_url_data,$this->request->server['HTTPS']));
                    }
                    if ($this->session->data['redirect_route'] == 'product/manufacturer/info'){
                        $this->response->redirect($this->url->link('product/manufacturer/info', 'manufacturer_id=' . $this->session->data['manufacturer_id'].$additional_url_data,$this->request->server['HTTPS']));
                    }
                    if ($this->session->data['redirect_route'] == 'information/information'){
                        $this->response->redirect($this->url->link('information/information', 'information_id=' . $this->session->data['information_id'].$additional_url_data,$this->request->server['HTTPS']));
                    }
                }else{
                    $this->response->redirect($this->url->link('common/home'));
                }
			    
		} else {
			$this->response->redirect($this->url->link('common/home'));
		}
	}
}