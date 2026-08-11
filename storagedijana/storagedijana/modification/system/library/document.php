<?php
/**
 * @package		OpenCart
 * @author		Daniel Kerr
 * @copyright	Copyright (c) 2005 - 2017, OpenCart, Ltd. (https://www.opencart.com/)
 * @license		https://opensource.org/licenses/GPL-3.0
 * @link		https://www.opencart.com
*/

/**
* Document class
*/
class Document {
	private $title;
	private $description;
	private $keywords;
private $opengraph = array();
				private $twittercard = array();
				private $structureddata = array();

	private $links = array();
	private $styles = array();
	private $scripts = array();

                private $db;
            	public function __construct() {
            		$this->db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
            	}
            

	/**
     * 
     *
     * @param	string	$title
     */
	public function setTitle($title) {
		$this->title = $title;
	}

	/**
     * 
	 * 
	 * @return	string
     */
	public function getTitle() {
		return $this->title;
	}

	/**
     * 
     *
     * @param	string	$description
     */
	public function setDescription($description) {
		$this->description = $description;
	}

	/**
     * 
     *
     * @param	string	$description
	 * 
	 * @return	string
     */
	public function getDescription() {
		return $this->description;
	}

	/**
     * 
     *
     * @param	string	$keywords
     */
	public function setKeywords($keywords) {
		$this->keywords = $keywords;
	}

	/**
     *
	 * 
	 * @return	string
     */
	public function getKeywords() {
		return $this->keywords;
	}
	
	/**
     * 
     *
     * @param	string	$href
	 * @param	string	$rel
     */
public function setOpengraph($meta, $content) {
                    $this->opengraph[] = array(
                        'meta'   => $meta,
                        'content' => $content
                     );
                }
                
                public function getOpengraph() {
                    return $this->opengraph;
                }

				public function setTwittercard($name, $content) {
                    $this->twittercard[] = array(
                        'name'   => $name,
                        'content' => $content
                     );
                }
                
                public function getTwittercard() {
                    return $this->twittercard;
                }
                
                public function setStructureddata($content) {
                    $this->structureddata[] = array(
                        'content' => $content
                     );
                }
                
                public function getStructureddata() {
                    return $this->structureddata;
                }
	public function addLink($href, $rel) {

        //huntbee canonical  start  
        $replaced_canonical = false;
        if ($rel == 'canonical' || $rel == 'next' || $rel == 'prev') {
			$http_protocol = (isset($_SERVER['HTTPS']) && (($_SERVER['HTTPS'] == 'on') || ($_SERVER['HTTPS'] == '1'))) ? "https://"  : "http://";
			$current_url = $http_protocol.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
			$current_url = preg_replace('/\?.*/', '', $current_url);
		
			$custom_canonical = $this->db->query("SELECT canonical FROM ".DB_PREFIX."custom_canonical WHERE `url` = '".$this->db->escape($current_url)."' LIMIT 1");
			if ($custom_canonical->row) {
				$replaced_canonical = str_replace($current_url, $custom_canonical->row['canonical'], $href);
			}
		}
		
		if ($replaced_canonical) 				
				$this->links[$href] = array('href' => $replaced_canonical,	'rel'  => $rel);
		else
		//huntbee canonical end
            
		$this->links[$href] = array(
			'href' => $href,
			'rel'  => $rel
		);
	}

	/**
     * 
	 * 
	 * @return	array
     */
	public function getLinks() {
		return $this->links;
	}

	/**
     * 
     *
     * @param	string	$href
	 * @param	string	$rel
	 * @param	string	$media
     */
	public function addStyle($href, $rel = 'stylesheet', $media = 'screen', $position = 'header') {
		$this->styles[$position][$href] = array(
			'href'  => $href,
			'rel'   => $rel,
			'media' => $media
		);
	}

	/**
     * 
	 * 
	 * @return	array
     */
	public function getStyles($position = 'header') {
		if (isset($this->styles[$position])) {
			return $this->styles[$position];
		} else {
			return array();
		}
	}

	/**
     * 
     *
     * @param	string	$href
	 * @param	string	$position
     */
	public function addScript($href, $position = 'header') {
		$this->scripts[$position][$href] = $href;
	}

	/**
     * 
     *
     * @param	string	$position
	 * 
	 * @return	array
     */
	public function getScripts($position = 'header') {
		if (isset($this->scripts[$position])) {
			return $this->scripts[$position];
		} else {
			return array();
		}
	}
}