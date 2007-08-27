<?php

/**
 * Controls the viewing and layout of the site
 *
 * @version $Id$
 * @author  Matt McNaney <matt at tux dot appstate dot edu>
 * @package Core
 */

/********** Errors ****************/

define('LAYOUT_SESSION_NOT_SET',    -1);
define('LAYOUT_NO_CONTENT',         -2);
define('LAYOUT_NO_THEME',           -3);
define('LAYOUT_BAD_JS_DATA',        -4);
define('LAYOUT_JS_FILE_NOT_FOUND',  -5);
define('LAYOUT_BOX_ORDER_BROKEN',   -6);
define('LAYOUT_INI_FILE',           -7);
define('LAYOUT_BAD_THEME_VAR',      -8);

if (!defined('LAYOUT_THEME_EXEC')) {
    define('LAYOUT_THEME_EXEC', false);
 }

if (!defined('XML_MODE')) {
    define('XML_MODE', false);
 }

PHPWS_Core::initModClass('layout', 'Layout_Settings.php');
PHPWS_Core::initCoreClass('Template.php');
PHPWS_Core::requireConfig('layout');

class Layout {

    /**
     * Adds your content to the layout queue.
     *
     * If the module and content_var are set, layout will remember
     * the "position" of the content. The admin will then be able
     * to shift this position using the administrative options in Layout.
     * 
     * If the module and content_var are NOT set, the data goes into the 
     * BODY tag.
     *
     * If default_body is true, then the content we will placed into the
     * BODY tag by default instead of the DEFAULT tag. This occurs when 
     * a preset theme variable was not created for the content variable.
     *
     * @author Matt McNaney <matt at tux dot appstate dot edu>
     */
    function add($text, $module=NULL, $content_var=NULL, $default_body=FALSE)
    {
        if (!is_string($text)) {
            return;
        }
        Layout::checkSettings();
        // If content variable is not in system (and not NULL) then make
        // a new box for it.

        if (isset($module) && isset($content_var)) {
            if (!$_SESSION['Layout_Settings']->isContentVar($module, $content_var)) {
                if ($default_body) {
                    $theme_var = DEFAULT_THEME_VAR;
                } else {
                    $theme_var = NULL;
                }
                Layout::addBox($content_var, $module, $theme_var);
            }
        } else {
            $module = 'layout';
            $content_var = DEFAULT_CONTENT_VAR;
        }

        Layout::_loadBox($text, $module, $content_var);
    }


    function plug($content, $theme_var)
    {
        if (empty($content) || empty($theme_var) || preg_match('/\W/', $theme_var)) {
            return false;
        }
        $GLOBALS['Layout_Plugs'][strtoupper($theme_var)][] = $content;
    }

    function getPlugs()
    {
        if (!isset($GLOBALS['Layout_Plugs'])) {
            return null;
        } else {
            foreach ($GLOBALS['Layout_Plugs'] as $theme_var=>$content) {
                $tpl[$theme_var] = implode('', $content);
            }
            return $tpl;
        }
    }

    function _loadBox($text, $module, $contentVar)
    {
        $GLOBALS['Layout'][$module][$contentVar][] = $text;
    }

    function addBox($content_var, $module, $theme_var=NULL, $theme=NULL)
    {
        PHPWS_Core::initModClass('layout', 'Box.php');

        if (!isset($theme)) {
            $theme = $_SESSION['Layout_Settings']->current_theme;
        }

        if (!isset($theme_var)) {
            $mod_theme_var = strtoupper(sprintf('%s_%s', $module, $content_var));
            if (in_array($mod_theme_var, $_SESSION['Layout_Settings']->_theme_variables)) {
                $theme_var = $mod_theme_var;
            } else {
                $theme_var = DEFAULT_BOX_VAR;
            }
        }

        $box = new Layout_Box;
        $box->setTheme($theme);
        $box->setContentVar($content_var);
        $box->setModule($module);
        $box->setThemeVar($theme_var);

        $result = $box->save();
        if (PEAR::isError($result)) {
            PHPWS_Error::log($result);
            PHPWS_Core::errorPage();
        }
        Layout::resetBoxes();
    }

