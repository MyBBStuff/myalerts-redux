<?php

final class MyAlerts
{
    public static function install()
    {
        global $db;

        static::createAlertTypesTable($db);
        static::createAlertsTable($db);
        static::addDisabledAlertTypesToUsersTable($db);
        static::addDefaultAlertTypes($db);
    }

    public static function isInstalled()
    {
        global $db;

        return $db->table_exists('alert_types') && $db->table_exists('alerts');
    }

    public static function uninstall()
    {
        global $db;

        static::removeDisabledAlertTypesFromUsersTable($db);
        static::deleteAlertsTable($db);
        static::deleteAlertTypesTable($db);

        $pl = static::getPluginLibrary();

        $pl->settings_delete('myalerts', true);
        $pl->templates_delete('myalerts');
        $pl->stylesheet_delete('alerts.css');
        $db->delete_query('tasks', "file = 'myalerts'");
    }

    public static function activate()
    {
        global $lang;

        $lang->load('myalerts');

        $pl = static::getPluginLibrary();

        static::addSettings($pl, $lang);
        static::addTemplates($pl);
        static::addStylesheet($pl);
        static::editTemplates();
        static::addTask($lang);
    }

    public static function deactivate()
    {
        global $lang;

        $lang->load('myalerts');

        $pl = static::getPluginLibrary();

        static::disableTask();
        static::removeTemplateEdits();
        static::removeStylesheet($pl);
    }

    public static function addHooks()
    {
        global $plugins;

        $plugins->add_hook('admin_user_users_delete_commit', ['MyAlerts', 'usersDeleteCommit']);
    }

    /**
     * @return PluginLibrary
     */
    private static function getPluginLibrary()
    {
        global $PL, $lang;

        if (!isset($PL)) {
            $path = __DIR__ . '/../../pluginlibrary.php';

            if (!file_exists($path)) {
                flash_message($lang->myalerts_pluginlibrary_missing, 'error');
                admin_redirect('index.php?module=config-plugins');
            }

            require_once $path;
        }

        if ($PL->version < 9) {
            flash_message($lang->myalerts_pluginlibrary_too_old, 'error');
            admin_redirect('index.php?module=config-plugins');
        }

        return $PL;
    }

    private static function createAlertTypesTable(DB_Base $db)
    {
        if (!$db->table_exists('alert_types')) {
            $prefix = TABLE_PREFIX;

            switch ($db->type) {
                case 'pgsql':
                    $query = <<<SQL
CREATE TABLE {$prefix}alert_types (
	id SERIAL,
	code VARCHAR(50) NOT NULL,
	enabled BOOLEAN NOT NULL DEFAULT TRUE,
	PRIMARY KEY (id),
	CONSTRAINT code_unique UNIQUE(code)
);
SQL;
                    break;
                case 'sqlite':
                    $query = <<<SQL
CREATE TABLE {$prefix}alert_types (
  id INTEGER NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  enabled BOOLEAN NOT NULL CHECK (enabled IN (0,1)),
  PRIMARY KEY (id)
);
SQL;
                    break;
                default:
                    $query = <<<SQL
CREATE TABLE {$prefix}alert_types (
  id SERIAL,
  code VARCHAR(50) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (id),
  CONSTRAINT code_unique UNIQUE(code)
);
SQL;
                    break;
            }

            $db->write_query($query);
        }
    }

    private static function deleteAlertTypesTable(DB_Base $db)
    {
        if ($db->table_exists('alert_types')) {
            $db->drop_table('alert_types');
        }
    }

