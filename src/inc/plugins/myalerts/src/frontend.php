<?php

final class MyAlerts
{
    /**
     * @var array $alertTypes
     */
    private static $alertTypes;

    private static function checkAndLoadAlertTypes()
    {
        if (!isset(static::$alertTypes)) {
            global $db;

            static::$alertTypes = [];

            $query = $db->simple_select('alert_types', '*');
            while ($row = $db->fetch_array($query)) {
                static::$alertTypes[$row['code']] = [
                    'id' => (int) $row['id'],
                    'enabled' => (bool) $row['enabled'],
                ];
            }
        }
    }

    /**
     * Check whether an alert type is enabled.
     *
     * @param string $code The code for the alert type.
     *
     * @return bool
     */
    public static function isAlertTypeEnabled($code)
    {
        static::checkAndLoadAlertTypes();

        if (isset(static::$alertTypes[$code])) {
            return static::$alertTypes[$code]['enabled'];
        }

        return false;
    }

    private static function getAlertTypeIdFromCode($code)
    {
        static::checkAndLoadAlertTypes();

        if (isset(static::$alertTypes[$code])) {
            return static::$alertTypes[$code]['id'];
        }

        return -1;
    }

    public static function addAlert($uid, $code, $fromUid, $objectType, $objectId, array $extraDetails = [], $forced = false)
    {
        global $db;

        $uid = (int) $uid;
        $fromUid = (int) $fromUid;
        $alertTypeId = static::getAlertTypeIdFromCode($code);
        $objectType = $db->escape_string($objectType);
        $objectId = (int) $objectId;

        if ($alertTypeId == -1) {
            // Invalid alert type, no further processing.
            return;
        }

        if (!$forced) {
            // check for existing alerts with the same object type, id and uid
            $query = $db->simple_select('alerts', 'COUNT(*) AS count', "uid = {$uid} AND object_type = '{$objectType}' AND object_id = {$objectId}");
            $count = (int) $db->fetch_field($query, 'count');

            if ($count > 0) {
                // already got an alert for this object, do not repeat alerts
                return;
            }
        }

        $extra = '';

        if (!empty($extraDetails)) {
            $extra = $db->escape_string(json_encode($extraDetails));
        }

        $db->insert_query('alerts', [
            'uid' => $uid,
            'alert_type_id' => $alertTypeId,
            'from_uid' => $fromUid,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'forced' => $forced === true ? 1 : 0,
            'extra_details' => $extra,
        ]);
    }

    public static function addHooks()
    {
        global $plugins;

        $plugins->add_hook('datahandler_user_insert', ['MyAlerts', 'dataHandlerUserInsert']);
        $plugins->add_hook('reputation_do_add_process', ['MyAlerts', 'reputationDoAddProcess']);
        $plugins->add_hook('datahandler_pm_insert_end', ['MyAlerts', 'dataHandlerPmInsertEnd']);
    }

    public static function dataHandlerUserInsert(UserDataHandler &$dataHandler)
    {
        $dataHandler->user_insert_data['myalerts_disabled_alert_types'] = null;
    }

    public static function reputationDoAddProcess()
    {
        global $mybb, $reputation;

        if (!isset($mybb->user['uid']) || $mybb->user['uid'] < 1 || !static::isAlertTypeEnabled('rep')) {
            return;
        }

        static::addAlert($reputation['uid'], 'rep', $mybb->user['uid'], 'rep', 0);
    }

    public static function dataHandlerPmInsertEnd(PMDataHandler &$dataHandler)
    {
        if (!static::isAlertTypeEnabled('pm')) {
            return;
        }

        $index = 0;
        foreach ($dataHandler->data['recipients'] as $recipient) {
            static::addAlert(
                $recipient['uid'],
                'pm',
                $dataHandler->data['sender']['uid'],
                'pm',
                $dataHandler->pmid[$index],
                [
                    'subject' => $dataHandler->data['subject'],
                    'sender_name' => $dataHandler->data['sender']['username'],
                ],
                false
            );

            $index++;
        }
    }
}