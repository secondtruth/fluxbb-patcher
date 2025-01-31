<?php
/**
 * FluxBB Patcher 2.0
 * http://fluxbb.org/forums/viewtopic.php?id=4431
 */

class PATCHER
{
	var $flux_mod = null;

	var $config = array();
	var $config_org = array();

	var $cur_file = null; // Currently patched file
	var $cur_file_path = null; // Path to currently patched file
	var $cur_file_modified = false;
	var $find = null;
	var $start_pos = 0;
	var $global_step = 1;

	var $action = null;

	var $steps = array();
	var $log = array();

	// Determine current action
	var $install = false;
	var $uninstall = false;
	var $update = false;
	var $disable = false;
	var $enable = false;

	var $modify_file_commands = array('FIND', 'REPLACE', 'BEFORE ADD', 'AFTER ADD'); // TODO: other commands
	var $global_commands = array('OPEN', 'RUN', 'RUN CODE', 'DELETE', 'RENAME', 'UPLOAD', 'NOTE');

	// Validate only
	var $validate = false;

	var $orginal_files = array();
	var $modifed_files = array();

	function __construct($flux_mod)
	{
		$this->flux_mod = $flux_mod;

		$patcher_config = array('installed_mods' => array(), 'steps' => array());
		if (file_exists(PUN_ROOT.'patcher_config.php'))
			require PUN_ROOT.'patcher_config.php';
		$this->config = $this->config_org = $patcher_config;
		$this->config['patcher_config_rev'] = PATCHER_CONFIG_REV;

	}


	function __get($name)
	{
		$function_name = 'get_'.$name;
		return $this->$name = $this->$function_name();
	}


	function execute_action($action, $validate_only = false)
	{
		$this->install = $this->uninstall = $this->update = $this->disable = $this->enable = false;
		$this->action = $action;
		$this->$action = true;
		$this->steps = $this->get_steps();

		$this->validate = $validate_only;
		return $this->patch();
	}


	function make_changes()
	{
		global $fs;

	//	if (isset($_SESSION['patcher_config']))
//		{
//			$this->config = unserialize($_SESSION['patcher_config']);
//			unset($_SESSION['patcher_config']);
//		}

		if (isset($_SESSION['patcher_steps']))
			$this->steps = unserialize($_SESSION['patcher_steps']);

//		print_r($this->steps);

		if (isset($_SESSION['patcher_files']))
		{
			$files = unserialize($_SESSION['patcher_files']);
			foreach ($files as $cur_file => $contents)
				$fs->put(PUN_ROOT.$cur_file, $contents);
		}

		$this->validate = false;
		$this->patch();
	}


	function unmet_requirements()
	{
		global $lang_admin_plugin_patcher;
		$requirements = array();

		foreach ($this->log as $cur_action => $readme_files)
		{
			foreach ($readme_files as $cur_readme => $cur_steps)
			{
				foreach ($cur_steps as $key => $cur_step)
				{
					if (isset($cur_step['status']) && $cur_step['status'] == STATUS_NOT_DONE)
					{
						if (!isset($requirements['cannot_open']))
							$requirements['cannot_open'] = array();
						$requirements['cannot_open'][] = array(false, $cur_step['code'], 'Cannot open file <a href="'.PLUGIN_URL.'&show_log#a'.$key.'">#'.$key.'</a>');
					}
					if (isset($cur_step['substeps']))
					{
						foreach ($cur_step['substeps'] as $id => $cur_substep)
						{
							if (isset($cur_substep['status']) && $cur_substep['status'] == STATUS_NOT_DONE)
							{
								if (!isset($requirements['missing_strings']))
									$requirements['missing_strings'] = array();

								$requirements['missing_strings'][] = array(false, $cur_step['code'], $lang_admin_plugin_patcher['Missing string'].' <a href="'.PLUGIN_URL.'&show_log#a'.$id.'">#'.$id.'</a>');
							}
						}
					}
				}
			}
		}

		return $requirements;
	}


	function revert_modified_files()
	{
		global $fs;

		// Revert modified files
		foreach ($this->orginal_files as $cur_file => $contents)
			$fs->put(PUN_ROOT.$cur_file, $contents);
	}


