<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade steps for report_courseradar.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create report_courseradar_conex if it is missing.
 *
 * Several savepoints call this because some ZIPs stored a version stamp
 * without applying the corresponding table creation.
 *
 * @param database_manager $dbman
 * @return void
 */
function report_courseradar_upgrade_ensure_conex_table($dbman): void {
    $table = new xmldb_table('report_courseradar_conex');
    if ($dbman->table_exists($table)) {
        return;
    }

    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timeasked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timefetched', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('livelabel', XMLDB_TYPE_CHAR, '50');
    $table->add_field('liveseconds', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('delayedlabel', XMLDB_TYPE_CHAR, '50');
    $table->add_field('delayedseconds', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('courseuser', XMLDB_INDEX_UNIQUE, ['courseid', 'userid']);
    $table->add_index('timefetched', XMLDB_INDEX_NOTUNIQUE, ['timefetched']);
    $table->add_index('timeasked', XMLDB_INDEX_NOTUNIQUE, ['timeasked']);

    $dbman->create_table($table);
}

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_report_courseradar_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026090200) {
        report_courseradar_upgrade_ensure_conex_table($dbman);
        upgrade_plugin_savepoint(true, 2026090200, 'report', 'courseradar');
    }

    if ($oldversion < 2026090201) {
        $table = new xmldb_table('report_courseradar_conex');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('livelabel', XMLDB_TYPE_CHAR, '50');
            $dbman->change_field_notnull($table, $field);
            $field = new xmldb_field('delayedlabel', XMLDB_TYPE_CHAR, '50');
            $dbman->change_field_notnull($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026090201, 'report', 'courseradar');
    }

    if ($oldversion < 2026090203) {
        upgrade_plugin_savepoint(true, 2026090203, 'report', 'courseradar');
    }

    if ($oldversion < 2026090204) {
        report_courseradar_upgrade_ensure_conex_table($dbman);
        upgrade_plugin_savepoint(true, 2026090204, 'report', 'courseradar');
    }

    // 1.9.4 ZIP already stored version 2026090204 without creating the table.
    if ($oldversion < 2026090206) {
        report_courseradar_upgrade_ensure_conex_table($dbman);
        upgrade_plugin_savepoint(true, 2026090206, 'report', 'courseradar');
    }

    if ($oldversion < 2026090207) {
        $table = new xmldb_table('report_courseradar_conex');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('liverows', XMLDB_TYPE_TEXT, null, null, null, null, null, 'delayedseconds');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $field = new xmldb_field('delayedrows', XMLDB_TYPE_TEXT, null, null, null, null, null, 'liverows');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026090207, 'report', 'courseradar');
    }

    return true;
}