    function addJSFile($directory)
    {
        $jsfile = PHPWS_SOURCE_DIR . $directory;

        if (!is_file($jsfile)) {
            return PHPWS_Error::get(LAYOUT_JS_FILE_NOT_FOUND, 'layout', 'addJSFile', $jsfile);
        }
    }

    // Index is the name of the javascript header
    // prevents repeated scripts
    function addJSHeader($script, $index=NULL)
    {
        static $index_count = 0;

        if (empty($index)) {
            $index = $index_count++;
        }
    
        $GLOBALS['Layout_JS'][$index]['head'] = $script;
    }

    function extraStyle($filename)
    {
        $styles = Layout::getExtraStyles();
        if (!isset($styles[$filename])) {
            return;
        }

        $link['file'] = Layout::getThemeDir() . $filename;
        $link['import'] = FALSE;

        $GLOBALS['Extra_Style'] = $link;
    }

    function addLink($link)
    {
        $GLOBALS['Layout_Links'][] = $link;
    }

    /**
     * Adds a module's style sheet to the style sheet list
     */
    function addStyle($module, $filename=NULL)
    {
        if (!LAYOUT_ALLOW_STYLE_LINKS) {
            return NULL;
        }

        if (!isset($filename)) {
            $filename = 'style.css';
        }

        $tag = md5($module . $filename);

        if (isset($GLOBALS['Style'][$tag])) {
            return;
        }

        $cssFile['tag'] = & $tag;
        $cssFile['import'] = TRUE;

        $templateLoc = "templates/$module/$filename";
        $moduleLoc   = sprintf('mod/%s/templates/%s', $module, $filename);

        if ( (FORCE_MOD_TEMPLATES || !is_file($templateLoc)) && is_file($moduleLoc)) {
            $cssFile['file'] = $moduleLoc;
        } else {
            $cssFile['file'] = $templateLoc;
        }

        Layout::addToStyleList($cssFile);
    
        $themeFile['file']   = PHPWS_Template::getTplDir($module) . $filename;
        $themeFile['import'] = TRUE;

        if (is_file($themeFile['file'])) {
            Layout::addToStyleList($themeFile);
            return;
        } elseif (FORCE_THEME_TEMPLATES) {
            return;
        }

    }

    function addToStyleList($value)
    {
        $alternate = FALSE;
        $title     = NULL;
        $import    = FALSE;
        $tag       = NULL;
        $media     = NULL;

        if (!is_array($value)) {
            $file = $value;
        } else {
            extract($value);
        }

        $style = array('file'      => $file,
                       'import'    => $import,
                       'alternate' => $alternate,
                       'title'     => $title,
                       'media'     => $media
                       );

        if (isset($tag)) {
            $GLOBALS['Style'][$tag] = $style;
        } else {
            $GLOBALS['Style'][] = $style;
        }
    }

    /**
     * Displays content passed to it disregarding all other information
     * passed to it.
     *
     * This is a good function to use for a popup window. The display
     * retains style sheet information.
     *
     */
    function nakedDisplay($content=NULL, $title=NULL)
    {
        Layout::disableRobots();
        echo Layout::wrap($content, $title, true);
        exit();
    }


    function checkSettings()
    {
        if (!isset($_SESSION['Layout_Settings'])) {
            $_SESSION['Layout_Settings'] = new Layout_Settings;
        }
    }

    function clear($module, $contentVar)
    {
        unset($GLOBALS['Layout'][$module][$contentVar]);
    }

    function disableRobots()
    {
        $GLOBALS['Layout_Robots'] = '00';
    }

    function disableFollow()
    {
        if (!isset($GLOBALS['Layout_Robots'])) {
            Layout::initLayout();
        }

        switch ($GLOBALS['Layout_Robots']){
        case '01':
            $GLOBALS['Layout_Robots'] = '00';
            break;

        case '11':
            $GLOBALS['Layout_Robots'] = '10';
            break;
        }
    }

