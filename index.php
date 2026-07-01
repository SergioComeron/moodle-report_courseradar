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
 * Course Radar report - main page.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname($_SERVER['SCRIPT_FILENAME'], 3) . '/config.php');
require_once($CFG->dirroot . '/report/courseradar/locallib.php');

// Parameters.
$courseid    = required_param('id', PARAM_INT);
$datefromstr = optional_param('datefrom', '', PARAM_ALPHANUMEXT);
$datetostr   = optional_param('dateto', '', PARAM_ALPHANUMEXT);

// Course and context.
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

// Teachers/managers (with the view capability) see the full report. Enrolled
// students — and teachers previewing via "Switch role to → Student" — see their
// personal self-view. Anyone else is denied access.
$canviewreport = has_capability('report/courseradar:view', $context);
$isstudentview = !$canviewreport
    && (is_enrolled($context, $USER, '', true) || is_role_switched($courseid));
if (!$canviewreport && !$isstudentview) {
    require_capability('report/courseradar:view', $context);
}

// Date range.
$defaultfrom = $course->startdate ?: mktime(0, 0, 0, 1, 1, (int)date('Y'));
if (!$defaultfrom) {
    $defaultfrom = mktime(0, 0, 0, 1, 1, (int)date('Y'));
}
$datefrom = $datefromstr ? strtotime($datefromstr) : $defaultfrom;
$dateto   = $datetostr ? strtotime($datetostr . ' 23:59:59') : time();
if (!$datefrom || $datefrom < 0) {
    $datefrom = $defaultfrom;
}
if (!$dateto || $dateto < $datefrom) {
    $dateto = time();
}
$datefromformat = date('Y-m-d', $datefrom);
$datetoformat   = date('Y-m-d', $dateto);
$isfiltered     = ($datefromstr !== '' || $datetostr !== '');

// Page setup.
$PAGE->set_url(new moodle_url('/report/courseradar/index.php', ['id' => $courseid]));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'report_courseradar'));
$PAGE->set_heading($course->fullname);

// Course modules.
$modinfo  = get_fast_modinfo($course);
$validcms = [];
foreach ($modinfo->get_cms() as $cm) {
    if (!$cm->deletioninprogress && $cm->modname !== 'label' && $cm->modname !== 'subsection') {
        $validcms[$cm->id] = $cm;
    }
}
$totalmodules = count($validcms);

// Enrolled users: separate students from teachers/managers.
$students      = report_courseradar_get_students($context);
$totalstudents = count($students);
$studentids    = array_keys($students);

// Student self-view.
// Enrolled students (without the view capability) see their own personal
// metrics for the whole course, plus an anonymous comparison with the class
// average. No date filter is applied: data covers the entire course history.
if ($isstudentview) {
    $myid = (int)$USER->id;

    // Per-student per-module views and last access (whole course, no date range).
    $studentlog   = []; // Keyed [uid][cmid] => view count.
    $lastaccessby = []; // Keyed [uid] => last module-view timestamp.
    $myday        = []; // Keyed [Y-m-d] => count of my own daily interactions.

    if ($totalstudents > 0 && $totalmodules > 0) {
        [$insql, $inparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'st');
        $baseparams = array_merge([
            'courseid'     => $courseid,
            'action'       => 'viewed',
            'contextlevel' => CONTEXT_MODULE,
        ], $inparams);

        $sql = "SELECT contextinstanceid AS cmid, userid,
                       COUNT(*) AS views, MAX(timecreated) AS lastaccess
                  FROM {logstore_standard_log}
                 WHERE courseid     = :courseid
                   AND action       = :action
                   AND contextlevel = :contextlevel
                   AND userid {$insql}
                 GROUP BY contextinstanceid, userid";
        $rs = $DB->get_recordset_sql($sql, $baseparams);
        foreach ($rs as $row) {
            $studentlog[$row->userid][$row->cmid] = (int)$row->views;
            $la = (int)$row->lastaccess;
            if (!isset($lastaccessby[$row->userid]) || $la > $lastaccessby[$row->userid]) {
                $lastaccessby[$row->userid] = $la;
            }
        }
        $rs->close();

        // My own daily activity across the whole course.
        $sqlday = "SELECT (timecreated / 86400) * 86400 AS dayts, COUNT(*) AS cnt
                     FROM {logstore_standard_log}
                    WHERE courseid     = :courseid
                      AND action       = :action
                      AND contextlevel = :contextlevel
                      AND userid       = :myid
                    GROUP BY timecreated / 86400
                    ORDER BY dayts";
        $rs = $DB->get_recordset_sql($sqlday, [
            'courseid'     => $courseid,
            'action'       => 'viewed',
            'contextlevel' => CONTEXT_MODULE,
            'myid'         => $myid,
        ]);
        foreach ($rs as $row) {
            $myday[date('Y-m-d', (int)$row->dayts)] = (int)$row->cnt;
        }
        $rs->close();
    }

    // Days since last access, per student.
    $daysinactive = [];
    foreach ($students as $uid => $stu) {
        $daysinactive[$uid] = report_courseradar_days_inactive($lastaccessby[$uid] ?? 0);
    }

    // Completion (whole course).
    $completionenabled = !empty($course->enablecompletion);
    $hasanycompletion  = false;
    $totaltracked      = 0;
    if ($completionenabled) {
        foreach ($validcms as $cm) {
            if ($cm->completion > 0) {
                $hasanycompletion = true;
                $totaltracked++;
            }
        }
    }
    $completedbystu  = []; // Keyed [uid] => completed tracked activities.
    $mycompletedcms  = []; // Keyed [cmid] => true for tracked activities I completed.
    if ($hasanycompletion && $totalstudents > 0) {
        [$cminsql, $cminp]   = $DB->get_in_or_equal(array_keys($validcms), SQL_PARAMS_NAMED, 'cm');
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
                if ((int)$row->userid === $myid) {
                    $mycompletedcms[(int)$row->cmid] = true;
                }
            }
        }
        $rs->close();
    }

    // Engagement scores for all students (drives my score and the class average).
    $riskscores = report_courseradar_engagement_scores(
        $students,
        $studentlog,
        $daysinactive,
        $totalmodules,
        $hasanycompletion,
        $totaltracked,
        $completedbystu
    );

    // My own metrics.
    $myvisited    = count($studentlog[$myid] ?? []);
    $mycoverage   = ($totalmodules > 0) ? (int)round(($myvisited / $totalmodules) * 100) : 0;
    $myscore      = $riskscores[$myid] ?? 0;
    $mydaysraw    = $daysinactive[$myid] ?? -1;
    $mycompletion = ($hasanycompletion && $totaltracked > 0)
        ? (int)round((count($mycompletedcms) / $totaltracked) * 100)
        : 0;

    // Class averages (anonymous aggregates).
    $clsscore = $riskscores ? (int)round(array_sum($riskscores) / count($riskscores)) : 0;
    $covsum   = 0.0;
    $compsum  = 0.0;
    foreach ($students as $uid => $stu) {
        if ($totalmodules > 0) {
            $covsum += (count($studentlog[$uid] ?? []) / $totalmodules) * 100;
        }
        if ($hasanycompletion && $totaltracked > 0) {
            $compsum += (($completedbystu[$uid] ?? 0) / $totaltracked) * 100;
        }
    }
    $clscoverage   = $totalstudents > 0 ? (int)round($covsum / $totalstudents) : 0;
    $clscompletion = $totalstudents > 0 ? (int)round($compsum / $totalstudents) : 0;

    // Pending resources: visible to me, not yet visited, with a viewable URL.
    $myvisitedcms = $studentlog[$myid] ?? [];
    $pending      = [];
    foreach ($validcms as $cmid => $cm) {
        if (!$cm->uservisible || isset($myvisitedcms[$cmid]) || empty($cm->url)) {
            continue;
        }
        $pending[] = [
            'name' => $cm->get_formatted_name(),
            'url'  => $cm->url->out(false),
            'icon' => $cm->get_icon_url()->out(false),
            'type' => get_string('pluginname', $cm->modname),
        ];
    }

    // My activity-over-time chart (daily, or weekly when the span exceeds 90 days).
    $stuchart = null;
    if (!empty($myday)) {
        $chfrom = $course->startdate ?: strtotime((string)min(array_keys($myday)));
        $chto   = time();
        $stulabels = [];
        $stuvalues = [];
        $stuweekly = (($chto - $chfrom) / DAYSECS) > 90;
        if ($stuweekly) {
            $byweek = [];
            foreach ($myday as $day => $cnt) {
                $ts    = strtotime($day);
                $monds = $ts - ((int)date('N', $ts) - 1) * 86400;
                $wk    = date('Y-m-d', $monds);
                $byweek[$wk] = ($byweek[$wk] ?? 0) + $cnt;
            }
            ksort($byweek);
            foreach ($byweek as $wk => $cnt) {
                $stulabels[] = userdate(strtotime($wk), get_string('chartdateformat', 'report_courseradar'));
                $stuvalues[] = $cnt;
            }
        } else {
            for ($d = $chfrom; $d <= $chto; $d += DAYSECS) {
                $stulabels[] = userdate($d, get_string('chartdateformat', 'report_courseradar'));
                $stuvalues[] = $myday[date('Y-m-d', $d)] ?? 0;
            }
        }
        if (!empty($stuvalues)) {
            $stuchart = new \core\chart_line();
            $stuchart->set_smooth(true);
            $stuchart->add_series(new \core\chart_series(
                get_string('totalinteractions', 'report_courseradar'),
                $stuvalues
            ));
            $stuchart->set_labels($stulabels);
        }
    }

    $mydaystext = ($mydaysraw < 0)
        ? get_string('neveraccessed', 'report_courseradar')
        : $mydaysraw;

    $templatecontext = [
        'coursename'    => format_string($course->fullname),
        'score'         => $myscore,
        'scoreclass'    => report_courseradar_barclass($myscore),
        'scoretextclass' => str_replace('bg-', 'text-', report_courseradar_barclass($myscore)),
        'coverage'      => $mycoverage,
        'coverageclass' => report_courseradar_barclass($mycoverage),
        'completion'    => $mycompletion,
        'hascompletion' => $hasanycompletion,
        'daysinactive'  => $mydaystext,
        'classscore'    => $clsscore,
        'classcoverage' => $clscoverage,
        'classcompletion' => $clscompletion,
        'scoreabove'    => $myscore >= $clsscore,
        'coverageabove' => $mycoverage >= $clscoverage,
        'completionabove' => $mycompletion >= $clscompletion,
        'pending'       => $pending,
        'haspending'    => !empty($pending),
        'pendingcount'  => count($pending),
        'allvisited'    => empty($pending) && $totalmodules > 0,
        'charthtml'     => $stuchart ? $OUTPUT->render($stuchart) : '',
        'haschart'      => (bool)$stuchart,
    ];

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('report_courseradar/student_view', $templatecontext);
    echo $OUTPUT->footer();
    exit;
}

// Log queries.
$logdata    = []; // Keyed by cmid: totalviews, uniqueusers, lastaccess.
$bycm       = []; // Keyed [cmid][uid]: views, lastaccess.
$studentlog = []; // Keyed [uid][cmid]: view count.
$byday      = []; // Keyed by Y-m-d date string: interaction count.
$heatmap        = array_fill(0, 7, array_fill(0, 6, 0));    // Dow 0=Sun to 6=Sat, 4-hour blocks 0-5.
$heatstudents   = array_fill(0, 7, array_fill(0, 6, [])); // Same structure: list of fullnames.

