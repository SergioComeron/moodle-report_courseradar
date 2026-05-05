<?php
require_once(__DIR__ . '/../../config.php');

// ─── Parámetros ───────────────────────────────────────────────────────────────
$courseid    = required_param('id', PARAM_INT);
$datefromstr = optional_param('datefrom', '', PARAM_ALPHANUMEXT);
$datetostr   = optional_param('dateto',   '', PARAM_ALPHANUMEXT);

// ─── Curso y contexto ────────────────────────────────────────────────────────
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('report/courseradar:view', $context);

// ─── Procesado de fechas ─────────────────────────────────────────────────────
$defaultfrom = $course->startdate ?: mktime(0, 0, 0, 1, 1, (int)date('Y'));
if (!$defaultfrom) {
    $defaultfrom = mktime(0, 0, 0, 1, 1, (int)date('Y'));
}
$datefrom = $datefromstr ? strtotime($datefromstr) : $defaultfrom;
$dateto   = $datetostr   ? strtotime($datetostr . ' 23:59:59') : time();

if (!$datefrom || $datefrom < 0) {
    $datefrom = $defaultfrom;
}
if (!$dateto || $dateto < $datefrom) {
    $dateto = time();
}

$datefromformat = date('Y-m-d', $datefrom);
$datetoformat   = date('Y-m-d', $dateto);
$isfiltered     = ($datefromstr !== '' || $datetostr !== '');

// ─── Configuración de página ─────────────────────────────────────────────────
$PAGE->set_url(new moodle_url('/report/courseradar/index.php', ['id' => $courseid]));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'report_courseradar'));
$PAGE->set_heading($course->fullname);

// ─── Módulos del curso ───────────────────────────────────────────────────────
$modinfo = get_fast_modinfo($course);
$allcms  = $modinfo->get_cms();

$validcms = [];
foreach ($allcms as $cm) {
    if (!$cm->deletioninprogress) {
        $validcms[$cm->id] = $cm;
    }
}
$totalmodules = count($validcms);

// ─── Usuarios matriculados: separar profesores de estudiantes ────────────────
$allenrolled = get_enrolled_users(
    $context,
    '',
    0,
    'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.picture, u.imagealt, u.email'
);
$canview    = get_enrolled_users($context, 'report/courseradar:view', 0, 'u.id');
$canviewids = array_keys($canview);

$students = [];
foreach ($allenrolled as $u) {
    if (!in_array($u->id, $canviewids)) {
        $students[$u->id] = $u;
    }
}
uasort($students, fn($a, $b) => strcmp($a->lastname . $a->firstname, $b->lastname . $b->firstname));
$totalstudents = count($students);

// ─── Datos de log ────────────────────────────────────────────────────────────
$logdata    = [];   // [cmid => {totalviews, uniqueusers, lastaccess}]
$bycm       = [];   // [cmid][userid] => {views, lastaccess}
$studentlog = [];   // [userid][cmid] => views

if ($totalstudents > 0 && $totalmodules > 0) {
    $studentids = array_keys($students);
    [$insql, $inparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'st');

    $logparams = array_merge([
        'courseid'     => $courseid,
        'action'       => 'viewed',
        'contextlevel' => CONTEXT_MODULE,
        'datefrom'     => $datefrom,
        'dateto'       => $dateto,
    ], $inparams);

    // Totales por módulo
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

    // Detalle por usuario y módulo
    $sql2 = "SELECT contextinstanceid AS cmid,
                    userid,
                    COUNT(*)         AS views,
                    MAX(timecreated) AS lastaccess
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
        $bycm[$row->cmid][$row->userid]         = $row;
        $studentlog[$row->userid][$row->cmid]   = (int)$row->views;
    }
    $rs->close();
}

// ─── Estadísticas de resumen ─────────────────────────────────────────────────
$totalinteractions = 0;
$maxviews          = 0;
$maxname           = get_string('none', 'report_courseradar');
$engagements       = [];

foreach ($validcms as $cmid => $cm) {
    $v = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->totalviews  : 0;
    $u = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->uniqueusers : 0;
    $totalinteractions += $v;
    if ($v > $maxviews) {
        $maxviews = $v;
        $maxname  = format_string($cm->name);
    }
    if ($totalstudents > 0) {
        $engagements[] = ($u / $totalstudents) * 100;
    }
}
$avgengagement = $engagements ? round(array_sum($engagements) / count($engagements)) : 0;

// ─── Organizar por sección ───────────────────────────────────────────────────
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

