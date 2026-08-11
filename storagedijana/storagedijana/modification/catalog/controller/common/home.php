<?php
class ControllerCommonHome extends Controller {
	public function index() {
		$this->document->setTitle($this->config->get('hb_metatags_hmtitle'.$this->config->get('config_language_id')));
		$this->document->setDescription($this->config->get('hb_metatags_hmdesc'.$this->config->get('config_language_id')));
		$this->document->setKeywords($this->config->get('hb_metatags_hmkeyword'.$this->config->get('config_language_id')));

		if (isset($this->request->get['route']) || 1 == 1) {
			$this->document->addLink($this->url->link('common/home'), 'canonical');
		}

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');

			$this->load->model('extension/module/hb_seo_snippets');	
			$this->model_extension_module_hb_seo_snippets->home_social();
            $this->model_extension_module_hb_seo_snippets->knowledge_graph();
		    $this->model_extension_module_hb_seo_snippets->site_search();
			$this->model_extension_module_hb_seo_snippets->local_business();
			
		$data['header'] = $this->load->controller('common/header');

                unset($this->session->data['redirect_route']);
			    

		$this->response->setOutput($this->load->view('common/home', $data));
	}
}
