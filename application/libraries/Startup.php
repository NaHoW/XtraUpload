<?php
/* vim: set ts=4 sw=4 sts=0: */

/**
 * XtraUpload
 *
 * A turn-key open source web 2.0 PHP file uploading package requiring PHP v5
 *
 * @package		XtraUpload
 * @author		Matthew Glinski
 * @copyright	Copyright (c) 2006, XtraFile.com
 * @license		http://xtrafile.com/docs/license
 * @link		http://xtrafile.com
 * @since		Version 2.0
 * @filesource
 */
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * XtraUpload Startup Class
 *
 * @package		XtraUpload
 * @subpackage	Libraries
 * @category	Libraries
 * @author		Matthew Glinski
 * @link		http://xtrafile.com/docs/pages/files
 */
class Startup {

	/**
	 * Skin name
	 *
	 * @access	public
	 * @var		string
	 */
	public $skin = "";

	/**
	 * Site configuration
	 *
	 * @access	public
	 * @var		object
	 */
	public $site_config = "";

	/**
	 * Group configuration
	 *
	 * @access	public
	 * @var		object
	 */
	public $group_config = "";

	/**
	 * XtraUpload version in database
	 *
	 * @access	public
	 * @var		string
	 */
	public $db_version = "";

	/**
	 * CodeIgniter
	 *
	 * @accses	private
	 * @var		object
	 */
	private $CI = "";

	/**
	 * Flag install or not
	 *
	 * @accses	private
	 * @var		bool
	 */
	private $_is_installed = FALSE;

	/**
	 * Flag upgrade or not
	 *
	 * @access	private
	 * @var		bool
	 */
	private $_is_upgradable = FALSE;

	/**
	 * Constructor
	 *
	 * Set any variables
	 *
	 * @accses	public
	 * @return	void
	 */
	public function __construct()
	{
		log_message('debug', "Startup Class Initialized");

		// Define the path to the cache folder
		define('CACHEPATH', APPPATH.'cache/');

		// Define Hard Coded Script Version
		include(APPPATH.'/config/xu_ver.php');
		define('XU_VERSION', $version);

		$this->CI =& get_instance();
		// Load the DB and session class
		$this->CI->load->database();

		// Load General Functions and XU API
		$this->CI->load->library(array('functions', 'xu_api'));
		$this->_check_setup();

		define('XU_VERSION_READ', $this->CI->functions->parse_version(XU_VERSION));
		define('XU_DB_VERSION_READ', $this->CI->functions->parse_version($this->db_version));

		$this->available_locale = available_lang();

		if( ! isset($this->CI->config->config['is_installed']))
		{
			$this->_set_autolang();
			if(!$this->_is_installed && !preg_match('#^install/#', uri_string()))
			{
				redirect('install/setup');
				exit;
			}
			elseif(!$this->_is_installed && $this->_is_upgradable)
			{
				redirect('install/setup');
				exit;
			}
			elseif($this->_is_installed && isset($this->CI->config->config['is_installed']))
			{
				redirect('home');
			}
			return;
		}
		elseif(!$this->_is_installed && !preg_match('#^(install|setup|step\d)#', $_SERVER['REQUEST_URI']))
		{
			redirect('install/setup');
		}
		elseif($this->_is_upgradable && !preg_match('#^install#', uri_string()))
		{
			$this->CI->load->driver('session');
			$this->_get_config();
			$this->_get_locale();
			redirect('install/update');
			exit;
		}
		elseif($this->CI->config->config['is_installed'] && preg_match('#^(install|setup|step\d)#', uri_string()) && $this->_db_installed() && !$this->_is_upgradable)
		{
			redirect('home');
		}

		if($this->_db_installed() === FALSE)
		{
			return;
		}

		$this->CI->load->driver('session');

		// Load 2 helpers
		$this->CI->load->helper(array('url', 'cssbutton'));

		// Setup group config object
		$this->group_config = new stdClass();

		// Get the active skin name
		log_message('debug', 'Getting started for Skin.');
		$this->_get_skin();

		// Get the sitewide config settings
		log_message('debug', 'Getting started for Config.');
		$this->_get_config();

		// Get the user locale
		$this->_get_locale();
		$this->CI->lang->set_locale($this->locale);

		// Get the user group config settings for the accessing user
		$this->get_group();

		// Define system wide view vars
		$this->CI->load->vars(array(
			'base_url' => base_url(),
			'server_url' => base_url(),
			'skin' => $this->skin
		));

		// Load General Functions and XU API
		//$this->CI->load->library(array('functions', 'xu_api'));

		//define('XU_VERSION_READ', $this->CI->functions->parse_version(XU_VERSION));
		//define('XU_DB_VERSION_READ', $this->CI->functions->parse_version($this->db_version));
		// Load site menus
		$this->_setup_menu();
		// Load the Files Subsystem and the USers subsystem
		$this->CI->load->model(array('users', 'files/files_db', 'admin_logger'));
		// load all custom startup files
		$this->_run_startup();
	}