    private static function createAlertsTable(DB_Base $db)
    {
        if (!$db->table_exists('alerts')) {
            $prefix = TABLE_PREFIX;

            switch ($db->type) {
                case 'pgsql':
                    $query = <<<SQL
CREATE TABLE {$prefix}alerts (
  id SERIAL,
  uid INT NOT NULL,
  alert_type_id INT NOT NULL,
  from_uid INT NOT NULL,
  object_type VARCHAR(50) NOT NULL,
  object_id INT NOT NULL,
  forced BOOLEAN NOT NULL DEFAULT FALSE,
  extra_details JSONB NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  read_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id)
);
SQL;
                    break;
                case 'sqlite':
                    $query = <<<SQL
CREATE TABLE {$prefix}alerts (
  id INTEGER NOT NULL,
  uid INTEGER NOT NULL,
  alert_type_id INTEGER NOT NULL,
  from_uid INTEGER NULL,
  object_type VARCHAR(50) NOT NULL,
  object_id INTEGER NOT NULL,
  forced BOOLEAN NOT NULL CHECK (forced IN (0,1)),
  extra_details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id)
);
SQL;
                    break;
                default:
                    $query = <<<SQL
CREATE TABLE {$prefix}alerts (
  id SERIAL,
  uid INT UNSIGNED NOT NULL,
  alert_type_id BIGINT UNSIGNED NOT NULL,
  from_uid INT UNSIGNED NULL,
  object_type VARCHAR(50) NOT NULL,
  object_id INT UNSIGNED NOT NULL,
  forced TINYINT(1) NOT NULL DEFAULT '0',
  extra_details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id)
);
SQL;
                    break;
            }

            $db->write_query($query);
        }
    }

    private static function deleteAlertsTable(DB_Base $db)
    {
        if ($db->table_exists('alerts')) {
            $db->drop_table('alerts');
        }
    }

    private static function addDisabledAlertTypesToUsersTable(DB_Base $db)
    {
        if (!$db->field_exists('myalerts_disabled_alert_types', 'users')) {
            $db->add_column(
                'users',
                'myalerts_disabled_alert_types',
                'TEXT'
            );
        }
    }

    private static function removeDisabledAlertTypesFromUsersTable(DB_Base $db)
    {
        if ($db->field_exists('myalerts_disabled_alert_types', 'users')) {
            $db->drop_column('users', 'myalerts_disabled_alert_types');
        }
    }

    private static function addDefaultAlertTypes(DB_Base $db)
    {
        $prefix = TABLE_PREFIX;

        switch ($db->type) {
            case 'pgsql':
                $query = <<<SQL
INSERT INTO {$prefix}alert_types (code, enabled) VALUES
  ('rep', TRUE),
  ('pm', TRUE),
  ('buddylist', TRUE),
  ('quoted', TRUE),
  ('post_threadauthor', TRUE),
  ('subscribed_thread', TRUE),
  ('rated_threadauthor', TRUE),
  ('voted_threadauthor', TRUE)
ON CONFLICT DO NOTHING;
SQL;
                break;
            case 'sqlite':
                $query = <<<SQL
INSERT OR IGNORE INTO {$prefix}alert_types (code, enabled) VALUES
  ('rep', 1),
  ('pm', 1),
  ('buddylist', 1),
  ('quoted', 1),
  ('post_threadauthor', 1),
  ('subscribed_thread', 1),
  ('rated_threadauthor', 1),
  ('voted_threadauthor', 1);
SQL;
                break;
            default:
                $query = <<<SQL
INSERT INTO {$prefix}alert_types (code, enabled) VALUES
  ('rep', 1),
  ('pm', 1),
  ('buddylist', 1),
  ('quoted', 1),
  ('post_threadauthor', 1),
  ('subscribed_thread', 1),
  ('rated_threadauthor', 1),
  ('voted_threadauthor', 1)
ON DUPLICATE KEY UPDATE code = code;
SQL;

                break;
        }

        $db->write_query($query);
    }

    private static function addSettings(PluginLibrary $pl, MyLanguage $lang)
    {
        $pl->settings(
            'myalerts',
            $lang->setting_group_myalerts,
            $lang->setting_group_myalerts_desc,
            [
                'perpage'        => [
                    'title'       => $lang->setting_myalerts_perpage,
                    'description' => $lang->setting_myalerts_perpage_desc,
                    'value'       => '10',
                    'optionscode' => 'text',
                ],
                'dropdown_limit' => [
                    'title'       => $lang->setting_myalerts_dropdown_limit,
                    'description' => $lang->setting_myalerts_dropdown_limit_desc,
                    'value'       => '5',
                    'optionscode' => 'text',
                ],
                'autorefresh'    => [
                    'title'       => $lang->setting_myalerts_autorefresh,
                    'description' => $lang->setting_myalerts_autorefresh_desc,
                    'value'       => '0',
                    'optionscode' => 'text',
                ],
                'avatar_size'    => [
                    'title'       => $lang->setting_myalerts_avatar_size,
                    'description' => $lang->setting_myalerts_avatar_size_desc,
                    'value'       => '64|64',
                    'optionscode' => 'text',
                ],
            ]
        );
    }

    private static function addTemplates(PluginLibrary $pl)
    {
        $dir = new DirectoryIterator(__DIR__ . '/../resources/templates');
        $templates = [];
        foreach ($dir as $file) {
            if (!$file->isDot() && !$file->isDir() && pathinfo(
                    $file->getPathname(),
                    PATHINFO_EXTENSION
                ) === 'html'
            ) {
                $templateName = $file->getPathname();
                $templateName = basename($templateName, '.html');
                $templates[$templateName] = file_get_contents($file->getPathname());
            }
        }

        $pl->templates(
            'myalerts',
            'MyAlerts',
            $templates
        );
    }

    private static function addStylesheet(PluginLibrary $pl)
    {
        global $db;

        $stylesheet = file_get_contents(
            __DIR__ . '/../resources/stylesheets/alerts.css'
        );

        $pl->stylesheet('alerts.css', $stylesheet);

        // Attach usercp.css to alerts.php
        $query = $db->simple_select(
            'themestylesheets',
            'sid,attachedto,tid',
            "name = 'usercp.css'"
        );
        while ($userCpStylesheet = $db->fetch_array($query)) {
            $sid = (int) $userCpStylesheet['sid'];

            $db->update_query(
                'themestylesheets',
                [
                    'attachedto' => $db->escape_string(
                        $userCpStylesheet['attachedto'] . '|alerts.php'
                    ),
                ],
                "sid = {$sid}"
            );

            update_theme_stylesheet_list((int) $userCpStylesheet['tid']);
        }
    }

    private static function removeStylesheet(PluginLibrary $pl)
    {
        global $db;

        $pl->stylesheet_deactivate('alerts.css');

        // remove usercp.css from alerts.php
        $query = $db->simple_select(
            'themestylesheets',
            'sid,attachedto,tid',
            "name = 'usercp.css'"
        );

        while ($userCpStylesheet = $db->fetch_array($query)) {
            $sid = (int) $userCpStylesheet['sid'];

            $attachedTo = str_replace(
                '|alerts.php',
                '',
                $userCpStylesheet['attachedto']
            );

            $db->update_query(
                'themestylesheets',
                [
                    'attachedto' => $db->escape_string($attachedTo),
                ],
                "sid = {$sid}"
            );

            update_theme_stylesheet_list((int) $userCpStylesheet['tid']);
        }
    }

    private static function editTemplates()
    {
        require_once MYBB_ROOT . '/inc/adminfunctions_templates.php';

        find_replace_templatesets('headerinclude', '/$/', '{$myalerts_js}');

        find_replace_templatesets(
            'header_welcomeblock_member',
            "#" . preg_quote('{$modcplink}') . "#i",
            '{$myalerts_headericon}{$modcplink}'
        );

        find_replace_templatesets(
            'footer',
            '/$/',
            '{$myalerts_modal}'
        );
    }

    private static function addTask(MyLanguage $lang)
    {
        global $db;

        $query = $db->simple_select(
            'tasks',
            '*',
            'file = \'myalerts\'',
            ['limit' => '1']
        );

        if ($db->num_rows($query) == 0) {
            require_once MYBB_ROOT . '/inc/functions_task.php';

            $myTask = array(
                'title'       => $lang->myalerts_task_title,
                'file'        => 'myalerts',
                'description' => $lang->myalerts_task_description,
                'minute'      => 0,
                'hour'        => 1,
                'day'         => '*',
                'weekday'     => 1,
                'month'       => '*',
                'nextrun'     => TIME_NOW + 3600,
                'lastrun'     => 0,
                'enabled'     => 1,
                'logging'     => 1,
                'locked'      => 0,
            );

            $taskId = (int) $db->insert_query('tasks', $myTask);
            $theTask = $db->fetch_array(
                $db->simple_select('tasks', '*', 'tid = ' . $taskId)
            );
        } else {
            $theTask = $db->fetch_array($query);
            $taskId = $theTask['tid'];
        }

        global $plugins, $cache;

        $nextrun = fetch_next_run($theTask);
        $db->update_query(
            'tasks',
            [
                'nextrun' => $nextrun,
            ],
            'tid = ' . (int) $taskId
        );
        $plugins->run_hooks('admin_tools_tasks_add_commit');
        $cache->update_tasks();
    }

    private static function disableTask()
    {
        global $db;

        $db->update_query('tasks', ['enabled' => 0], "file = 'myalerts'");
    }

    private static function removeTemplateEdits()
    {
        require_once MYBB_ROOT . '/inc/adminfunctions_templates.php';

        find_replace_templatesets(
            'headerinclude',
            "#" . preg_quote('{$myalerts_js}') . "#i",
            ''
        );

        find_replace_templatesets(
            'header_welcomeblock_member',
            "#" . preg_quote('{$myalerts_headericon}') . "#i",
            ''
        );

        find_replace_templatesets(
            'footer',
            "#" . preg_quote('{$myalerts_modal}') . "#i",
            ''
        );
    }

    public static function usersDeleteCommit()
    {
        global $db, $user;

        $user['uid'] = (int) $user['uid'];
        $db->delete_query('alerts', "uid='{$user['uid']}'");
    }
}