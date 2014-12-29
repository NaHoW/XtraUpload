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
 * XtraUpload File Download Class
 *
 * @package		XtraUpload
 * @subpackage	Libraries
 * @category	Libraries
 * @author		Matthew Glinski
 * @link		http://xtrafile.com/docs/pages/files
 */
class XU_Filedownload {

	// Public Vars
	public $file = null;
	public $resume = true;
	public $filename = null;
	public $mime = null;
	public $speed = 0;
	public $bandwidth = 0;

	// Private Vars, Do NOT Set!!
	private $CI;
	private $file_len = 0;
	private $file_mod = 0;
	private $file_type = 0;
	private $file_section = 0;
	private $bufsize = 8192;
	private $seek_start = 0;
	private $seek_end = -1;
	private $setup = false;

	/**
	 * File Download Constructor
	 *
	 * The constructor sets up the download system as ready for files
	 */
	function __construct()
	{
		$this->CI =& get_instance();
		log_message('debug', "Download Class Initialized");
	}

	/**
	 * Set Config
	 *
	 * Sets Config Vars
	 *
	 * @access  public
	 * @param   Config Array
	 * @return  null
	 */
	 public function set_config($config = array())
	 {
		if (count($config) > 0)
		{
			$this->_initialize($config);
		}
	 }

	/**
	 * Send Download
	 *
	 * Begins download
	 *
	 * @access  public
	 * @param   Config Array
	 * @return  integer OR false
	 */
	public function send_download($config = array())
	{
		$this->CI =& get_instance();
		// Setup the download, or die on error.
		$this->_initialize($config);

		// Grab some vars
		$seek = $this->seek_start;
		$speed = $this->speed;
		$bufsize = $this->bufsize;
		$packet = 1;

		// Make sure we dont timeout wheil serving the download

		@set_time_limit(0);
		$this->bandwidth = 0;

		// THIS IS VERY IMPORTANT, DO NOT REMOVE THIS CALL UNDER ANY CIRCUMSTANCES
		// --------------------
		// START IMPORTANT CALL
		session_write_close();
		// END IMPORTANT CALL
		// --------------------

		// Get the filesize and filename
		$size = filesize($this->file);
		if ($seek > ($size - 1)) $seek = 0;
		if ($this->filename == null) $this->filename = basename($this->file);

		// Open a file pointer to the file
		$res = fopen($this->file,'rb');

		// If partial request skip to the part we want
		if ($seek) fseek($res , $seek);
		if ($this->seek_end < $seek) $this->seek_end = $size - 1;

		$this->_send_headers($size, $seek, $this->seek_end); //always use the last seek
		$size = $this->seek_end - $seek + 1;

		$packet = 0;

		// While the user is connected
		while (!($user_aborted = connection_aborted() || connection_status() == 1) && $size > 0)
		{
			$startpacket = microtime(1);

			if ($size < $bufsize)
			{
				echo $this->fullread($res , $size);
				$this->bandwidth += $size;
			}
			else
			{
				echo $this->fullread($res , $bufsize);
				$this->bandwidth += $bufsize;
			}

			$size -= $bufsize;
			flush();

			if($speed > 0)
			{
				$timeend = microtime(1);

				$packettime = $timeend - $startpacket;
				$microsleep = ($bufsize / ($speed * 1024))*1000*1000 - $packettime;
				usleep($microsleep);
			}
		}
		fclose($res);
		return $this->bandwidth;
	}

	//Read a file segment
	public function fullread($fh, $size)
	{
		$buffer ='';
		$done = 0;
		while($done < $size)
		{
			if ($size - $done > $this->bufsize)
			{
				$thisbuff = fread($fh, $this->bufsize);
				$buffer .= $thisbuff;
				$did = strlen($thisbuff);
			}
			else
			{
				$thisbuff = fread($fh, $size - $done);
				$buffer .= $thisbuff;
				$did = strlen($thisbuff);
			}
			$done = $done + $did;
		}
		return $buffer;
	}

	/**
	 * Initialize the user preferences
	 *
	 * Accepts an associative array as input, containing display preferences
	 *
	 * @access  private
	 * @param   array of config preferences
	 * @return  void
	 */
	private function _initialize($config = array())
	{
		if($this->setup)
		{
			return true;
		}
		// Set Each Config Value
		foreach ($config as $key => $val)
		{
			$this->$key = $val;
		}

		if($this->mime == NULL)
		{
			// Grab the file extension
			$x = explode('.', $this->file);
			$extension = end($x);

			// Load the mime types
			@include(APPPATH.'config/mimes.php');

			// Set a default mime if we can't find it
			if ( ! isset($mimes[$extension]))
			{
				$this->mime = 'application/octet-stream';
			}
			else
			{
				$this->mime = (is_array($mimes[$extension])) ? $mimes[$extension][0] : $mimes[$extension];
			}
		}

		// Is the client requesting a partial download?
		if ($this->CI->input->server('HTTP_RANGE'))
		{
			// What part of the file is the client requesting
			$seek_range = substr($this->CI->input->server('HTTP_RANGE') , strlen('bytes='));
			$range = explode('-',$seek_range);

			if ($range[0] > 0)
			{
				$this->seek_start = intval($range[0]);
			}

			if ($range[1] > 0)
			{
				$this->seek_end = intval($range[1]);
			}
			else
			{
				$this->seek_end = -1;
			}

			// Do we want to serve a partial request?
			if (!$this->resume)
			{
				$this->seek_start = 0;
			}
			else
			{
				$this->file_section = 1;
			}

		}
		else
		{
			// Serve the whole file, from the beginning
			$this->seek_start = 0;
			$this->seek_end = -1;
		}
	}

	/**
	 * Send Headers
	 *
	 * Sends Download Headers to the client, describing the download
	 *
	 * @access  private
	 * @param   size of file
	 * @param   begining of file
	 * @param   end of file
	 * @return  void
	 */
	private function _send_headers($size,$seek_start=null,$seek_end=null)
	{
		// Generate the server headers
		header('Content-type: ' . $this->mime);
		header('Content-Disposition: attachment; filename="' . $this->filename . '"');
		header("Content-Transfer-Encoding: binary");
		header('Expires: 0');

		if ($this->file_section && $this->resume)
		{
			header("HTTP/1.0 206 Partial Content");
			header("Status: 206 Partial Content");
			header("Accept-Ranges: bytes");
			header("Content-Range: bytes $seek_start-$seek_end/$size");
			header("Content-Length: " . ($seek_end - $seek_start + 1));
		}
		else
		{
			header("Content-Length: $size");
		}

		if (strstr($_SERVER['HTTP_USER_AGENT'], "MSIE"))
		{
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			header('Pragma: public');
		}
		else
		{
			header('Pragma: no-cache');
		}
	}
}

/* End of file Filedownload.php */
/* Location: ./application/libraries/Filedownload.php */
