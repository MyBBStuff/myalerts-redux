<?php

final class MyAlerts
{
    /**
     * @var array $alertTypes
     */
    private static $alertTypes;

    /**
     * Check whether an alert type is enabled.
     *
     * @param string $code The code for the alert type.
     *
     * @return bool
     */
    private static function isAlertTypeEnabled($code)
    {
        if (!isset(static::$alertTypes)) {
            global $db;

            static::$alertTypes = [];

            $query = $db->simple_select('alert_types', '*');
            while ($row = $db->fetch_array($query)) {
                static::$alertTypes[$row['code']] = (bool) $row['enabled'];
            }
        }

        if (isset(static::$alertTypes[$code])) {
            return static::$alertTypes[$code];
        }

        return false;
    }

    public static function addHooks()
    {
        global $plugins;

        $plugins->add_hook('datahandler_user_insert', ['MyAlerts', 'dataHandlerUserInsert'], 10, __FILE__);
        $plugins->add_hook('reputation_do_add_process', ['MyAlerts', 'reputationDoAddProcess'], 10, __FILE__);
    }

    public static function dataHandlerUserInsert(&$dataHandler)
    {
        global $db;

        $dataHandler->user_insert_data['myalerts_disabled_alert_types'] = $db->escape_string(
            json_encode(array())
        );
    }

    public static function reputationDoAddProcess()
    {
        global $mybb, $reputation;

        if (!isset($mybb->user['uid']) || $mybb->user['uid'] < 1 || !static::isAlertTypeEnabled('rep')) {
            return;
        }
    }
}