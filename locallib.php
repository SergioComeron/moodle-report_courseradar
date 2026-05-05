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
 * Internal library functions for report_courseradar.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Returns the Bootstrap progress bar colour class based on a percentage.
 *
 * @param int $pct Percentage (0-100).
 * @return string Bootstrap background class.
 */
function report_courseradar_barclass(int $pct): string {
    if ($pct >= 70) {
        return 'bg-success';
    }
    if ($pct >= 30) {
        return 'bg-warning';
    }
    return 'bg-danger';
}

/**
 * Separates enrolled users into students and non-students (teachers/managers).
 *
 * Users that hold the report/courseradar:view capability are considered
 * non-students and are excluded from interaction tracking.
 *
 * @param \context_course $context Course context.
 * @return array Associative array [userid => stdClass] sorted by lastname, firstname.
 */
function report_courseradar_get_students(\context_course $context): array {
    $allenrolled = get_enrolled_users(
        $context,
        '',
        0,
        'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,' .
        ' u.middlename, u.alternatename, u.picture, u.imagealt, u.email'
    );
    $canviewids = array_keys(
        get_enrolled_users($context, 'report/courseradar:view', 0, 'u.id')
    );

    $students = [];
    foreach ($allenrolled as $u) {
        if (!in_array($u->id, $canviewids)) {
            $students[$u->id] = $u;
        }
    }
    uasort($students, function ($a, $b) {
        return strcmp($a->lastname . $a->firstname, $b->lastname . $b->firstname);
    });
    return $students;
}

/**
 * Returns the at-risk students for a course in the given period.
 *
 * @param array $students   Array of student objects [userid => stdClass].
 * @param array $studentlog Per-student log data [userid][cmid] => views.
 * @param int   $totalmodules Total number of course modules.
 * @return array ['none' => [...], 'low' => [...]] keyed by risk level.
 */
function report_courseradar_atrisk(array $students, array $studentlog, int $totalmodules): array {
    $result = ['none' => [], 'low' => []];
    foreach ($students as $uid => $stu) {
        $visited = isset($studentlog[$uid]) ? count($studentlog[$uid]) : 0;
        if ($visited === 0) {
            $result['none'][$uid] = $stu;
        } else if ($totalmodules > 0 && ($visited / $totalmodules) < 0.30) {
            $result['low'][$uid] = $stu;
        }
    }
    return $result;
}
