<?php
/**
 * Joomla! System plugin - Language Domains
 *
 * @author    Yireo (info@yireo.com)
 * @copyright Copyright 2015 Yireo.com. All rights reserved
 * @license   GNU Public License
 * @link      http://www.yireo.com
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

jimport('joomla.plugin.plugin');

require_once JPATH_SITE . '/plugins/system/languagefilter/languagefilter.php';

/**
 * Class plgSystemLanguageDomains
 *
 * @package     Joomla!
 * @subpackage  System
 * @since       2013
 */
class plgSystemLanguageDomains extends plgSystemLanguageFilter
{
    protected $bindings = false;

	/**
	 * Constructor
	 *
	 * @param   mixed  &$subject  Instance of JEventDispatcher
	 * @param   mixed  $config    Configuration array
	 */
	public function __construct(&$subject, $config)
	{
		JLoader::import('joomla.version');
		$version = new JVersion;
		$majorVersion = $version->getShortVersion();

		if (version_compare($majorVersion, '3.2', 'ge'))
		{
			require_once JPATH_SITE . '/plugins/system/languagedomains/rewrite-32/associations.php';
			require_once JPATH_SITE . '/plugins/system/languagedomains/rewrite-32/multilang.php';
		}

		$this->app = JFactory::getApplication();

		$rt = parent::__construct($subject, $config);

		// Detect the current language 
		$currentLanguageTag = $this->detectLanguage();

		// If this is the Site-application
		if ($this->app->isSite() == true)
		{
			// Get the bindings
			$bindings = $this->getBindings();

			if ($bindings)
			{
				// Check whether the currently defined language is in the list of domains
				if (!isset($bindings[$currentLanguageTag]))
				{
					$this->setLanguage($currentLanguageTag);

					return $rt;
				}

				// Check if the current default language is correct
				foreach ($bindings as $bindingLanguageTag => $bindingDomain)
				{
					if (stristr(JURI::current(), $bindingDomain) == true)
					{
						// Change the current default language
						$newLanguageTag = $bindingLanguageTag;
						break;
					}
				}

				// Make sure the current language-tag is registered as current
				if (!empty($newLanguageTag) && $newLanguageTag != $currentLanguageTag)
				{
					$this->setLanguage($newLanguageTag);
				}
			}
		}

		return $rt;
	}

	/**
	 * Event onAfterInitialise
	 *
	 * @return null
	 */
	public function onAfterInitialise()
	{
		// Remove the cookie if it exists
        $this->cleanLanguageCookie();

		// Reset certain parameters of the parent plugin
		$this->params->set('remove_default_prefix', 1);
		$this->params->set('detect_browser', 0);

		// Enable item-associations
		$this->app->item_associations = $this->params->get('item_associations', 1);
		$this->app->menu_associations = $this->params->get('item_associations', 1);

		// If this is the Administrator-application, or if debugging is set, do nothing
		if ($this->app->isSite() == false)
		{
			return;
		}

		// Disable browser-detection
		$this->app->setDetectBrowser(false);

		// Detect the language
		$languageTag = JFactory::getLanguage()->getTag();

		// Detect the language again
		if (empty($languageTag))
		{
			$language = JFactory::getLanguage();
			$languageTag = $language->getTag();
		}

		// Get the bindings
		$bindings = $this->getBindings();

		// Preliminary checks
		if (empty($bindings) || (!empty($languageTag) && !isset($bindings[$languageTag])))
		{
			// Run the event of the parent-plugin
			$rt = parent::onAfterInitialise();

			// Re-enable item-associations
			$this->app->item_associations = $this->params->get('item_associations', 1);
			$this->app->menu_associations = $this->params->get('item_associations', 1);

			return;
		}

		// Check for an empty language
		if (empty($languageTag))
		{
			// Check if the current default language is correct
			foreach ($bindings as $bindingLanguageTag => $bindingDomain)
			{
				if (stristr(JURI::current(), $bindingDomain) == true)
				{
					// Change the current default language
					$newLanguageTag = $bindingLanguageTag;

					break;
				}
			}
		}

		// Override the default language if the domain was matched
		if (empty($languageTag) && !empty($newLanguageTag))
		{
			$languageTag = $newLanguageTag;
		}

		// Make sure the current language-tag is registered as current
		if (!empty($languageTag))
		{
			$this->setLanguage($languageTag);

			$component = JComponentHelper::getComponent('com_languages');
			$component->params->set('site', $languageTag);
		}

		// Run the event of the parent-plugin
		$rt = parent::onAfterInitialise();

		// Re-enable item-associations
		$this->app->item_associations = $this->params->get('item_associations', 1);
		$this->app->menu_associations = $this->params->get('item_associations', 1);

        $this->resetDefaultLanguage();

		return $rt;
	}

