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
 * Compact loading spinner, optionally with a "Loading" label.
 *
 * @param bool $withtext Include the loadingdata string.
 * @return string HTML
 */
function report_courseradar_loading(bool $withtext = false): string {
    $spin = '<span class="spinner-border spinner-border-sm text-muted" role="status" aria-hidden="true"></span>';
    if (!$withtext) {
        return $spin;
    }
    return $spin . ' <span class="text-muted">' . get_string('loadingdata', 'report_courseradar') . '</span>';
}

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
        'u.id, u.username, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,' .
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
 * Whether time-spent data from block_dedication can be read.
 *
 * Requires the Catalyst flavour of block_dedication, which precalculates
 * sessions into its own table through a scheduled task. Older flavours of the
 * block compute dedication on the fly and expose no such table, so the feature
 * simply stays hidden.
 *
 * @return bool True when the block and its data table are present.
 */
function report_courseradar_dedication_available(): bool {
    global $DB;
    return class_exists('\block_dedication\lib\utils')
        && $DB->get_manager()->table_exists('block_dedication');
}

/**
 * Returns the time each student spent in the course, as recorded by block_dedication.
 *
 * Sessions are aggregated in a single query rather than calling
 * block_dedication\lib\utils::timespent() per student, which would issue one
 * query each and ignore the report date range.
 *
 * @param int   $courseid   Course id.
 * @param array $studentids Student user ids to report on.
 * @param int   $datefrom   Only count sessions started at or after this timestamp (0 = no limit).
 * @param int   $dateto     Only count sessions started at or before this timestamp (0 = no limit).
 * @return array [userid => seconds spent]; empty when the block is unavailable.
 */
function report_courseradar_dedication(int $courseid, array $studentids, int $datefrom = 0, int $dateto = 0): array {
    global $DB;
    if (empty($studentids) || !report_courseradar_dedication_available()) {
        return [];
    }
    [$insql, $params] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'ded');
    $params['courseid'] = $courseid;
    $where = "courseid = :courseid AND userid {$insql}";
    if ($datefrom > 0) {
        $where .= ' AND timestart >= :datefrom';
        $params['datefrom'] = $datefrom;
    }
    if ($dateto > 0) {
        $where .= ' AND timestart <= :dateto';
        $params['dateto'] = $dateto;
    }
    $rows = $DB->get_records_sql(
        "SELECT userid, SUM(timespent) AS secs
           FROM {block_dedication}
          WHERE {$where}
       GROUP BY userid",
        $params
    );
    $result = [];
    foreach ($rows as $row) {
        $result[(int)$row->userid] = (int)$row->secs;
    }
    return $result;
}

/**
 * Returns the average time spent, counting only students with recorded sessions.
 *
 * Matches the semantics of block_dedication\lib\utils::get_average(), which
 * divides by the number of distinct users present in the dedication table.
 *
 * @param array $dedication [userid => seconds spent].
 * @return int Average seconds per student with data, 0 when there is none.
 */
function report_courseradar_dedication_average(array $dedication): int {
    $withdata = array_filter($dedication, function ($secs) {
        return $secs > 0;
    });
    if (empty($withdata)) {
        return 0;
    }
    return (int)round(array_sum($withdata) / count($withdata));
}

/**
 * Formats a number of seconds as a compact time-spent string.
 *
 * @param int $totalsecs Seconds spent; 0 or less means no data.
 * @return string Compact representation, e.g. '3h 12m', '45m', '< 1m' or '—'.
 */
function report_courseradar_format_dedication(int $totalsecs): string {
    if ($totalsecs <= 0) {
        return '—';
    }
    $hours = (int)floor($totalsecs / HOURSECS);
    $mins  = (int)floor(($totalsecs - ($hours * HOURSECS)) / MINSECS);
    if ($hours > 0) {
        return $mins > 0 ? $hours . 'h ' . $mins . 'm' : $hours . 'h';
    }
    if ($mins > 0) {
        return $mins . 'm';
    }
    return '< 1m';
}

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

