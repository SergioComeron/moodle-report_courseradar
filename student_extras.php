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
 * JSON extras for student self-view: class averages and activity chart.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(dirname($_SERVER['SCRIPT_FILENAME'], 3) . '/config.php');
require_once($CFG->dirroot . '/report/courseradar/locallib.php');

$courseid = required_param('id', PARAM_INT);
$course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context  = context_course::instance($courseid);

require_login($course);
require_sesskey();

$canview = has_capability('report/courseradar:view', $context);
$isstudent = !$canview && (is_enrolled($context, $USER, '', true) || is_role_switched($courseid));
if (!$canview && !$isstudent) {
    require_capability('report/courseradar:view', $context);
}

$myid      = (int)$USER->id;
$display   = report_courseradar_student_display();
$students  = report_courseradar_get_students($context);
$studentids = array_keys($students);
$totalstudents = count($students);

$modinfo  = get_fast_modinfo($course);
$validcms = [];
foreach ($modinfo->get_cms() as $cm) {
    if (!$cm->deletioninprogress && $cm->modname !== 'label' && $cm->modname !== 'subsection') {
        $validcms[$cm->id] = $cm;
    }
}
$totalmodules = count($validcms);

$out = [
    'classscore'         => 0,
    'classcoverage'      => 0,
    'classcompletion'    => 0,
    'classdedication'    => report_courseradar_format_dedication(0),
    'classdedicationpct' => 0,
    'chartdata'          => null,
];

if ($display['studentshowcomparison'] && $totalstudents > 0 && $totalmodules > 0) {
    $studentlog   = [];
    $lastaccessby = [];
    [$insql, $inparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'st');
    $rs = $DB->get_recordset_sql(
        "SELECT userid,
                COUNT(DISTINCT contextinstanceid) AS visited,
                MAX(timecreated) AS lastaccess
           FROM {logstore_standard_log}
          WHERE courseid     = :courseid
            AND action       = :action
            AND contextlevel = :contextlevel
            AND userid {$insql}
       GROUP BY userid",
        array_merge([
            'courseid'     => $courseid,
            'action'       => 'viewed',
            'contextlevel' => CONTEXT_MODULE,
        ], $inparams)
    );
    foreach ($rs as $row) {
        $uid = (int)$row->userid;
        $n   = (int)$row->visited;
        $studentlog[$uid] = $n > 0 ? array_fill(1, $n, 1) : [];
        $lastaccessby[$uid] = (int)$row->lastaccess;
    }
    $rs->close();

    $daysinactive = [];
    foreach ($students as $uid => $stu) {
        $daysinactive[$uid] = report_courseradar_days_inactive($lastaccessby[$uid] ?? 0);
    }

    $hasanycompletion = false;
    $totaltracked     = 0;
    $completedbystu   = [];
    if (!empty($course->enablecompletion)) {
        foreach ($validcms as $cm) {
            if ($cm->completion > 0) {
                $hasanycompletion = true;
                $totaltracked++;
            }
        }
    }
    if ($hasanycompletion) {
        [$cminsql, $cminp] = $DB->get_in_or_equal(array_keys($validcms), SQL_PARAMS_NAMED, 'cm');
        [$stcinsql, $stcinp] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'stc');
        $rs = $DB->get_recordset_sql(
            "SELECT coursemoduleid AS cmid, userid, completionstate
               FROM {course_modules_completion}
              WHERE coursemoduleid {$cminsql} AND userid {$stcinsql} AND completionstate > 0",
            array_merge($cminp, $stcinp)
        );
        foreach ($rs as $row) {
            if (isset($validcms[$row->cmid]) && $validcms[$row->cmid]->completion > 0) {
                $completedbystu[$row->userid] = ($completedbystu[$row->userid] ?? 0) + 1;
            }
        }
        $rs->close();
    }

    $scores = report_courseradar_engagement_scores(
        $students,
        $studentlog,
        $daysinactive,
        $totalmodules,
        $hasanycompletion,
        $totaltracked,
        $completedbystu
    );
    $out['classscore'] = $scores ? (int)round(array_sum($scores) / count($scores)) : 0;
    $covsum = 0.0;
    $compsum = 0.0;
    foreach ($students as $uid => $stu) {
        $covsum += (count($studentlog[$uid] ?? []) / $totalmodules) * 100;
        if ($hasanycompletion && $totaltracked > 0) {
            $compsum += (($completedbystu[$uid] ?? 0) / $totaltracked) * 100;
        }
    }
    $out['classcoverage']   = (int)round($covsum / $totalstudents);
    $out['classcompletion'] = $totalstudents > 0 ? (int)round($compsum / $totalstudents) : 0;

    if ($display['studentshowdedication']) {
        $dedication = report_courseradar_dedication($courseid, $studentids);
        $avg = report_courseradar_dedication_average($dedication);
        $mine = $dedication[$myid] ?? 0;
        $dedmax = max($mine, $avg, 1);
        $out['classdedication']    = report_courseradar_format_dedication($avg);
        $out['classdedicationpct'] = (int)round(($avg / $dedmax) * 100);
        $out['dedicationpct']      = (int)round(($mine / $dedmax) * 100);
    }
}

if ($display['studentshowchart']) {
    $myday = [];
    $rs = $DB->get_recordset_sql(
        "SELECT (timecreated / 86400) * 86400 AS dayts, COUNT(*) AS cnt
           FROM {logstore_standard_log}
          WHERE courseid     = :courseid
            AND action       = :action
            AND contextlevel = :contextlevel
            AND userid       = :myid
          GROUP BY timecreated / 86400
          ORDER BY dayts",
        [
            'courseid'     => $courseid,
            'action'       => 'viewed',
            'contextlevel' => CONTEXT_MODULE,
            'myid'         => $myid,
        ]
    );
    foreach ($rs as $row) {
        $myday[date('Y-m-d', (int)$row->dayts)] = (int)$row->cnt;
    }
    $rs->close();
    if (!empty($myday)) {
        $chfrom = $course->startdate ?: strtotime((string)min(array_keys($myday)));
        $out['chartdata'] = report_courseradar_chart_series($myday, $chfrom, time());
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out);