if ($totalstudents > 0 && $totalmodules > 0) {
    [$insql, $inparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'st');

    $logparams = array_merge([
        'courseid'     => $courseid,
        'action'       => 'viewed',
        'contextlevel' => CONTEXT_MODULE,
        'datefrom'     => $datefrom,
        'dateto'       => $dateto,
    ], $inparams);

    // Aggregate totals per module.
    $sql = "SELECT contextinstanceid AS cmid,
                   COUNT(*)               AS totalviews,
                   COUNT(DISTINCT userid) AS uniqueusers,
                   MAX(timecreated)       AS lastaccess
              FROM {logstore_standard_log}
             WHERE courseid     = :courseid
               AND action       = :action
               AND contextlevel = :contextlevel
               AND timecreated >= :datefrom
               AND timecreated <= :dateto
               AND userid {$insql}
             GROUP BY contextinstanceid";
    $logdata = $DB->get_records_sql($sql, $logparams);

    // Per-user per-module detail.
    $sql2 = "SELECT contextinstanceid AS cmid, userid,
                    COUNT(*) AS views, MAX(timecreated) AS lastaccess
               FROM {logstore_standard_log}
              WHERE courseid     = :courseid
                AND action       = :action
                AND contextlevel = :contextlevel
                AND timecreated >= :datefrom
                AND timecreated <= :dateto
                AND userid {$insql}
              GROUP BY contextinstanceid, userid";
    $rs = $DB->get_recordset_sql($sql2, $logparams);
    foreach ($rs as $row) {
        $bycm[$row->cmid][$row->userid]       = $row;
        $studentlog[$row->userid][$row->cmid] = (int)$row->views;
    }
    $rs->close();

    // Activity per day (aggregated in SQL — avoids fetching raw timestamps).
    // FLOOR(timecreated / 86400) * 86400 gives the UTC midnight timestamp for each day.
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

    // Heatmap: interactions per (day-of-week, 4-hour block), aggregated in SQL.
    // Unix epoch (ts=0) was a Thursday; (days_since_epoch + 4) % 7 gives PHP date('w').
    // ts % 86400 / 14400 gives the 4-hour block (0-5).
    $sql4 = "SELECT MOD(timecreated / 86400 + 4, 7)     AS dow,
                    timecreated % 86400 / 14400          AS timeblock,
                    COUNT(*)                             AS cnt
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

    // Heatmap per-student: distinct (dow, timeblock, userid) to populate the click panel.
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

    // Weekly interactions per student for sparklines (604800 = seconds in a week).
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
    $weekdata = []; // Keyed by uid then week timestamp: view count.
    $rs = $DB->get_recordset_sql($sql5, $logparams);
    foreach ($rs as $row) {
        $weekdata[$row->userid][(int)$row->weekts] = (int)$row->cnt;
    }
    $rs->close();
}

// Course-level visits per student (course home page views).
$coursevisits      = []; // Keyed by userid: visit count.
$lastcoursevisit   = []; // Keyed by userid: timestamp of last course view.
$totalcoursevisits = 0;

if ($totalstudents > 0) {
    [$cvinsql, $cvinparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'cv');
    $cvsql = "SELECT userid, COUNT(*) AS visits, MAX(timecreated) AS lastcoursevisit
                FROM {logstore_standard_log}
               WHERE courseid     = :courseid
                 AND action       = 'viewed'
                 AND target       = 'course'
                 AND timecreated >= :datefrom
                 AND timecreated <= :dateto
                 AND userid {$cvinsql}
               GROUP BY userid";
    $rs = $DB->get_recordset_sql($cvsql, array_merge([
        'courseid' => $courseid,
        'datefrom' => $datefrom,
        'dateto'   => $dateto,
    ], $cvinparams));
    foreach ($rs as $row) {
        $coursevisits[$row->userid]     = (int)$row->visits;
        $lastcoursevisit[$row->userid]  = (int)$row->lastcoursevisit;
        $totalcoursevisits += (int)$row->visits;
    }
    $rs->close();
}

// Week-over-week comparison (current calendar week vs previous week, always absolute).
$now           = time();
$dow           = (int)date('N', $now); // 1=Mon … 7=Sun (ISO-8601).
$curweekstart  = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now) - ($dow - 1));
$prevweekstart = $curweekstart - 604800;

$wkcurinteractions  = 0;
$wkprevinteractions = 0;
$wkcurvisits        = 0;
$wkprevvisits       = 0;

if ($totalstudents > 0) {
    if ($totalmodules > 0) {
        [$wkinsql, $wkinparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'wka');
        $wkrow = $DB->get_record_sql(
            "SELECT COUNT(CASE WHEN timecreated >= :curstart  THEN 1 END) AS curwk,
                    COUNT(CASE WHEN timecreated <  :curstart2 THEN 1 END) AS prevwk
               FROM {logstore_standard_log}
              WHERE courseid     = :courseid
                AND action       = 'viewed'
                AND contextlevel = :contextlevel
                AND timecreated >= :prevstart
                AND userid {$wkinsql}",
            array_merge([
                'courseid'     => $courseid,
                'contextlevel' => CONTEXT_MODULE,
                'curstart'     => $curweekstart,
                'curstart2'    => $curweekstart,
                'prevstart'    => $prevweekstart,
            ], $wkinparams)
        );
        $wkcurinteractions = $wkrow ? (int)$wkrow->curwk : 0;
        $wkprevinteractions = $wkrow ? (int)$wkrow->prevwk : 0;
    }

    [$wkinsql2, $wkinparams2] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'wkb');
    $wkrow2 = $DB->get_record_sql(
        "SELECT COUNT(CASE WHEN timecreated >= :curstart  THEN 1 END) AS curwk,
                COUNT(CASE WHEN timecreated <  :curstart2 THEN 1 END) AS prevwk
           FROM {logstore_standard_log}
          WHERE courseid     = :courseid
            AND action       = 'viewed'
            AND target       = 'course'
            AND timecreated >= :prevstart
            AND userid {$wkinsql2}",
        array_merge([
            'courseid'  => $courseid,
            'curstart'  => $curweekstart,
            'curstart2' => $curweekstart,
            'prevstart' => $prevweekstart,
        ], $wkinparams2)
    );
    $wkcurvisits = $wkrow2 ? (int)$wkrow2->curwk : 0;
    $wkprevvisits = $wkrow2 ? (int)$wkrow2->prevwk : 0;
}

// Pre-compute sparkline bars for every student.
$weekslots = [];
for ($w = (int)($datefrom / 604800) * 604800; $w <= $dateto; $w += 604800) {
    $weekslots[] = $w;
}

$sparklines = [];
foreach ($students as $uid => $stu) {
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

// Completion data.
$completionenabled = !empty($course->enablecompletion);
$completions       = []; // Keyed by cmid: count of students who completed.
$completionbyuser  = []; // Keyed [cmid][uid]: completion state.

if ($completionenabled && $totalstudents > 0 && $totalmodules > 0) {
    [$cminsql, $cminp] = $DB->get_in_or_equal(array_keys($validcms), SQL_PARAMS_NAMED, 'cm');
    [$stcinsql, $stcinp] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'stc');
    $rs = $DB->get_recordset_sql(
        "SELECT coursemoduleid AS cmid, userid, completionstate
           FROM {course_modules_completion}
          WHERE coursemoduleid {$cminsql} AND userid {$stcinsql} AND completionstate > 0",
        array_merge($cminp, $stcinp)
    );
    foreach ($rs as $row) {
        $completions[$row->cmid] = ($completions[$row->cmid] ?? 0) + 1;
        $completionbyuser[$row->cmid][$row->userid] = (int)$row->completionstate;
    }
    $rs->close();
}

$hasanycompletion = false;
if ($completionenabled) {
    foreach ($validcms as $cm) {
        if ($cm->completion > 0) {
            $hasanycompletion = true;
            break;
        }
    }
}

// Per-student completion progress.
$totaltracked   = 0;
$completedbystu = [];
if ($hasanycompletion) {
    foreach ($validcms as $cm) {
        if ($cm->completion > 0) {
            $totaltracked++;
        }
    }
    foreach ($students as $uid => $stu) {
        $count = 0;
        foreach ($validcms as $cmid => $cm) {
            if ($cm->completion > 0 && isset($completionbyuser[$cmid][$uid])) {
                $count++;
            }
        }
        $completedbystu[$uid] = $count;
    }
}

// Summary statistics.
$totalinteractions = 0;
$maxviews          = 0;
$maxname           = get_string('none', 'report_courseradar');
$engagements       = [];

foreach ($validcms as $cmid => $cm) {
    $v = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->totalviews : 0;
    $u = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->uniqueusers : 0;
    $totalinteractions += $v;
    if ($v > $maxviews) {
        $maxviews = $v;
        $maxname = format_string($cm->name);
    }
    if ($totalstudents > 0) {
        $engagements[] = ($u / $totalstudents) * 100;
    }
}
$avgengagement = $engagements ? round(array_sum($engagements) / count($engagements)) : 0;

// Engagement distribution: count students per quartile of resources visited.
$engdist = [0 => 0, 25 => 0, 50 => 0, 75 => 0];
foreach ($students as $uid => $stu) {
    $pct = ($totalmodules > 0) ? round((count($studentlog[$uid] ?? []) / $totalmodules) * 100) : 0;
    if ($pct < 25) {
        $engdist[0]++;
    } else if ($pct < 50) {
        $engdist[25]++;
    } else if ($pct < 75) {
        $engdist[50]++;
    } else {
        $engdist[75]++;
    }
}

// Aggregate interactions by module type.
$bytype = [];
foreach ($validcms as $cmid => $cm) {
    $mod = $cm->modname;
    if (!isset($bytype[$mod])) {
        $bytype[$mod] = ['modules' => 0, 'views' => 0];
    }
    $bytype[$mod]['modules']++;
    if (isset($logdata[$cmid])) {
        $bytype[$mod]['views'] += (int)$logdata[$cmid]->totalviews;
    }
}
// Sort by total views descending.
$typeviews = array_column($bytype, 'views');
array_multisort($typeviews, SORT_DESC, $bytype);

// Max views across types for CSS bar normalisation.
$typemaxviews = !empty($bytype) ? max(array_column($bytype, 'views')) : 1;
if ($typemaxviews === 0) {
    $typemaxviews = 1;
}

// At-risk students.
$atrisknone = [];
$atrisklow  = [];
foreach ($students as $uid => $stu) {
    $visited = isset($studentlog[$uid]) ? count($studentlog[$uid]) : 0;
    if ($visited === 0) {
        $atrisknone[$uid] = $stu;
    } else if ($totalmodules > 0 && ($visited / $totalmodules) < 0.30) {
        $atrisklow[$uid] = $stu;
    }
}
$totalrisk = count($atrisknone) + count($atrisklow);

// Top least-visited resources.
$topunseen = report_courseradar_top_unseen($validcms, $logdata, $totalstudents, count($validcms));

// Unique module types present in topunseen (for its filter bar).
$topunseentypes = [];
foreach ($topunseen as $item) {
    $mod = $item['cm']->modname;
    if (!in_array($mod, $topunseentypes, true)) {
        $topunseentypes[] = $mod;
    }
}

// Days inactive per student (derived from last activity, no extra query).
$daysinactive = [];
foreach ($students as $uid => $stu) {
    $lastact = 0;
    if (isset($studentlog[$uid])) {
        foreach (array_keys($studentlog[$uid]) as $cid) {
            if (isset($bycm[$cid][$uid]) && (int)$bycm[$cid][$uid]->lastaccess > $lastact) {
                $lastact = (int)$bycm[$cid][$uid]->lastaccess;
            }
        }
    }
    $daysinactive[$uid] = report_courseradar_days_inactive($lastact);
}

// Composite engagement score per student (0 = no activity, 100 = fully engaged).
$riskscores = report_courseradar_engagement_scores(
    $students,
    $studentlog,
    $daysinactive,
    $totalmodules,
    $hasanycompletion,
    $totaltracked,
    $completedbystu
);

// Score distribution histogram: count students per 20-point band.
$scorebands = report_courseradar_score_bands($riskscores);

// Scatter plot data: visited %, engagement score, name, profile URL.
$scatterdata = report_courseradar_scatter_data(
    $students,
    $studentlog,
    $riskscores,
    $totalmodules,
    $courseid
);

// Activity chart data.
$chartlabels  = [];
$chartvalues  = [];
$chartweekly  = false;

if (!empty($byday)) {
    $dayrange = ($dateto - $datefrom) / DAYSECS;
    if ($dayrange > 90) {
        $chartweekly = true;
        $byweek = [];
        foreach ($byday as $day => $cnt) {
            $ts     = strtotime($day);
            $isodow = (int)date('N', $ts); // 1 = Mon ... 7 = Sun.
            $monds  = $ts - ($isodow - 1) * 86400;
            $wk     = date('Y-m-d', $monds);
            $byweek[$wk] = ($byweek[$wk] ?? 0) + $cnt;
        }
        ksort($byweek);
        foreach ($byweek as $wk => $cnt) {
            $chartlabels[] = userdate(strtotime($wk), get_string('chartdateformat', 'report_courseradar'));
            $chartvalues[] = $cnt;
        }
    } else {
        for ($d = $datefrom; $d <= $dateto; $d += DAYSECS) {
            $chartlabels[] = userdate($d, get_string('chartdateformat', 'report_courseradar'));
            $chartvalues[] = $byday[date('Y-m-d', $d)] ?? 0;
        }
    }
}