/**
 * Student-view section flags from plugin settings.
 *
 * Unsaved config is treated as enabled, matching the checkbox defaults.
 *
 * @return array<string,bool>
 */
function report_courseradar_student_display(): array {
    $keys = [
        'studentshowscore',
        'studentshowcoverage',
        'studentshowcompletion',
        'studentshowdedication',
        'studentshowdaysinactive',
        'studentshowcomparison',
        'studentshowpending',
        'studentshowchart',
        'studentshowconexiones',
    ];
    $out = [];
    foreach ($keys as $key) {
        $val = get_config('report_courseradar', $key);
        $out[$key] = ($val === false) ? true : (bool)(int)$val;
    }
    return $out;
}

/**
 * Deferred activity charts: daily totals, heatmap, heatmap students, weekly bars.
 *
 * @param int   $courseid
 * @param array $studentids
 * @param array $students   [userid => stdClass]
 * @param int   $datefrom
 * @param int   $dateto
 * @return array{byday: array, heatmap: array, heatstudents: array, weekdata: array}
 */
function report_courseradar_activity_charts(
    int $courseid,
    array $studentids,
    array $students,
    int $datefrom,
    int $dateto
): array {
    global $DB;

    $heatmap      = array_fill(0, 7, array_fill(0, 6, 0));
    $heatstudents = array_fill(0, 7, array_fill(0, 6, []));
    $byday        = [];
    $weekdata     = [];

    if (empty($studentids)) {
        return compact('byday', 'heatmap', 'heatstudents', 'weekdata');
    }

    [$insql, $inparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'st');
    $logparams = array_merge([
        'courseid'     => $courseid,
        'action'       => 'viewed',
        'contextlevel' => CONTEXT_MODULE,
        'datefrom'     => $datefrom,
        'dateto'       => $dateto,
    ], $inparams);

    $sql3 = "SELECT (timecreated / 86400) * 86400 AS dayts, COUNT(*) AS cnt
               FROM {logstore_standard_log}
              WHERE courseid     = :courseid
                AND action       = :action
                AND contextlevel = :contextlevel
                AND timecreated >= :datefrom
                AND timecreated <= :dateto
                AND userid {$insql}
              GROUP BY timecreated / 86400
              ORDER BY dayts";
    $rs = $DB->get_recordset_sql($sql3, $logparams);
    foreach ($rs as $row) {
        $byday[date('Y-m-d', (int)$row->dayts)] = (int)$row->cnt;
    }
    $rs->close();

    $sql4 = "SELECT MOD(timecreated / 86400 + 4, 7) AS dow,
                    timecreated % 86400 / 14400      AS timeblock,
                    COUNT(*)                         AS cnt
               FROM {logstore_standard_log}
              WHERE courseid     = :courseid
                AND action       = :action
                AND contextlevel = :contextlevel
                AND timecreated >= :datefrom
                AND timecreated <= :dateto
                AND userid {$insql}
              GROUP BY MOD(timecreated / 86400 + 4, 7),
                       timecreated % 86400 / 14400";
    $rs = $DB->get_recordset_sql($sql4, $logparams);
    foreach ($rs as $row) {
        $heatmap[(int)$row->dow][(int)$row->timeblock] = (int)$row->cnt;
    }
    $rs->close();

    $sql4b = "SELECT DISTINCT MOD(timecreated / 86400 + 4, 7) AS dow,
                              timecreated % 86400 / 14400      AS timeblock,
                              userid
                FROM {logstore_standard_log}
               WHERE courseid     = :courseid
                 AND action       = :action
                 AND contextlevel = :contextlevel
                 AND timecreated >= :datefrom
                 AND timecreated <= :dateto
                 AND userid {$insql}";
    $rs = $DB->get_recordset_sql($sql4b, $logparams);
    foreach ($rs as $row) {
        $uid = (int)$row->userid;
        if (isset($students[$uid])) {
            $heatstudents[(int)$row->dow][(int)$row->timeblock][] = [
                'id'   => $uid,
                'name' => fullname($students[$uid]),
            ];
        }
    }
    $rs->close();
    foreach ($heatstudents as $d => $blocks) {
        foreach ($blocks as $b => $entries) {
            usort($heatstudents[$d][$b], fn($a, $b) => strcmp($a['name'], $b['name']));
        }
    }

    $sql5 = "SELECT userid,
                    (timecreated / 604800) * 604800 AS weekts,
                    COUNT(*)                        AS cnt
               FROM {logstore_standard_log}
              WHERE courseid     = :courseid
                AND action       = :action
                AND contextlevel = :contextlevel
                AND timecreated >= :datefrom
                AND timecreated <= :dateto
                AND userid {$insql}
              GROUP BY userid, timecreated / 604800
              ORDER BY userid, weekts";
    $rs = $DB->get_recordset_sql($sql5, $logparams);
    foreach ($rs as $row) {
        $weekdata[$row->userid][(int)$row->weekts] = (int)$row->cnt;
    }
    $rs->close();

    return compact('byday', 'heatmap', 'heatstudents', 'weekdata');
}

