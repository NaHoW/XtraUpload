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
 * XtraUpload Cron Item
 *
 * @package		XtraUpload
 * @subpackage	Controllers
 * @category	Controllers
 * @author		Matthew Glinski
 * @link		http://xtrafile.com/docs/cron
 */
class Files_cron {

	private $server = false;
	private $CI = '';
	private $debug = false;

	public function __construct($server)
	{
		if(!defined('IN_CRON'))
		{
			return false;
		}

		$this->CI = &get_instance();

		if(defined('XU_DEBUG'))
		{
			$this->debug = true;
		}

		$this->server = $server;
		$this->_run_cron();
	}

	private function _run_cron()
	{
		if(!defined('IN_CRON'))
		{
			return false;
		}

		// delete file without a DB entry, IE: deleted or baned
		$this->_prune_database_files();

		// Delete database entries without files, edge case
		$this->_prune_database_files();

		// delete files that have expired
		$this->_prune_expired_files();

		// delete file without a DB entry, IE: deleted or baned
		$this->_prune_folder_files(ROOTPATH.'/filestore');

		// clear temp folder
		$this->_clear_temp();
	}

	private function _prune_database_files()
	{
		$files = $this->CI->db->get_where('files', array('server' => $this->server));
		foreach($files->result() as $file)
		{
			if(!file_exists($file->filename))
			{
				$this->CI->db->delete('refrence', array('link_id' => $file->id));
				$this->CI->db->delete('files', array('id' => $file->id));

				if($this->debug)
				{
					echo "DELETE DEBUG _prune_database_files -> ".ROOTPATH.'/filestore/'.$file->prefix.'/'.$file->filename."<br />\n";
				}
			}
		}
	}

	private function _prune_expired_files()
	{
		$files = $this->CI->db->join('files', 'refrence.link_id = files.id')->get_where('refrence', array('server' => $this->server));
		foreach($files->result() as $file)
		{
			if($file->user == 0)
			{
				$group = 1;
			}
			else
			{
				$group = $this->CI->db->select('group')->get_where('users', array('id' => $file->user))->row()->group;
			}
			$group = $this->CI->db->get_where('groups', array('id' => $group))->row();

			if(($file->last_download < (time() - (3600 * 24 * $group->file_expire))) and $group->file_expire != 0)
			{
				if($this->debug)
				{
					echo "DELETE DEBUG _prune_expired_files -> ".$file->last_download." < ".(time() - (3600 * 24 * $group->file_expire))." -> ".ROOTPATH.'/filestore/'.$file->prefix.'/'.$file->filename."<br />\n";
				}

				$this->CI->files_db->delete_file($file->file_id, $file->secid, $file->link_name);
			}
		}
	}

	private function _prune_folder_files($dir)
	{
		$fh = @opendir($dir);
		while ($file = @readdir($fh))
		{
			if (($file != '..' && $file != '.' && !preg_match('#\.gitignore#', $file) && $file != 'index.php' && $file != 'index.html' && $file != '.DS_Store' && $file != '.htaccess'))
			{
				if(is_dir($dir . '/' . $file) and $file != '.svn')
				{
					$this->_prune_folder_files($dir.'/'.$file);
				}
				else
				{
					$q = $this->CI->db->join('files', 'refrence.link_id = files.id')->get_where('refrence', array('filename' => str_replace('./', '', $dir.'/'.$file), 'server' => $this->server), 1, 0);
					$num = $q->num_rows();
					if($num == '0' and $file != '.svn')
					{
						$file_obj = $q->row();
						if(!empty($file_obj->thumb))
						{
							unlink($file_obj->thumb);
						}

						if($this->debug)
						{
							echo "DELETE DEBUG _prune_folder_files -> ".$dir . '/' . $file."<br />\n";
						}

						unlink($dir.'/'.$file);
					}
				}
			}
		}
		closedir ($fh);
	}

	private function _clear_temp()
	{
		$temp = @opendir(ROOTPATH.'/temp/');
		while ($file = @readdir($temp))
		{
			if (($file != 'index.php' && !preg_match('#\.gitignore#', $file) && $file != 'index.html' && $file != '.DS_Store' && $file != '.htaccess' && !is_dir(ROOTPATH.'/temp/' . $file)))
			{
				log_message('debug', "Removing $file");
				unlink(ROOTPATH.'/temp/'.$file);
			}
		}
		@closedir ($temp);
	}
}

/* End of file Files_cron.php */
/* Location: ./application/cron/Files_cron.php */