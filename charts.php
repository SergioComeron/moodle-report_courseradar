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
 * JSON: heatmap, activity chart and sparklines (deferred from the main report).
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(dirname($_SERVER['SCRIPT_FILENAME'], 3) . '/config.php');
require_once($CFG->dirroot . '/report/courseradar/locallib.php');

$courseid    = required_param('id', PARAM_INT);
$datefromstr = optional_param('datefrom', '', PARAM_RAW);
$datetostr   = optional_param('dateto', '', PARAM_RAW);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('report/courseradar:view', $context);
require_sesskey();

$defaultfrom = $course->startdate ?: mktime(0, 0, 0, 1, 1, (int)date('Y'));
$datefrom = $datefromstr ? strtotime($datefromstr) : $defaultfrom;
$dateto   = $datetostr ? strtotime($datetostr . ' 23:59:59') : time();
if (!$datefrom || $datefrom < 0) {
    $datefrom = $defaultfrom;
}
if (!$dateto || $dateto < $datefrom) {
    $dateto = time();
}

$students   = report_courseradar_get_students($context);
$studentids = array_keys($students);
$charts     = report_courseradar_activity_charts(
    $courseid,
    $studentids,
    $students,
    $datefrom,
    $dateto
);
$series     = report_courseradar_chart_series($charts['byday'], $datefrom, $dateto);
$sparklines = report_courseradar_sparkline_bars(
    $charts['weekdata'],
    $datefrom,
    $dateto,
    $studentids
);

$heatmax = 1;
foreach ($charts['heatmap'] as $drow) {
    foreach ($drow as $val) {
        if ($val > $heatmax) {
            $heatmax = $val;
        }
    }
}

$daykeymap = [
    0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
    4 => 'thursday', 5 => 'friday', 6 => 'saturday',
];
$daynames = [];
foreach ($daykeymap as $dow => $key) {
    $daynames[$dow] = mb_substr(get_string($key, 'calendar'), 0, 3);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'chart'        => $series,
    'heatmap'      => $charts['heatmap'],
    'heatstudents' => $charts['heatstudents'],
    'heatmax'      => $heatmax,
    'daynames'     => $daynames,
    'dayorder'     => [1, 2, 3, 4, 5, 6, 0],
    'timeslots'    => ['0–3h', '4–7h', '8–11h', '12–15h', '16–19h', '20–23h'],
    'sparklines'   => $sparklines,
    'timeslabel'   => get_string('times', 'report_courseradar'),
    'weeklylabel'  => get_string('weeklyaggregated', 'report_courseradar'),
    'weekvslabel'  => get_string('weekvspreview', 'report_courseradar'),
]);