// Build Moodle chart object.
$chartobj = null;
if (!empty($chartvalues)) {
    $chartobj = new \core\chart_line();
    $chartobj->set_smooth(true);
    $cseries = new \core\chart_series(
        get_string('totalinteractions', 'report_courseradar'),
        $chartvalues
    );
    $chartobj->add_series($cseries);
    $chartobj->set_labels($chartlabels);
}

// Heatmap max value for colour normalisation.
$heatmax = 1;
foreach ($heatmap as $drow) {
    foreach ($drow as $val) {
        if ($val > $heatmax) {
            $heatmax = $val;
        }
    }
}

// Day names: full names from calendar component, abbreviated to 3 chars.
$daykeymap = [0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
              4 => 'thursday', 5 => 'friday', 6 => 'saturday'];
$daynames  = [];
foreach ($daykeymap as $dow => $key) {
    $daynames[$dow] = mb_substr(get_string($key, 'calendar'), 0, 3);
}
$dayorder  = [1, 2, 3, 4, 5, 6, 0]; // Mon to Sun display order.
$timeslots = ['0–3h', '4–7h', '8–11h', '12–15h', '16–19h', '20–23h'];

// Organise modules by section.
$bysection = [];
foreach ($validcms as $cmid => $cm) {
    $snum = $cm->sectionnum;
    if (!isset($bysection[$snum])) {
        $si    = $modinfo->get_section_info($snum);
        $sname = ($si && !empty($si->name))
            ? format_string($si->name)
            : ($snum === 0 ? get_string('general') : get_string('section') . ' ' . $snum);
        $bysection[$snum] = ['name' => $sname, 'cms' => []];
    }
    $bysection[$snum]['cms'][] = $cm;
}
ksort($bysection);

// Whether the course has hidden activities (drives the show/hide toggle).
$hashidden = false;
foreach ($validcms as $cm) {
    if (!$cm->visible) {
        $hashidden = true;
        break;
    }
}

// Number of resource table columns (varies when completion is enabled).
$rescols = $hasanycompletion ? 8 : 7;
// Number of student table columns (base 9 + 1 if completion tracking active).
$stucols = $hasanycompletion ? 10 : 9;