    function disableIndex()
    {
        if (!isset($GLOBALS['Layout_Robots'])) {
            Layout::initLayout();
        }

        switch ($GLOBALS['Layout_Robots']){
        case '10':
            $GLOBALS['Layout_Robots'] = '00';
            break;

        case '11':
            $GLOBALS['Layout_Robots'] = '01';
            break;
        }
    }

    function processHeld()
    {
        if (empty($GLOBALS['Layout_Held'])) {
            return;
        }
    
        foreach ($GLOBALS['Layout_Held'] as $module => $content_info) {
            foreach ($content_info as $content_var => $info) {
                $display = PHPWS_Template::process($info['values'], $module, $info['template']);
                if ($module != 'layout') {
                    Layout::add($display, $module, $content_var);
                } else {
                    Layout::add($display);
                }
            }
        }

    }

    /**
     * Main function controlling the display of data passed
     * to layout
     */
    function display()
    {
        if (LAYOUT_THEME_EXEC) {
            $theme_exec = sprintf('themes/%s/theme.php', Layout::getCurrentTheme());
            @include_once $theme_exec;
        }
        Layout::processHeld();
        $themeVarList = array();

        $header = Layout::getHeader();
        if (!empty($header)) {
            Layout::add($header, 'layout', 'header', FALSE);
        }

        $footer = Layout::getFooter();
        if (!empty($footer)) {
            Layout::add($footer, 'layout', 'footer', FALSE);
        }

        $contentList = Layout::getBoxContent();
        // if content list is blank
        // 404 error?

        foreach ($contentList as $module=>$content) {
            foreach ($content as $contentVar=>$template){

                if(!($theme_var = $_SESSION['Layout_Settings']->getBoxThemeVar($module, $contentVar))) {
                    $theme_var = DEFAULT_THEME_VAR;
                }

                if (!in_array($theme_var, $themeVarList)) {
                    $themeVarList[] = $theme_var;
                }

                $order = $_SESSION['Layout_Settings']->getBoxOrder($module, $contentVar);

                if (empty($order)) {
                    $order = MAX_ORDER_VALUE;
                }

                if (isset($unsortedLayout[$theme_var][$order])) {
                    PHPWS_Error::log(LAYOUT_BOX_ORDER_BROKEN, 'layout', 'Layout::display', $theme_var);
                }
                $unsortedLayout[$theme_var][$order] = $template;
            }
        }

        if (isset($GLOBALS['Layout_Plugs'])) {
            foreach ($GLOBALS['Layout_Plugs'] as $plug_var=>$content) {
                if (!in_array($plug_var, $themeVarList)) {
                    $themeVarList[] = $plug_var;
                    $unsortedLayout[$plug_var][0] = implode('', $content);
                }
            }
        }

        if (isset($themeVarList)) {
            foreach ($themeVarList as $theme_var){
                ksort($unsortedLayout[$theme_var]);
                $upper_theme_var = strtoupper($theme_var);
                if (Layout::isMoveBox() && !isset($GLOBALS['Layout_Plugs'][$theme_var])) {
                    $bodyLayout[$upper_theme_var] = '<fieldset class="layout-variable"><legend>' . $theme_var .  '</legend>' . implode('', $unsortedLayout[$theme_var]) . '</fieldset>';
                } else {
                    $bodyLayout[$upper_theme_var] = implode('', $unsortedLayout[$theme_var]);
                }

            }

            Layout::loadHeaderTags($bodyLayout);

            $finalTheme = &Layout::loadTheme(Layout::getCurrentTheme(), $bodyLayout);

            if (PEAR::isError($finalTheme)) {
                $content = implode('', $bodyLayout);
            } else {
                $content = $finalTheme->get();
                if (LABEL_TEMPLATES) {
                    $content = "\n<!-- START TPL: " . $finalTheme->lastTemplatefile . " -->\n"
                        . $content
                        . "\n<!-- END TPL: " . $finalTheme->lastTemplatefile . " -->\n";
                }
            }
        } else {
            $plain = implode('<br />', $unsortedLayout[$theme_var]);
            $content =  Layout::wrap($plain);
        }
        return $content;
    }