	function get_steps()
	{
		$steps = array();

		if ($this->install || $this->update)
		{
			// Load steps for current mod
			$steps[$this->flux_mod->id.'/'.$this->flux_mod->readme_file_name] = $this->flux_mod->get_steps();

			// Load steps for related mods (readme_mod_name.txt)
			foreach ($this->flux_mod->readme_file_list as $cur_readme_file)
			{
				$cur_readme_file = ltrim($cur_readme_file, '/');
				if (strpos($cur_readme_file, '_') === false)
					continue;

				$mod_key = substr($cur_readme_file, strpos($cur_readme_file, '_') + 1);
				$mod_key = substr($mod_key, 0, strpos($mod_key, '.txt'));
				$mod_key = str_replace('_', '-', $mod_key);

				if (isset($this->config['installed_mods'][$mod_key]) && (!isset($this->config['installed_mods'][$this->flux_mod->id]) || !in_array($cur_readme_file, $this->config['installed_mods'][$this->flux_mod->id])))
					$steps[$this->flux_mod->id.'/'.$cur_readme_file] = $this->flux_mod->get_steps($cur_readme_file);
			}

			foreach ($this->config['installed_mods'] as $cur_mod_id => $inst_mods_readme_files)
			{
				$flux_mod = new FLUX_MOD($cur_mod_id);
				foreach ($flux_mod->readme_file_list as $cur_readme_file)
				{
					$cur_readme_file = ltrim($cur_readme_file, '/');

					// skip if readme was already installed
					if (in_array($cur_readme_file, $inst_mods_readme_files))
						continue;

					$mod_key = substr($cur_readme_file, strpos($cur_readme_file, '_') + 1);
					$mod_key = substr($mod_key, 0, strpos($mod_key, '.txt'));
					$mod_key = str_replace('_', '-', $mod_key);

					if ($mod_key == $this->flux_mod->id)
						$steps[$flux_mod->id.'/'.$cur_readme_file] = $flux_mod->get_steps($cur_readme_file);
				}
			}
		}

		// Uninstall, disable, enable
		else
		{
			// Load cached steps
			foreach ($this->config['steps'] as $cur_readme_file => $step_list)
			{
				if (strpos($cur_readme_file, $this->flux_mod->id) !== false || strpos($cur_readme_file, str_replace('-', '_', $this->flux_mod->id)) !== false)
					$steps[$cur_readme_file] = $step_list;
			}

			if ($this->uninstall || $this->disable)
			{
				// Reverse readme list
				$steps = array_reverse($steps);

				// Correct the order of steps
				foreach ($steps as $cur_readme_file => &$step_list)
				{
					$run_steps_start = $run_steps_end = $upload_steps_end = $cur_step_list = array();
					$cur_open_steps = array();
					foreach ($step_list as $key => $cur_step)
					{
						// Move RUN and DELETE steps at the end
						if (in_array($cur_step['command'], array('RUN', 'DELETE')))
						{
							$code = trim($cur_step['code']);
							$run_steps_end[] = $cur_step;
						}

						// Delete files at the end
						elseif ($cur_step['command'] == 'UPLOAD')
							$upload_steps_end[] = $cur_step;

						elseif (in_array($cur_step['command'], array('OPEN')))
						{
							$cur_step['substeps'] = array();
							$cur_step_list[] = $cur_step;
						}

						elseif (in_array($cur_step['command'], array('FIND')))
						{
							$idx = count($cur_step_list) - 1;
							$cur_step_list[$idx]['substeps'][][0] = $cur_step;
						}

						elseif (in_array($cur_step['command'], array('REPLACE', 'AFTER ADD', 'BEFORE ADD')))
						{
							$idx = count($cur_step_list) - 1;
							$arr = $cur_step_list[$idx]['substeps'];
							$idx2 = count($arr) - 1;
							$cur_step_list[$idx]['substeps'][$idx2][] = $cur_step;
						}

						else
							$cur_step_list[] = $cur_step;
					}

					$new_step_list = array();
					foreach ($cur_step_list as $key => $c_step_list)
					{
						if (!isset($c_step_list['substeps']))
						{
							$new_step_list[] = $c_step_list;
							continue;
						}

						$substeps = array_reverse($c_step_list['substeps']);
						unset($c_step_list['substeps']);
						$new_step_list[] = $c_step_list;
						foreach ($substeps as $cur_step)
							foreach ($cur_step as $cur_step_sub)
								$new_step_list[] = $cur_step_sub;
					}

					$step_list = array_merge($run_steps_start, $new_step_list, $run_steps_end, $upload_steps_end);
				}
			}
		}

		return $steps;
	}