	/**
	 * Destructor
	 *
	 * DB closing
	 *
	 * @access	public
	 * @return	void
	 */
	public function __destruct()
	{
		if($this->_is_installed) {
			$this->CI->db->close();
		}
	}

	/**
	 * Startup::_get_locale()
	 *
	 * Get default user locale, and check language is rtl or not
	 *
	 * @access	private
	 * @return	void
	 */
	private function _get_locale()
	{
		try {
			$locale = new Zend_Locale(Zend_Locale::BROWSER);
		} catch(Exception $e) {
			$locale = new Zend_Locale('en_US');
		}
		$this->locale = $locale;
		if($this->CI->session->userdata('id'))
		{
			$query = $this->CI->db->get_where('users', array('id' => $this->CI->session->userdata('id')));
			$user = $query->row();
			$this->locale = $user->locale;
		}
		else
		{
			$this->locale = isset($this->site_config->site_locale) ? $this->site_config->site_locale : $locale;
		}
		log_message('debug', 'Get Locale: '.$this->locale);
		$is_rtl = is_rtl($this->locale);
		$this->is_rtl = !empty($is_rtl['characters']) ? 'rtl' : 'ltr';
	}

	/**
	 * Startup::_get_skin()
	 *
	 * Get user skin
	 *
	 * @access	private
	 * @return	void
	 */
	private function _get_skin()
	{
		// Encrypt the cache filename for security
		$skin_name = md5($this->CI->config->config['encryption_key'].'skin_name');

		// Check if the cache file previously exists
		if(file_exists(CACHEPATH . $skin_name))
		{
			// Dont wast time with the DB, load the cached version
			$this->skin = $this->CI->security->xss_clean($this->CI->load->file(CACHEPATH . $skin_name , true));
			log_message('debug', "Load skin from cache {$this->skin}");
		}
		else
		{
			// Get skin name from DB
			$this->skin = $this->CI->db->get_where('skin', array('active' => '1'))->row()->name;
			// Save the config object to cache for increased performance
			file_put_contents(CACHEPATH . $skin_name , $this->skin);
			log_message('debug', "Load skin from database {$this->skin}");
		}
	}

	/**
	 * Startup::_get_config()
	 *
	 * Get configuration from cache or DB
	 *
	 * @access	private
	 * @return	void
	 */
	private function _get_config()
	{
		$config_file_name = md5($this->CI->config->config['encryption_key'].'site_config');
		if(file_exists(CACHEPATH . $config_file_name))
		{
			log_message('debug', 'Config Loaded from Cache.');
			$this->site_config = json_decode(base64_decode($this->CI->load->file(CACHEPATH . $config_file_name, true)));
		}
		else
		{
			log_message('debug', 'Config Loaded from Database.');
			$q = $this->CI->db->get('config');
			foreach($q->result() as $row)
			{
				@$this->site_config->{@$row->name} = @$row->value;
			}
			file_put_contents(CACHEPATH . $config_file_name, base64_encode(json_encode($this->site_config)));
		}
	}