/**
 * Sparkline bar specs per student.
 *
 * @param array $weekdata [userid][weekts] => count
 * @param int   $datefrom
 * @param int   $dateto
 * @param array $studentids
 * @return array [userid => list of {cnt, height, label}]
 */
function report_courseradar_sparkline_bars(
    array $weekdata,
    int $datefrom,
    int $dateto,
    array $studentids
): array {
    $weekslots = [];
    for ($w = (int)($datefrom / 604800) * 604800; $w <= $dateto; $w += 604800) {
        $weekslots[] = $w;
    }
    $sparklines = [];
    foreach ($studentids as $uid) {
        if (empty($weekdata[$uid])) {
            $sparklines[$uid] = [];
            continue;
        }
        $maxcnt = max($weekdata[$uid]);
        $bars = [];
        foreach ($weekslots as $w) {
            $cnt = $weekdata[$uid][$w] ?? 0;
            $bars[] = [
                'cnt'    => $cnt,
                'height' => $maxcnt > 0 ? max(3, (int)round(($cnt / $maxcnt) * 100)) : 3,
                'label'  => userdate($w, get_string('chartdateformat', 'report_courseradar')),
            ];
        }
        $sparklines[$uid] = $bars;
    }
    return $sparklines;
}

/**
 * Line-chart labels/values from daily counts.
 *
 * @param array $byday [Y-m-d => count]
 * @param int   $datefrom
 * @param int   $dateto
 * @return array{labels: array, values: array, weekly: bool}
 */
function report_courseradar_chart_series(array $byday, int $datefrom, int $dateto): array {
    $labels = [];
    $values = [];
    $weekly = false;
    if (empty($byday)) {
        return ['labels' => $labels, 'values' => $values, 'weekly' => $weekly];
    }
    $dayrange = ($dateto - $datefrom) / DAYSECS;
    if ($dayrange > 90) {
        $weekly = true;
        $byweek = [];
        foreach ($byday as $day => $cnt) {
            $ts     = strtotime($day);
            $isodow = (int)date('N', $ts);
            $monds  = $ts - ($isodow - 1) * 86400;
            $wk     = date('Y-m-d', $monds);
            $byweek[$wk] = ($byweek[$wk] ?? 0) + $cnt;
        }
        ksort($byweek);
        foreach ($byweek as $wk => $cnt) {
            $labels[] = userdate(strtotime($wk), get_string('chartdateformat', 'report_courseradar'));
            $values[] = $cnt;
        }
    } else {
        for ($d = $datefrom; $d <= $dateto; $d += DAYSECS) {
            $labels[] = userdate($d, get_string('chartdateformat', 'report_courseradar'));
            $values[] = $byday[date('Y-m-d', $d)] ?? 0;
        }
    }
    return ['labels' => $labels, 'values' => $values, 'weekly' => $weekly];
}