	function patch()
	{
		global $fs;
		$failed = false;

		if ($this->uninstall || $this->disable)
		{
			foreach ($this->flux_mod->files_to_upload as $from => $to)
			{
				// Copy install mod file as we want to uninstall mod
				if ($this->uninstall && strpos($from, 'install_mod.php') !== false)
					$fs->copy($this->flux_mod->readme_file_dir.'/'.$from, PUN_ROOT.'install_mod.php');
				elseif (strpos($from, 'gen.php') !== false) // TODO: make this relative to RUN commands
					$fs->copy($this->flux_mod->readme_file_dir.'/'.$from, PUN_ROOT.'gen.php');
			}
		}
		if ($this->uninstall)
			$this->friendly_url_uninstall_upload();

		$i = 1;
		foreach ($this->log as $log)
			foreach ($log as $cur_action_log)
				$i += count($cur_action_log);
		$this->log[$this->action] = array();

		$steps = $this->steps; // TODO: there is something wrong with variables visiblity
//		foreach ($this->steps as $cur_readme_file => &$step_list)
		while (list($cur_readme_file, $step_list) = each($this->steps)) // Allow to add steps inside loop
		{
			$log_readme = array();

			foreach ($step_list as $key => $cur_step)
			{
				$cur_step['status'] = STATUS_UNKNOWN;

				$function = 'step_'.str_replace(' ', '_', strtolower($cur_step['command']));
				if (is_callable(array($this, $function)))
				{
					$this->command = $cur_step['command'];
					$this->code = $cur_step['code'];
					$this->comments = array();
					$this->result = '';

					// Execute current step
					if ($this->validate || (!$this->validate && !isset($cur_step['validated'])))
						$cur_step['status'] = $this->$function();

					// Replace STATUS_DONE with STATUS_REVERTED when uninstalling mod
					if (($this->uninstall || $this->disable) && $cur_step['status'] == STATUS_DONE)
						$cur_step['status'] = STATUS_REVERTED;

					if ($this->result != '')
						$cur_step['result'] = $this->result;

					$cur_step['code'] = $this->code;
					$cur_step['comments'] = $this->comments;

					if (in_array($this->command, $this->modify_file_commands) && $this->validate)
						$this->steps[$cur_readme_file][$key]['validated'] = true;
				}

				if (!(($this->uninstall || $this->disable) && $cur_step['command'] == 'NOTE') // Don't display Note message when uninstalling mod
					&& $cur_step['status'] != STATUS_NOTHING_TO_DO) // Skip if mod is disabled and we want to uninstall it (as file changes has been already reverted)
				{
					if (in_array($cur_step['command'], $this->global_commands))
					{
						$this->global_step = $i; // it is a global action

						if ($cur_step['command'] == 'UPLOAD')
						{
							$code = array();
							foreach ($this->flux_mod->files_to_upload as $from => $to)
								$code[] = $from.' to '.$to;
							$cur_step['substeps'][0] = array('code' => implode("\n", $code));
							unset($cur_step['code']);
						}
						elseif ($cur_step['command'] == 'RUN CODE')
						{
							$cur_step['substeps'][0] = array('code' => $cur_step['code']);
							unset($cur_step['code']);
						}

						$log_readme[$i] = $cur_step;
					}
					else
					{
						if (!isset($log_readme[$this->global_step]['substeps']))
							$log_readme[$this->global_step]['substeps'] = array();

						$log_readme[$this->global_step]['substeps'][$i] = $cur_step;
					}
				}

				if (($cur_step['status'] == STATUS_DONE || $cur_step['status'] == STATUS_REVERTED) && $cur_step['command'] != 'OPEN' && !$this->cur_file_modified)
					$this->cur_file_modified = true;

				if ($cur_step['status'] == STATUS_NOT_DONE)
				{
					// If some step fail, make whole mod install fail
					if (!$failed)
						$failed = true;

					// Delete step if it fails
					if ($this->install || $this->update)
					{
						if (in_array($cur_step['command'], array('BEFORE ADD', 'AFTER ADD', 'REPLACE')) && $key > 0 && isset($step_list[$key-1]) && $step_list[$key-1]['command'] == 'FIND')
							unset($step_list[$key-1]);
						unset($step_list[$key]);
					}
				}

				// Delete step for uninstall when step was done
				if ($this->uninstall && $cur_step['status'] != STATUS_NOT_DONE && !in_array($cur_step['command'], array('FIND', 'OPEN')))
				{
					if (in_array($cur_step['command'], array('BEFORE ADD', 'AFTER ADD', 'REPLACE')) && isset($step_list[$key-1]) && $step_list[$key-1]['command'] == 'FIND')
						unset($step_list[$key-1]);

					unset($step_list[$key]);
				}

				$i++;
			}

			$this->log[$this->action][$cur_readme_file] = $log_readme;

			$step_list = array_values($step_list);
			if ($this->uninstall)
			{
				// Delete empty OPEN steps
				foreach ($step_list as $key => $cur_step)
				{
					if ($cur_step['command'] == 'OPEN' && ((isset($step_list[$key+1]['command']) && $step_list[$key+1]['command'] == 'OPEN') || !isset($step_list[$key+1])))
						unset($step_list[$key]);
				}
				$step_list = array_values($step_list);
			}

			// Update patcher config
			$cur_mod = substr($cur_readme_file, 0, strpos($cur_readme_file, '/'));
			$cur_readme = substr($cur_readme_file, strpos($cur_readme_file, '/') + 1);

			if ($this->uninstall)
			{
				if (count($step_list) == 0 && isset($this->config['installed_mods'][$cur_mod]) && in_array($cur_readme, $this->config['installed_mods'][$cur_mod]))
					$this->config['installed_mods'][$cur_mod] = array_diff($this->config['installed_mods'][$cur_mod], array($cur_readme)); // delete an element

				if (empty($step_list))
					unset($this->config['steps'][$cur_readme_file]);
				else
					$this->config['steps'][$cur_readme_file] = $step_list;
			}
			elseif ($this->install || $this->update)
			{
				if (!isset($this->config['installed_mods'][$cur_mod]))
					$this->config['installed_mods'][$cur_mod] = array();

				if (!in_array($cur_readme, $this->config['installed_mods'][$cur_mod]))
					$this->config['installed_mods'][$cur_mod][] = $cur_readme;

				$this->config['steps'][$cur_readme_file] = $step_list;
			}
		}

		// Update patcher config
		if ($this->uninstall)
		{
			if (isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']))
				unset($this->config['installed_mods'][$this->flux_mod->id]['disabled']);

			if (isset($this->config['installed_mods'][$this->flux_mod->id]['version']))
				unset($this->config['installed_mods'][$this->flux_mod->id]['version']);

			if ($failed)
				$this->config['installed_mods'][$this->flux_mod->id]['uninstall_failed'] = true;
			else
			{
				if (isset($this->config['installed_mods'][$this->flux_mod->id]['uninstall_failed']))
					unset($this->config['installed_mods'][$this->flux_mod->id]['uninstall_failed']);
				if (empty($this->config['installed_mods'][$this->flux_mod->id]))
					unset($this->config['installed_mods'][$this->flux_mod->id]);
			}
		}
		elseif ($this->install || $this->update)
		{
			$this->config['installed_mods'][$this->flux_mod->id]['version'] = $this->flux_mod->version;

			if ($this->update && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']))
				unset($this->config['installed_mods'][$this->flux_mod->id]['disabled']);
		}
		elseif ($this->enable && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']))
			unset($this->config['installed_mods'][$this->flux_mod->id]['disabled']);
		elseif ($this->disable && $GLOBALS['action'] != 'update')
			$this->config['installed_mods'][$this->flux_mod->id]['disabled'] = 1;

		// if some file was opened, save it
		$this->step_save();

		$_SESSION['patcher_files'] = serialize($this->modifed_files);

		if ($this->config != $this->config_org)
		{
			if (!defined('PATCHER_NO_SAVE') && !$this->validate)
				$fs->put(PUN_ROOT.'patcher_config.php', '<?php'."\n\n".'// DO NOT EDIT THIS FILE!'."\n\n".'$patcher_config = '.var_export($this->config, true).';');
			elseif (defined('PATCHER_DEBUG'))
				$fs->put(PATCHER_ROOT.'debug/patcher_config.php', '<?php'."\n\n".'// DO NOT EDIT THIS FILE!'."\n\n".'$patcher_config = '.var_export($this->config, true).';');
		}

		return !$failed;
	}


	function check_code(&$code)
	{
		$reg = preg_quote($code, '#');
		if (preg_match('#'.$reg.'#si', $this->cur_file))
			return true;

		// Code was not found
		// Ignore multiple tab characters
		$reg = preg_replace("#\t+#", '\t*', $reg);
		$this->comments[] = 'Tabs ignored';
		if (preg_match('#'.$reg.'#si', $this->cur_file, $matches))
		{
			$code = $matches[0];
			return true;
		}

		// Ignore spaces
		$reg = preg_replace('#\s+#', '\s*', $reg);
		$this->comments[] = 'Spaces ignored';
		if (preg_match('#'.$reg.'#si', $this->cur_file, $matches))
		{
			$code = $matches[0];
			return true;
		}

		// has query?
		$check_code = $code;
		if (strpos($check_code, 'query(') !== false)
		{
			preg_match_all('#\n\t*.*?query\((.*?)\) or error.*\n#', "\n".$check_code."\n", $find_m, PREG_SET_ORDER);

			foreach ($find_m as $key => $cur_find_m)
			{
				$find_line = trim($cur_find_m[0]);
				$find_query = trim($cur_find_m[1]);

				$query_id = md5($find_line);

				// Some mod modified this query before
				if (preg_match('#\n\t*.*?query\((.*?)\) or error.*?\/\/ QUERY ID: '.preg_quote($query_id).'#', $this->cur_file, $matches))
				{
					$query_line = trim($matches[0]);
					$cur_file_query = $matches[1];

					$check_code = str_replace($find_line, $query_line, $check_code);
				}
			}
			$this->comments[] = 'Query match';
			if (strpos($this->cur_file, $check_code) !== false)
				return true;
		}

		return false;
	}


	function replace_code($find, $replace)
	{
		// Mod was already disabled before
		if ($this->uninstall && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']))
			return STATUS_NOTHING_TO_DO;

		if ($this->uninstall || $this->disable)
		{
			// Swap $find with $replace
			$tmp = $find;
			$find = $replace;
			$replace = $tmp;

			$pos = strrpos(substr($this->cur_file, 0, $this->start_pos), $find);
			if ($pos === false)
			{
				$pos = strrpos($this->cur_file, $find);
				$this->comments[] = 'Whole file';

				if ($pos === false && in_array($this->command, array('BEFORE ADD', 'AFTER ADD')))
				{
					if ($this->command == 'BEFORE ADD')
						$find = $this->code."\n";
					elseif ($this->command == 'AFTER ADD')
						$find = "\n".$this->code;

					$replace = '';
					$this->comments[0] = 'Removing code';
					$pos = strpos($this->cur_file, $find);
				}
			}
			else
				$this->start_pos = $pos;

			if ($pos === false)
				return STATUS_NOT_DONE;

			$this->cur_file = substr_replace($this->cur_file, $replace, $pos, strlen($find));
			return STATUS_DONE;
		}

		$pos = strpos($this->cur_file, $find, $this->start_pos);
		if ($pos === false)
		{
			$pos = strpos($this->cur_file, $find);
			$this->comments[0] = 'Whole file';
		}
		else
			$this->start_pos = $pos + strlen($replace);

		if ($pos === false)
			return STATUS_NOT_DONE;

		$this->cur_file = substr_replace($this->cur_file, $replace, $pos, strlen($find));
		return STATUS_DONE;
	}


	function step_upload()
	{
		global $lang_admin_plugin_patcher, $fs;

		if (defined('PATCHER_NO_SAVE') || ($this->validate && $this->uninstall))
			return STATUS_UNKNOWN;

		// Should never happen
		if ($this->enable || $this->disable)
			return STATUS_NOTHING_TO_DO;

		if ($this->uninstall)
		{
			$directories = array();
			foreach ($this->flux_mod->files_to_upload as $from => $to)
			{
				if (file_exists(PUN_ROOT.$to))
					$fs->delete(PUN_ROOT.$to);

				$cur_path = '';
				$dir_structure = explode('/', $to);
				foreach ($dir_structure as $cur_dir)
				{
					$cur_path .= '/'.$cur_dir;
					if (is_dir(PUN_ROOT.$cur_path) && !in_array($cur_path, $directories))
						$directories[] = $cur_path;
				}
			}
			rsort($directories);
			foreach ($directories as $cur_dir)
			{
				// Remove directories that are empty
				if ($fs->is_empty_directory(PUN_ROOT.$cur_dir))
					$fs->remove_directory(PUN_ROOT.$cur_dir);
			}

			return STATUS_REVERTED;
		}

		foreach ($this->flux_mod->files_to_upload as $from => $to)
		{
			if (is_dir($this->flux_mod->readme_file_dir.'/'.$from))
				$fs->copy_directory($this->flux_mod->readme_file_dir.'/'.$from, PUN_ROOT.$to);
				// TODO: friendly_url_upload for directory
			else
			{
				if (is_dir(PUN_ROOT.$to) || substr($to, -1) == '/' || strpos(basename($to), '.') === false) // as a comment above
					$to .= (substr($to, -1) == '/' ? '' : '/').basename($from);

				if (!$fs->copy($this->flux_mod->readme_file_dir.'/'.$from, PUN_ROOT.$to))
					message(sprintf($lang_admin_plugin_patcher['Can\'t copy file'], pun_htmlspecialchars($from), pun_htmlspecialchars($to))); // TODO: move message somewhere :)

				$this->friendly_url_upload($to);
			}
		}
		return STATUS_DONE;
	}


	function step_open()
	{
		global $lang_admin_plugin_patcher, $fs;

		// if some file was opened, save it
		$this->step_save();

		// Mod was already disabled before
		if (($this->uninstall && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled'])))
			return STATUS_NOTHING_TO_DO;

		$this->code = trim($this->code);

		if (!file_exists(PUN_ROOT.$this->code))
		{
			// Language file that is not English does not exist?
			if (strpos(strtolower($this->code), 'lang/') !== false && strpos(strtolower($this->code), '/english') === false)
			{
				$this->cur_file = '';
				$this->cur_file_path = '';
				return STATUS_NOTHING_TO_DO;
			}

			$this->cur_file = '';
			$this->cur_file_path = $this->code;
			$this->result = $lang_admin_plugin_patcher['File does not exist error'];
			return STATUS_NOT_DONE;
		}

		$this->cur_file_path = $this->code;

		if (!$fs->is_writable(PUN_ROOT.$this->code))
			message(sprintf($lang_admin_plugin_patcher['File not writable'], pun_htmlspecialchars($this->code)));

		if (isset($this->modifed_files[$this->code]))
			$this->cur_file = $this->modifed_files[$this->code];
		else
		{
			$this->cur_file = file_get_contents(PUN_ROOT.$this->code);
			$this->orginal_files[$this->code] = $this->cur_file;
		}

		// Convert EOL to Unix style
		$this->cur_file = str_replace("\r\n", "\n", $this->cur_file);

		$this->friendly_url_open();

		$this->start_pos = $this->uninstall ? strlen($this->cur_file) : 0;
		$this->cur_file_modified = false;
		return STATUS_DONE;
	}


	function step_save()
	{
		global $fs;
		if (empty($this->cur_file_path) || !$this->cur_file_modified || empty($this->cur_file))
			return;

		$this->friendly_url_save();

		if ($this->validate)
		{
			$this->modifed_files[$this->cur_file_path] = $this->cur_file;
		}
		elseif (!defined('PATCHER_NO_SAVE'))
			$fs->put(PUN_ROOT.$this->cur_file_path, $this->cur_file);
		elseif (isset($GLOBALS['patcher_debug']['save']) && in_array($this->cur_file_path, $GLOBALS['patcher_debug']['save']))
			$fs->put(PATCHER_ROOT.'debug/'.basename($this->cur_file_path), $this->cur_file);

		$this->cur_file = '';
		$this->cur_file_path = '';
		$this->cur_file_modified = false;
	}


	function step_find()
	{
		$this->find = $this->code;

		// Mod was already disabled before
		if ($this->uninstall && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']) || $this->cur_file_path == '')
			return STATUS_NOTHING_TO_DO;

		if ($this->uninstall || $this->disable)
			return STATUS_UNKNOWN;
		elseif (!$this->check_code($this->find))
		{
			$this->find = '';
			return STATUS_NOT_DONE;
		}
		$this->code = $this->find;

		return STATUS_UNKNOWN;
	}


	function step_replace()
	{
		// Mod was already disabled before
		if ($this->uninstall && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']) || $this->cur_file_path == '')
			return STATUS_NOTHING_TO_DO;

		if ((!$this->uninstall && !$this->disable && empty($this->find)))
			return STATUS_UNKNOWN;

		if (empty($this->find) || empty($this->cur_file))
			return STATUS_NOT_DONE;

		// Add QUERY ID at end of query line
		if (strpos($this->code, 'query(') !== false)
		{
			preg_match_all('#\n\t*.*?query\(.*?\) or error.*\n#', "\n".$this->find."\n", $first_m, PREG_SET_ORDER);
			preg_match_all('#\n\t*.*?query\(.*?\) or error.*\n#', "\n".$this->code."\n", $second_m, PREG_SET_ORDER);

			foreach ($first_m as $key => $first)
			{
				$query_line = trim($first[0]);
				$replace_line = trim($second_m[$key][0]);

				$this->code = str_replace($replace_line, $replace_line.' // QUERY ID: '.md5($query_line), $this->code);
			}
		}

		$status = $this->replace_code(trim($this->find), trim($this->code));

		// has query?
		if (in_array($status, array(STATUS_NOT_DONE, STATUS_REVERTED)) && strpos($this->find, 'query(') !== false)
		{
			preg_match_all('#\n\t*.*?query\((.*?)\) or error.*\n#', "\n".$this->find."\n", $find_m, PREG_SET_ORDER);
			preg_match_all('#\n\t*.*?query\((.*?)\) or error.*\n#', "\n".$this->code."\n", $code_m, PREG_SET_ORDER);

			foreach ($find_m as $key => $cur_find_m)
			{
				$find_line = trim($cur_find_m[0]);
				$find_query = trim($cur_find_m[1]);
				$code_line = trim($code_m[$key][0]);
				$code_query = $code_m[$key][1];

				$query_id = md5($find_line);

				// Some mod modified this query before
				if (preg_match('#\n\t*.*?query\((.*?)\) or error.*?\/\/ QUERY ID: '.preg_quote($query_id).'#', $this->cur_file, $matches))
				{
					$query_line = trim($matches[0]);
					$cur_file_query = $matches[1];

					if ($this->uninstall || $this->disable)
					{
						$replace_with = revert_query($cur_file_query, $code_query, $find_query);

						if (!$replace_with)
							break;

						$line = str_replace($find_query, $replace_with, $find_line); // line with query

						// Make sure we have QUERY ID at the end of line
						if ($find_query != $replace_with && strpos($line, '// QUERY ID') === false)
							$line .= ' // QUERY ID: '.$query_id;

						$this->find = str_replace($find_line, $line, $this->find);
						$this->code = str_replace($code_line, $query_line, $this->code);
					}
					else
					{
						$replace_with = replace_query($cur_file_query, $code_query); // query

						if (!$replace_with)
							break;

						$line = str_replace($code_query, $replace_with, $code_line); // line with query
						$this->find = str_replace($find_line, $query_line, $this->find);
						$this->code = str_replace($code_line, $line, $this->code);
					}
				}
			}

			if ($this->install || $this->enable || strpos($this->cur_file, $this->code) !== false)
			{
				$status = $this->replace_code(trim($this->find), trim($this->code));
				$this->comments[] = 'Query ID';
			}
		}
		$this->find = $this->code;
		return $status;
	}


	function step_after_add()
	{
		// Mod was already disabled before
		if ($this->uninstall && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']) || $this->cur_file_path == '')
			return STATUS_NOTHING_TO_DO;

		if (empty($this->find) || empty($this->cur_file))
			return STATUS_UNKNOWN;

		return $this->replace_code($this->find, $this->find."\n".$this->code);
	}


	function step_before_add()
	{
		// Mod was already disabled before
		if ($this->uninstall && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']) || $this->cur_file_path == '')
			return STATUS_NOTHING_TO_DO;

		if (empty($this->find) || empty($this->cur_file))
			return STATUS_UNKNOWN;

		return $this->replace_code($this->find, $this->code."\n".$this->find);
	}


	function step_at_the_end_of_file_add()
	{
		// Mod was already disabled before
		if ($this->uninstall && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']) || $this->cur_file_path == '')
			return STATUS_NOTHING_TO_DO;

		// TODO: not tested
		if ($this->uninstall || $this->disable)
		{
			$pos = strrpos($this->cur_file, "\n\n".$this->code);
			if ($pos === false)
				return STATUS_NOT_DONE;

			$this->cur_file = substr_replace($this->cur_file, '', $pos, strlen("\n\n".$this->code));
			return STATUS_REVERTED;
		}

		$this->cur_file .= "\n\n".$this->code;
		return STATUS_DONE;
	}


	function step_add_new_elements_of_array()
	{
		// Mod was already disabled before
		if ($this->uninstall && isset($this->config['installed_mods'][$this->flux_mod->id]['disabled']) || $this->cur_file_path == '')
			return STATUS_NOTHING_TO_DO;

		$count = 0;
		if ($this->uninstall || $this->disable)
		{
			$this->cur_file = preg_replace('#'.make_regexp($this->code).'#si', '', $this->cur_file, 1, $count); // TODO: fix to str_replace_once
			if ($count == 1)
				return STATUS_REVERTED;

			return STATUS_NOT_DONE;
		}

		$this->cur_file = preg_replace('#,?\s*\);#si', ','."\n\n".$this->code."\n".');', $this->cur_file, 1, $count); // TODO: fix to str_replace_once
		if ($count == 1)
			return STATUS_DONE;

		return STATUS_NOT_DONE;
	}


	function step_run_code()
	{
		if (defined('PATCHER_NO_SAVE') || $this->validate)
			return STATUS_UNKNOWN;

		global $db;
		eval($this->code);
		return STATUS_DONE; // done
	}

	function step_run()
	{
		global $lang_admin_plugin_patcher;

		if (($this->enable || $this->disable)/* && $this->code == 'install_mod.php'*/)
			return STATUS_NOTHING_TO_DO;

		if (defined('PATCHER_NO_SAVE') || $this->validate)
			return STATUS_UNKNOWN;

		if ($this->code == 'install_mod.php')
		{
			if (!file_exists(PUN_ROOT.$this->code))
			{
				$this->result = $lang_admin_plugin_patcher['File does not exist error'];
				return STATUS_NOT_DONE;
			}

			if (!isset($_GET['skip_install']))
			{
				$install_code = file_get_contents(PUN_ROOT.'install_mod.php');
				$install_code = substr($install_code, strpos($install_code, '<?php') + 5);
				$len = strlen($install_code);

				if (($pos = strpos($install_code, '// DO NOT EDIT ANYTHING BELOW THIS LINE!')) !== false)
					$len = $pos;
				elseif (($pos = strpos($install_code, 'function install(')) !== false && ($pos2 = strpos($install_code, '/***', $pos)) !== false)
					$len = $pos2;

				// Fix for changes in install_mod.php for another private messaging system
				elseif (($pos = strpos($install_code, '// Make sure we are running a FluxBB version')) !== false)
					$len = $pos;

				$install_code = substr($install_code, 0, $len);
				$install_code = str_replace(array('define(\'PUN_TURN_OFF_MAINT\', 1);', 'define(\'PUN_ROOT\', \'./\');', 'require PUN_ROOT.\'include/common.php\';'), '', $install_code);
				$install_code = str_replace('or error(', 'or myerror(', $install_code);

				$lines = explode("\n", $install_code);
				foreach ($lines as $cur_line)
					if (preg_match('#^\$[a-zA-Z0-9_-]+#', $cur_line, $matches))
						eval('global '.$matches[0].';');

				eval($install_code);
				if ($this->uninstall)
				{
					if (!function_exists('restore'))
					{
						$this->result = $lang_admin_plugin_patcher['Database not restored'];
						return STATUS_UNKNOWN;
					}
					restore();
					$this->result = $lang_admin_plugin_patcher['Database restored'];
				}
				elseif ($this->install || $this->update)
				{
					install();
					$this->result = sprintf($lang_admin_plugin_patcher['Database prepared for'], $mod_title);
				}
			}
			return STATUS_DONE;
		}

		ob_start();
		require_once PUN_ROOT.$this->code;
		$this->result = ob_get_clean();

		return STATUS_DONE;
	}


	function step_delete()
	{
		global $fs;

		// Should never happen
		if ($this->enable || $this->disable)
			return STATUS_NOTHING_TO_DO;

		if (defined('PATCHER_NO_SAVE') || $this->validate)
			return STATUS_UNKNOWN;

		// Delete step is usually for install_mod.php so when uninstalling that file does not exist
		if ($this->uninstall)
			return STATUS_UNKNOWN;

		$this->code = trim($this->code);
		if (!file_exists(PUN_ROOT.$this->code))
			return STATUS_UNKNOWN;

		if ($fs->delete(PUN_ROOT.$this->code))
			return STATUS_DONE; // done

		$this->result = $lang_admin_plugin_patcher['Can\'t delete file error'];
		return STATUS_NOT_DONE;
	}


	function step_rename()
	{
		global $fs;
		if (defined('PATCHER_NO_SAVE') || $this->validate)
			return STATUS_UNKNOWN;

		$this->code = trim($this->code);

		$lines = explode("\n", $this->code);
		foreach ($lines as $line)
		{
			$files = explode('to', $line);
			$file_to_rename = trim($files[0]);
			$new_file = trim($files[1]);

			// TODO: fix status as it indicates last renamed file
			if (!file_exists($new_file) && $fs->move(PUN_ROOT.$file_to_rename, PUN_ROOT.$new_file))
				$status = STATUS_DONE;
		}
		return $status;
	}


	// If friendly url mod is installed revert its changes from current file (apply again while saving this file)
	function friendly_url_open()
	{
		if ($this->flux_mod->id == 'friendly-url' || !isset($this->config['installed_mods']['friendly-url']) || isset($this->config['installed_mods']['friendly-url']['disabled']) || !isset($this->config['steps']['friendly-url/files/gen.php']))
			return;

		$steps = $this->config['steps']['friendly-url/files/gen.php'];
		$steps = array_values($steps);
		$cur_file = '';

		$changes = array();
		$found = false;
		for ($i = 0; $i < count($steps) - 1; $i++)
		{
			if ($found)
			{
				// Revert changes
				unset($this->config['steps']['friendly-url/files/gen.php'][$i]);
				$changes[] = array('replace' => $steps[$i]['code'], 'search' => $steps[++$i]['code']);
				unset($this->config['steps']['friendly-url/files/gen.php'][$i]);

				if (isset($steps[$i+1]['command']) && $steps[$i+1]['command'] == 'OPEN')
					break;
			}

			if (!$found && (!isset($steps[$i]['command']) || $steps[$i]['command'] != 'OPEN' || $steps[$i]['code'] != $this->cur_file_path))
				continue;
			$found = true;
			unset($this->config['steps']['friendly-url/files/gen.php'][$i]);
		}
		$this->config['steps']['friendly-url/files/gen.php'] = array_values($this->config['steps']['friendly-url/files/gen.php']);
		$changes = array_reverse($changes);
		$end_pos = strlen($this->cur_file);
		foreach ($changes as $cur_change)
		{
			$pos = strrpos(substr($this->cur_file, 0, $end_pos), $cur_change['search']);
			if ($pos === false)
				$pos = strrpos($this->cur_file, $cur_change['search']); // as the changes are sorted by string position this should never happen
			else
				$end_pos = $pos;

			$this->cur_file = substr_replace($this->cur_file, $cur_change['replace'], $pos, strlen($cur_change['search']));
		}
	}


	// If friendly url mod is installed apply its changes again (as patcher reverted them in open step)
	function friendly_url_save()
	{
		if ($this->flux_mod->id == 'friendly-url' || !isset($this->config['installed_mods']['friendly-url']) || isset($this->config['installed_mods']['friendly-url']['disabled']))
			return;

		$cur_readme_file = 'friendly-url/files/gen.php';
		if (!isset($this->config['steps'][$cur_readme_file]))
			$this->config['steps'][$cur_readme_file] = array();

		if (file_exists(MODS_DIR.'friendly-url/files/gen.php'))
		{
			$changes = array();
			require_once MODS_DIR.'friendly-url/files/gen.php';
			$this->cur_file = url_replace_file($this->cur_file_path, $this->cur_file, $changes);
			$this->config['steps'][$cur_readme_file] = array_merge($this->config['steps'][$cur_readme_file], url_get_steps($changes));
		}
	}


	// If friendly url mod is installed apply its changes
	function friendly_url_upload($cur_file_name)
	{
		global $fs;

		if ($this->flux_mod->id == 'friendly-url' || !isset($this->config['installed_mods']['friendly-url']) || isset($this->config['installed_mods']['friendly-url']['disabled'])
			|| substr($cur_file_name, -4) != '.php' || in_array($cur_file_name, array('gen.php', 'install_mod.php'))
			|| dirname($cur_file_name) != '.' && substr($cur_file_name, 0, 7) != 'include') // directory other than PUN_ROOT and include
			return;

		$gen_file = 'friendly-url/files/gen.php';
		if (!isset($this->config['steps'][$gen_file]))
			$this->config['steps'][$gen_file] = array();

		if (file_exists(MODS_DIR.$gen_file))
		{
			$changes = array();
			require_once MODS_DIR.'friendly-url/files/gen.php';
			$cur_file = file_get_contents(PUN_ROOT.$cur_file_name);
			$cur_file = url_replace_file($cur_file_name, $cur_file, $changes);
			if (count($changes) > 0)
				$fs->put(PUN_ROOT.$cur_file_name, $cur_file);
			$this->config['steps'][$gen_file] = array_merge($this->config['steps'][$gen_file], url_get_steps($changes));
		}
	}


	function friendly_url_uninstall_upload()
	{
		$gen_file = 'friendly-url/files/gen.php';
		if (!isset($this->config['steps'][$gen_file]))
			return;

		foreach ($this->flux_mod->files_to_upload as $from => $to)
		{
			$remove_steps = false;
			foreach ($this->config['steps'][$gen_file] as $key => $cur_step)
			{
				if ($remove_steps)
				{
					if (in_array($cur_step['command'], $this->modify_file_commands))
						unset($this->config['steps'][$gen_file][$key]);
					else
						$remove_steps = false;
				}
				elseif ($cur_step['command'] == 'OPEN' && $cur_step['code'] == $to)
				{
					unset($this->config['steps'][$gen_file][$key]);
					$remove_steps = true;
				}
			}
		}
	}
}