	/**
	 * Startup::get_group()
	 *
	 * Get user group
	 *
	 * @access	public
	 * @return	void
	 */
	public function get_group($gid='')
	{
		if($gid != '')
		{
			$this->group_config = $this->CI->db->get_where('groups', array('id' => intval($gid)))->row();
			return;
		}
		else
		{
			if($this->CI->session->userdata('group'))
			{
				$group = $this->CI->session->userdata('group');
			}
			else
			{
				$group = 1;
			}
		}
		$group_file_name = md5($this->CI->config->config['encryption_key'].'group_'.$group);
		if(file_exists(CACHEPATH . $group_file_name))
		{
			$this->group_config = json_decode(base64_decode($this->CI->load->file(CACHEPATH . $group_file_name, true)));
		}
		else
		{
			$this->group_config = $this->CI->db->get_where('groups', array('id' => $group))->row();
			file_put_contents(CACHEPATH . $group_file_name, base64_encode(json_encode($this->group_config)));
		}
	}

	/**
	 * Startup::_run_startup()
	 *
	 * Run startup
	 *
	 * @access	private
	 * @return	void
	 */
	private function _run_startup()
	{
		$extend_file_name = md5($this->CI->config->config['encryption_key'].'extend');
		if(file_exists(CACHEPATH . $extend_file_name))
		{
			$extend = json_decode(base64_decode($this->CI->load->file(CACHEPATH . $extend_file_name, true)));

			// Open a known directory, and proceed to read its contents
			foreach($extend as $app)
			{
				$this->CI->load->extention($app);
			}
		}
	}

	/**
	 * Startup::_setup_menu()
	 *
	 * Set menu
	 *
	 * @access	private
	 * @return	void
	 */
	private function _setup_menu()
	{
		// load main menu links
		$this->CI->xu_api->menus->add_main_menu_link('home', lang('Home'), 'assets/images/other/home2_16.png');

		// can user access URL Uploading?
		if($this->group_config->can_url_upload)
		{
			$this->CI->xu_api->menus->add_main_menu_link('upload/url', lang('URL Upload'), 'assets/images/icons/connect_16.png');
		}

		// can user access search page?
		if($this->group_config->can_search)
		{
			$this->CI->xu_api->menus->add_main_menu_link('files/search', lang('Search'), 'assets/images/icons/search_16.png');
		}

		// can user access the admin panel?
		if($this->group_config->admin)
		{
			$this->CI->xu_api->menus->add_main_menu_link('admin/home', lang('Admin'), 'assets/images/other/admin_16.png');
		}

		// load either admin or user manu links
		if(substr($this->CI->uri->uri_string(), 0, 5) == 'admin')
		{
			$id = $this->CI->xu_api->menus->add_admin_menu(lang('Manage'));
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/files/view', lang('Files'), 'assets/images/icons/hard_disk_16.png');
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/transactions/view', lang('Transactions'), 'assets/images/icons/transaction_16.png');
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/user/view', lang('Users'), 'assets/images/icons/user_16.png');

			$id = $this->CI->xu_api->menus->add_admin_menu(lang('Configure'));
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/config', lang('Site Config'), 'assets/images/icons/options_16.png');
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/gateways/view', lang('Payment Gateways'), 'assets/images/icons/credit_card_16.png');
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/extend/view', lang('Plugins'), 'assets/images/icons/component_16.png');
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/skin/view', lang('Skins'), 'assets/images/icons/colors_16.png');
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/server/view', lang('Servers'), 'assets/images/other/server_16.png');
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/group/view', lang('User Groups'), 'assets/images/icons/user_group_16.png');

			$id = $this->CI->xu_api->menus->add_admin_menu(lang('Misc'));
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/menu_shortcuts/view', lang('Admin Menu Shortcuts'), 'assets/images/icons/sticky_16.png');
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/email/view', lang('Mass Emailer'), 'assets/images/icons/mail_16.png');

			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/stats/view', lang('Site Stats'), 'assets/images/icons/chart_16.png');
			//$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/translator', lang('Translation'), 'assets/images/icons/spelling_16.png');
			$this->CI->xu_api->menus->add_admin_menu_link($id, '/admin/actions/view', lang('Tools/Maintenance'), 'assets/images/icons/tools_16.png');

			$this->CI->xu_api->menus->add_plugin_menu_link('/admin/config/plugin', lang('Plugin Config'), 'assets/images/icons/options_16.png');

			// Admin Menu Shortcuts Code
			$this->CI->load->model('admin/menu_shortcuts/admin_menu_shortcuts_db');

			$menu_id = $this->CI->xu_api->menus->add_admin_menu(lang('Shortcuts'));
			$this->CI->xu_api->menus->add_admin_menu_link($menu_id, '/admin/menu_shortcuts/add/'.base64_encode($this->CI->uri->uri_string()), lang('Add This Page'), 'assets/images/icons/add_16.png');

			$links = $this->CI->admin_menu_shortcuts_db->get_shortcuts();
			foreach($links->result() as $link)
			{
				$this->CI->xu_api->menus->add_admin_menu_link($menu_id, $link->link, $link->title, 'assets/images/icons/link_16.png');
			}

			$new_order = array();
			$i=1;
			$order = $this->CI->xu_api->menus->get_admin_menu_order();
			foreach ($order as $place => $id)
			{
				if($id != $menu_id)
				{
					$new_order[$i] = $id;
					$i++;
				}
				else
				{
					$new_order[0] = $id;
				}
			}
			$this->CI->xu_api->menus->put_admin_menu_order($new_order);
		}
		else
		{
			$this->CI->xu_api->menus->add_sub_menu_link('Files', 'home', lang('Upload'), 'assets/images/other/upload_16.png');
			$this->CI->xu_api->menus->add_sub_menu_link('Files', 'files/manage', lang('Manage'), 'assets/images/other/manage-files_16.png', true);
			$this->CI->xu_api->menus->add_sub_menu_link('Create-login', 'folder/create', lang('File Folder'), 'assets/images/icons/folder_16.png');
			$this->CI->xu_api->menus->add_sub_menu_link('Create-login', 'image/create_gallery', lang('Image Gallery'), 'assets/images/other/images_16.png');
		}
		// Enable embed code for MP3s
		$this->CI->xu_api->embed->add_embed_type('mp3', array('width' => '470', 'height' => '30', 'speed' => '50'));
		// Enable embed code for Flash Video
		$this->CI->xu_api->embed->add_embed_type('flv', array('width' => $this->site_config->player_width, 'height' => $this->site_config->player_height, 'speed' => '4000'));
		// Enable embed code for Other Videos
		$types = array('mp4', 'mpg', 'wmv', 'avi');
		$this->CI->xu_api->embed->add_embed_type($types, array('width' => $this->site_config->player_width, 'height' => $this->site_config->player_height, 'speed' => '8000'));

	}

