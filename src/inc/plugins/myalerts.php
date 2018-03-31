<?php

if (!defined('IN_MYBB')) {
    die('This file cannot be accessed directly.');
}

if (defined('THIS_SCRIPT')) {
    global $templatelist;

    if (isset($templatelist) && !empty($templatelist)) {
        $templatelist .= ',';
    }
}

if (defined('IN_ACP')) {
    require_once __DIR__ . '/myalerts/src/admin.php';
} else {
    require_once __DIR__ . '/myalerts/src/frontend.php';
}

MyAlerts::addHooks();

function myalerts_info()
{
    global $lang;

    $lang->load('myalerts');

    return [
        'name'			=> $lang->myalerts_name,
        'description'	=> $lang->myalerts_description,
        'website'		=> 'https://www.mybbstuff.com',
        'author'		=> 'Euan T',
        'authorsite'	=> '',
        'version'		=> '3.0',
        'compatibility'	=> '18*',
        'codename'		=> 'mybbstuff_myalerts'
    ];
}

function myalerts_install()
{
    MyAlerts::install();
}

function myalerts_is_installed()
{
    return MyAlerts::isInstalled();
}

function myalerts_uninstall()
{
    MyAlerts::uninstall();
}

function myalerts_activate()
{
    MyAlerts::activate();
}

function myalerts_deactivate()
{
    MyAlerts::deactivate();
}

/**
 * Check whether MyAlerts is activated. Useful for 3rd parties. Example usage:
 *
 * <pre>
 * if (function_exists('myalerts_is_activated') && myalerts_is_activated()) {
 *  // Do work with MyAlerts.
 * }
 * </pre>
 *
 * @return bool Whether MyAlerts is activated and installed.
 */
function myalerts_is_activated()
{
    global $cache;
    $plugins = $cache->read('plugins');
    $activePlugins = $plugins['active'];
    $isActive = false;
    if (in_array('myalerts', $activePlugins)) {
        $isActive = true;
    }
    return myalerts_is_installed() && $isActive;
}