// Output.
echo $OUTPUT->header();
// phpcs:disable
?>
<style>
/* ── Course Radar ──────────────────────────────────────────────────────────── */
.cr-card              { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.08); transition: transform .15s; }
.cr-card:hover        { transform: translateY(-3px); }
.cr-stat              { font-size: 2.2rem; font-weight: 700; line-height: 1.1; }
.cr-stat-label        { font-size: .85rem; color: #6c757d; margin-top: .25rem; }
.cr-maxname           { font-size: 1rem; font-weight: 600; word-break: break-word; }
.progress             { height: 22px; border-radius: 6px; }
.progress-bar         { font-size: .8rem; font-weight: 600; }
/* Secciones y filas */
.cr-section-row       { background: #f0f2f5 !important; }
.cr-section-row td    { font-weight: 600; font-size: .9rem; padding: .5rem .75rem !important; }
.cr-detail-row td     { padding: 0 !important; border-top: 0 !important; }
.cr-detail-inner      { background: #f8f9fa; border-top: 2px solid #dee2e6; }
.cr-seen-list         { max-height: 260px; overflow-y: auto; }
tr.cr-resource-row:hover { background: #f0f7ff; }
tr.cr-student-row:hover  { background: #f0f7ff; }
.btn.cr-btn-active    { background-color: #0d6efd; color: #fff; }
.cr-badge-mod         { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; }
.cr-student-badge     { font-size: .75rem; cursor: default; }
.cr-act-icon          { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:5px; position:relative; cursor:default; flex-shrink:0; }
.cr-act-icon img      { width:15px; height:15px; }
.cr-act-icon .cr-act-cnt { position:absolute; top:-4px; right:-4px; font-size:9px; font-weight:700; line-height:1; padding:1px 3px; border-radius:3px; background:#0d6efd; color:#fff; }
.cr-act-icon.cr-act-done .cr-act-cnt { background:#198754; }
.cr-act-grid          { display:flex; flex-wrap:wrap; gap:3px; }
.cr-zero              { color: #adb5bd; }
/* Alumnos en riesgo */
.cr-risk-card         { border-left: 4px solid #dc3545 !important; }
.cr-risk-names        { max-height: 180px; overflow-y: auto; columns: 2; column-gap: 1rem; }
.cr-risk-names a      { display: block; font-size: .85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* Heatmap */
.cr-heatmap           { border-collapse: separate; border-spacing: 3px; font-size: .78rem; width: 100%; }
.cr-heatmap th        { font-weight: 600; padding: 3px 6px; text-align: center; white-space: nowrap; }
.cr-heatmap td        { border-radius: 4px; padding: 5px 4px; text-align: center; min-width: 36px; cursor: pointer; }
.cr-heatmap td.cr-heatmap-selected { outline: 2px solid #0d6efd; outline-offset: -2px; }
.cr-heatmap-panel     { border: 1px solid #dee2e6; border-radius: 8px; padding: .75rem 1rem; background: #f8f9fa; }
/* Fila de gráficos: apila las dos tarjetas según el ancho REAL disponible
   (container query), no el viewport. Así, al abrir el drawer derecho de
   bloques el contenido se estrecha y las tarjetas pasan a una sola columna
   en vez de solaparse. */
.cr-charts-row        { container-type: inline-size; }
@container (max-width: 1199px) {
  .cr-charts-row > [class*="col-xl-"] { flex: 0 0 100%; max-width: 100%; }
}
/* Ordenación */
.cr-th-sort           { cursor: pointer; user-select: none; white-space: nowrap; }
.cr-th-sort::after    { content: ' ⇅'; opacity: .35; font-size: .75em; }
.cr-th-asc::after     { content: ' ▲'; opacity: 1; }
.cr-th-desc::after    { content: ' ▼'; opacity: 1; }
#cr-sort-notice       { display: none; }
/* Sparkline */
.cr-sparkline         { background: #f0f2f5; border-radius: 4px; padding: 4px 4px 0; display: flex; align-items: flex-end; gap: 2px; height: 44px; }
.cr-spark-bar         { flex: 1; min-width: 3px; border-radius: 2px 2px 0 0; background: #0d6efd; opacity: .75; transition: opacity .15s; cursor: default; }
.cr-spark-bar:hover   { opacity: 1; }
.cr-spark-empty       { color: #adb5bd; font-size: .8rem; }
/* Filtro de tipos */
.cr-type-filter-btn   { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; transition: all .15s; }
/* Scatter plot */
.cr-scatter-wrap      { position: relative; }
.cr-score-help summary { cursor: pointer; color: #0d6efd; font-size: .85rem; font-weight: 600; }
.cr-score-formula     { background: #f0f2f5; border-radius: 6px; padding: .5rem .75rem; }
.cr-score-formula code { color: #212529; font-size: .85rem; }
#cr-scatter-tip       { position: fixed; pointer-events: none; background: rgba(0,0,0,.78); color: #fff; padding: 5px 10px; border-radius: 6px; font-size: .8rem; line-height: 1.5; white-space: nowrap; display: none; z-index: 9999; }
</style>
<script>
/* ── Estado persistente (localStorage por curso) ───────────────────────────── */
var crStateKey      = 'cr_state_<?php echo (int)$courseid; ?>';
var crStateLoading  = false;
var crScatterData   = <?php echo json_encode($scatterdata, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '[]'; ?>;
var crScoreBands    = <?php echo json_encode(array_values($scorebands), JSON_HEX_TAG) ?: '[0,0,0,0,0]'; ?>;

function crSaveState() {
    if (crStateLoading) { return; }
    var hiddenTypes = [];
    document.querySelectorAll('.cr-type-filter-btn').forEach(function(b) {
        if (!b.classList.contains('cr-type-active')) { hiddenTypes.push(b.dataset.modname); }
    });
    var hiddenTopUnseen = [];
    document.querySelectorAll('.cr-topunseen-filter-btn').forEach(function(b) {
        if (!b.classList.contains('cr-type-active')) { hiddenTopUnseen.push(b.dataset.modname); }
    });
    var cb  = document.getElementById('cr-show-hidden');
    var tab = document.querySelector('.cr-tab-btn.active');
    try {
        localStorage.setItem(crStateKey, JSON.stringify({
            tab:             tab ? tab.dataset.tab : 'cr-tab-overview',
            hiddenTypes:     hiddenTypes,
            showHidden:      cb ? cb.checked : false,
            hiddenTopUnseen: hiddenTopUnseen
        }));
    } catch (e) {}
}

function crLoadState() {
    var state;
    try { state = JSON.parse(localStorage.getItem(crStateKey) || '{}'); } catch (e) { state = {}; }
    crStateLoading = true;

    if (state.tab) { crShowTab(state.tab); }

    if (state.hiddenTypes && state.hiddenTypes.length) {
        document.querySelectorAll('.cr-type-filter-btn').forEach(function(b) {
            if (state.hiddenTypes.indexOf(b.dataset.modname) >= 0) {
                b.classList.remove('cr-type-active', 'btn-primary');
                b.classList.add('btn-outline-secondary');
            }
        });
    }

    var cb = document.getElementById('cr-show-hidden');
    if (cb && state.showHidden !== undefined) { cb.checked = state.showHidden; }

    if (state.hiddenTopUnseen && state.hiddenTopUnseen.length) {
        document.querySelectorAll('.cr-topunseen-filter-btn').forEach(function(b) {
            if (state.hiddenTopUnseen.indexOf(b.dataset.modname) >= 0) {
                b.classList.remove('cr-type-active', 'btn-warning');
                b.classList.add('btn-outline-secondary');
            }
        });
    }
    crApplyTopUnseenLimit();

    crApplyFilters();
    crStateLoading = false;
}

/* ── Inicializar ─────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    crLoadState();
    if (typeof bootstrap !== 'undefined') {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
            new bootstrap.Tooltip(el);
        });
    }
});

/* ── Toggle fila de detalle ────────────────────────────────────────────────── */
function crToggle(btn, rowId) {
    var row = document.getElementById(rowId);
    if (!row) { return; }
    if (row.style.display === 'table-row') {
        row.style.display = 'none';
        btn.classList.remove('cr-btn-active');
    } else {
        row.style.display = 'table-row';
        btn.classList.add('cr-btn-active');
    }
}

function crToggleDateFilter() {
    var wrap  = document.getElementById('cr-datefilter-wrap');
    var arrow = document.getElementById('cr-datefilter-arrow');
    var open  = wrap.style.display === 'none';
    wrap.style.display = open ? '' : 'none';
    arrow.textContent  = open ? '▴' : '▾';
}

/* ── Pestañas principales ──────────────────────────────────────────────────── */
function crShowTab(tabId) {
    document.querySelectorAll('.cr-tab-panel').forEach(function(p) { p.style.display = 'none'; });
    document.querySelectorAll('.cr-tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById(tabId).style.display = '';
    document.querySelector('[data-tab="' + tabId + '"]').classList.add('active');
    crSaveState();
    if (tabId === 'cr-tab-students') { setTimeout(crDrawScatter, 0); }
    if (tabId === 'cr-tab-overview') { setTimeout(crDrawScoreDist, 0); }
}

/* ── Ordenar tabla de recursos ─────────────────────────────────────────────── */
function crSortResources(th, isNumeric) {
    var table = document.getElementById('cr-resources-table');
    var tbody = table.querySelector('tbody');
    var colIndex = Array.from(th.parentNode.children).indexOf(th);
    var asc = th.dataset.sortDir !== 'asc';

    th.parentNode.querySelectorAll('th').forEach(function(h) {
        h.dataset.sortDir = '';
        h.classList.remove('cr-th-asc', 'cr-th-desc');
    });
    th.dataset.sortDir = asc ? 'asc' : 'desc';
    th.classList.add(asc ? 'cr-th-asc' : 'cr-th-desc');

    var rows = Array.from(tbody.querySelectorAll('tr.cr-resource-row'));
    rows.sort(function(a, b) {
        var tdA = a.querySelectorAll('td')[colIndex];
        var tdB = b.querySelectorAll('td')[colIndex];
        var vA  = tdA ? (tdA.dataset.sort !== undefined ? tdA.dataset.sort : tdA.textContent.trim()) : '';
        var vB  = tdB ? (tdB.dataset.sort !== undefined ? tdB.dataset.sort : tdB.textContent.trim()) : '';
        var cmp = isNumeric ? (parseFloat(vA) || 0) - (parseFloat(vB) || 0) : vA.localeCompare(vB);
        return asc ? cmp : -cmp;
    });

    tbody.querySelectorAll('.cr-section-row').forEach(function(r) { r.style.display = 'none'; });
    document.getElementById('cr-sort-notice').style.display = 'block';

    rows.forEach(function(row) {
        tbody.appendChild(row);
        var dr = document.getElementById(row.dataset.detail);
        if (dr) { tbody.appendChild(dr); }
    });
}

function crResetSort() { location.reload(); }

/* ── Filtro de tipos en "Recursos menos visitados" ─────────────────────────── */
function crApplyTopUnseenLimit() {
    var hiddenTypes = [];
    document.querySelectorAll('.cr-topunseen-filter-btn').forEach(function(b) {
        if (!b.classList.contains('cr-type-active')) { hiddenTypes.push(b.dataset.modname); }
    });
    var tbody = document.querySelector('#cr-topunseen-table tbody');
    if (!tbody) { return; }
    var visible = 0;
    tbody.querySelectorAll('tr[data-modname]').forEach(function(row) {
        var typeHidden = hiddenTypes.indexOf(row.dataset.modname) >= 0;
        if (!typeHidden && visible < 10) {
            row.style.display = '';
            row.querySelector('td').textContent = ++visible;
        } else {
            row.style.display = 'none';
        }
    });
}

function crFilterTopUnseen(btn, modname) {
    var wasActive = btn.classList.contains('cr-type-active');
    if (wasActive) {
        btn.classList.remove('cr-type-active', 'btn-warning');
        btn.classList.add('btn-outline-secondary');
    } else {
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('cr-type-active', 'btn-warning');
    }
    crApplyTopUnseenLimit();
    crSaveState();
}

/* ── Buscador de estudiantes ───────────────────────────────────────────────── */
function crSearchStudents(q) {
    var needle = q.trim().toLowerCase();
    var tbody  = document.querySelector('#cr-students-table tbody');
    tbody.querySelectorAll('tr.cr-student-row').forEach(function(row) {
        var name  = row.querySelector('td').textContent.trim().toLowerCase();
        var match = needle === '' || name.indexOf(needle) >= 0;
        row.style.display = match ? '' : 'none';
        if (!match) {
            var dr = document.getElementById(row.dataset.detail);
            if (dr) { dr.style.display = 'none'; }
        }
    });
}

/* ── Filtros de recursos (tipos + ocultas): función central ────────────────── */
function crApplyFilters() {
    var cb = document.getElementById('cr-show-hidden');
    var showHidden = cb ? cb.checked : true;

    var hiddenTypes = [];
    document.querySelectorAll('.cr-type-filter-btn').forEach(function(b) {
        if (!b.classList.contains('cr-type-active')) { hiddenTypes.push(b.dataset.modname); }
    });

    var tbody = document.querySelector('#cr-resources-table tbody');
    tbody.querySelectorAll('tr.cr-resource-row').forEach(function(row) {
        var isHiddenActivity = row.dataset.hidden === '1';
        var isTypeFiltered   = hiddenTypes.indexOf(row.dataset.modname) >= 0;
        var hide = (!showHidden && isHiddenActivity) || isTypeFiltered;
        row.style.display = hide ? 'none' : '';
        if (hide) {
            var dr = document.getElementById(row.dataset.detail);
            if (dr) { dr.style.display = 'none'; }
        }
    });

    var inSortMode = document.getElementById('cr-sort-notice').style.display === 'block';
    if (!inSortMode) {
        tbody.querySelectorAll('tr.cr-section-row').forEach(function(srow) {
            var snum = srow.dataset.sectionid;
            var hasVisible = false;
            tbody.querySelectorAll('tr.cr-resource-row[data-section="' + snum + '"]').forEach(function(r) {
                if (r.style.display !== 'none') { hasVisible = true; }
            });
            srow.style.display = hasVisible ? '' : 'none';
        });
    }
}

function crFilterType(btn) {
    var wasActive = btn.classList.contains('cr-type-active');
    if (wasActive) {
        btn.classList.remove('cr-type-active', 'btn-primary');
        btn.classList.add('btn-outline-secondary');
    } else {
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('cr-type-active', 'btn-primary');
    }
    crApplyFilters();
    crSaveState();
}

function crToggleHidden() { crApplyFilters(); crSaveState(); }

var crProfileBase = '<?php echo (new moodle_url('/user/view.php', ['course' => $courseid]))->out(false); ?>';
var crHeatmapSelected = null;
function crHeatmapClick(td) {
    var panel = document.getElementById('cr-heatmap-panel');
    if (crHeatmapSelected === td) {
        crHeatmapClose();
        return;
    }
    if (crHeatmapSelected) { crHeatmapSelected.classList.remove('cr-heatmap-selected'); }
    crHeatmapSelected = td;
    td.classList.add('cr-heatmap-selected');
    var students = JSON.parse(td.dataset.students || '[]');
    document.getElementById('cr-heatmap-panel-title').textContent =
        td.dataset.label + ' — ' + students.length + ' ' +
        (students.length === 1 ? '<?php echo get_string('student', 'report_courseradar'); ?>'
                               : '<?php echo get_string('uniquestudents', 'report_courseradar'); ?>');
    var body = document.getElementById('cr-heatmap-panel-body');
    body.innerHTML = students.length === 0
        ? '<span class="text-muted small"><?php echo get_string('nostudents', 'report_courseradar'); ?></span>'
        : students.map(function(s) {
            return '<a href="' + crProfileBase + '&amp;id=' + s.id + '"' +
                   ' class="badge bg-light text-dark border text-decoration-none">' +
                   s.name + '</a>';
          }).join('');
    panel.classList.remove('d-none');
    panel.scrollIntoView({behavior: 'smooth', block: 'nearest'});
}
function crHeatmapClose() {
    if (crHeatmapSelected) { crHeatmapSelected.classList.remove('cr-heatmap-selected'); crHeatmapSelected = null; }
    document.getElementById('cr-heatmap-panel').classList.add('d-none');
}

/* ── Ordenar tabla de estudiantes ──────────────────────────────────────────── */
function crSortStudents(th, isNumeric) {
    var table = document.getElementById('cr-students-table');
    var tbody = table.querySelector('tbody');
    var colIndex = Array.from(th.parentNode.children).indexOf(th);
    var asc = th.dataset.sortDir !== 'asc';

    th.parentNode.querySelectorAll('th').forEach(function(h) {
        h.dataset.sortDir = '';
        h.classList.remove('cr-th-asc', 'cr-th-desc');
    });
    th.dataset.sortDir = asc ? 'asc' : 'desc';
    th.classList.add(asc ? 'cr-th-asc' : 'cr-th-desc');

    var rows = Array.from(tbody.querySelectorAll('tr.cr-student-row'));
    rows.sort(function(a, b) {
        var tdA = a.querySelectorAll('td')[colIndex];
        var tdB = b.querySelectorAll('td')[colIndex];
        var vA  = tdA ? (tdA.dataset.sort !== undefined ? tdA.dataset.sort : tdA.textContent.trim()) : '';
        var vB  = tdB ? (tdB.dataset.sort !== undefined ? tdB.dataset.sort : tdB.textContent.trim()) : '';
        var cmp = isNumeric ? (parseFloat(vA) || 0) - (parseFloat(vB) || 0) : vA.localeCompare(vB);
        return asc ? cmp : -cmp;
    });

    rows.forEach(function(row) {
        tbody.appendChild(row);
        var dr = document.getElementById(row.dataset.detail);
        if (dr) { tbody.appendChild(dr); }
    });
}

/* ── Histograma de distribución del score ──────────────────────────────────── */
function crDrawScoreDist() {
    var canvas = document.getElementById('cr-scoredist-canvas');
    if (!canvas) { return; }
    var dpr = window.devicePixelRatio || 1;
    var w   = canvas.offsetWidth;
    var h   = canvas.offsetHeight || 220;
    if (w === 0) { return; }
    canvas.width  = w * dpr;
    canvas.height = h * dpr;
    var ctx    = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    var labels = ['0–19', '20–39', '40–59', '60–79', '80–100'];
    var colors = ['#dc3545', '#fd7e14', '#ffc107', '#20c997', '#198754'];
    var vals   = crScoreBands;
    var max    = Math.max.apply(null, vals) || 1;
    var ml = 40, mr = 16, mt = 20, mb = 38;
    var pw = w - ml - mr, ph = h - mt - mb;
    var n  = vals.length, gap = 10;
    var bw = (pw - gap * (n - 1)) / n;

    // Grid lines + Y labels.
    ctx.lineWidth = 1;
    for (var g = 0; g <= 4; g++) {
        var gy = mt + ph - (g / 4) * ph;
        ctx.strokeStyle = '#e9ecef';
        ctx.beginPath(); ctx.moveTo(ml, gy); ctx.lineTo(ml + pw, gy); ctx.stroke();
        ctx.fillStyle = '#6c757d'; ctx.font = '10px system-ui,sans-serif'; ctx.textAlign = 'right';
        ctx.fillText(Math.round((g / 4) * max), ml - 5, gy + 3);
    }

    // Bars.
    vals.forEach(function(v, i) {
        var bh = ph > 0 ? (v / max) * ph : 0;
        var bx = ml + i * (bw + gap);
        var by = mt + ph - bh;
        ctx.fillStyle   = colors[i];
        ctx.globalAlpha = 0.85;
        ctx.fillRect(bx, by, bw, bh);
        ctx.globalAlpha = 1;
        if (v > 0) {
            ctx.fillStyle = '#343a40'; ctx.font = 'bold 11px system-ui,sans-serif'; ctx.textAlign = 'center';
            ctx.fillText(v, bx + bw / 2, by - 5);
        }
        ctx.fillStyle = '#6c757d'; ctx.font = '10px system-ui,sans-serif'; ctx.textAlign = 'center';
        ctx.fillText(labels[i], bx + bw / 2, mt + ph + 14);
    });

    // Axis.
    ctx.strokeStyle = '#adb5bd'; ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.moveTo(ml, mt); ctx.lineTo(ml, mt + ph); ctx.lineTo(ml + pw, mt + ph); ctx.stroke();
}

/* ── Scatter plot: recursos visitados vs. score ────────────────────────────── */
function crDrawScatter() {
    var canvas = document.getElementById('cr-scatter-canvas');
    if (!canvas) { return; }
    var dpr = window.devicePixelRatio || 1;
    var w   = canvas.offsetWidth;
    var h   = canvas.offsetHeight || 320;
    if (w === 0) { return; }
    canvas.width  = w * dpr;
    canvas.height = h * dpr;
    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);

    var ml = 56, mr = 16, mt = 16, mb = 46;
    var pw = w - ml - mr;
    var ph = h - mt - mb;

    function toX(v) { return ml + (v / 100) * pw; }
    function toY(v) { return mt + ph - (v / 100) * ph; }

    // Grid lines.
    ctx.lineWidth = 1;
    for (var i = 0; i <= 5; i++) {
        var v = i * 20;
        ctx.strokeStyle = '#e9ecef';
        ctx.beginPath(); ctx.moveTo(ml, toY(v)); ctx.lineTo(ml + pw, toY(v)); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(toX(v), mt); ctx.lineTo(toX(v), mt + ph); ctx.stroke();
        ctx.fillStyle = '#6c757d';
        ctx.font = '11px system-ui,sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(v, ml - 6, toY(v) + 4);
        ctx.textAlign = 'center';
        ctx.fillText(v + '%', toX(v), mt + ph + 16);
    }

    // Axes.
    ctx.strokeStyle = '#adb5bd'; ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.moveTo(ml, mt); ctx.lineTo(ml, mt + ph); ctx.lineTo(ml + pw, mt + ph); ctx.stroke();

    // Axis labels.
    ctx.fillStyle = '#495057'; ctx.font = 'bold 11px system-ui,sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('<?php echo get_string('scatter_xaxis', 'report_courseradar'); ?>', ml + pw / 2, mt + ph + 36);
    ctx.save();
    ctx.translate(13, mt + ph / 2);
    ctx.rotate(-Math.PI / 2);
    ctx.fillText('<?php echo get_string('scatter_yaxis', 'report_courseradar'); ?>', 0, 0);
    ctx.restore();

    // Points.
    crScatterData.forEach(function(d) {
        var px  = toX(d.x);
        var py  = toY(d.y);
        var col = d.y >= 70 ? '#198754' : (d.y >= 40 ? '#fd7e14' : '#dc3545');
        ctx.beginPath();
        ctx.arc(px, py, 7, 0, 2 * Math.PI);
        ctx.globalAlpha = 0.82;
        ctx.fillStyle   = col;
        ctx.fill();
        ctx.globalAlpha = 1;
        ctx.strokeStyle = '#fff';
        ctx.lineWidth   = 1.5;
        ctx.stroke();
    });
}

(function() {
    function getHit(canvas, ex, ey) {
        var r  = canvas.getBoundingClientRect();
        var w  = canvas.offsetWidth;
        var h  = canvas.offsetHeight || 320;
        var ml = 56, mr = 16, mt = 16, mb = 46;
        var pw = w - ml - mr, ph = h - mt - mb;
        var cx = ex - r.left, cy = ey - r.top;
        for (var i = 0; i < crScatterData.length; i++) {
            var d  = crScatterData[i];
            var px = ml + (d.x / 100) * pw;
            var py = mt + ph - (d.y / 100) * ph;
            if (Math.sqrt((cx - px) * (cx - px) + (cy - py) * (cy - py)) <= 9) { return d; }
        }
        return null;
    }
    document.addEventListener('DOMContentLoaded', function() {
        var canvas = document.getElementById('cr-scatter-canvas');
        var tip    = document.getElementById('cr-scatter-tip');
        if (!canvas || !tip) { return; }
        canvas.addEventListener('mousemove', function(e) {
            var d = getHit(canvas, e.clientX, e.clientY);
            if (d) {
                tip.style.display = 'block';
                tip.style.left    = (e.clientX + 14) + 'px';
                tip.style.top     = (e.clientY - 32) + 'px';
                tip.innerHTML     = '<strong>' + d.name + '</strong><br>' +
                    '<?php echo get_string('scatter_xaxis', 'report_courseradar'); ?>: ' + d.x + '%<br>' +
                    '<?php echo get_string('scatter_yaxis', 'report_courseradar'); ?>: ' + d.y;
                canvas.style.cursor = 'pointer';
            } else {
                tip.style.display = 'none';
                canvas.style.cursor = 'crosshair';
            }
        });
        canvas.addEventListener('mouseleave', function() { tip.style.display = 'none'; });
        canvas.addEventListener('click', function(e) {
            var d = getHit(canvas, e.clientX, e.clientY);
            if (d) { window.location.href = d.url; }
        });
        window.addEventListener('resize', function() { crDrawScoreDist(); crDrawScatter(); });
        crDrawScoreDist();
    });
})();
/* Al abrir/cerrar el drawer derecho de bloques el ancho del contenido cambia,
   pero Chart.js no se entera. Forzamos un resize tras la transición (0.2s)
   para que el gráfico de actividad se redibuje al nuevo ancho. */
['theme_boost/drawers:shown', 'theme_boost/drawers:hidden'].forEach(function(ev) {
    document.addEventListener(ev, function() {
        setTimeout(function() { window.dispatchEvent(new Event('resize')); }, 250);
    });
});
</script>

<div class="container-fluid px-0">

<!-- ── Cabecera ──────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-start mb-3">
  <div>
    <h2 class="mb-0 fw-bold">
      <?php echo $OUTPUT->pix_icon('i/report', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('pluginname', 'report_courseradar'); ?>
    </h2>
    <p class="text-muted mb-0 small"><?php echo get_string('plugindesc', 'report_courseradar'); ?></p>
    <p class="text-muted mb-0 small fw-semibold">
      <?php echo $OUTPUT->pix_icon('i/calendar', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('analyzingperiod', 'report_courseradar', (object)[
          'from' => $datefromformat,
          'to'   => $datetoformat,
      ]); ?>
    </p>
  </div>
</div>

<!-- ── Filtro de fechas ──────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-end mb-3 gap-2">
  <button type="button"
          class="btn btn-link btn-sm text-muted text-decoration-none p-0"
          onclick="crToggleDateFilter()"
          id="cr-datefilter-toggle">
    <?php echo get_string('adjustperiod', 'report_courseradar'); ?>
    <span id="cr-datefilter-arrow"><?php echo $isfiltered ? '▴' : '▾'; ?></span>
  </button>
</div>
<div id="cr-datefilter-wrap" <?php if (!$isfiltered): ?>style="display:none;"<?php endif; ?>>
  <div class="card cr-card mb-4">
    <div class="card-body py-3">
      <form method="get" action="" class="row g-2 align-items-end">
        <input type="hidden" name="id" value="<?php echo $courseid; ?>">
        <div class="col-sm-4 col-md-3">
          <label for="cr_datefrom" class="form-label mb-1 small fw-semibold">
            <?php echo get_string('datefrom', 'report_courseradar'); ?>
          </label>
          <input type="date" class="form-control form-control-sm" id="cr_datefrom"
                 name="datefrom" value="<?php echo s($datefromformat); ?>">
        </div>
        <div class="col-sm-4 col-md-3">
          <label for="cr_dateto" class="form-label mb-1 small fw-semibold">
            <?php echo get_string('dateto', 'report_courseradar'); ?>
          </label>
          <input type="date" class="form-control form-control-sm" id="cr_dateto"
                 name="dateto" value="<?php echo s($datetoformat); ?>">
        </div>
        <div class="col-sm-4 col-md-2">
          <button type="submit" class="btn btn-primary btn-sm w-100">
            <?php echo get_string('applyfilter', 'report_courseradar'); ?>
          </button>
        </div>
        <?php if ($isfiltered): ?>
        <div class="col-sm-4 col-md-2">
          <a href="<?php echo (new moodle_url('/report/courseradar/index.php', ['id' => $courseid]))->out(false); ?>"
             class="btn btn-outline-secondary btn-sm w-100">
            <?php echo get_string('resetfilter', 'report_courseradar'); ?>
          </a>
        </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<!-- ── Navegación por pestañas ──────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <button class="nav-link active cr-tab-btn" data-tab="cr-tab-overview"
            onclick="crShowTab('cr-tab-overview')">
      <?php echo $OUTPUT->pix_icon('i/dashboard', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('tab_overview', 'report_courseradar'); ?>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link cr-tab-btn" data-tab="cr-tab-resources"
            onclick="crShowTab('cr-tab-resources')">
      <?php echo $OUTPUT->pix_icon('i/course', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('tab_resources', 'report_courseradar'); ?>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link cr-tab-btn" data-tab="cr-tab-students"
            onclick="crShowTab('cr-tab-students')">
      <?php echo $OUTPUT->pix_icon('i/group', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('tab_students', 'report_courseradar'); ?>
    </button>
  </li>
</ul>

<div id="cr-tab-overview" class="cr-tab-panel">
<!-- ── Tarjetas de resumen ───────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg">
    <div class="card cr-card h-100 border-0 border-start border-primary border-4">
      <div class="card-body">
        <div class="cr-stat text-primary"><?php echo $totalmodules; ?></div>
        <div class="cr-stat-label"><?php echo get_string('totalresources', 'report_courseradar'); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg">
    <div class="card cr-card h-100 border-0 border-start border-success border-4">
      <div class="card-body">
        <div class="cr-stat text-success"><?php echo number_format($totalinteractions); ?></div>
        <div class="cr-stat-label"><?php echo get_string('totalinteractions', 'report_courseradar'); ?></div>
        <?php if ($totalstudents > 0 && $totalmodules > 0):
          if ($wkprevinteractions === 0 && $wkcurinteractions === 0):
            $wkiicon = '—'; $wkiclass = 'text-muted'; $wkipct = '';
          elseif ($wkprevinteractions === 0):
            $wkiicon = '↑'; $wkiclass = 'text-success'; $wkipct = '+100%';
          else:
            $wkipct_n = round((($wkcurinteractions - $wkprevinteractions) / $wkprevinteractions) * 100);
            $wkiicon  = $wkipct_n > 0 ? '↑' : ($wkipct_n < 0 ? '↓' : '→');
            $wkiclass = $wkipct_n > 0 ? 'text-success' : ($wkipct_n < 0 ? 'text-danger' : 'text-muted');
            $wkipct   = $wkipct_n > 0 ? '+' . $wkipct_n . '%' : $wkipct_n . '%';
          endif; ?>
        <div class="mt-1">
          <small class="<?php echo $wkiclass; ?> fw-semibold"><?php echo $wkiicon; ?> <?php echo $wkipct; ?></small>
          <small class="text-muted ms-1"><?php echo get_string('weekvspreview', 'report_courseradar'); ?></small>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg">
    <div class="card cr-card h-100 border-0 border-start border-secondary border-4">
      <div class="card-body">
        <div class="cr-stat text-secondary"><?php echo number_format($totalcoursevisits); ?></div>
        <div class="cr-stat-label"><?php echo get_string('coursevisits', 'report_courseradar'); ?></div>
        <?php if ($totalstudents > 0):
          if ($wkprevvisits === 0 && $wkcurvisits === 0):
            $wkvicon = '—'; $wkvclass = 'text-muted'; $wkvpct = '';
          elseif ($wkprevvisits === 0):
            $wkvicon = '↑'; $wkvclass = 'text-success'; $wkvpct = '+100%';
          else:
            $wkvpct_n = round((($wkcurvisits - $wkprevvisits) / $wkprevvisits) * 100);
            $wkvicon  = $wkvpct_n > 0 ? '↑' : ($wkvpct_n < 0 ? '↓' : '→');
            $wkvclass = $wkvpct_n > 0 ? 'text-success' : ($wkvpct_n < 0 ? 'text-danger' : 'text-muted');
            $wkvpct   = $wkvpct_n > 0 ? '+' . $wkvpct_n . '%' : $wkvpct_n . '%';
          endif; ?>
        <div class="mt-1">
          <small class="<?php echo $wkvclass; ?> fw-semibold"><?php echo $wkvicon; ?> <?php echo $wkvpct; ?></small>
          <small class="text-muted ms-1"><?php echo get_string('weekvspreview', 'report_courseradar'); ?></small>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg">
    <div class="card cr-card h-100 border-0 border-start border-info border-4">
      <div class="card-body">
        <div class="cr-stat text-info"><?php echo $avgengagement; ?>%</div>
        <div class="cr-stat-label"><?php echo get_string('avgengagement', 'report_courseradar'); ?></div>
        <?php if ($totalstudents > 0): ?>
        <div class="progress mt-2" style="height:6px;">
          <div class="progress-bar bg-info" style="width:<?php echo $avgengagement; ?>%"></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg">
    <div class="card cr-card h-100 border-0 border-start border-warning border-4">
      <div class="card-body">
        <div class="cr-maxname text-warning"><?php echo $maxname; ?></div>
        <div class="cr-stat-label">
          <?php echo get_string('mostviewed', 'report_courseradar'); ?>
          <?php if ($maxviews > 0): ?>
            <span class="badge bg-warning text-dark ms-1"><?php echo $maxviews; ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Panel de alumnos en riesgo ───────────────────────────────────────── -->
<?php if ($totalstudents > 0 && $totalrisk > 0): ?>
<div class="card cr-card cr-risk-card mb-4">
  <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
    <h5 class="mb-0 fw-bold text-danger">
      <?php echo $OUTPUT->pix_icon('i/warning', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('atrisk', 'report_courseradar'); ?>
      <span class="badge bg-danger text-white ms-2"><?php echo $totalrisk; ?></span>
    </h5>
    <small class="text-muted"><?php echo get_string('atrisk_info', 'report_courseradar'); ?></small>
  </div>
  <div class="card-body">
    <div class="row g-4">

      <?php if (!empty($atrisknone)): ?>
      <div class="col-md-6">
        <h6 class="fw-bold text-danger mb-2">
          <?php echo $OUTPUT->pix_icon('i/invalid', '', 'core', ['class' => 'me-1']); ?>
          <?php echo get_string('atrisk_noactivity', 'report_courseradar'); ?>
          <span class="badge bg-danger text-white ms-1"><?php echo count($atrisknone); ?></span>
        </h6>
        <div class="cr-risk-names">
          <?php foreach ($atrisknone as $uid => $stu): ?>
          <a href="<?php echo (new moodle_url('/user/view.php', ['id' => $uid, 'course' => $courseid]))->out(false); ?>">
            <?php echo fullname($stu); ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($atrisklow)): ?>
      <div class="col-md-6">
        <h6 class="fw-bold text-warning mb-2">
          <?php echo $OUTPUT->pix_icon('i/warning', '', 'core', ['class' => 'me-1']); ?>
          <?php echo get_string('atrisk_lowactivity', 'report_courseradar'); ?>
          <span class="badge bg-warning text-dark ms-1"><?php echo count($atrisklow); ?></span>
        </h6>
        <div class="cr-risk-names">
          <?php foreach ($atrisklow as $uid => $stu): ?>
          <?php
            $lv  = count($studentlog[$uid] ?? []);
            $lpct = $totalmodules > 0 ? round(($lv / $totalmodules) * 100) : 0;
          ?>
          <a href="<?php echo (new moodle_url('/user/view.php', ['id' => $uid, 'course' => $courseid]))->out(false); ?>">
            <?php echo fullname($stu); ?>
            <span class="badge bg-warning text-dark"><?php echo $lpct; ?>%</span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>

  </div>
</div>
<?php endif; ?>

<!-- ── Histograma de puntuación de participación ─────────────────────────── -->
<?php if ($totalstudents > 1): ?>
<div class="card cr-card mb-4">
  <div class="card-header bg-white border-bottom py-3">
    <h5 class="mb-0 fw-bold">
      <?php echo $OUTPUT->pix_icon('i/stats', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('scoredist_title', 'report_courseradar'); ?>
    </h5>
    <small class="text-muted"><?php echo get_string('scoredist_desc', 'report_courseradar'); ?></small>
  </div>
  <div class="card-body">
    <canvas id="cr-scoredist-canvas" style="display:block;width:100%;height:220px;"></canvas>
  </div>
</div>
<?php endif; ?>

<!-- ── Top recursos menos visitados ────────────────────────────────────── -->
<?php if (!empty($topunseen)): ?>
<div class="card cr-card mb-4 border-warning border-2">
  <div class="card-header bg-white border-bottom py-3">
    <h5 class="mb-0 fw-bold text-warning">
      <?php echo $OUTPUT->pix_icon('i/risk_xss', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('topunseen', 'report_courseradar'); ?>
      <span class="badge bg-warning text-dark ms-2"><?php echo count($topunseen); ?></span>
    </h5>
    <small class="text-muted"><?php echo get_string('topunseeninfo', 'report_courseradar'); ?></small>
  </div>
  <?php if (count($topunseentypes) > 1): ?>
  <div class="px-3 pt-3 pb-2 border-bottom d-flex flex-wrap gap-3 align-items-center">
    <small class="text-muted fw-semibold me-1"><?php echo get_string('filterbytype', 'report_courseradar'); ?></small>
    <?php foreach ($topunseentypes as $mod): ?>
    <button type="button"
            class="btn btn-sm btn-warning cr-topunseen-filter-btn cr-type-active cr-badge-mod me-2 mb-1"
            data-modname="<?php echo s($mod); ?>"
            onclick="crFilterTopUnseen(this,'<?php echo s($mod); ?>')">
      <?php echo get_string('modulename', $mod); ?>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0" id="cr-topunseen-table">
        <thead class="table-light">
          <tr>
            <th style="width:2rem">#</th>
            <th><?php echo get_string('resource', 'report_courseradar'); ?></th>
            <th style="min-width:160px"><?php echo get_string('coverage', 'report_courseradar'); ?></th>
            <th class="text-center"><?php echo get_string('uniquestudents', 'report_courseradar'); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($topunseen as $rank => $item): ?>
        <?php
          $cm      = $item['cm'];
          $iconurl = $cm->get_icon_url()->out(false);
          $cmurl   = $cm->url;
          $pct     = $item['pct'];
          $barclass = report_courseradar_barclass($pct);
        ?>
        <tr data-modname="<?php echo s($cm->modname); ?>">
          <td class="text-muted fw-bold"><?php echo $rank + 1; ?></td>
          <td>
            <img src="<?php echo $iconurl; ?>" alt="" style="width:18px;height:18px;" class="me-1">
            <?php if ($cmurl): ?>
              <a href="<?php echo $cmurl->out(false); ?>" target="_blank">
                <?php echo format_string($cm->name); ?>
              </a>
            <?php else: ?>
              <?php echo format_string($cm->name); ?>
            <?php endif; ?>
          </td>
          <td>
            <div class="progress" style="height:18px;" title="<?php echo $pct; ?>%">
              <div class="progress-bar <?php echo $barclass; ?>"
                   style="width:<?php echo $pct; ?>%">
                <?php if ($pct >= 15): echo $pct . '%'; endif; ?>
              </div>
            </div>
            <?php if ($pct < 15): ?>
              <small class="text-muted"><?php echo $pct; ?>%</small>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <span class="fw-bold"><?php echo $item['unique']; ?></span>
            <small class="text-danger ms-1">
              (<?php echo $item['unseen']; ?> <?php echo get_string('notviewed', 'report_courseradar'); ?>)
            </small>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Gráfico de actividad + Heatmap ───────────────────────────────────── -->
<?php if (!empty($chartvalues) || array_sum(array_map('array_sum', $heatmap)) > 0): ?>
<div class="row g-3 mb-4 cr-charts-row">

  <!-- Gráfico temporal -->
  <?php if (!empty($chartvalues)): ?>
  <div class="col-xl-7">
    <div class="card cr-card h-100">
      <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 fw-bold">
          <?php echo get_string('activityovertime', 'report_courseradar'); ?>
          <?php if ($chartweekly): ?>
            <small class="text-muted fw-normal ms-2 small">
              <?php echo get_string('weeklyaggregated', 'report_courseradar'); ?>
            </small>
          <?php endif; ?>
        </h5>
        <small class="text-muted"><?php echo get_string('activityovertime_desc', 'report_courseradar'); ?></small>
      </div>
      <div class="card-body">
        <?php echo $OUTPUT->render($chartobj); ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Heatmap -->
  <div class="col-xl-<?php echo !empty($chartvalues) ? '5' : '12'; ?>">
    <div class="card cr-card h-100">
      <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 fw-bold">
          <?php echo get_string('activitypattern', 'report_courseradar'); ?>
        </h5>
        <small class="text-muted"><?php echo get_string('activitypattern_desc', 'report_courseradar'); ?></small>
      </div>
      <div class="card-body p-3 overflow-auto">
        <table class="cr-heatmap">
          <thead>
            <tr>
              <th></th>
              <?php foreach ($timeslots as $slot): ?>
              <th><?php echo $slot; ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dayorder as $dow): ?>
            <tr>
              <th class="text-end pe-2"><?php echo $daynames[$dow]; ?></th>
              <?php for ($b = 0; $b < 6; $b++): ?>
              <?php
                $val      = $heatmap[$dow][$b];
                $intensity = round($val / $heatmax, 2);
                $bg = $val > 0
                    ? 'background:rgba(13,110,253,' . $intensity . ');'
                    : 'background:#f0f2f5;';
                $color = $intensity > 0.5 ? 'color:#fff;' : '';
              ?>
              <td style="<?php echo $bg . $color . ($val === 0 ? 'cursor:default;' : ''); ?>"
                  title="<?php echo s($daynames[$dow] . ' ' . $timeslots[$b] . ': ' . $val); ?>"
                  <?php if ($val > 0): ?>
                  data-label="<?php echo s($daynames[$dow] . ' ' . $timeslots[$b]); ?>"
                  data-students="<?php echo s(json_encode($heatstudents[$dow][$b])); ?>"
                  onclick="crHeatmapClick(this)"
                  <?php endif; ?>>
                <?php echo $val > 0 ? $val : ''; ?>
              </td>
              <?php endfor; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div id="cr-heatmap-panel" class="cr-heatmap-panel mt-3 d-none">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <strong id="cr-heatmap-panel-title" class="small"></strong>
            <button type="button" class="btn-close" style="font-size:.7rem;"
                    onclick="crHeatmapClose()"></button>
          </div>
          <div id="cr-heatmap-panel-body" class="d-flex flex-wrap gap-2"></div>
        </div>
      </div>
    </div>
  </div>

</div>
<?php endif; ?>

</div><!-- /cr-tab-overview -->

<div id="cr-tab-resources" class="cr-tab-panel" style="display:none;">
<!-- ── Resumen por tipo de módulo ──────────────────────────────────────────-->
<?php if (!empty($bytype)): ?>
<div class="card cr-card mb-4">
  <div class="card-header bg-white border-bottom py-3">
    <h5 class="mb-0 fw-bold">
      <?php echo $OUTPUT->pix_icon('i/stats', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('moduletypesummary', 'report_courseradar'); ?>
    </h5>
    <small class="text-muted"><?php echo get_string('moduletypesummary_desc', 'report_courseradar'); ?></small>
  </div>
  <div class="card-body">
    <?php foreach ($bytype as $mod => $data): ?>
    <?php
      $pct = (int)round(($data['views'] / $typemaxviews) * 100);
      $avg = $data['modules'] > 0 ? round($data['views'] / $data['modules'], 1) : 0;
    ?>
    <div class="d-flex align-items-center mb-2 gap-2">
      <div style="width:90px; flex-shrink:0; text-align:right;">
        <span class="badge bg-light text-dark border cr-badge-mod"><?php echo get_string('modulename', $mod); ?></span>
      </div>
      <div class="flex-grow-1">
        <div class="progress" style="height:22px;">
          <div class="progress-bar bg-primary"
               role="progressbar"
               style="width:<?php echo $pct; ?>%; min-width:<?php echo $data['views'] > 0 ? '2rem' : '0'; ?>;"
               title="<?php echo $data['views']; ?> <?php echo get_string('totalviews', 'report_courseradar'); ?>">
            <?php if ($pct >= 15): ?>
              <?php echo $data['views']; ?> <?php echo get_string('times', 'report_courseradar'); ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div style="width:80px; flex-shrink:0;" class="text-end">
        <small class="fw-bold"><?php echo $data['views']; ?></small>
        <small class="text-muted ms-1">(<?php echo $data['modules']; ?> <?php echo get_string('modules', 'report_courseradar'); ?>)</small>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Tabla de recursos por sección ──────────────────────────────────────-->
<div class="card cr-card mb-4">
  <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
    <div>
      <h5 class="mb-0 fw-bold">
        <?php echo $OUTPUT->pix_icon('i/course', '', 'core', ['class' => 'me-1']); ?>
        <?php echo get_string('resourceactivity', 'report_courseradar'); ?>
        <span class="badge bg-secondary ms-2"><?php echo $totalmodules; ?></span>
      </h5>
      <small class="text-muted"><?php echo get_string('resourceactivity_desc', 'report_courseradar'); ?></small>
    </div>
    <div class="d-flex align-items-center gap-3">
      <?php if ($hashidden): ?>
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" id="cr-show-hidden" onchange="crToggleHidden()">
        <label class="form-check-label small text-muted" for="cr-show-hidden">
          <?php echo get_string('showhidden', 'report_courseradar'); ?>
        </label>
      </div>
      <?php endif; ?>
      <span id="cr-sort-notice" class="text-muted small">
        <a href="javascript:crResetSort()" class="btn btn-sm btn-outline-secondary">
          <?php echo get_string('resetsort', 'report_courseradar'); ?>
        </a>
      </span>
    </div>
  </div>
  <?php if (count($bytype) > 1): ?>
  <div class="px-3 pt-3 pb-2 border-bottom d-flex flex-wrap gap-3 align-items-center">
    <small class="text-muted fw-semibold me-1"><?php echo get_string('filterbytype', 'report_courseradar'); ?></small>
    <?php foreach (array_keys($bytype) as $mod): ?>
    <button type="button"
            class="btn btn-sm btn-primary cr-type-filter-btn cr-type-active cr-badge-mod me-2 mb-1"
            data-modname="<?php echo s($mod); ?>"
            onclick="crFilterType(this)">
      <?php echo get_string('modulename', $mod); ?>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="cr-resources-table">
        <thead class="table-light border-bottom border-2">
          <tr>
            <th class="cr-th-sort" onclick="crSortResources(this,false)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('resource', 'report_courseradar'); ?>
            </th>
            <th class="cr-th-sort" onclick="crSortResources(this,false)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('type', 'report_courseradar'); ?>
            </th>
            <th class="text-center cr-th-sort" onclick="crSortResources(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('totalviews', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('totalviews_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <th class="text-center cr-th-sort" onclick="crSortResources(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('uniquestudents', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('uniquestudents_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <th class="cr-th-sort" style="min-width:160px" onclick="crSortResources(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('coverage', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('coverage_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <?php if ($hasanycompletion): ?>
            <th class="text-center cr-th-sort" onclick="crSortResources(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('completion', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('completion_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <?php endif; ?>
            <th class="cr-th-sort" onclick="crSortResources(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('lastaccess', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('lastaccess_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <th class="text-center"><?php echo get_string('details', 'report_courseradar'); ?></th>
          </tr>
        </thead>
        <tbody>

<?php if (empty($validcms)): ?>
          <tr>
            <td colspan="<?php echo $rescols; ?>" class="text-center text-muted py-5">
              <?php echo get_string('nointeractions', 'report_courseradar'); ?>
            </td>
          </tr>
<?php else: ?>

<?php foreach ($bysection as $snum => $section): ?>
          <tr class="cr-section-row" data-sectionid="<?php echo $snum; ?>">
            <td colspan="<?php echo $rescols; ?>">
              <?php echo $OUTPUT->pix_icon('i/folder', '', 'core', ['class' => 'me-1']); ?>
              <?php echo $section['name']; ?>
            </td>
          </tr>

  <?php foreach ($section['cms'] as $cm): ?>
  <?php
    $cmid     = $cm->id;
    $views    = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->totalviews  : 0;
    $unique   = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->uniqueusers : 0;
    $lastts   = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->lastaccess  : 0;
    $last     = $lastts ? userdate($lastts, get_string('strftimedate', 'langconfig')) : '—';
    $pct      = ($totalstudents > 0) ? min(100, round(($unique / $totalstudents) * 100)) : 0;
    $notseen  = max(0, $totalstudents - $unique);
    $barclass = report_courseradar_barclass($pct);
    $iconurl  = $cm->get_icon_url()->out(false);
    $cmurl    = $cm->url;
    $detailid = 'crdetail_' . $cmid;

    // Finalización
    $hastracking = $hasanycompletion && ($cm->completion > 0);
    $cmplcount   = $hastracking ? (int)($completions[$cmid] ?? 0) : 0;
    $cmplpct     = ($hastracking && $totalstudents > 0) ? min(100, round(($cmplcount / $totalstudents) * 100)) : -1;
  ?>
          <tr class="cr-resource-row<?php echo !$cm->visible ? ' text-muted' : ''; ?>"
              data-detail="<?php echo $detailid; ?>"
              data-modname="<?php echo s($cm->modname); ?>"
              data-section="<?php echo $snum; ?>"
              <?php if (!$cm->visible): ?>data-hidden="1"<?php endif; ?>>

            <!-- Nombre -->
            <td data-sort="<?php echo s(format_string($cm->name)); ?>">
              <img src="<?php echo $iconurl; ?>" alt="" style="width:20px;height:20px;" class="me-1">
              <?php if ($cmurl): ?>
                <a href="<?php echo $cmurl->out(false); ?>" target="_blank"
                   class="<?php echo !$cm->visible ? 'text-muted text-decoration-line-through' : ''; ?>">
                  <?php echo format_string($cm->name); ?>
                </a>
              <?php else: ?>
                <span class="<?php echo !$cm->visible ? 'text-muted text-decoration-line-through' : ''; ?>">
                  <?php echo format_string($cm->name); ?>
                </span>
              <?php endif; ?>
              <?php if (!$cm->visible): ?>
                <span class="badge bg-secondary ms-1 cr-badge-mod">
                  <?php echo get_string('hidden', 'report_courseradar'); ?>
                </span>
              <?php endif; ?>
            </td>

            <!-- Tipo -->
            <td data-sort="<?php echo s(get_string('modulename', $cm->modname)); ?>">
              <span class="badge bg-light text-dark border cr-badge-mod"><?php echo get_string('modulename', $cm->modname); ?></span>
            </td>

            <!-- Vistas totales -->
            <td class="text-center fw-bold <?php echo $views === 0 ? 'cr-zero' : ''; ?>"
                data-sort="<?php echo $views; ?>">
              <?php echo $views; ?>
            </td>

            <!-- Estudiantes únicos -->
            <td class="text-center" data-sort="<?php echo $unique; ?>">
              <span class="fw-semibold <?php echo ($unique === $totalstudents && $totalstudents > 0) ? 'text-success' : ''; ?>">
                <?php echo $unique; ?>/<?php echo $totalstudents; ?>
              </span>
              <?php if ($notseen > 0): ?>
              <br><small class="text-danger"><?php echo $notseen; ?> <?php echo get_string('notviewed', 'report_courseradar'); ?></small>
              <?php endif; ?>
            </td>

            <!-- Cobertura -->
            <td data-sort="<?php echo $pct; ?>">
              <div class="progress" title="<?php echo $pct; ?>%">
                <div class="progress-bar <?php echo $barclass; ?>"
                     role="progressbar" style="width:<?php echo $pct; ?>%"
                     aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100">
                  <?php if ($pct >= 15): echo $pct . '%'; endif; ?>
                </div>
              </div>
              <?php if ($pct < 15): ?><small class="text-muted"><?php echo $pct; ?>%</small><?php endif; ?>
            </td>

            <!-- Finalización (solo si el curso la tiene habilitada) -->
            <?php if ($hasanycompletion): ?>
            <td class="text-center" data-sort="<?php echo $cmplpct >= 0 ? $cmplpct : -1; ?>">
              <?php if (!$hastracking): ?>
                <small class="text-muted">—</small>
              <?php else: ?>
                <span class="fw-semibold <?php echo $cmplcount === $totalstudents ? 'text-success' : ''; ?>">
                  <?php echo $cmplcount; ?>/<?php echo $totalstudents; ?>
                </span>
                <br>
                <div class="progress mt-1" style="height:8px;" title="<?php echo $cmplpct; ?>%">
                  <div class="progress-bar <?php echo report_courseradar_barclass($cmplpct); ?>"
                       style="width:<?php echo $cmplpct; ?>%"></div>
                </div>
              <?php endif; ?>
            </td>
            <?php endif; ?>

            <!-- Último acceso -->
            <td data-sort="<?php echo $lastts; ?>">
              <small class="text-muted"><?php echo $last; ?></small>
            </td>

            <!-- Botón detalle -->
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary"
                      type="button"
                      onclick="crToggle(this,'<?php echo $detailid; ?>')"
                      title="<?php echo get_string('details', 'report_courseradar'); ?>">
                <?php echo $OUTPUT->pix_icon('i/group', '', 'core'); ?>
              </button>
            </td>
          </tr>

          <!-- Fila de detalle -->
          <tr id="<?php echo $detailid; ?>" class="cr-detail-row" style="display:none;">
            <td colspan="<?php echo $rescols; ?>">
              <div class="cr-detail-inner p-3">
                <div class="row g-3">

                  <!-- Quién sí ha visto -->
                  <div class="col-md-6">
                    <h6 class="text-success fw-bold mb-2">
                      <?php echo $OUTPUT->pix_icon('i/valid', '', 'core', ['class' => 'me-1']); ?>
                      <?php echo get_string('haveviewed', 'report_courseradar'); ?>
                      <span class="badge bg-success text-white ms-1"><?php echo $unique; ?></span>
                    </h6>
                    <?php if ($unique > 0): ?>
                    <div class="cr-seen-list">
                      <ul class="list-unstyled mb-0">
                        <?php foreach ($students as $uid => $stu): ?>
                        <?php if (isset($bycm[$cmid][$uid])): ?>
                        <?php $urow = $bycm[$cmid][$uid]; ?>
                        <li class="d-flex align-items-center py-1 border-bottom border-light gap-2">
                          <a href="<?php echo (new moodle_url('/user/view.php', ['id' => $uid, 'course' => $courseid]))->out(false); ?>"
                             class="flex-grow-1 text-truncate">
                            <?php echo fullname($stu); ?>
                          </a>
                          <?php if ($hastracking && isset($completionbyuser[$cmid][$uid])): ?>
                            <span class="badge bg-success cr-badge-mod" title="<?php echo get_string('completion', 'report_courseradar'); ?>">✓</span>
                          <?php endif; ?>
                          <span class="badge bg-primary cr-student-badge">
                            <?php echo $urow->views; ?> <?php echo get_string('times', 'report_courseradar'); ?>
                          </span>
                          <small class="text-muted text-nowrap">
                            <?php echo userdate($urow->lastaccess, get_string('strftimedatetimeshort', 'langconfig')); ?>
                          </small>
                        </li>
                        <?php endif; ?>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                    <?php else: ?>
                    <p class="text-muted small mb-0"><?php echo get_string('noviewsyet', 'report_courseradar'); ?></p>
                    <?php endif; ?>
                  </div>

                  <!-- Quién NO ha visto -->
                  <div class="col-md-6">
                    <h6 class="text-danger fw-bold mb-2">
                      <?php echo $OUTPUT->pix_icon('i/invalid', '', 'core', ['class' => 'me-1']); ?>
                      <?php echo get_string('haventviewed', 'report_courseradar'); ?>
                      <span class="badge bg-danger text-white ms-1"><?php echo $notseen; ?></span>
                    </h6>
                    <?php if ($notseen > 0): ?>
                    <div class="cr-seen-list">
                      <ul class="list-unstyled mb-0">
                        <?php foreach ($students as $uid => $stu): ?>
                        <?php if (!isset($bycm[$cmid][$uid])): ?>
                        <li class="d-flex align-items-center py-1 border-bottom border-light gap-2">
                          <a href="<?php echo (new moodle_url('/user/view.php', ['id' => $uid, 'course' => $courseid]))->out(false); ?>"
                             class="flex-grow-1 text-truncate">
                            <?php echo fullname($stu); ?>
                          </a>
                          <?php if ($hastracking && isset($completionbyuser[$cmid][$uid])): ?>
                            <span class="badge bg-success cr-badge-mod" title="<?php echo get_string('completion', 'report_courseradar'); ?>">✓ completado</span>
                          <?php endif; ?>
                        </li>
                        <?php endif; ?>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                    <?php else: ?>
                    <p class="text-success small mb-0 fw-semibold">
                      <?php echo get_string('allstudentsviewed', 'report_courseradar'); ?>
                    </p>
                    <?php endif; ?>
                  </div>

                </div>
              </div>
            </td>
          </tr>

  <?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>

        </tbody>
      </table>
    </div>
  </div>
</div><!-- /card recursos -->

</div><!-- /cr-tab-resources -->

<div id="cr-tab-students" class="cr-tab-panel" style="display:none;">
<?php if ($totalstudents > 1): ?>
<!-- ── Scatter plot: recursos vs. participación ───────────────────────────── -->
<div class="card cr-card mb-4">
  <div class="card-header bg-white border-bottom py-3">
    <h5 class="mb-0 fw-bold">
      <?php echo $OUTPUT->pix_icon('i/stats', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('scatter_title', 'report_courseradar'); ?>
    </h5>
    <small class="text-muted"><?php echo get_string('scatter_desc', 'report_courseradar'); ?></small>
  </div>
  <div class="card-body cr-scatter-wrap">
    <canvas id="cr-scatter-canvas" style="display:block;width:100%;height:320px;cursor:crosshair;"></canvas>
    <details class="cr-score-help mt-3">
      <summary><?php echo get_string('scorehelp_title', 'report_courseradar'); ?></summary>
      <div class="small text-muted mt-2">
        <p class="mb-2"><?php echo get_string('scorehelp_factors', 'report_courseradar'); ?></p>
        <ul class="mb-2 ps-3">
          <li><?php echo get_string('scorehelp_resources', 'report_courseradar'); ?></li>
          <li><?php echo get_string('scorehelp_recency', 'report_courseradar'); ?></li>
          <?php if ($hasanycompletion && $totaltracked > 0): ?>
          <li><?php echo get_string('scorehelp_completion', 'report_courseradar'); ?></li>
          <?php endif; ?>
        </ul>
        <div class="cr-score-formula">
          <span class="fw-semibold"><?php echo get_string('scorehelp_formula', 'report_courseradar'); ?>:</span>
          <code><?php echo ($hasanycompletion && $totaltracked > 0)
              ? get_string('scorehelp_formula_full', 'report_courseradar')
              : get_string('scorehelp_formula_basic', 'report_courseradar'); ?></code>
        </div>
      </div>
    </details>
  </div>
</div>
<div id="cr-scatter-tip"></div>
<?php endif; ?>
<!-- ── Tabla de actividad por estudiante ─────────────────────────────────── -->
<div class="card cr-card mb-4">
  <div class="card-header bg-white border-bottom py-3">
    <h5 class="mb-0 fw-bold">
      <?php echo $OUTPUT->pix_icon('i/group', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('studentengagement', 'report_courseradar'); ?>
      <span class="badge bg-secondary ms-2"><?php echo $totalstudents; ?></span>
    </h5>
    <small class="text-muted"><?php echo get_string('studentengagement_desc', 'report_courseradar'); ?></small>
  </div>
  <?php if ($totalstudents > 5): ?>
  <div class="px-3 pt-3 pb-2 border-bottom">
    <input type="search" class="form-control form-control-sm" id="cr-student-search"
           placeholder="<?php echo get_string('searchstudent', 'report_courseradar'); ?>"
           oninput="crSearchStudents(this.value)">
  </div>
  <?php endif; ?>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="cr-students-table">
        <thead class="table-light border-bottom border-2">
          <tr>
            <th class="cr-th-sort" onclick="crSortStudents(this,false)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('student', 'report_courseradar'); ?>
            </th>
            <th class="text-center cr-th-sort" onclick="crSortStudents(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('resourcesvisited', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('resourcesvisited_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <th class="cr-th-sort" style="min-width:160px" onclick="crSortStudents(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('coverage', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('studentcoverage_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <th class="text-center cr-th-sort" onclick="crSortStudents(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('totalviews', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('studentviews_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <th class="text-center cr-th-sort" onclick="crSortStudents(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('coursevisits', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('coursevisits_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <th class="cr-th-sort" onclick="crSortStudents(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('lastcoursevisit', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('lastcoursevisit_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <th class="cr-th-sort" onclick="crSortStudents(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('lastactivity', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('lastactivity_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <th class="cr-th-sort text-center" onclick="crSortStudents(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('daysinactive', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('daysinactive_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <?php if ($hasanycompletion): ?>
            <th class="cr-th-sort text-center" onclick="crSortStudents(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('completionstu', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('completionstu_desc', 'report_courseradar'); ?>
              </small>
            </th>
            <?php endif; ?>
            <th class="cr-th-sort text-center" onclick="crSortStudents(this,true)"
                title="<?php echo get_string('sortby', 'report_courseradar'); ?>">
              <?php echo get_string('riskscore', 'report_courseradar'); ?>
              <small class="d-block fw-normal" style="font-size:.7rem;color:#6c757d;">
                <?php echo get_string('riskscore_desc', 'report_courseradar'); ?>
              </small>
            </th>
          </tr>
        </thead>
        <tbody>

<?php if (empty($students)): ?>
          <tr>
            <td colspan="<?php echo $stucols; ?>" class="text-center text-muted py-5">
              <?php echo get_string('nostudents', 'report_courseradar'); ?>
            </td>
          </tr>
<?php else: ?>

  <?php foreach ($students as $uid => $stu): ?>
  <?php
    $visited     = isset($studentlog[$uid]) ? count($studentlog[$uid]) : 0;
    $totalv      = isset($studentlog[$uid]) ? array_sum($studentlog[$uid]) : 0;
    $pctstu      = ($totalmodules > 0) ? min(100, round(($visited / $totalmodules) * 100)) : 0;
    $barstu      = report_courseradar_barclass($pctstu);
    $isinactive  = ($totalv === 0);
    $studetailid = 'crstudetail_' . $uid;

    $lastact = 0;
    if (isset($studentlog[$uid])) {
        foreach (array_keys($studentlog[$uid]) as $cid) {
            if (isset($bycm[$cid][$uid]) && (int)$bycm[$cid][$uid]->lastaccess > $lastact) {
                $lastact = (int)$bycm[$cid][$uid]->lastaccess;
            }
        }
    }
  ?>
          <tr class="cr-student-row" style="cursor:pointer;"
              data-detail="<?php echo $studetailid; ?>"
              onclick="crToggle(this,'<?php echo $studetailid; ?>')">

            <td data-sort="<?php echo s($stu->lastname . ' ' . $stu->firstname); ?>">
              <a href="<?php echo (new moodle_url('/user/view.php', ['id' => $uid, 'course' => $courseid]))->out(false); ?>"
                 onclick="event.stopPropagation();">
                <?php echo fullname($stu); ?>
              </a>
              <?php if ($isinactive): ?>
                <span class="badge bg-danger ms-1 cr-badge-mod">0</span>
              <?php endif; ?>
            </td>

            <td class="text-center"
                data-sort="<?php echo $visited; ?>">
              <span class="fw-semibold <?php echo $visited === 0 ? 'cr-zero' : ($visited === $totalmodules ? 'text-success' : ''); ?>">
                <?php echo $visited; ?>/<?php echo $totalmodules; ?>
              </span>
            </td>

            <td data-sort="<?php echo $pctstu; ?>">
              <div class="progress" title="<?php echo $pctstu; ?>%">
                <div class="progress-bar <?php echo $barstu; ?>"
                     role="progressbar" style="width:<?php echo $pctstu; ?>%"
                     aria-valuenow="<?php echo $pctstu; ?>" aria-valuemin="0" aria-valuemax="100">
                  <?php if ($pctstu >= 15): echo $pctstu . '%'; endif; ?>
                </div>
              </div>
              <?php if ($pctstu < 15): ?><small class="text-muted"><?php echo $pctstu; ?>%</small><?php endif; ?>
            </td>

            <td class="text-center fw-bold <?php echo $totalv === 0 ? 'cr-zero' : ''; ?>"
                data-sort="<?php echo $totalv; ?>">
              <?php echo $totalv; ?>
            </td>

            <?php $cvisits = $coursevisits[$uid] ?? 0; ?>
            <td class="text-center fw-bold <?php echo $cvisits === 0 ? 'cr-zero' : ''; ?>"
                data-sort="<?php echo $cvisits; ?>">
              <?php echo $cvisits; ?>
            </td>

            <?php $lastcv = $lastcoursevisit[$uid] ?? 0; ?>
            <td data-sort="<?php echo $lastcv; ?>">
              <small class="text-muted">
                <?php echo $lastcv
                    ? userdate($lastcv, get_string('strftimedatetimeshort', 'langconfig'))
                    : get_string('never'); ?>
              </small>
            </td>

            <td data-sort="<?php echo $lastact; ?>">
              <small class="text-muted">
                <?php echo $lastact
                    ? userdate($lastact, get_string('strftimedatetimeshort', 'langconfig'))
                    : get_string('never'); ?>
              </small>
            </td>

            <?php
              $days  = $daysinactive[$uid];
              $badge = report_courseradar_inactive_class($days);
            ?>
            <td class="text-center" data-sort="<?php echo $days; ?>">
              <?php if ($days < 0): ?>
                <span class="badge <?php echo $badge; ?>">
                  <?php echo get_string('neveraccessed', 'report_courseradar'); ?>
                </span>
              <?php elseif ($days === 0): ?>
                <span class="badge bg-success text-white">
                  &lt; 1
                </span>
              <?php else: ?>
                <span class="badge <?php echo $badge; ?>">
                  <?php echo $days; ?>d
                </span>
              <?php endif; ?>
            </td>

            <?php if ($hasanycompletion):
              $stucomp = $completedbystu[$uid] ?? 0;
              $compclass = ($totaltracked > 0 && $stucomp === $totaltracked)
                  ? 'text-success'
                  : ($stucomp > 0 ? 'text-warning' : 'cr-zero');
            ?>
            <td class="text-center fw-semibold <?php echo $compclass; ?>"
                data-sort="<?php echo $stucomp; ?>">
              <?php echo $stucomp; ?>/<?php echo $totaltracked; ?>
            </td>
            <?php endif; ?>

            <?php
              $rscore = $riskscores[$uid];
              $rscoreclass = $rscore >= 75 ? 'bg-success' : ($rscore >= 50 ? 'bg-warning text-dark' : 'bg-danger');
            ?>
            <td class="text-center" data-sort="<?php echo $rscore; ?>">
              <span class="badge <?php echo $rscoreclass; ?>"><?php echo $rscore; ?></span>
            </td>

          </tr>

          <!-- Detalle del estudiante -->
          <tr id="<?php echo $studetailid; ?>" class="cr-detail-row" style="display:none;">
            <td colspan="<?php echo $stucols; ?>">
              <div class="cr-detail-inner p-3">
                <div class="d-flex gap-3 mb-3 flex-wrap">
                  <small class="text-muted d-flex align-items-center gap-1"><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#0d6efd22;border:1px solid #0d6efd55;flex-shrink:0;"></span><?php echo get_string('haveviewed', 'report_courseradar'); ?></small>
                  <small class="text-muted d-flex align-items-center gap-1"><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#19875422;border:1px solid #19875455;flex-shrink:0;"></span><?php echo get_string('completion', 'report_courseradar'); ?></small>
                  <small class="text-muted d-flex align-items-center gap-1"><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#f0f2f5;border:1px solid #dee2e6;flex-shrink:0;"></span><?php echo get_string('haventviewed', 'report_courseradar'); ?></small>
                </div>
                <?php foreach ($bysection as $snum => $section): ?>
                <?php
                  $secvisited = [];
                  $secunseen  = [];
                  foreach ($section['cms'] as $cm) {
                      $cmid = $cm->id;
                      if (isset($studentlog[$uid][$cmid])) {
                          $secvisited[] = $cm;
                      } else {
                          $secunseen[] = $cm;
                      }
                  }
                ?>
                <div class="mb-3">
                  <small class="text-muted fw-semibold d-block mb-1"><?php echo $section['name']; ?></small>
                  <div class="d-flex align-items-start">
                    <div class="cr-act-grid me-2 pe-2 border-end">
                      <?php foreach ($secvisited as $cm): ?>
                      <?php
                        $cmid = $cm->id;
                        $svcount = $studentlog[$uid][$cmid] ?? 0;
                        $completed_stu = $completionenabled && $cm->completion > 0
                            && isset($completionbyuser[$cmid][$uid]);
                        $bgcol = $completed_stu ? '#198754' : '#0d6efd';
                        $title = s(format_string($cm->name)) . ' (' . $svcount . ' ' . get_string('times', 'report_courseradar') . ')';
                        if ($completed_stu) { $title .= ' ✓'; }
                      ?>
                      <span class="cr-act-icon <?php echo $completed_stu ? 'cr-act-done' : ''; ?>"
                            style="background:<?php echo $bgcol; ?>22;border:1px solid <?php echo $bgcol; ?>55;"
                            title="<?php echo $title; ?>">
                        <img src="<?php echo $cm->get_icon_url()->out(false); ?>" alt="">
                        <?php if ($svcount > 1): ?>
                        <span class="cr-act-cnt <?php echo $completed_stu ? 'bg-success' : ''; ?>"><?php echo $svcount; ?></span>
                        <?php endif; ?>
                      </span>
                      <?php endforeach; ?>
                      <?php if (empty($secvisited)): ?>
                        <small class="text-muted fst-italic"><?php echo get_string('noviewsyet', 'report_courseradar'); ?></small>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($secunseen)): ?>
                    <div class="cr-act-grid ps-2">
                      <?php foreach ($secunseen as $cm): ?>
                      <span class="cr-act-icon"
                            style="background:#f0f2f5;border:1px solid #dee2e6;"
                            title="<?php echo s(format_string($cm->name)); ?>">
                        <img src="<?php echo $cm->get_icon_url()->out(false); ?>" alt="" style="opacity:.4;">
                      </span>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
                <?php if (!empty($sparklines[$uid])): ?>
                <?php
                  $spbars  = $sparklines[$uid];
                  $spcount = count($spbars);
                  $splast  = $spbars[$spcount - 1]['cnt'];
                  $spprev  = $spcount > 1 ? $spbars[$spcount - 2]['cnt'] : 0;
                  if ($spprev === 0 && $splast === 0):
                    $spicon = '—'; $spclass = 'text-muted'; $sppctstr = '';
                  elseif ($spprev === 0):
                    $spicon = '↑'; $spclass = 'text-success'; $sppctstr = '+100%';
                  else:
                    $sppct_n = round((($splast - $spprev) / $spprev) * 100);
                    $spicon  = $sppct_n > 0 ? '↑' : ($sppct_n < 0 ? '↓' : '→');
                    $spclass = $sppct_n > 0 ? 'text-success' : ($sppct_n < 0 ? 'text-danger' : 'text-muted');
                    $sppctstr = $sppct_n > 0 ? '+' . $sppct_n . '%' : $sppct_n . '%';
                  endif;
                ?>
                <div class="mt-3">
                  <small class="text-muted fw-semibold d-block mb-1">
                    <?php echo get_string('weeklyactivity', 'report_courseradar'); ?>
                    <span class="<?php echo $spclass; ?> ms-2"><?php echo $spicon; ?> <?php echo $sppctstr; ?></span>
                    <span class="text-muted ms-1"><?php echo get_string('weekvspreview', 'report_courseradar'); ?></span>
                  </small>
                  <div class="cr-sparkline">
                    <?php foreach ($spbars as $bar): ?>
                    <div class="cr-spark-bar"
                         style="height:<?php echo $bar['height']; ?>%"
                         title="<?php echo s($bar['label']); ?>: <?php echo $bar['cnt']; ?> <?php echo get_string('times', 'report_courseradar'); ?>">
                    </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </td>
          </tr>

  <?php endforeach; ?>
<?php endif; ?>

        </tbody>
      </table>
    </div>
  </div>
</div><!-- /card estudiantes -->
</div><!-- /cr-tab-students -->

</div><!-- /container -->
<?php echo $OUTPUT->footer(); ?>