    function getBox($module, $contentVar)
    {
        if (isset($_SESSION['Layout_Settings']->_boxes[$module][$contentVar])) {
            return $_SESSION['Layout_Settings']->_boxes[$module][$contentVar];
        } else {
            return NULL;
        }
    }

    function getContentVars()
    {
        Layout::checkSettings();
        return $_SESSION['Layout_Settings']->getContentVars();
    }

    function getCurrentTheme()
    {
        return $_SESSION['Layout_Settings']->current_theme;
    }

    /**
     * Loads information sent to add function
     */
    function getBoxContent()
    {
        $list = NULL;
        if (!isset($GLOBALS['Layout'])) {
            return PHPWS_Error::get(LAYOUT_SESSION_NOT_SET, 'layout', 'getBoxContent');
        }

        foreach ($GLOBALS['Layout'] as $module=>$content){
            foreach ($content as $contentVar=>$contentList) {
                if (empty($contentList) || !is_array($contentList)) {
                    continue;
                }
                if (Layout::isMoveBox()) {
                    $box = Layout::getBox($module, $contentVar);
                    // the _MAIN content variable will return an empty box
                    // This is the BODY tag which cannot be moved
                    if (!empty($box)) {
                        array_unshift($contentList, Layout::moveBoxesTag($box));
                        //                        $contentList[] = Layout::moveBoxesTag($box);
                    }
                }
                $list[$module][$contentVar] = implode('', $contentList);
            }
        }

        return $list;
    }

    function getDefaultTheme()
    {
        return $_SESSION['Layout_Settings']->default_theme;
    }

    function getFooter()
    {
        if (PHPWS_Settings::get('layout', 'footer_fp_only') && isset($_REQUEST['module'])) {
            return null;
        }

        return PHPWS_Text::parseOutput($_SESSION['Layout_Settings']->footer);
    }

    function getHeader()
    {
        if (PHPWS_Settings::get('layout', 'header_fp_only') && isset($_REQUEST['module'])) {
            return null;
        }

        return PHPWS_Text::parseOutput($_SESSION['Layout_Settings']->header);
    }

    function getJavascript($directory, $data=NULL, $base=NULL)
    {
        if (isset($data) && !is_array($data)) {
            return;
        }

        if (!empty($base)) {
            if (!preg_match('/\/$/', $base)) {
                $base .= '/';
            }
        }

        PHPWS_CORE::initCoreClass('File.php');
        $headfile    = $base . 'javascript/' . $directory . '/head.js';
        $bodyfile    = $base . 'javascript/' . $directory . '/body.js';
        $defaultfile = $base . 'javascript/' . $directory . '/default.php';

        if (is_file($defaultfile)) {
            require $defaultfile;       
        }

        if (isset($default)) {
            if (isset($data)) {
                $data = array_merge($default, $data);
            }
            else {
                $data = $default;
            }
        }

        Layout::loadJavascriptFile($headfile, $directory, $data);

        if (is_file($bodyfile)) {
            if (isset($data)) {
                return PHPWS_Template::process($data, 'layout', $bodyfile, TRUE);
            } else {
                return file_get_contents($bodyfile);
            }
        }

    }

    function getMetaRobot($meta_robots)
    {
        switch ((string)$meta_robots){
        case '11':
            return 'all';
            break;

        case '10':
            return 'index, nofollow';
            break;

        case '01':
            return 'noindex, follow';
            break;

        case '00':
            return 'none';
            break;
        }
    }