// ─── Helper: color de barra según porcentaje ─────────────────────────────────
function cr_barclass(int $pct): string {
    if ($pct >= 70) return 'bg-success';
    if ($pct >= 30) return 'bg-warning';
    return 'bg-danger';
}

// ─── Salida ──────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<style>
/* ── Course Radar styles ──────────────────────────────────────── */
.cr-card           { border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.08); transition: transform .15s; }
.cr-card:hover     { transform: translateY(-3px); }
.cr-stat           { font-size: 2.2rem; font-weight: 700; line-height: 1.1; }
.cr-stat-label     { font-size: .85rem; color: #6c757d; margin-top: .25rem; }
.cr-maxname        { font-size: 1rem; font-weight: 600; word-break: break-word; }
.progress          { height: 22px; border-radius: 6px; }
.progress-bar      { font-size: .8rem; font-weight: 600; }
.cr-section-row    { background: #f0f2f5 !important; }
.cr-section-row td { font-weight: 600; font-size: .9rem; padding: .5rem .75rem !important; }
.cr-detail-row td  { padding: 0 !important; border-top: 0 !important; }
.cr-detail-inner   { background: #f8f9fa; border-top: 2px solid #dee2e6; }
.btn.cr-btn-active { background-color: #0d6efd; color: #fff; }
.cr-badge-mod      { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; }
.cr-seen-list      { max-height: 260px; overflow-y: auto; }
.cr-student-badge  { font-size: .75rem; cursor: default; }
tr.cr-resource-row:hover { background: #f0f7ff; }
tr.cr-student-row:hover  { background: #f0f7ff; }
.cr-zero           { color: #adb5bd; }
</style>
<script>
function crToggle(btn, rowId) {
    var row = document.getElementById(rowId);
    if (!row) return;
    if (row.style.display === 'table-row') {
        row.style.display = 'none';
        btn.classList.remove('cr-btn-active');
    } else {
        row.style.display = 'table-row';
        btn.classList.add('cr-btn-active');
    }
}
</script>

<div class="container-fluid px-0">

  <!-- ── Cabecera ──────────────────────────────────────────────── -->
  <div class="d-flex align-items-start mb-3 gap-3">
    <div>
      <h2 class="mb-0 fw-bold">
        <?php echo $OUTPUT->pix_icon('i/report', '', 'core', ['class' => 'me-1']); ?>
        <?php echo get_string('pluginname', 'report_courseradar'); ?>
      </h2>
      <p class="text-muted mb-0 small"><?php echo get_string('plugindesc', 'report_courseradar'); ?></p>
    </div>
  </div>

  <!-- ── Filtro de fechas ──────────────────────────────────────── -->
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

  <!-- ── Tarjetas de resumen ───────────────────────────────────── -->
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

  <!-- ── Tabla de recursos por sección ─────────────────────────── -->
  <div class="card cr-card mb-4">
    <div class="card-header bg-white border-bottom py-3">
      <h5 class="mb-0 fw-bold">
        <?php echo $OUTPUT->pix_icon('i/course', '', 'core', ['class' => 'me-1']); ?>
        <?php echo get_string('resourceactivity', 'report_courseradar'); ?>
        <span class="badge bg-secondary ms-2"><?php echo $totalmodules; ?></span>
      </h5>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th><?php echo get_string('resource', 'report_courseradar'); ?></th>
              <th><?php echo get_string('type', 'report_courseradar'); ?></th>
              <th class="text-center"><?php echo get_string('totalviews', 'report_courseradar'); ?></th>
              <th class="text-center"><?php echo get_string('uniquestudents', 'report_courseradar'); ?></th>
              <th style="min-width:180px"><?php echo get_string('coverage', 'report_courseradar'); ?></th>
              <th><?php echo get_string('lastaccess', 'report_courseradar'); ?></th>
              <th class="text-center"><?php echo get_string('details', 'report_courseradar'); ?></th>
            </tr>
          </thead>
          <tbody>

<?php if (empty($validcms)): ?>
            <tr>
              <td colspan="7" class="text-center text-muted py-5">
                <?php echo get_string('nointeractions', 'report_courseradar'); ?>
              </td>
            </tr>
<?php else: ?>

<?php foreach ($bysection as $snum => $section): ?>
            <!-- Sección -->
            <tr class="cr-section-row">
              <td colspan="7">
                <?php echo $OUTPUT->pix_icon('i/folder', '', 'core', ['class' => 'me-1 text-secondary']); ?>
                <?php echo $section['name']; ?>
              </td>
            </tr>

  <?php foreach ($section['cms'] as $cm): ?>
  <?php
    $cmid    = $cm->id;
    $views   = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->totalviews  : 0;
    $unique  = isset($logdata[$cmid]) ? (int)$logdata[$cmid]->uniqueusers : 0;
    $last    = isset($logdata[$cmid])
               ? userdate($logdata[$cmid]->lastaccess, get_string('strftimedate', 'langconfig'))
               : '—';
    $pct     = ($totalstudents > 0) ? min(100, round(($unique / $totalstudents) * 100)) : 0;
    $notseen = max(0, $totalstudents - $unique);
    $barclass  = cr_barclass($pct);
    $iconurl   = $cm->get_icon_url()->out(false);
    $cmurl     = $cm->url;
    $detailid  = 'crdetail_' . $cmid;
  ?>
            <tr class="cr-resource-row <?php echo (!$cm->visible ? 'text-muted' : ''); ?>">
              <!-- Nombre -->
              <td>
                <img src="<?php echo $iconurl; ?>" alt="" style="width:20px;height:20px;" class="me-1 flex-shrink-0">
                <?php if ($cmurl): ?>
                  <a href="<?php echo $cmurl->out(false); ?>" target="_blank"
                     class="<?php echo (!$cm->visible ? 'text-muted text-decoration-line-through' : ''); ?>">
                    <?php echo format_string($cm->name); ?>
                  </a>
                <?php else: ?>
                  <span class="<?php echo (!$cm->visible ? 'text-muted text-decoration-line-through' : ''); ?>">
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
              <td>
                <span class="badge bg-light text-dark border cr-badge-mod">
                  <?php echo $cm->modname; ?>
                </span>
              </td>

              <!-- Vistas totales -->
              <td class="text-center fw-bold <?php echo ($views === 0 ? 'cr-zero' : ''); ?>">
                <?php echo $views; ?>
              </td>

              <!-- Estudiantes únicos -->
              <td class="text-center">
                <span class="fw-semibold <?php echo ($unique === $totalstudents && $totalstudents > 0 ? 'text-success' : ''); ?>">
                  <?php echo $unique; ?>/<?php echo $totalstudents; ?>
                </span>
                <?php if ($notseen > 0): ?>
                <br><small class="text-danger"><?php echo $notseen; ?> <?php echo get_string('notviewed', 'report_courseradar'); ?></small>
                <?php endif; ?>
              </td>

              <!-- Cobertura -->
              <td>
                <div class="progress" title="<?php echo $pct; ?>%">
                  <div class="progress-bar <?php echo $barclass; ?>"
                       role="progressbar"
                       style="width:<?php echo $pct; ?>%"
                       aria-valuenow="<?php echo $pct; ?>"
                       aria-valuemin="0"
                       aria-valuemax="100">
                    <?php if ($pct >= 15): echo $pct . '%'; endif; ?>
                  </div>
                </div>
                <?php if ($pct < 15): ?>
                  <small class="text-muted"><?php echo $pct; ?>%</small>
                <?php endif; ?>
              </td>

              <!-- Último acceso -->
              <td><small class="text-muted"><?php echo $last; ?></small></td>

              <!-- Botón detalle -->
              <td class="text-center">
                <button class="btn btn-sm btn-outline-primary"
                        type="button"
                        onclick="crToggle(this, '<?php echo $detailid; ?>')"
                        title="<?php echo get_string('details', 'report_courseradar'); ?>">
                  <?php echo $OUTPUT->pix_icon('i/group', '', 'core'); ?>
                </button>
              </td>
            </tr>

            <!-- Fila de detalle -->
            <tr id="<?php echo $detailid; ?>" class="cr-detail-row" style="display:none;">
              <td colspan="7">
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
                              <span class="badge bg-primary cr-student-badge" title="<?php echo get_string('totalviews', 'report_courseradar'); ?>">
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
                            <li class="py-1 border-bottom border-light">
                              <a href="<?php echo (new moodle_url('/user/view.php', ['id' => $uid, 'course' => $courseid]))->out(false); ?>">
                                <?php echo fullname($stu); ?>
                              </a>
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

                    </div><!-- /row -->
                </div><!-- /cr-detail-inner -->
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

  <!-- ── Tabla de actividad por estudiante ─────────────────────── -->
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
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th><?php echo get_string('student', 'report_courseradar'); ?></th>
              <th class="text-center"><?php echo get_string('resourcesvisited', 'report_courseradar'); ?></th>
              <th style="min-width:180px"><?php echo get_string('coverage', 'report_courseradar'); ?></th>
              <th class="text-center"><?php echo get_string('totalviews', 'report_courseradar'); ?></th>
              <th><?php echo get_string('lastactivity', 'report_courseradar'); ?></th>
              <th class="text-center"><?php echo get_string('details', 'report_courseradar'); ?></th>
            </tr>
          </thead>
          <tbody>

<?php if (empty($students)): ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-5">
                <?php echo get_string('nostudents', 'report_courseradar'); ?>
              </td>
            </tr>
<?php else: ?>

  <?php foreach ($students as $uid => $stu): ?>
  <?php
    $visited  = isset($studentlog[$uid]) ? count($studentlog[$uid]) : 0;
    $totalv   = isset($studentlog[$uid]) ? array_sum($studentlog[$uid]) : 0;
    $pctstu   = ($totalmodules > 0) ? min(100, round(($visited / $totalmodules) * 100)) : 0;
    $barstu   = cr_barclass($pctstu);

    // Última actividad del estudiante
    $lastact = 0;
    if (isset($studentlog[$uid])) {
        foreach (array_keys($studentlog[$uid]) as $cid) {
            if (isset($bycm[$cid][$uid]) && (int)$bycm[$cid][$uid]->lastaccess > $lastact) {
                $lastact = (int)$bycm[$cid][$uid]->lastaccess;
            }
        }
    }
    $studetailid = 'crstudetail_' . $uid;
    $isinactive  = ($totalv === 0);
  ?>
            <tr class="cr-student-row <?php echo ($isactive = !$isactive) ? '' : ''; ?>">

              <!-- Nombre del estudiante -->
              <td>
                <a href="<?php echo (new moodle_url('/user/view.php', ['id' => $uid, 'course' => $courseid]))->out(false); ?>">
                  <?php echo fullname($stu); ?>
                </a>
                <?php if ($isinactive): ?>
                  <span class="badge bg-danger ms-1 cr-badge-mod">0 interacciones</span>
                <?php endif; ?>
              </td>

              <!-- Recursos visitados -->
              <td class="text-center">
                <span class="fw-semibold <?php echo ($visited === 0 ? 'cr-zero' : ($visited === $totalmodules ? 'text-success' : '')); ?>">
                  <?php echo $visited; ?>/<?php echo $totalmodules; ?>
                </span>
              </td>

              <!-- Cobertura -->
              <td>
                <div class="progress" title="<?php echo $pctstu; ?>%">
                  <div class="progress-bar <?php echo $barstu; ?>"
                       role="progressbar"
                       style="width:<?php echo $pctstu; ?>%"
                       aria-valuenow="<?php echo $pctstu; ?>"
                       aria-valuemin="0"
                       aria-valuemax="100">
                    <?php if ($pctstu >= 15): echo $pctstu . '%'; endif; ?>
                  </div>
                </div>
                <?php if ($pctstu < 15): ?>
                  <small class="text-muted"><?php echo $pctstu; ?>%</small>
                <?php endif; ?>
              </td>

              <!-- Total vistas -->
              <td class="text-center fw-bold <?php echo ($totalv === 0 ? 'cr-zero' : ''); ?>">
                <?php echo $totalv; ?>
              </td>

              <!-- Última actividad -->
              <td>
                <small class="text-muted">
                  <?php echo $lastact
                    ? userdate($lastact, get_string('strftimedatetimeshort', 'langconfig'))
                    : get_string('never'); ?>
                </small>
              </td>

              <!-- Botón detalle -->
              <td class="text-center">
                <button class="btn btn-sm btn-outline-secondary"
                        type="button"
                        onclick="crToggle(this, '<?php echo $studetailid; ?>')">
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
                      <small class="text-muted fw-semibold d-block mb-1">
                        <?php echo $section['name']; ?>
                      </small>
                      <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($section['cms'] as $cm): ?>
                        <?php
                          $cmid    = $cm->id;
                          $seen    = isset($studentlog[$uid][$cmid]);
                          $svcount = $seen ? $studentlog[$uid][$cmid] : 0;
                          $iconurl = $cm->get_icon_url()->out(false);
                        ?>
                        <span class="badge <?php echo $seen ? 'bg-success' : 'bg-light text-dark border'; ?> cr-student-badge d-flex align-items-center gap-1"
                              title="<?php echo s(format_string($cm->name)); ?><?php echo $seen ? ' (' . $svcount . ' ' . get_string('times', 'report_courseradar') . ')' : ''; ?>">
                          <img src="<?php echo $iconurl; ?>" alt="" style="width:13px;height:13px;">
                          <?php echo shorten_text(format_string($cm->name), 22); ?>
                          <?php if ($seen && $svcount > 1): ?>
                            <span class="opacity-75">(<?php echo $svcount; ?>)</span>
                          <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <?php endforeach; ?>
                </div><!-- /cr-detail-inner -->
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
