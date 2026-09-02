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
 * JSON endpoint: Zoom/Vimeo connection report for one student.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(dirname($_SERVER['SCRIPT_FILENAME'], 3) . '/config.php');
require_once($CFG->dirroot . '/report/courseradar/locallib.php');

$courseid = required_param('id', PARAM_INT);
$userid   = required_param('userid', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_sesskey();

$canview = has_capability('report/courseradar:view', $context);
if (!$canview) {
    if ($userid !== (int)$USER->id || !is_enrolled($context, $USER, '', true)) {
        throw new required_capability_exception($context, 'report/courseradar:view', 'nopermissions', '');
    }
} else {
    $students = report_courseradar_get_students($context);
    if (!isset($students[$userid])) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('student', 'report_courseradar'));
    }
}

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
$refresh = optional_param('refresh', 0, PARAM_BOOL);

header('Content-Type: application/json; charset=utf-8');

$store = \report_courseradar\conexiones_store::class;
$row   = $store::get($courseid, $userid);
if (!$refresh && $store::is_fresh($row)) {
    echo json_encode($store::export($row));
    exit;
}

echo json_encode($store::refresh_user($course, $user));