    function getMetaTags($page_metatags=null)
    {
        if (!$page_metatags) {
            extract($_SESSION['Layout_Settings']->getMetaTags());
            if (!isset($GLOBALS['Layout_Robots'])) {
                $meta_robots = '11';
            } else {
                $meta_robots = $GLOBALS['Layout_Robots'];
            }
        } else {
            extract($page_metatags);
        }

        // Say it loud
        $metatags[] = '<meta name="generator" content="phpWebSite" />';

        $metatags[] = '<meta content="text/html; charset=UTF-8"  http-equiv="Content-Type" />';
        if (!empty($author)) {
            $metatags[] = '<meta name="author" content="' . $meta_author . '" />';
        } else {
            $metatags[] = '<meta name="author" content="phpWebSite" />';
        }

        if (!empty($meta_keywords)) {
            $metatags[] = '<meta name="keywords" content="' . $meta_keywords .'" />';
        }

        if (!empty($meta_description)) {
            $metatags[] = '<meta name="description" content="' . $meta_description . '" />';
        }
    
        if (!empty($meta_owner)) {
            $metatags[] = '<meta name="owner" content="' . $meta_owner . '" />';
        }

        $robot = Layout::getMetaRobot($meta_robots);
        $metatags[] = '<meta name="robots" content="' . $robot . '" />';

        if (isset($GLOBALS['extra_meta_tags']) && is_array($GLOBALS['extra_meta_tags'])) {
            $metatags = array_merge($metatags, $GLOBALS['extra_meta_tags']);
        }

        return implode("\n", $metatags);
    }

    /**
     * Uses meta tags to load or refresh a new page
     */
    function metaRoute($address=NULL, $time=5)
    {
        if (empty($address)) {
            $address = './index.php';
        }

        $time = (int)$time;

        $GLOBALS['extra_meta_tags'][] = sprintf('<meta http-equiv="refresh" content="%s; url=%s" />', $time, $address);
    }


    function getStyleLinks($header=FALSE)
    {
        if (!isset($GLOBALS['Style'])) {
            return TRUE;
        }

        foreach ($GLOBALS['Style'] as $link) {
            $links[] = Layout::styleLink($link, $header);
        }

        if (isset($GLOBALS['Extra_Style'])) {
            $links[] = Layout::styleLink($GLOBALS['Extra_Style'], $header);
        }

        return implode("\n", $links);
    }

    function getTheme()
    {
        return $_SESSION['Layout_Settings']->current_theme;
    }

    function getThemeDir()
    {
        Layout::checkSettings();
        $themeDir = Layout::getTheme();
        return 'themes/' . $themeDir . '/';
    }

    function isMoveBox()
    {
        return $_SESSION['Layout_Settings']->isMoveBox();
    }

    // Loads a javascript file into the header of the theme
    // index is the name of javascript. prevents repeats
    function loadJavascriptFile($filename, $index, $data=NULL)
    {
        if (!is_file($filename)) {
            return FALSE;
        }

        if (isset($data)) {
            $result = PHPWS_Template::process($data, 'layout', $filename, TRUE);

            if (!empty($result)) {
                Layout::addJSHeader($result, $index);
            } else {
                Layout::addJSHeader(file_get_contents($filename), $index);
            }
        } else {
            Layout::addJSHeader(file_get_contents($filename), $index);
        }
    }

    function getModuleJavascript($module, $script_name, $data=NULL)
    {
        $base = PHPWS_SOURCE_DIR . "mod/$module";
        $dir_check = "/javascript/$script_name";

        if (!is_dir($base . $dir_check)) {
            return FALSE;
        }
      
        return Layout::getJavascript($script_name,$data, $base);
    }

    function importStyleSheets() 	 
    { 	 
        if (isset($_SESSION['Layout_Settings']->_style_sheets)) {
            foreach ($_SESSION['Layout_Settings']->_style_sheets as $css) {
                Layout::addToStyleList($css);
            }
        }
    }


    /**
     * Inserts the content data into the current theme
     */
    function &loadTheme($theme, $template)
    {
        $tpl = new PHPWS_Template;
        $themeDir = Layout::getThemeDir();

        if (PEAR::isError($themeDir)) {
            PHPWS_Error::log($themeDir);
            PHPWS_Core::errorPage();
        }

        $result = $tpl->setFile($themeDir . 'theme.tpl', TRUE);

        if (PEAR::isError($result)) {
            return $result;
        }

        $template['THEME_DIRECTORY'] = 'themes/' . $theme . '/';
        $tpl->setData($template);
        return $tpl;
    }

