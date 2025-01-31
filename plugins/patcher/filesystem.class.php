<?php


class FILESYSTEM
{
	var $ftp;
	var $root = PUN_ROOT;
	var $is_ftp = false;
	var $ftp_data = array();
	var $is_connected = false;

	function __construct($ftp_data = null)
	{
		if (isset($ftp_data) && is_array($ftp_data))
		{
			$this->is_ftp = true;
			$this->ftp_data = $ftp_data;
		}
	}

	// Returns path of temporary file in cache directory
	function tmpname()
	{
		return FORUM_CACHE_DIR.md5(time().rand());
	}


	function check_connection()
	{
		if (!$this->is_ftp || $this->is_connected)
			return false;

		require_once PATCHER_ROOT.'ftp.class.php';

		$this->ftp = new JFTP();
		if (!$this->ftp->connect($this->ftp_data['host'], $this->ftp_data['port']))
			error('FTP: Connection failed', __FILE__, __LINE__);
		if (!$this->ftp->login($this->ftp_data['user'], $this->ftp_data['pass']))
			error('FTP: Login failed', __FILE__, __LINE__);
		if (!$this->ftp->chdir($this->ftp_data['path']))
			error('FTP: Directory change failed', __FILE__, __LINE__);

		if (!@$this->ftp->listDetails($this->fix_path('config.php')))
			error('FTP: The FluxBB root directory is not valid', __FILE__, __LINE__);

		$this->root = $this->ftp_data['path'];

		$this->is_connected = true;
	}

	function fix_path($path)
	{
		$len = strlen(PUN_ROOT);

		// Is the current path prefixed with PUN_ROOT directory?
		if (substr($path, 0, $len) == PUN_ROOT)
			return ltrim(substr($path, $len), '/');

		return $path;
	}


	function mkdir($pathname)
	{
		$this->check_connection();
		return $this->is_ftp ? $this->ftp->mkdir($this->fix_path($pathname)) : mkdir($pathname);
	}

	function move($src, $dest)
	{
		$this->check_connection();
		if ($this->is_ftp)
		{
			$src_path = $this->fix_path($src);

			// File is already on the FTP server (eg. in fluxbb cache directory) so move it to another location
			if (substr($src, 0, strlen(PUN_ROOT)) == PUN_ROOT)
				return $this->ftp->rename($src_path, $this->fix_path($dest));

			// We have to upload file to the FTP server
			else
				return $this->ftp->store($src, $this->fix_path($dest)) && unlink($src);
		}
		return rename($src, $dest);
	}

	function copy($src, $dest)
	{
		$this->check_connection();
		if ($this->is_ftp)
			return $this->ftp->store($src, $this->fix_path($dest));

		return copy($src, $dest);
	}

	function put($file, $data)
	{
		$this->check_connection();
		return $this->is_ftp ? $this->ftp->write($this->fix_path($file), $data) : file_put_contents($file, $data);
	}

	function delete($file)
	{
		$this->check_connection();
		if ($this->is_ftp)
			return $this->ftp->delete($this->fix_path($file));

		return unlink($file);
	}

	// Recursive directory remove
	function remove_directory($path)
	{
		if (!is_dir($path))
			return false;

		$this->check_connection();

		$list = $this->list_to_remove($path);

		// It files aren't writable the rest of this function will not be executed
		$this->are_files_writable($list);

		foreach ($list as $cur_file)
		{
			if (is_dir($cur_file))
			{
				if ($this->is_ftp)
					$this->ftp->delete($this->fix_path($cur_file));
				else
					rmdir($cur_file);
			}
			else
				$this->delete($cur_file);
		}
		return true;
	}

	function list_to_remove($path)
	{
		$files = array();
		$d = dir($path);
		while ($f = $d->read())
		{
			if ($f == '.' || $f == '..')
				continue;

			if (is_file($path.'/'.$f))
				$files[] = $path.'/'.$f;
			else
			{
				$files = array_merge($files, $this->list_to_remove($path.'/'.$f));
				//$directories[] = $path.'/'.$f;
			}
		}
		$d->close();
		$files[] = $path;
		return $files;
	}


	function copy_directory($source, $dest)
	{
		if (!is_dir($dest))
			$this->mkdir($dest);

		$d = dir($source);
		while ($f = $d->read())
		{
			if ($f != '.' && $f != '..' && $f != '.git' && $f != '.svn')
			{
				if (is_dir($source.'/'.$f))
					$this->copy_directory($source.'/'.$f, $dest.'/'.$f);
				else
					$this->copy($source.'/'.$f, $dest.'/'.$f);
			}
		}
		$d->close();
		return true;
	}

	function is_empty_directory($dir)
	{
		$d = dir($dir);
		while ($f = $d->read())
		{
			if ($f != '.' && $f != '..')
			{
				$d->close();
				return false;
			}
		}
		$d->close();
		return true;
	}


	function is_writable($path)
	{
		if ($path == PUN_ROOT.'.')
			return $this->is_writable(PUN_ROOT);

		$this->check_connection();
		if ($this->is_ftp)
		{
			$details = array();
			$name = '';
			if (is_dir($path))
			{
				if (substr($path, -1) != '/')
					$path .= '/';
				$fixed_path = $this->fix_path($path);
				if ($fixed_path == './')
					$fixed_path = '';
				$details = @$this->ftp->listDetails($fixed_path.'../');

				// Can't read directory contents?
				if (!is_array($details))
					return false;

				$name = basename($path);

				foreach ($details as $cur_details)
				{
					if ($cur_details['name'] == $name)
					{
						//print_r($cur_details);
						$rights = $cur_details['rights'];
						if (substr($rights, 0, 1) == 'd' && substr($rights, 2, 1) == 'w')
							return true;
						else
							return false;
					}
				}
			}
			else
			{
				$details = $this->ftp->listDetails($this->fix_path($path));
				$name = $this->fix_path($path);

				$rights = $details[0]['rights'];

				// Is not a file?
				if (substr($rights, 0, 1) != '-')
					return false;

				// TODO: real permissions checking
				if (substr($rights, 2, 1) == 'w')
					return true;
			}

			return false;
		}

		return is_writable($path);
	}


	function are_files_writable($files)
	{
		global $lang_admin_plugin_patcher;

		$not_writable = array();
		foreach ($files as $cur_file)
		{
			if (!$this->is_writable($cur_file))
				$not_writable[] = $cur_file;
		}

		if (count($not_writable) > 0)
			message($lang_admin_plugin_patcher['Files not writable info'].':<br />'.implode('<br />', $not_writable));
		return true;
	}
}

