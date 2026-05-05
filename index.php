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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/report/courseradar/locallib.php');

// Parameters.
$courseid    = required_param('id', PARAM_INT);
$datefromstr = optional_param('datefrom', '', PARAM_ALPHANUMEXT);
$datetostr   = optional_param('dateto', '', PARAM_ALPHANUMEXT);

// Course and context.
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('report/courseradar:view', $context);

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
    if (!$cm->deletioninprogress) {
        $validcms[$cm->id] = $cm;
    }
}
$totalmodules = count($validcms);

// Enrolled users: separate students from teachers/managers.
$students      = report_courseradar_get_students($context);
$totalstudents = count($students);
$studentids    = array_keys($students);

// Log queries.
$logdata    = []; // Keyed by cmid: totalviews, uniqueusers, lastaccess.
$bycm       = []; // Keyed [cmid][uid]: views, lastaccess.
$studentlog = []; // Keyed [uid][cmid]: view count.
$byday      = []; // Keyed by Y-m-d date string: interaction count.
$heatmap    = array_fill(0, 7, array_fill(0, 6, 0)); // Dow 0=Sun to 6=Sat, 4-hour blocks 0-5.

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
            'label'  => date('d M', $w),
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
$topunseen = report_courseradar_top_unseen($validcms, $logdata, $totalstudents);

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
            $chartlabels[] = date('d M', strtotime($wk));
            $chartvalues[] = $cnt;
        }
    } else {
        for ($d = $datefrom; $d <= $dateto; $d += DAYSECS) {
            $chartlabels[] = date('d M', $d);
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

// Number of resource table columns (varies when completion is enabled).
$rescols = $hasanycompletion ? 8 : 7;

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
.cr-zero              { color: #adb5bd; }
/* Alumnos en riesgo */
.cr-risk-card         { border-left: 4px solid #dc3545 !important; }
.cr-risk-names        { max-height: 180px; overflow-y: auto; columns: 2; column-gap: 1rem; }
.cr-risk-names a      { display: block; font-size: .85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* Heatmap */
.cr-heatmap           { border-collapse: separate; border-spacing: 3px; font-size: .78rem; width: 100%; }
.cr-heatmap th        { font-weight: 600; padding: 3px 6px; text-align: center; white-space: nowrap; }
.cr-heatmap td        { border-radius: 4px; padding: 5px 4px; text-align: center; min-width: 36px; cursor: default; }
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
</style>
<script>
/* ── Inicializar tooltips Bootstrap ───────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        new bootstrap.Tooltip(el);
    });
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
function crFilterTopUnseen(btn, modname) {
    var wasActive = btn.classList.contains('cr-type-active');
    if (wasActive) {
        btn.classList.remove('cr-type-active', 'btn-warning');
        btn.classList.add('btn-outline-secondary');
    } else {
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('cr-type-active', 'btn-warning');
    }

    var hiddenTypes = [];
    document.querySelectorAll('.cr-topunseen-filter-btn').forEach(function(b) {
        if (!b.classList.contains('cr-type-active')) { hiddenTypes.push(b.dataset.modname); }
    });

    var tbody = document.querySelector('#cr-topunseen-table tbody');
    tbody.querySelectorAll('tr[data-modname]').forEach(function(row) {
        row.style.display = hiddenTypes.indexOf(row.dataset.modname) >= 0 ? 'none' : '';
    });
}

/* ── Filtro de tipos de recurso ────────────────────────────────────────────── */
function crFilterType(btn, modname) {
    var wasActive = btn.classList.contains('cr-type-active');
    if (wasActive) {
        btn.classList.remove('cr-type-active', 'btn-primary');
        btn.classList.add('btn-outline-secondary');
    } else {
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('cr-type-active', 'btn-primary');
    }

    var hiddenTypes = [];
    document.querySelectorAll('.cr-type-filter-btn').forEach(function(b) {
        if (!b.classList.contains('cr-type-active')) { hiddenTypes.push(b.dataset.modname); }
    });

    var tbody = document.querySelector('#cr-resources-table tbody');
    tbody.querySelectorAll('tr.cr-resource-row').forEach(function(row) {
        var hide = hiddenTypes.indexOf(row.dataset.modname) >= 0;
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
  </div>
</div>

<!-- ── Filtro de fechas ──────────────────────────────────────────────────── -->
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

<!-- ── Tarjetas de resumen ───────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card cr-card h-100 border-0 border-start border-primary border-4">
      <div class="card-body">
        <div class="cr-stat text-primary"><?php echo $totalmodules; ?></div>
        <div class="cr-stat-label"><?php echo get_string('totalresources', 'report_courseradar'); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card cr-card h-100 border-0 border-start border-success border-4">
      <div class="card-body">
        <div class="cr-stat text-success"><?php echo number_format($totalinteractions); ?></div>
        <div class="cr-stat-label"><?php echo get_string('totalinteractions', 'report_courseradar'); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
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
  <div class="col-6 col-lg-3">
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
      <span class="badge bg-danger ms-2"><?php echo $totalrisk; ?></span>
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
          <span class="badge bg-danger ms-1"><?php echo count($atrisknone); ?></span>
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
      <?php echo s($mod); ?>
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
<div class="row g-3 mb-4">

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
              <td style="<?php echo $bg . $color; ?>"
                  title="<?php echo $daynames[$dow] . ' ' . $timeslots[$b] . ': ' . $val; ?>">
                <?php echo $val > 0 ? $val : ''; ?>
              </td>
              <?php endfor; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
<?php endif; ?>

<!-- ── Resumen por tipo de módulo ──────────────────────────────────────────-->
<?php if (!empty($bytype)): ?>
<div class="card cr-card mb-4">
  <div class="card-header bg-white border-bottom py-3">
    <h5 class="mb-0 fw-bold">
      <?php echo $OUTPUT->pix_icon('i/stats', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('moduletypesummary', 'report_courseradar'); ?>
    </h5>
  </div>
  <div class="card-body">
    <?php foreach ($bytype as $mod => $data): ?>
    <?php
      $pct = (int)round(($data['views'] / $typemaxviews) * 100);
      $avg = $data['modules'] > 0 ? round($data['views'] / $data['modules'], 1) : 0;
    ?>
    <div class="d-flex align-items-center mb-2 gap-2">
      <div style="width:90px; flex-shrink:0; text-align:right;">
        <span class="badge bg-light text-dark border cr-badge-mod"><?php echo $mod; ?></span>
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
    <h5 class="mb-0 fw-bold">
      <?php echo $OUTPUT->pix_icon('i/course', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('resourceactivity', 'report_courseradar'); ?>
      <span class="badge bg-secondary ms-2"><?php echo $totalmodules; ?></span>
    </h5>
    <span id="cr-sort-notice" class="text-muted small">
      <a href="javascript:crResetSort()" class="btn btn-sm btn-outline-secondary">
        <?php echo get_string('resetsort', 'report_courseradar'); ?>
      </a>
    </span>
  </div>
  <?php if (count($bytype) > 1): ?>
  <div class="px-3 pt-3 pb-2 border-bottom d-flex flex-wrap gap-3 align-items-center">
    <small class="text-muted fw-semibold me-1"><?php echo get_string('filterbytype', 'report_courseradar'); ?></small>
    <?php foreach (array_keys($bytype) as $mod): ?>
    <button type="button"
            class="btn btn-sm btn-primary cr-type-filter-btn cr-type-active cr-badge-mod me-2 mb-1"
            data-modname="<?php echo s($mod); ?>"
            onclick="crFilterType(this,'<?php echo s($mod); ?>')">
      <?php echo s($mod); ?>
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
              data-section="<?php echo $snum; ?>">

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
            <td data-sort="<?php echo s($cm->modname); ?>">
              <span class="badge bg-light text-dark border cr-badge-mod"><?php echo $cm->modname; ?></span>
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
                      <span class="badge bg-success ms-1"><?php echo $unique; ?></span>
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
                      <span class="badge bg-danger ms-1"><?php echo $notseen; ?></span>
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

<!-- ── Tabla de actividad por estudiante ─────────────────────────────────── -->
<div class="card cr-card mb-4">
  <div class="card-header bg-white border-bottom py-3">
    <h5 class="mb-0 fw-bold">
      <?php echo $OUTPUT->pix_icon('i/group', '', 'core', ['class' => 'me-1']); ?>
      <?php echo get_string('studentengagement', 'report_courseradar'); ?>
      <span class="badge bg-secondary ms-2"><?php echo $totalstudents; ?></span>
    </h5>
  </div>
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
            <th class="text-center"><?php echo get_string('details', 'report_courseradar'); ?></th>
          </tr>
        </thead>
        <tbody>

<?php if (empty($students)): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-5">
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
          <tr class="cr-student-row" data-detail="<?php echo $studetailid; ?>">

            <td data-sort="<?php echo s($stu->lastname . ' ' . $stu->firstname); ?>">
              <a href="<?php echo (new moodle_url('/user/view.php', ['id' => $uid, 'course' => $courseid]))->out(false); ?>">
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

            <td class="text-center">
              <button class="btn btn-sm btn-outline-secondary"
                      type="button"
                      onclick="crToggle(this,'<?php echo $studetailid; ?>')">
                <?php echo $OUTPUT->pix_icon('i/search', '', 'core'); ?>
              </button>
            </td>
          </tr>

          <!-- Detalle del estudiante -->
          <tr id="<?php echo $studetailid; ?>" class="cr-detail-row" style="display:none;">
            <td colspan="6">
              <div class="cr-detail-inner p-3">
                <?php foreach ($bysection as $snum => $section): ?>
                <div class="mb-2">
                  <small class="text-muted fw-semibold d-block mb-1"><?php echo $section['name']; ?></small>
                  <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($section['cms'] as $cm): ?>
                    <?php
                      $cmid    = $cm->id;
                      $seen    = isset($studentlog[$uid][$cmid]);
                      $svcount = $seen ? $studentlog[$uid][$cmid] : 0;
                      $completed_stu = $completionenabled && $cm->completion > 0
                                    && isset($completionbyuser[$cmid][$uid]);
                      $badgeclass = $completed_stu
                          ? 'bg-success'
                          : ($seen ? 'bg-primary' : 'bg-light text-dark border');
                      $iconurl = $cm->get_icon_url()->out(false);
                      $title = s(format_string($cm->name));
                      if ($seen)        { $title .= ' (' . $svcount . ' ' . get_string('times', 'report_courseradar') . ')'; }
                      if ($completed_stu) { $title .= ' ✓'; }
                    ?>
                    <span class="badge <?php echo $badgeclass; ?> cr-student-badge d-flex align-items-center gap-1"
                          title="<?php echo $title; ?>">
                      <img src="<?php echo $iconurl; ?>" alt="" style="width:13px;height:13px;">
                      <?php echo shorten_text(format_string($cm->name), 22); ?>
                      <?php if ($seen && $svcount > 1): ?>
                        <span class="opacity-75">(<?php echo $svcount; ?>)</span>
                      <?php endif; ?>
                      <?php if ($completed_stu): ?><span>✓</span><?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endforeach; ?>
                <?php if (!empty($sparklines[$uid])): ?>
                <div class="mt-3">
                  <small class="text-muted fw-semibold d-block mb-1">
                    <?php echo get_string('weeklyactivity', 'report_courseradar'); ?>
                  </small>
                  <div class="cr-sparkline">
                    <?php foreach ($sparklines[$uid] as $bar): ?>
                    <div class="cr-spark-bar"
                         style="height:<?php echo $bar['height']; ?>%"
                         data-bs-toggle="tooltip"
                         data-bs-placement="top"
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

</div><!-- /container -->
<?php echo $OUTPUT->footer(); ?>