    function moveBoxes($key)
    {
        $_SESSION['Layout_Settings']->_move_box = (bool)$key;
    }

    function reset($theme=null)
    {
        if ($theme) {
            $_SESSION['Layout_Settings'] = new Layout_Settings($theme);
        } else {
            $_SESSION['Layout_Settings'] = new Layout_Settings;
        }
    }


    function resetBoxes()
    {
        $_SESSION['Layout_Settings']->loadBoxes();
    }

    function resetDefaultBoxes()
    {
        $db = new PHPWS_DB('layout_box');
        $db->addWhere('theme', Layout::getDefaultTheme());
        $result = $db->delete();

        if (PEAR::isError($result)) {
            PHPWS_Error::log($result);
        }
    }


    /**
     * Unlike the add function, which appends a content variable's
     * data, set OVERWRITES the current values
     */
    function set($text, $module=null, $contentVar=null)
    {
        Layout::checkSettings();
        if (!isset($contentVar)) {
            $contentVar = DEFAULT_CONTENT_VAR;
        }

        $GLOBALS['Layout'][$module][$contentVar] = NULL;
        Layout::add($text, $module, $contentVar);
    }

    function miniLinks()
    {
        if (Layout::isMoveBox()) {
            $vars['action']  = 'admin';
            $vars['command'] = 'turn_off_box_move';
            $links[] = PHPWS_Text::moduleLink(dgettext('layout', 'Box move off'), 'layout', $vars);
        }


        $key = Key::getCurrent();
        if (javascriptEnabled() && Layout::getExtraStyles() &&
            Key::checkKey($key)) {
            

            $js_vars['width']  = 400;
            $js_vars['height'] = 200;
            $vars['key_id']    = $key->id;
            $vars['action']    = 'admin';

            $js_vars['label'] = dgettext('layout', 'Change style');
            $vars['command']  = 'js_style_change';

            $js_vars['address'] = PHPWS_Text::linkAddress('layout', $vars, TRUE);
            $links[] = javascript('open_window', $js_vars);

            if (!$key->isHomeKey()) {
                $js_vars['height'] = 400;
                $js_vars['label'] = dgettext('layout', 'Meta tags');
                $vars['command']  = 'page_meta_tags';
                $js_vars['address'] = PHPWS_Text::linkAddress('layout', $vars, TRUE);
                $links[] = javascript('open_window', $js_vars);
            }
        }

        if (!isset($links)) {
            return;
        }

        MiniAdmin::add('layout', $links);

        // MiniAdmin runs get before layout and runtime won't work
        // with flagged keys 
        MiniAdmin::get();
    }

    function styleLink($link, $header=FALSE)
    {
        $browser = getBrowserInfo('browser');
        $version = getBrowserInfo('browser_version');

        // *sigh* not worth the hassle to conform to IE
        if ($browser == 'MSIE' && (int)$version <= 7) {
            $can_import = FALSE;
        } else {
            $can_import = TRUE;
        }

        // NEED TO CHECK if using xml-stylesheet
        extract($link);

        if (!empty($title)) {
            $cssTitle = 'title="' . preg_replace('/\W/', '_', $title) . '"';
        } else {
            $cssTitle = NULL;
        }

        if (!empty($media)) {
            $media_tag = sprintf(' media="%s"', $media);
        } else {
            $media_tag = NULL;
        }


        if ($header == TRUE) {
            if (isset($alternate) && $alternate == TRUE) {
                return sprintf('<?xml-stylesheet alternate="yes" %s href="%s" type="text/css"%s?>', $cssTitle, $file, $media_tag);
            } else {
                return sprintf('<?xml-stylesheet %s href="%s" type="text/css"%s?>', $cssTitle, $file, $media_tag);
            }
        } else {
            if ($import == TRUE && $can_import) {
                return sprintf('<style type="text/css"> @import url("%s")%s;</style>', $file, $media);
            } elseif (isset($alternate) && $alternate == TRUE) {
                return sprintf('<link rel="alternate stylesheet" %s href="%s" type="text/css"%s />', $cssTitle, $file, $media_tag);
            } else {
                return sprintf('<link rel="stylesheet" %s href="%s" type="text/css"%s />', $cssTitle, $file, $media_tag);
            }
        }
    }