    public function onAfterRoute()
    {
		// Run the event of the parent-plugin
		$rt = parent::onAfterRoute();

		// Detect the current language 
		$languageTag = $this->detectLanguage();
        $this->debug('Current language tag: ' . $languageTag);

        if (empty($languageTag))
        {
            $this->redirectLanguageToDomain($languageTag);
        }
    }

	/**
	 * Event onAfterRender
	 *
	 * @return null
	 */
	public function onAfterRender()
	{
		// Reset certain parameters of the parent plugin
		$this->params->set('remove_default_prefix', 1);
		$this->params->set('detect_browser', 0);

		// If this is the Administrator-application, or if debugging is set, do nothing
		if ($this->app->isAdmin() || JDEBUG)
		{
			return;
		}

		// Fetch the document buffer
		$buffer = JResponse::getBody();

		// Get the bindings
		$bindings = $this->getBindings();

		if (empty($bindings))
		{
			return;
		}

		// Loop through the languages and check for any URL
		$languages = JLanguageHelper::getLanguages('sef');
		$debugStartTime = microtime(true);

		foreach ($languages as $languageSef => $language)
		{
			$languageCode = $language->lang_code;

			if (!isset($bindings[$languageCode]))
			{
				continue;
			}

			if (empty($bindings[$languageCode]))
			{
				continue;
			}

			if (empty($languageSef))
			{
				continue;
			}

			$domain = $bindings[$languageCode];
			$domain = $this->getUrlFromDomain($domain);

			// Replace shortened URLs
			if (preg_match_all('/([\'\"]{1})\/(' . $languageSef . ')\/([^\'\"]?)/', $buffer, $matches))
			{
				foreach ($matches[0] as $index => $match)
				{
                    $this->debug('Match shortened URL: '.$match);

                    if ($this->allowUrlChange($match) == false)
                    {
                        continue;
                    }

					$buffer = str_replace($match, $matches[1][$index] . $domain . $matches[3][$index], $buffer);
				}
			}

            // Replace shortened URLs that contain /index.php/
            if (JFactory::getConfig()->get('sef_rewrite', 0) == 0)
            {
                if (preg_match_all('/([\'\"]{1})\/index.php\/(' . $languageSef . ')\/([^\'\"]?)/', $buffer, $matches))
                {
                    foreach ($matches[0] as $index => $match)
                    {
                        $this->debug('Match shortened URL with /index.php/: '.$match);

                        if ($this->allowUrlChange($match) == true)
                        {
                            $buffer = str_replace($match, $matches[1][$index] . $domain . $matches[3][$index], $buffer);
                        }
                    }
                }
            }

			// Replace full URLs
			if (preg_match_all('/(http|https)\:\/\/([a-zA-Z0-9\-\/\.]{5,40})\/' . $languageSef . '\/([^\'\"]+)/', $buffer, $matches))
			{
				foreach ($matches[0] as $match)
				{
                    $this->debug('Match full URL: '.$match);

                    if ($this->allowUrlChange($match) == true)
                    {
					    $match = preg_replace('/(\'|\")/', '', $match);
    					$workMatch = str_replace('index.php/', '', $match);
	    				$matchDomain = $this->getDomainFromUrl($workMatch);

		    			if (empty($matchDomain) || in_array($matchDomain, $bindings) || in_array('www.' . $matchDomain, $bindings))
			    		{
				    		$buffer = str_replace($match, $domain . $matches[3][$index], $buffer);
					    }
                    }
				}
			}
		}

		$currentLanguageTag = JFactory::getLanguage()->getTag();

		JResponse::setBody($buffer);
	}