	/**
	 * Startup::_check_setup()
	 *
	 * Check initial setup or not
	 *
	 * @access	private
	 * @return	void
	 */
	private function _check_setup()
	{
		if(!isset($this->CI->config->config['is_installed']))
		{
			return;
		}
		else
		{
			$this->_is_installed = true;
		}
		$this->CI->load->model('xu');
		$this->db_version = $this->CI->xu->get_version();
		define('XU_DB_VERSION', $this->db_version);
		$db_version = str_replace(array(',', '.'), '', $this->db_version);
		$current_version = str_replace(array(',', '.'), '', XU_VERSION);
		if($db_version < $current_version)
		{
			$this->_is_upgradable = true;
		}
	}

	/**
	 * Startup::_set_autolang()
	 *
	 * Set user language from browser
	 *
	 * @access	private
	 * @return	void
	 */
	private function _set_autolang()
	{
		try {
			$this->locale = new Zend_Locale(Zend_Locale::BROWSER);
		} catch(Zend_Locale_Exception $e) {
			$this->locale = new Zend_Locale('en_US');
		}
		$mofile = APPPATH."language/{$this->locale}/xtraupload.mo";
		if(file_exists($mofile))
		{
			$this->translate = new Zend_Translate('gettext', $mofile);
		}
		else
		{
			$this->translate = new Zend_Translate('gettext', APPPATH."language/en_US/xtraupload.mo");
		}
	}

	/**
	 * Startup::_db_installed()
	 *
	 * Check version in database
	 *
	 * @access	private
	 * @return	void
	 */
	private function _db_installed()
	{
		if(!$this->CI->xu->get_version())
		{
			return FALSE;
		}
		return TRUE;
	}

}

/* End of file Startup.php */
/* Location: ./application/libraries/Startup.php */
