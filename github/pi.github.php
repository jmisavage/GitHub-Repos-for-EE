<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/*
Copyright (c) 2012 Jeremy Misavage

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
ELLISLAB, INC. BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

$plugin_info = array(
						'pi_name'			=> 'GitHub',
						'pi_version'		=> '0.9.0',
						'pi_author'			=> 'Jeremy Misavage',
						'pi_author_url'		=> 'http://idlehamster.com/',
						'pi_description'	=> 'A plugin that allows you to display public repos from any GitHub user.',
						'pi_usage'			=> Github::usage()
					);
					
class Github {
	
	var $return_data	= '';
	var $base_url		= 'https://api.github.com/';
	var $cache_name		= 'github_cache';
	var $cache_expired	= FALSE;
	var $refresh		= 15;		// Period between cache refreshes, in minutes
	var $parameters		= array();
	var $use_stale		= 'yes';
	
	/**
	 * Constructor
	 *
	 * @access	public
	 */
	function Github()
	{
		$this->EE =& get_instance();

		// Fetch parameters
		$this->refresh	= $this->EE->TMPL->fetch_param('refresh', $this->refresh);
		$user			= $this->EE->TMPL->fetch_param('user');
			
		// build url
		$url = $this->base_url . "users/" . $user . "/repos";
		
		
		// retrieve statuses
		$repos = $this->_fetch_data($url);
		
		
		// Some variables needed for the parsing process
		$count		= 0;
		$updated_at	= array();
		$created_at	= array();
		
		// parse updated_at date variables outside of the loop to save processing
		if (preg_match_all("/".LD."(user_)?updated_at\s+format=(\042|\047)([^\\2]*?)\\2".RD."/s", $this->EE->TMPL->tagdata, $matches))
		{
			for ($i = 0; $i < count($matches['0']); $i++)
			{
				$matches['0'][$i] = str_replace(array(LD, RD), '', $matches['0'][$i]);
				$updated_at[$matches['0'][$i]] = $this->EE->localize->fetch_date_params($matches['3'][$i]);
			}
		}
		
		// parse created_at date variables outside of the loop to save processing
		if (preg_match_all("/".LD."(user_)?created_at\s+format=(\042|\047)([^\\2]*?)\\2".RD."/s", $this->EE->TMPL->tagdata, $matches))
		{
			for ($i = 0; $i < count($matches['0']); $i++)
			{
				$matches['0'][$i] = str_replace(array(LD, RD), '', $matches['0'][$i]);
				$created_at[$matches['0'][$i]] = $this->EE->localize->fetch_date_params($matches['3'][$i]);
			}
		}
		
		
		// Loop through all the repos and do our tag replacements
		foreach ($repos as $key => $val)
		{
			$tagdata = $this->EE->TMPL->tagdata;
			
			// add count
			$val['count'] = $count++;
			
			// Prep conditionals
			$cond = $val;
			$tagdata = $this->EE->functions->prep_conditionals($tagdata, $cond['owner']);
			
			unset($cond['owner']);
			$tagdata = $this->EE->functions->prep_conditionals($tagdata, $cond);
				
			foreach ($this->EE->TMPL->var_single as $var_key => $var_val)
			{				
				// parse {updated_at}
				if (isset($updated_at[$var_key]))
				{
					$date = strtotime( $repos[$key]['updated_at'] );
					
					foreach ($updated_at[$var_key] as $dvar)
					{
						$var_val = str_replace($dvar, $this->EE->localize->convert_timestamp($dvar, $date, TRUE), $var_val);
					}
					
					$tagdata = $this->EE->TMPL->swap_var_single($var_key, $var_val, $tagdata);
				}
				
				// parse {created_at}
				if (isset($created_at[$var_key]))
				{
					$date = strtotime( $repos[$key]['created_at'] );
					
					foreach ($created_at[$var_key] as $dvar)
					{
						$var_val = str_replace($dvar, $this->EE->localize->convert_timestamp($dvar, $date, TRUE), $var_val);
					}
					
					$tagdata = $this->EE->TMPL->swap_var_single($var_key, $var_val, $tagdata);
				}
				
				$this->EE->TMPL->log_item("GitHub: " . $var_key);
				
				// All the variables in the standard GitHub json object
				if (isset($val[$var_key]))
				{
					$tagdata = $this->EE->TMPL->swap_var_single($var_key, $val[$var_key], $tagdata);
				}
				else
				{
					$tagdata = $this->EE->TMPL->swap_var_single($var_key, '', $tagdata);
				}
			}
			
			$this->return_data .= $tagdata;
		}
	}
	
	function _fetch_data($url) {
		$raw		= '';
		$cached		= $this->_check_cache($url);
		
		if ($this->cache_expired OR ! $cached)
		{
			$this->EE->TMPL->log_item("GitHub: Fetching repos remotely");
			
			if ( function_exists('curl_init'))
			{
				$raw = $this->_curl_fetch($url); 
			}
			else
			{
				$raw = $this->_fsockopen_fetch($url);
			}
		}
		
		// Verify Data
		$obj = $this->_verify_data($raw);
				
		if ( ! $obj)
		{
			// Did we try to grab new data? Tell them that it failed.
			if ( ! $cached OR $this->cache_expired)
			{
				$this->EE->TMPL->log_item("GitHub: Unable to retrieve repos remotely");
								
				// Try to parse cache? Is it worth it?
				if ($this->use_stale != 'yes' OR ! $cached)
				{
					return FALSE;
				}
				
				$this->EE->TMPL->log_item("GitHub: Using stale cache: " . $url);
			}
			else
			{
				$this->EE->TMPL->log_item("GitHub: Repos retrieved from cache.");
			}
			
			// Check the cache
			$obj = $this->_verify_data($cached);
			
			
			// Refresh the cache timestamp, even if the cache file
			// is the rate limiting message. We need to stop asking for data for a while.
			
			if ($this->cache_expired)
			{
				$this->_write_cache($cached, $url);
			}
				
			if ( ! $obj)
			{
				$this->EE->TMPL->log_item("GitHub: Invalid cache file");
				return FALSE;
			}
		}
		else
		{
			// We have (valid) new data - cache it
			$this->_write_cache($raw, $url);			
		}

		return json_decode($obj, true);
	}
	
	
	
	function _verify_data($data) {
		return $data;
	}
	
	function _write_cache($data, $url) {
		// Check for cache directory
		$dir = APPPATH.'cache/'.$this->cache_name.'/';

		if ( ! @is_dir($dir))
		{
			if ( ! @mkdir($dir, 0777))
			{
				return FALSE;
			}
			
			@chmod($dir, 0777);            
		}
		
		// add a timestamp to the top of the file
		$data = time()."\n".$data;
		
		
		// Write the cached data
		$file = $dir.md5($url);
	
		if ( ! $fp = @fopen($file, 'wb'))
		{
			return FALSE;
		}

		flock($fp, LOCK_EX);
		fwrite($fp, $data);
		flock($fp, LOCK_UN);
		fclose($fp);
        
		@chmod($file, 0777);
	}
	
	function _check_cache($url)
	{	
		// Check for cache directory
		$dir = APPPATH.'cache/'.$this->cache_name.'/';
		
		if ( ! @is_dir($dir))
		{
			return FALSE;
		}
		
		// Check for cache file
        $file = $dir.md5($url);
		
		if ( ! file_exists($file) OR ! ($fp = @fopen($file, 'rb')))
		{
			return FALSE;
		}
		       
		flock($fp, LOCK_SH);
                    
		$cache = @fread($fp, filesize($file));
                    
		flock($fp, LOCK_UN);
        
		fclose($fp);

        // Grab the timestamp from the first line
		$eol = strpos($cache, "\n");
		
		$timestamp = substr($cache, 0, $eol);
		$cache = trim((substr($cache, $eol)));
		
		if ( time() > ($timestamp + ($this->refresh * 60)) )
		{
			$this->cache_expired = TRUE;
		}
		
        return $cache;
	}
	
	
	
	/***********************************************************************
	*                                                                      *
	*                        Network Helper Methods                        *
	*                                                                      *
	***********************************************************************/
	
	/**
	 * Fetch GitHub repos using cURL
	 */
	function _curl_fetch($url) {
		$ch = curl_init(); 
		curl_setopt($ch, CURLOPT_URL, $url); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		
		$data = curl_exec($ch);
		
		curl_close($ch);

		return $data;
	}
	
	/**
	 * Fetch GitHub repos using fsockopen
	 */
	function  _fsockopen_fetch($url) {
		$target = parse_url($url);
		
		$data = '';
		
		$fp = fsockopen($target['host'], 80, $error_num, $error_str, 8); 

		if (is_resource($fp))
		{
			fputs($fp, "GET {$url} HTTP/1.0\r\n");
			fputs($fp, "Host: {$target['host']}\r\n");
			fputs($fp, "User-Agent: EE/EllisLab PHP/" . phpversion() . "\r\n\r\n");

		    $headers = TRUE;

		    while ( ! feof($fp))
		    {
		        $line = fgets($fp, 4096);

		        if ($headers === FALSE)
		        {
		            $data .= $line;
		        }
		        elseif (trim($line) == '')
		        {
		            $headers = FALSE;
		        }
		    }

		    fclose($fp); 
		}
		
		return $data;
	}

	
	// --------------------------------------------------------------------
	
	/**
	 * Usage
	 *
	 * Plugin Usage
	 *
	 * @access	public
	 * @return	string
	 */
	function usage()
	{
		ob_start(); 
		?>
		------------------
		EXAMPLE USAGE:
		------------------
		
		{exp:github user="github_username"}
		<div class="github_repo">
			<h1>{name}</h1>
			<h2>{created_at format="%m-%d %g:%i"}</h2>
			<p>{description}</p>
		</div>
		{/exp:github}
		
		------------------
		PARAMETERS:
		------------------

		user="jmisavage"
		- The GitHub user of the repos to show.
		
		refresh="30"
		- Time (in minutes) of cache interval for the requested GitHub.  Defaults to 15.
		
		------------------
		VARIABLES:
		------------------
		
		{count}
		{created_at format="%m-%d-%Y"}
		{update_at format="%m-%d-%Y"}
		{watchers}
		{forks}
		{open_issues}
		{language}
		{html_url}
		{url}
		{svn_url}
		{git_url}
		{name}
		{description}
		
		------------------
		CHANGELOG:
		------------------		
		Version 0.9.0 - Does basically everything you need
		Version 0.2.0 - Added caching
		Version 0.1.0 - Initial build, gets data from GitHub every page load
		
		<?php
		$buffer = ob_get_contents();

		ob_end_clean(); 

		return $buffer;
	}

	// --------------------------------------------------------------------
	
}
// END Github Class

/* End of file  pi.github.php */
/* Location: ./system/expressionengine/third_party/github/pi.github.php */