	/**
	 * Method to get the bindings for languages
	 *
	 * @return null
	 */
	protected function getBindings()
	{
        if (is_array($this->bindings))
        {
            return $this->bindings;
        }

		$bindings = trim($this->params->get('bindings'));

		if (empty($bindings))
		{
	        $this->bindings = array();		
    
            return $this->bindings;
		}

		$bindingsArray = explode("\n", $bindings);
		$bindings = array();

		foreach ($bindingsArray as $index => $binding)
		{
			$binding = explode('=', $binding);

			if (isset($binding[0]) && isset($binding[1]))
			{
				$languageCode = trim($binding[0]);
				$languageCode = str_replace('_', '-', $languageCode);

				$domain = trim($binding[1]);

				$bindings[$languageCode] = $domain;
			}
		}

		arsort($bindings);
        $this->bindings = $bindings;

		return $this->bindings;
	}

	/**
	 * Helper-method to get a proper URL from the domain @access public @param string @return string
	 *
	 * @param   string  $domain  Domain to obtain the URL from
	 *
	 * @return string
	 */
	protected function getUrlFromDomain($domain)
	{
		// Add URL-elements to the domain
		if (preg_match('/^(http|https):\/\//', $domain) == false)
		{
			$domain = 'http://' . $domain;
		}

		if (preg_match('/\/$/', $domain) == false)
		{
			$domain = $domain . '/';
		}

		if (JFactory::getConfig()->get('sef_rewrite', 0) == 0 && preg_match('/index\.php/', $domain) == false)
		{
			$domain = $domain . 'index.php/';
		}

		return $domain;
	}

	/**
	 * Helper-method to get a proper URL from the domain @access public @param string @return string
	 *
	 * @param   string  $url  URL to obtain the domain from
	 *
	 * @return string
	 */
	protected function getDomainFromUrl($url)
	{
		// Add URL-elements to the domain
		if (preg_match('/^(http|https):\/\/([a-zA-Z0-9\.\-\_]+)/', $url, $match))
		{
			$domain = $match[2];
			$domain = preg_replace('/^www\./', '', $domain);

			return $domain;
		}

		return false;
	}

    protected function redirectLanguageToDomain($languageTag)
    {
        // Check whether to allow redirects or to leave things as they are
        $allowRedirect = $this->allowRedirect();

        if ($allowRedirect == false)
        {
            return false;
        }

		// Get the bindings
		$bindings = $this->getBindings();

		if (array_key_exists($languageTag, $bindings))
        {
            $domain = $bindings[$languageTag];

            if (stristr(JURI::current(), $domain) == false)
            {
                // Add URL-elements to the domain
                $domain = $this->getUrlFromDomain($domain);

                // Replace the current domain with the new domain
                $currentUrl = JURI::current();
                $newUrl = str_replace(JURI::base(), $domain, $currentUrl);

                // Set the cookie
                $conf = JFactory::getConfig();
                $cookie_domain = $conf->get('config.cookie_domain', '');
                $cookie_path = $conf->get('config.cookie_path', '/');
                setcookie(JApplication::getHash('language'), null, time() - 365 * 86400, $cookie_path, $cookie_domain);

                // Redirect
                $this->app->redirect($newUrl);
                $this->app->close();
            }
        }
    }

	/**
	 * Wipe language cookie
	 */
	protected function cleanLanguageCookie()
	{
		$languageHash = JApplication::getHash('language');

		if (isset($_COOKIE[$languageHash]))
		{
			$conf = JFactory::getConfig();
			$cookie_domain = $conf->get('config.cookie_domain', '');
			$cookie_path = $conf->get('config.cookie_path', '/');
			setcookie($languageHash, '', time() - 3600, $cookie_path, $cookie_domain);
			JFactory::getApplication()->input->cookie->set($languageHash, '');
		}
    }

	/**
	 * Detect the current language
	 *
	 * @return string
	 */
	protected function detectLanguage()
	{
		if (!empty($this->default_lang))
        {
            return $this->default_lang;
        }

		// Load the current language as detected from the URL
		$currentLanguageTag = $this->app->input->get('language');

		if (empty($currentLanguageTag))
		{
		    $currentLanguageTag = $this->app->input->get('lang');
        }

		if (empty($currentLanguageTag))
		{
			$currentLanguageTag = JFactory::getLanguage()->getTag();
		}

        return $currentLanguageTag;
    }