    function submitHeaders($theme, &$template)
    {
        if (!defined('CURRENT_LANGUAGE')) {
            if (defined('DEFAULT_LANGUAGE')) {
                define('CURRENT_LANGUAGE', DEFAULT_LANGUAGE);
            } else {
                define('CURRENT_LANGUAGE', 'en_US');
            }
        }


        if(XML_MODE == TRUE && stristr($_SERVER['HTTP_ACCEPT'],'application/xhtml+xml')){
            header('Content-Type: application/xhtml+xml; charset=UTF-8');
            $template['XML'] = '<?xml version="1.0" encoding="UTF-8"?>';
            $template['DOCTYPE'] = "\n" . '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
';
            $template['XHTML'] = '<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="' . CURRENT_LANGUAGE . '">';
            $template['XML_STYLE'] = Layout::getStyleLinks(TRUE);
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            $template['DOCTYPE'] = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
';
            $template['XHTML'] = '<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="' . CURRENT_LANGUAGE . '" lang="' . CURRENT_LANGUAGE . '">';
            $template['STYLE'] = Layout::getStyleLinks(FALSE);
        }

        header('Content-Language: ' . CURRENT_LANGUAGE);
        header('Content-Script-Type: text/javascript');
        header('Content-Style-Type: text/css');

        if ($_SESSION['Layout_Settings']->cache == FALSE) {
            header('Cache-Control : no-cache');
            header('Pragma: no-cache');
        }
    }

    function cacheOff()
    {
        $_SESSION['Layout_Settings']->_cache = FALSE;
    }

    function getBase()
    {
        return '<base href="'
            . PHPWS_Core::getHttp()
            . $_SERVER['HTTP_HOST']
            . preg_replace("/index.*/", "", $_SERVER['PHP_SELF'])
            . '" />';
    }

    function getPageTitle($only_root=FALSE)
    {
        return $_SESSION['Layout_Settings']->getPageTitle($only_root);
    }

    function addPageTitle($title)
    {
        $GLOBALS['Layout_Page_Title_Add'][] = $title;
    }

    function getMetaPage($key_id)
    {
        $db = new PHPWS_DB('layout_metatags');
        $db->addWhere('key_id', $key_id);
        return $db->select('row');
    }


    function loadHeaderTags(&$template)
    {
        $page_metatags = null;

        $theme = Layout::getCurrentTheme();
        $key = Key::getCurrent();
        if (Key::checkKey($key, false)) {
            $page_metatags = Layout::getMetaPage($key->id);

            if (PEAR::isError($page_metatags)) {
                PHPWS_Error::log($page_metatags);
                $page_metatags = null;
            }
        }


        if (isset($GLOBALS['Layout_JS'])) {
            foreach ($GLOBALS['Layout_JS'] as $script=>$javascript)
                $jsHead[] = $javascript['head'];
      
            $template['JAVASCRIPT'] = implode("\n", $jsHead);
        }

        Layout::importStyleSheets();
        Layout::submitHeaders($theme, $template);
        if (!empty($GLOBALS['Layout_Links'])) {
            $template['STYLE'] .= "\n" . implode("\n", $GLOBALS['Layout_Links']);
        }

        $template['METATAGS']   = Layout::getMetaTags($page_metatags);
        if ($page_metatags) {
            $template['PAGE_TITLE'] = $page_metatags['page_title'] . PAGE_TITLE_DIVIDER . $_SESSION['Layout_Settings']->getPageTitle(true);
        } else {
            $template['PAGE_TITLE'] = $_SESSION['Layout_Settings']->getPageTitle();
        }
        $template['ONLY_TITLE'] = $_SESSION['Layout_Settings']->getPageTitle(TRUE);
        $template['BASE']       = Layout::getBase();
   }

