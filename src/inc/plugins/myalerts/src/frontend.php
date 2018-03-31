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

        $plugins->add_hook('reputation_do_add_process', ['MyAlerts', 'reputationAlert']);
        $plugins->add_hook('reputation_end', ['MyAlerts', 'readReputationAlert']);
        $plugins->add_hook('datahandler_pm_insert_end', ['MyAlerts', 'pmAlert']);
        $plugins->add_hook('private_read_end', ['MyAlerts', 'readPmAlert']);
        $plugins->add_hook('usercp_do_editlists_end', ['MyAlerts', 'buddyListAlert']);
        $plugins->add_hook('usercp_cancelrequest_start', ['MyAlerts', 'deleteBuddyListAlert']);
    }

    public static function reputationAlert()
    {
        global $mybb, $reputation;

        if (!isset($mybb->user['uid']) || $mybb->user['uid'] < 1 || !static::isAlertTypeEnabled('rep')) {
            return;
        }

        static::addAlert($reputation['uid'], 'rep', $mybb->user['uid'], 'rep', 0);
    }

    public static function readReputationAlert()
    {
        global $mybb, $db;

        $uid = $mybb->get_input('uid', MyBB::INPUT_INT);

        if ($uid != $mybb->user['uid']) {
            return;
        }

        $prefix = TABLE_PREFIX;

        $query = <<<SQL
UPDATE {$prefix}alerts SET read_at = CURRENT_TIMESTAMP() WHERE object_type = 'rep' AND uid = {$uid} AND read_at IS NULL;
SQL;

        $db->write_query($query);
    }

    public static function pmAlert(PMDataHandler &$dataHandler)
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

    public static function readPmAlert()
    {
        global $mybb, $db;

        $id = $mybb->get_input('pmid', MyBB::INPUT_INT);

        $prefix = TABLE_PREFIX;
        $uid = (int) $mybb->user['uid'];

        $query = <<<SQL
UPDATE {$prefix}alerts SET read_at = CURRENT_TIMESTAMP() WHERE object_type = 'pm' AND object_id = {$id} AND uid = {$uid} AND read_at IS NULL;
SQL;

        $db->write_query($query);
    }

    public static function buddyListAlert()
    {
        if (!static::isAlertTypeEnabled('buddylist')) {
            return;
        }

        global $mybb, $db;

        if ($mybb->get_input('manage') == 'ignored') {
            // we only care about buddy list modifications, not ignore list modifications
            return;
        }

        $existingUsers = explode(',', $mybb->user['buddylist']);
        $existingUsers = array_map('intval', $existingUsers);

        if ($mybb->get_input('delete', MyBB::INPUT_INT)) {
            // delete any existing unread alerts for adding this user to the buddylist
            $toDelete = $mybb->get_input('delete', MyBB::INPUT_INT);

            if (!in_array($toDelete, $existingUsers)) {
                return;
            }

            $buddyAlertTypeId = static::getAlertTypeIdFromCode('buddylist');

            if ($buddyAlertTypeId === -1) {
                return;
            }

            $fromUid = (int) $mybb->user['uid'];

            $db->delete_query('alerts',
                "alert_type_id = {$buddyAlertTypeId} AND uid = {$toDelete} AND from_uid = {$fromUid} AND read_at IS NULL"
            );
        } else {
            $users = explode(',', $mybb->get_input('add_username'));
            $users = array_map('trim', $users);
            $users = array_unique($users);

            foreach ($users as $key => $username) {
                if (my_strtoupper($mybb->user['username']) == my_strtoupper($username)) {
                    // was trying to add self
                    unset($users[$key]);
                } else {
                    $users[$key] = $db->escape_string($username);
                }
            }

            switch ($db->type) {
                case 'mysql':
                case 'mysqli':
                    $field = 'username';
                    break;
                default:
                    $field = 'LOWER(username)';
                    break;
            }

            $query = $db->simple_select('users', 'uid', "{$field} IN ('".my_strtolower(implode("','", $users))."')");
            while ($row = $db->fetch_array($query)) {
                $uid = (int) $row['uid'];

                if (!in_array($uid, $existingUsers)) {
                    static::addAlert($row['uid'], 'buddylist', $mybb->user['uid'], 'buddy', $row['uid']);
                }
            }
        }
    }

    public static function deleteBuddyListAlert()
    {
        if (!static::isAlertTypeEnabled('buddylist')) {
            return;
        }

        global $mybb, $db;

        $query = $db->simple_select('buddyrequests', '*', 'id='.$mybb->get_input('id', MyBB::INPUT_INT).' AND uid='.(int)$mybb->user['uid']);
        $request = $db->fetch_array($query);

        if (empty($request)) {
            return;
        }

        $buddyAlertTypeId = static::getAlertTypeIdFromCode('buddylist');

        if ($buddyAlertTypeId === -1) {
            return;
        }

        $request['touid'] = (int) $request['touid'];
        $request['uid'] = (int) $request['uid'];

        $db->delete_query('alerts',
            "alert_type_id = {$buddyAlertTypeId} AND uid = {$request['touid']} AND from_uid = {$request['uid']} AND read_at IS NULL"
        );
    }
}