	/**
	 * Change the current language
	 *
	 * @param   string  $languageTag  Tag of a language
	 *
	 * @return null
	 */
	protected function setLanguage($languageTag)
	{
        // Set the input variable
        $this->app->input->set('language', $languageTag);
        $this->app->input->set('lang', $languageTag);

		// Rerun the constructor ugly style
		JFactory::getLanguage()->__construct($languageTag);

        // Reload languages 
        $language = JLanguage::getInstance($languageTag, false);
        $language->load('tpl_'.$this->app->getTemplate(), JPATH_SITE, $languageTag, true);
		$language->load('joomla', JPATH_SITE, $languageTag, true);
		$language->load('lib_joomla', JPATH_SITE, $languageTag, true);

        // Reinject the language back into the application
        try
        {
            $this->app->set('language', $languageTag);
        }
        catch(Exception $e)
        {
        }

        $this->app->loadLanguage($language);

        // Reset the JFactory
        try 
        {
            JFactory::$language = $language;
        }
        catch(Exception $e)
        {
        }
	}

	/**
	 * Allow a redirect
	 *
	 * @return bool
	 */
	private function allowRedirect()
	{
        $jinput = $this->app->input;

        if ($jinput->getMethod() == "POST" || count($jinput->post) > 0 || count($jinput->files) > 0)
        {
            return false;
        }

        if ($jinput->getCmd('tmpl') == 'component')
        {
            return false;
        }

        if (in_array($jinput->getCmd('format'), array('json', 'feed', 'api', 'opchtml')))
        {
            return false;
        }

        if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
        {
            return false;
        }

        return true;
    }

	/**
	 * Allow a specific URL to be changed by this plugin
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	private function allowUrlChange($url)
	{
        // Exclude specific component-calls
        if (preg_match('/format=(raw|json|api)/', $url))
        {
            return false;
        }
        
        // Exclude specific JavaScript
        if (preg_match('/\.js$/', $url))
        {
            return false;
        }

        // Do not rewrite non-SEF URLs
        if (stristr($url, 'index.php?option='))
        {
            return false;
        }

        // Exclude specific components
        $exclude_components = $this->getArrayFromParam('exclude_components');
        
        if (!empty($exclude_components))
        {
            foreach($exclude_components as $exclude_component)
            {
                if (stristr($url, 'components/' . $exclude_component))
                {
                    return false;
                }

                if (stristr($url, 'option=' . $exclude_component . '&'))
                {
                    return false;
                }
            }
        }
        
        // Exclude specific URLs
        $exclude_urls = $this->getArrayFromParam('exclude_urls');
        $exclude_urls[] = '/media/jui/js/';
        $exclude_urls[] = '/assets/js/';
        
        if (!empty($exclude_urls))
        {
            foreach($exclude_urls as $exclude_url)
            {
                if (stristr($url, $exclude_url))
                {
                    return false;
                }
            }
        }
        
        return true;
    }

	/**
	 * Get an array from a parameter
	 *
	 * @param string $param
	 *
	 * @return array
	 */
	private function getArrayFromParam($param)
	{
		$data = $this->params->get($param);
		$data = trim($data);

		if (empty($data))
		{
			return array();
		}

		$data = explode(',', $data);

		$newData = array();

		foreach ($data as $value)
		{
			$value = trim($value);

			if (!empty($value))
			{
				$newData[] = $value;
			}
		}

		return $newData;
	}

    private function debug($message)
    {
        if($this->allowRedirect() == false) {
                return false;
        }

        $debug = false;
        $jinput = $this->app->input;

        if ($jinput->getInt('debug') == 1)
        {
            $debug = true;
        }

        if ($this->params->get('debug') == 1)
        {
            $debug = true;
        }

        if ($debug)
        {
            echo '<script>console.log("LANGUAGE DOMAINS: '.addslashes($message).'");</script>';
        }
    }

    private function resetDefaultLanguage()
    {
        JFactory::getLanguage()->setDefault('en_GB');

        if (!class_exists('VmConfig')) {
                $vmConfigFile = JPATH_ROOT.'/administrator/components/com_virtuemart/helpers/config.php';
                if (file_exists($vmConfigFile)) {
                        defined('DS') or define('DS', DIRECTORY_SEPARATOR);
                        include_once($vmConfigFile);
                        VmConfig::loadConfig();
                        VmConfig::$defaultLang = 'en_gb';
                }
        } else {
                VmConfig::$defaultLang = 'en_gb';
        }
    }
}