    /**
     * Wraps the content with the layout header
     */
    function wrap($content, $title=NULL, $use_blank=false)
    {
        $template['CONTENT'] = $content;
        Layout::loadHeaderTags($template);

        if (isset($title)) {
            $template['PAGE_TITLE'] = strip_tags($title);
        }

        if ($use_blank) {
            $empty_tpl = sprintf('themes/%s/blank.tpl', Layout::getCurrentTheme());

            if (is_file($empty_tpl)) {
                $result = PHPWS_Template::process($template, 'layout', $empty_tpl, TRUE);
                return $result;
            }
        }
        $result = PHPWS_Template::process($template, 'layout', 'header.tpl');
        return $result;
    }

    function purgeBox($content_var)
    {
        $db = new PHPWS_DB('layout_box');
        $db->addWhere('content_var', $content_var);
        $result = $db->getObjects('Layout_Box');
        if (PEAR::isError($result)) {
            return $result;
        }

        foreach ($result as $box) {
            $check = $box->kill();
            if (PEAR::isError($check)) {
                return $check;
            }
        }

        return TRUE;
    }

    /**
     * Makes a select form option to move boxes to other parts
     * of the layout
     */
    function moveBoxesTag($box)
    {
        $vars['action']  = 'admin';
        $vars['command'] = 'move_popup';
        $vars['box']     = $box->id;

        $js['width']   = 300;
        $js['height']  = 250;
        $js['address'] = PHPWS_Text::linkAddress('layout', $vars, true);
        $js['label']   = '-' . dgettext('layout', 'Click to move') . '-';
        $js['class']   = 'move-popup';
        return '<div align="center">' . javascript('open_window', $js) . '</div>';
    }

    /**
     * Returns an array of alternate style sheets for the current theme
     */
    function getAlternateStyles()
    {
        $settings = &$_SESSION['Layout_Settings'];

        if (!isset($settings->_style_sheets)) {
            return NULL;
        }

        foreach ($settings->_style_sheets as $css) {
            if (@$css['title']) {
                $filename = str_ireplace('themes/' . $_SESSION['Layout_Settings']->current_theme . '/',
                                         '', $css['file']);
                $sheets[$filename] = $css['title'];
            }
        }

        return $sheets;
    }

    function getKeyStyle($key_id)
    {
        if (!isset($_SESSION['Layout_Settings']->_key_styles[$key_id])) {
            $_SESSION['Layout_Settings']->loadKeyStyle($key_id);
        }

        return $_SESSION['Layout_Settings']->_key_styles[$key_id];
    }

    function getExtraStyles()
    {
        return $_SESSION['Layout_Settings']->_extra_styles;
    }


    function showKeyStyle()
    {
        $key = Key::getCurrent();
        if (!Key::checkKey($key)) {
            return NULL;
        }

        if (@$style = Layout::getKeyStyle($key->id)) {
            Layout::extraStyle($style);
        }
    }
}

function javascriptEnabled()
{
    $js = PHPWS_Cookie::read('js_enabled');

    if (!$js) {
        javascript('test');
        PHPWS_Cookie::write('js_enabled', 'testing');
    } elseif ($js == 'yes') {
        return true;
    } elseif ($js == 'testing') {
        if (isset($_COOKIE['js_check'])) {
            PHPWS_Cookie::write('js_enabled', 'yes');
            setcookie ('js_check', '', time() - 3600);
            return true;
        } else {
            PHPWS_Cookie::write('js_enabled', 'no');
            return false;
        }
    } else {
        return false;
    }
}


function javascript($directory, $data=NULL)
{
    return Layout::getJavascript($directory, $data);
}

function check_cookie()
{
    $cookie = PHPWS_Cookie::read('cookie_enabled');
    if (!$cookie) {
        if (!isset($_GET['cc'])) {
            PHPWS_Cookie::write('cookie_enabled', 'y');
            PHPWS_Core::reroute('index.php?cc=1');
        } else {
            $tpl['MESSAGE'] = dgettext('layout', 'This site requires you to enable cookies on your browser.');
            $message = PHPWS_Template::process($tpl, 'layout', 'no_cookie.tpl');
            Layout::nakedDisplay($message);
        }
    }
}



?>