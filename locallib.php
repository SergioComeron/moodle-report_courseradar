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

/**
 * Returns the number of whole days elapsed since a Unix timestamp.
 *
 * @param int $lastaccess Unix timestamp of the last access; 0 means never.
 * @return int Days since last access, or -1 if never accessed.
 */
function report_courseradar_days_inactive(int $lastaccess): int {
    if ($lastaccess <= 0) {
        return -1;
    }
    return (int)floor((time() - $lastaccess) / DAYSECS);
}

/**
 * Returns the Bootstrap badge class for a given number of inactive days.
 *
 * @param int $days Days inactive; -1 means never accessed.
 * @return string CSS classes for the badge.
 */
function report_courseradar_inactive_class(int $days): string {
    if ($days < 0) {
        return 'bg-danger text-white';
    }
    if ($days <= 7) {
        return 'bg-success text-white';
    }
    if ($days <= 14) {
        return 'bg-warning text-dark';
    }
    return 'bg-danger text-white';
}

/**
 * Returns visible course modules sorted by student coverage ascending (least viewed first).
 *
 * Modules with 100% coverage are excluded since they need no attention.
 *
 * @param array $validcms      Course modules [cmid => cm_info].
 * @param array $logdata       Aggregate log data [cmid => stdClass{uniqueusers,...}].
 * @param int   $totalstudents Total number of enrolled students.
 * @param int   $limit         Maximum number of results to return.
 * @return array Sorted array of ['cm', 'unique', 'unseen', 'pct'] entries.
 */
/**
 * Computes the composite engagement score (0–100) for each student.
 *
 * The score combines % of resources visited, recency of last access and,
 * when completion tracking is active, % of tracked activities completed.
 *
 * @param array $students       [userid => stdClass]
 * @param array $studentlog     [userid][cmid] => views
 * @param array $daysinactive   [userid] => days since last activity (-1 = never)
 * @param int   $totalmodules   Total course modules
 * @param bool  $hasanycompletion Whether completion tracking is enabled
 * @param int   $totaltracked   Total tracked activities (0 if no completion)
 * @param array $completedbystu [userid] => count of completed activities
 * @return array [userid => score (int 0–100)]
 */
function report_courseradar_engagement_scores(
    array $students,
    array $studentlog,
    array $daysinactive,
    int $totalmodules,
    bool $hasanycompletion,
    int $totaltracked,
    array $completedbystu
): array {
    $scores = [];
    foreach ($students as $uid => $stu) {
        $visited  = count($studentlog[$uid] ?? []);
        $visitpct = ($totalmodules > 0) ? ($visited / $totalmodules) * 100 : 0;
        $days     = $daysinactive[$uid] ?? -1;
        $recpct   = ($days < 0) ? 0.0 : max(0.0, 100.0 - ($days * 100.0 / 30.0));
        if ($hasanycompletion && $totaltracked > 0) {
            $complpct   = (($completedbystu[$uid] ?? 0) / $totaltracked) * 100;
            $scores[$uid] = min(100, (int)round($visitpct * 0.35 + $recpct * 0.35 + $complpct * 0.30));
        } else {
            $scores[$uid] = min(100, (int)round($visitpct * 0.50 + $recpct * 0.50));
        }
    }
    return $scores;
}

/**
 * Counts students per 20-point engagement score band.
 *
 * @param array $riskscores [userid => score (0–100)]
 * @return array [0 => count, 20 => count, 40 => count, 60 => count, 80 => count]
 */
function report_courseradar_score_bands(array $riskscores): array {
    $bands = [0 => 0, 20 => 0, 40 => 0, 60 => 0, 80 => 0];
    foreach ($riskscores as $score) {
        $band = min(80, (int)(floor($score / 20) * 20));
        $bands[$band]++;
    }
    return $bands;
}

/**
 * Builds the scatter plot dataset for the student comparison chart.
 *
 * Each entry contains the % of resources visited (x), the engagement score (y),
 * the student display name, and a link to their course profile.
 *
 * @param array $students    [userid => stdClass]
 * @param array $studentlog  [userid][cmid] => views
 * @param array $riskscores  [userid => score]
 * @param int   $totalmodules
 * @param int   $courseid
 * @return array Array of ['x', 'y', 'name', 'url'] per student
 */
function report_courseradar_scatter_data(
    array $students,
    array $studentlog,
    array $riskscores,
    int $totalmodules,
    int $courseid
): array {
    $data = [];
    foreach ($students as $uid => $stu) {
        $visited = count($studentlog[$uid] ?? []);
        $vpct    = ($totalmodules > 0) ? round(($visited / $totalmodules) * 100) : 0;
        $data[]  = [
            'x'    => $vpct,
            'y'    => $riskscores[$uid] ?? 0,
            'name' => fullname($stu),
            'url'  => (new moodle_url('/user/view.php', ['id' => $uid, 'course' => $courseid]))->out(false),
        ];
    }
    return $data;
}

/**
 * Returns visible course modules sorted by student coverage ascending (least viewed first).
 *
 * Modules with 100% coverage are excluded since they need no attention.
 *
 * @param array $validcms      Course modules [cmid => cm_info].
 * @param array $logdata       Aggregate log data [cmid => stdClass{uniqueusers,...}].
 * @param int   $totalstudents Total number of enrolled students.
 * @param int   $limit         Maximum number of results to return.
 * @return array Sorted array of ['cm', 'unique', 'unseen', 'pct'] entries.
 */
function report_courseradar_top_unseen(
    array $validcms,
    array $logdata,
    int $totalstudents,
    int $limit = 10
): array {
    if ($totalstudents <= 0) {
        return [];
    }
    $items = [];
    foreach ($validcms as $cmid => $cm) {
        if (!$cm->visible) {
            continue;
        }
        $unique = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->uniqueusers : 0;
        $pct    = min(100, (int)round(($unique / $totalstudents) * 100));
        if ($pct >= 100) {
            continue;
        }
        $items[] = [
            'cm'     => $cm,
            'unique' => $unique,
            'unseen' => $totalstudents - $unique,
            'pct'    => $pct,
        ];
    }
    usort($items, function ($a, $b) {
        return $a['pct'] - $b['pct'];
    });
    return array_slice($items, 0, $limit);
}
