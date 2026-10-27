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

namespace report_courseradar;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../locallib.php');

/**
 * Client for the UDIMA connection report and the on-demand per-class viewing endpoints.
 *
 * Reuses block_zoom_udima OAuth and failover when that plugin is installed.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conexiones_client {
    /** Live Zoom connections. */
    public const TIPO_DIRECTO = 1;

    /** Deferred Vimeo views. */
    public const TIPO_DIFERIDO = 2;

    /**
     * Whether the API can be called (own settings or Zoom UDIMA credentials).
     *
     * @return bool
     */
    public static function is_configured(): bool {
        if (self::use_zoom_client()) {
            return true;
        }
        $clientid = get_config('report_courseradar', 'client_id');
        $secret   = get_config('report_courseradar', 'client_secret');
        $tokenurl = get_config('report_courseradar', 'token_url');
        $baseurl  = get_config('report_courseradar', 'baseurl');
        return ($clientid !== false && $clientid !== '')
            && ($secret !== false && $secret !== '')
            && ($tokenurl !== false && $tokenurl !== '')
            && ($baseurl !== false && $baseurl !== '');
    }

    /**
     * Fetch both connection types for one student in a course.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record (needs username / idnumber).
     * @param bool $force Skip the short-lived MUC cache.
     * @return array{live: array, delayed: array}
     */
    public static function fetch_user(\stdClass $course, \stdClass $user, bool $force = false): array {
        return [
            'live'    => self::fetch_tipo($course, $user, self::TIPO_DIRECTO, $force),
            'delayed' => self::fetch_tipo($course, $user, self::TIPO_DIFERIDO, $force),
        ];
    }

    /**
     * Fetch one report type, cached in MUC for 15 minutes.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record.
     * @param int $tipo 1 or 2.
     * @param bool $force Skip the short-lived MUC cache.
     * @return array Summarised payload.
     */
    public static function fetch_tipo(\stdClass $course, \stdClass $user, int $tipo, bool $force = false): array {
        $empty = [
            'ok'      => false,
            'label'   => '-',
            'count'   => 0,
            'seconds' => 0,
            'rows'    => [],
            'error'   => '',
            'request' => null,
        ];
        if ($tipo !== self::TIPO_DIRECTO && $tipo !== self::TIPO_DIFERIDO) {
            return $empty;
        }
        if (!self::is_configured()) {
            $empty['error'] = 'notconfigured';
            return $empty;
        }

        $cache    = \cache::make('report_courseradar', 'conexiones');
        $cachekey = $course->id . '_' . $user->id . '_' . $tipo . '_v7';
        if (!$force) {
            $cached = $cache->get($cachekey);
            if (is_array($cached) && isset($cached['ok'])) {
                return $cached;
            }
        }

        $campus  = self::campus_info();
        $field   = $campus['userid_field'];
        $alumnoid = (string)($user->$field ?? $user->username);
        $aula    = trim((string)$course->idnumber);
        if ($aula === '') {
            $aula = trim((string)$course->shortname);
        }

        $body = [
            'Origen'     => $campus['origen'],
            'AlumnoId'   => $alumnoid,
            'Tipo'       => $tipo,
            'Rol'        => 'alumno',
            'AulaMoodle' => $aula,
        ];

        try {
            $result = self::post('/Informes/GetInformeConexionesAlumno', $body);
        } catch (\Throwable $e) {
            $empty['error'] = $e->getMessage();
            $empty['request'] = [
                'method' => 'POST',
                'path'   => '/Informes/GetInformeConexionesAlumno',
                'body'   => $body,
            ];
            return $empty;
        }

        $request = [
            'method'    => 'POST',
            'url'       => $result['url'] ?? '',
            'path'      => '/Informes/GetInformeConexionesAlumno',
            'body'      => $body,
            'http_code' => $result['http_code'] ?? 0,
            'raw'       => $result['raw'] ?? '',
        ];

        if (!empty($result['error']) || ($result['http_code'] ?? 0) >= 400 || ($result['http_code'] ?? 0) < 1) {
            $empty['error'] = $result['error'] ?? ('HTTP ' . ($result['http_code'] ?? 0));
            $empty['request'] = $request;
            return $empty;
        }

        $summary = self::summarise($result['data'] ?? null, $tipo);
        $summary['request'] = $request;
        $cache->set($cachekey, $summary);
        return $summary;
    }

    /**
     * Per-class viewing time, fetched when the student opens a card.
     *
     * Direct calls POST /Informes/GetVisionadoDirectoAlumno.
     * On-demand calls POST /Informes/GetVisionadoDiferidoAlumno.
     * The body matches GetInformeConexionesAlumno without Tipo.
     * This is not used while loading the page or refreshing the stored totals.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record (needs username / idnumber).
     * @param int $tipo 1 live, 2 on-demand.
     * @return array Summarised classes. The card label is left unchanged.
     */
    public static function fetch_visionado(\stdClass $course, \stdClass $user, int $tipo): array {
        $empty = [
            'ok'      => false,
            'label'   => '0',
            'count'   => 0,
            'seconds' => 0,
            'rows'    => [],
            'error'   => '',
        ];
        if ($tipo === self::TIPO_DIRECTO) {
            $path = '/Informes/GetVisionadoDirectoAlumno';
        } else if ($tipo === self::TIPO_DIFERIDO) {
            $path = '/Informes/GetVisionadoDiferidoAlumno';
        } else {
            $empty['error'] = 'badtipo';
            return $empty;
        }
        if (!self::is_configured()) {
            $empty['error'] = 'notconfigured';
            return $empty;
        }

        $campus   = self::campus_info();
        $field    = $campus['userid_field'];
        $alumnoid = (string)($user->$field ?? $user->username);
        $aula     = trim((string)$course->idnumber);
        if ($aula === '') {
            $aula = trim((string)$course->shortname);
        }
        $body = [
            'Origen'     => $campus['origen'],
            'AlumnoId'   => $alumnoid,
            'Rol'        => 'alumno',
            'AulaMoodle' => $aula,
        ];

        try {
            $result = self::post($path, $body);
        } catch (\Throwable $e) {
            $empty['error'] = $e->getMessage();
            return $empty;
        }

        if (!empty($result['error']) || ($result['http_code'] ?? 0) >= 400 || ($result['http_code'] ?? 0) < 1) {
            $empty['error'] = $result['error'] ?? ('HTTP ' . ($result['http_code'] ?? 0));
            return $empty;
        }

        return self::summarise_visionado($result['data'] ?? null, $tipo);
    }

    /**
     * One row per class, with live time and recording time side by side.
     *
     * Calls both visionado endpoints in parallel. Rows are joined on IdReunion.
     * DuracionReal comes from the connection report, which is the payload that has it.
     * The card totals are not part of this response.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record (needs username / idnumber).
     * @return array
     */
    public static function fetch_sessions(\stdClass $course, \stdClass $user): array {
        $empty = [
            'ok'    => false,
            'rows'  => [],
            'error' => '',
        ];
        if (!self::is_configured()) {
            $empty['error'] = 'notconfigured';
            return $empty;
        }

        $campus   = self::campus_info();
        $field    = $campus['userid_field'];
        $alumnoid = (string)($user->$field ?? $user->username);
        $aula     = trim((string)$course->idnumber);
        if ($aula === '') {
            $aula = trim((string)$course->shortname);
        }
        $body = [
            'Origen'     => $campus['origen'],
            'AlumnoId'   => $alumnoid,
            'Rol'        => 'alumno',
            'AulaMoodle' => $aula,
        ];

        try {
            $results = self::post_many([
                self::TIPO_DIRECTO  => '/Informes/GetVisionadoDirectoAlumno',
                self::TIPO_DIFERIDO => '/Informes/GetVisionadoDiferidoAlumno',
            ], $body);
        } catch (\Throwable $e) {
            $empty['error'] = $e->getMessage();
            return $empty;
        }

        $liveok    = self::visionado_result_ok($results[self::TIPO_DIRECTO] ?? null);
        $delayedok = self::visionado_result_ok($results[self::TIPO_DIFERIDO] ?? null);
        if (!$liveok && !$delayedok) {
            $empty['error'] = $results[self::TIPO_DIRECTO]['error']
                ?? $results[self::TIPO_DIFERIDO]['error']
                ?? 'All APIs failed';
            return $empty;
        }

        $liverows = [];
        if ($liveok) {
            $live = self::summarise_visionado($results[self::TIPO_DIRECTO]['data'] ?? null, self::TIPO_DIRECTO);
            $liverows = $live['rows'];
        }
        $delayedrows = [];
        if ($delayedok) {
            $delayed = self::summarise_visionado($results[self::TIPO_DIFERIDO]['data'] ?? null, self::TIPO_DIFERIDO);
            $delayedrows = $delayed['rows'];
        }

        $sessions = self::merge_session_rows($liverows, $delayedrows);
        $liveinforme = self::fetch_tipo($course, $user, self::TIPO_DIRECTO);
        $delayedinforme = self::fetch_tipo($course, $user, self::TIPO_DIFERIDO);
        return [
            'ok'    => true,
            'rows'  => self::apply_class_durations(
                $sessions,
                !empty($liveinforme['ok']) ? $liveinforme['rows'] : [],
                !empty($delayedinforme['ok']) ? $delayedinforme['rows'] : []
            ),
            'error' => '',
        ];
    }

    /**
     * Join live and on-demand class rows into one list.
     *
     * The key is IdReunion. Without it, the scheduled date and start time are used.
     * Newest class first. A class present on only one side keeps a zero on the other.
     *
     * @param array $liverows Rows from summarise_visionado for live classes.
     * @param array $delayedrows Rows from summarise_visionado for recordings.
     * @return array
     */
    public static function merge_session_rows(array $liverows, array $delayedrows): array {
        $map = [];
        foreach ($liverows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = self::session_key($row);
            if (!isset($map[$key])) {
                $map[$key] = self::blank_session($row);
            }
            $map[$key]['live'] += (int)($row['seconds'] ?? 0);
            if ($map[$key]['scheduled'] <= 0) {
                $map[$key]['scheduled'] = (int)($row['scheduled'] ?? 0);
            }
        }
        foreach ($delayedrows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = self::session_key($row);
            if (!isset($map[$key])) {
                $map[$key] = self::blank_session($row);
            }
            $map[$key]['delayed'] += (int)($row['seconds'] ?? 0);
            if ($map[$key]['scheduled'] <= 0) {
                $map[$key]['scheduled'] = (int)($row['scheduled'] ?? 0);
            }
            if ($map[$key]['end'] === '' && !empty($row['end'])) {
                $map[$key]['end'] = (string)$row['end'];
            }
            if ($map[$key]['start'] === '' && !empty($row['start'])) {
                $map[$key]['start'] = (string)$row['start'];
            }
            if ($map[$key]['when'] === '' && !empty($row['when'])) {
                $map[$key]['when'] = (string)$row['when'];
                $map[$key]['sort'] = self::session_sort($row);
            }
        }

        $rows = [];
        foreach ($map as $row) {
            $row['livelabel'] = \report_courseradar_format_dedication((int)$row['live']);
            $row['delayedlabel'] = \report_courseradar_format_dedication((int)$row['delayed']);
            $rows[] = $row;
        }
        usort($rows, [self::class, 'compare_sessions']);
        foreach ($rows as $index => $row) {
            unset($rows[$index]['sort']);
        }
        return $rows;
    }

    /**
     * Copy each class's DuracionReal onto the matching visionado row.
     *
     * Visionado has the scheduled clock and the time the student watched.
     * The connection report has DuracionReal, the length of the class.
     * A live row is kept. The same length on the deferred report is not added again.
     * One length on a day is used for each class that day. Several lengths are paired
     * by the nearest start time.
     *
     * @param array $sessions Merged visionado rows.
     * @param array $liverows summarise() rows for live connections.
     * @param array $delayedrows summarise() rows for recordings.
     * @return array
     */
    public static function apply_class_durations(array $sessions, array $liverows, array $delayedrows): array {
        $byday = [];
        foreach ($liverows as $row) {
            self::collect_class_duration($byday, $row);
        }
        foreach ($delayedrows as $row) {
            self::collect_class_duration($byday, $row);
        }
        foreach ($sessions as $index => $session) {
            if (!is_array($session)) {
                continue;
            }
            $secs = self::take_class_seconds(
                $byday,
                trim((string)($session['when'] ?? '')),
                (string)($session['start'] ?? '')
            );
            $sessions[$index]['classseconds'] = $secs;
            $sessions[$index]['classlabel'] = $secs > 0
                ? \report_courseradar_format_dedication($secs)
                : '';
        }
        return $sessions;
    }

    /**
     * Keep one DuracionReal for a day. A repeated length from the other report is skipped.
     *
     * @param array $byday Day to list of candidates. Updated in place.
     * @param mixed $row One summarise() row.
     */
    private static function collect_class_duration(array &$byday, $row): void {
        if (!is_array($row)) {
            return;
        }
        $secs = (int)($row['classseconds'] ?? 0);
        $day = trim((string)($row['when'] ?? ''));
        if ($secs <= 0 || $day === '') {
            return;
        }
        if (!isset($byday[$day])) {
            $byday[$day] = [];
        }
        foreach ($byday[$day] as $existing) {
            if ((int)$existing['seconds'] === $secs) {
                return;
            }
        }
        $byday[$day][] = [
            'seconds' => $secs,
            'start'   => self::clock_to_minutes((string)($row['start'] ?? '')),
        ];
    }

    /**
     * Class length for one visionado row.
     *
     * A single length is shared by every class that day. Several lengths are paired
     * by the nearest start time, and each of those is used once.
     *
     * @param array $byday Day to remaining candidates. Updated in place.
     * @param string $day Session date, d/m/Y.
     * @param string $start Scheduled start, HH:MM.
     * @return int
     */
    private static function take_class_seconds(array &$byday, string $day, string $start): int {
        if ($day === '' || empty($byday[$day])) {
            return 0;
        }
        $distinct = [];
        foreach ($byday[$day] as $candidate) {
            $distinct[(int)$candidate['seconds']] = true;
        }
        if (count($distinct) === 1) {
            return (int)array_key_first($distinct);
        }
        $target = self::clock_to_minutes($start);
        $best = 0;
        $bestdist = null;
        foreach ($byday[$day] as $index => $candidate) {
            $candidatestart = $candidate['start'];
            if ($target === null || $candidatestart === null) {
                $dist = 0;
            } else {
                $dist = abs($candidatestart - $target);
            }
            if ($bestdist === null || $dist < $bestdist) {
                $bestdist = $dist;
                $best = $index;
            }
        }
        $secs = (int)$byday[$day][$best]['seconds'];
        array_splice($byday[$day], $best, 1);
        return $secs;
    }

    /**
     * Minutes from midnight for an HH:MM clock.
     *
     * @param string $clock
     * @return int|null
     */
    private static function clock_to_minutes(string $clock): ?int {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($clock), $match)) {
            return null;
        }
        return ((int)$match[1]) * 60 + (int)$match[2];
    }

    /**
     * Turn an unknown API payload into a short label plus optional rows.
     *
     * @param mixed $data Decoded JSON.
     * @param int $tipo 1 live, 2 on-demand, 0 when unknown.
     * @return array
     */
    public static function summarise($data, int $tipo = 0): array {
        $out = [
            'ok'      => true,
            'label'   => '0',
            'percent' => '',
            'count'   => 0,
            'seconds' => 0,
            'rows'    => [],
            'error'   => '',
            'request' => null,
        ];
        if ($data === null) {
            return $out;
        }
        if (!is_array($data)) {
            $out['label'] = (string)$data;
            return $out;
        }

        if (isset($data['datosInforme']) && is_array($data['datosInforme'])) {
            $data = $data['datosInforme'];
        }

        $generalseconds = 0;
        if (isset($data['General']) && is_array($data['General'])) {
            $generalseconds = self::extract_seconds($data['General']);
        }
        $rootseconds = max($generalseconds, self::extract_seconds($data));
        $list = [];
        if (!empty($data['Asistencias']) && is_array($data['Asistencias'])) {
            $list = $data['Asistencias'];
        } else if (!empty($data['asistencias']) && is_array($data['asistencias'])) {
            $list = $data['asistencias'];
        } else {
            $list = self::extract_list($data);
        }
        $seconds     = 0;
        $rows        = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $secs = self::extract_seconds($item);
            $seconds += $secs;
            $classsecs = self::class_seconds($item);
            $when  = self::first_string($item, ['Dia', 'Fecha', 'FechaInicio', 'FechaSesion', 'Date']);
            $title = self::first_string($item, [
                'Sesion', 'Sesión', 'Titulo', 'Título', 'Nombre', 'Descripcion', 'Descripción',
            ]);
            $start = self::first_string($item, ['Inicio', 'HoraInicio', 'HoraInicioZoom']);
            $end   = self::first_string($item, ['Fin', 'HoraFin', 'HoraFinZoom']);
            $sess  = self::first_string($item, ['Sesion', 'Sesión']);
            $rows[] = [
                'title'         => $title !== '' ? $title : ($sess !== '' ? $sess : $when),
                'when'          => $when,
                'session'       => $sess,
                'start'         => $start,
                'end'           => $end,
                'duration'      => $secs > 0 ? \report_courseradar_format_dedication($secs) : '',
                'classseconds'  => $classsecs,
                'classduration' => $classsecs > 0 ? \report_courseradar_format_dedication($classsecs) : '',
            ];
        }

        if ($rootseconds > $seconds) {
            $seconds = $rootseconds;
        }
        $out['seconds'] = $seconds;
        $out['count']   = count($rows);
        $out['rows']    = $rows;
        if ($seconds > 0) {
            $out['label'] = \report_courseradar_format_dedication($seconds);
        } else if ($out['count'] > 0) {
            $out['label'] = (string)$out['count'];
        }
        $general = (isset($data['General']) && is_array($data['General'])) ? $data['General'] : [];
        $percent = self::extract_percent($general, $tipo);
        if ($percent !== null) {
            $out['percent'] = $percent;
            $out['label'] .= ' · ' . $percent;
        }
        return $out;
    }

    /**
     * One row per class from GetVisionadoDirectoAlumno or GetVisionadoDiferidoAlumno.
     *
     * Dates are the class scheduled in CefSalas, not the moment the student watched it.
     * FechaFin exists only on the live report. IdsGrabacion exists only on the recording report.
     *
     * @param mixed $data Decoded JSON.
     * @param int $tipo 1 live, 2 on-demand.
     * @return array
     */
    public static function summarise_visionado($data, int $tipo = 0): array {
        $out = [
            'ok'      => true,
            'label'   => '0',
            'count'   => 0,
            'seconds' => 0,
            'rows'    => [],
            'error'   => '',
        ];
        if (!is_array($data)) {
            return $out;
        }
        if (isset($data['datosInforme']) && is_array($data['datosInforme'])) {
            $data = $data['datosInforme'];
        }

        $list = [];
        if (!empty($data['Grabaciones']) && is_array($data['Grabaciones'])) {
            $list = $data['Grabaciones'];
        } else if (!empty($data['grabaciones']) && is_array($data['grabaciones'])) {
            $list = $data['grabaciones'];
        } else if (self::is_list($data) && $data !== [] && is_array(reset($data))) {
            $list = $data;
        }

        $rowseconds = 0;
        $rows = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rawtime = $item['TiempoTotalUnico'] ?? ($item['TiempoTotal'] ?? null);
            $secs = self::visionado_seconds($rawtime);
            $rowseconds += $secs;
            $inicio = self::first_string($item, ['FechaInicio']);
            $fin = ($tipo === self::TIPO_DIFERIDO) ? '' : self::first_string($item, ['FechaFin']);
            $when = self::first_string($item, ['Fecha']);
            if ($when === '' && preg_match('/^(\d{2}\/\d{2}\/\d{4})/', $inicio, $datematch)) {
                $when = $datematch[1];
            }
            $recordings = [];
            if (!empty($item['IdsGrabacion']) && is_array($item['IdsGrabacion'])) {
                foreach ($item['IdsGrabacion'] as $recordingid) {
                    if (!is_array($recordingid) && (string)$recordingid !== '') {
                        $recordings[] = (string)$recordingid;
                    }
                }
            }
            $rows[] = [
                'title'      => $when,
                'when'       => $when,
                'session'    => '',
                'start'      => self::scheduled_clock($inicio),
                'end'        => self::scheduled_clock($fin),
                'duration'   => $secs > 0 ? \report_courseradar_format_dedication($secs) : '',
                'seconds'    => $secs,
                'scheduled'  => self::scheduled_span($inicio, $fin),
                'reunion'    => self::first_string($item, ['IdReunion']),
                'recordings' => $recordings,
            ];
        }

        $seconds = $rowseconds;
        if (isset($data['General']) && is_array($data['General']) && array_key_exists('TiempoTotal', $data['General'])) {
            $seconds = self::visionado_seconds($data['General']['TiempoTotal']);
        }
        $out['seconds'] = $seconds;
        $out['count'] = count($rows);
        $out['rows'] = $rows;
        if ($seconds > 0) {
            $out['label'] = \report_courseradar_format_dedication($seconds);
        }
        return $out;
    }

    /**
     * Duration in seconds for a visionado time field.
     *
     * Accepts the same strings as the connection report, or a number of seconds.
     * A scheduled clock such as "13/10/2026 18:00" is not a duration.
     *
     * @param mixed $value
     * @return int
     */
    private static function visionado_seconds($value): int {
        if (is_int($value) || is_float($value)) {
            return max(0, (int)round($value));
        }
        if (!is_string($value)) {
            return 0;
        }
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return 0;
        }
        $parsed = self::parse_duration_string($value, true);
        if ($parsed !== null) {
            return $parsed;
        }
        if (is_numeric($value)) {
            return max(0, (int)round((float)$value));
        }
        return 0;
    }

    /**
     * Clock (HH:MM) from a scheduled class datetime.
     *
     * @param string $value For example "13/10/2026 18:00".
     * @return string
     */
    private static function scheduled_clock(string $value): string {
        if (preg_match('/(\d{1,2}:\d{2})(?::\d{2})?\s*$/', trim($value), $match)) {
            return $match[1];
        }
        return '';
    }

    /**
     * Scheduled class length in seconds, from FechaInicio to FechaFin.
     *
     * @param string $inicio Scheduled start.
     * @param string $fin Scheduled end. Empty on the recording report.
     * @return int Zero when either clock is missing.
     */
    private static function scheduled_span(string $inicio, string $fin): int {
        $start = self::parse_scheduled($inicio);
        $end   = self::parse_scheduled($fin);
        if ($start === null || $end === null || $end <= $start) {
            return 0;
        }
        return $end - $start;
    }

    /**
     * Unix time for a CefSalas datetime such as "13/10/2026 18:00".
     *
     * @param string $value
     * @return int|null
     */
    private static function parse_scheduled(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i'] as $format) {
            $parsed = \DateTime::createFromFormat('!' . $format, $value);
            if (!$parsed instanceof \DateTime) {
                continue;
            }
            $errors = \DateTime::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) {
                continue;
            }
            return $parsed->getTimestamp();
        }
        return null;
    }

    /**
     * True when a visionado POST returned a usable body.
     *
     * @param array|null $result Result from post() or post_many().
     * @return bool
     */
    private static function visionado_result_ok(?array $result): bool {
        if ($result === null) {
            return false;
        }
        if (!empty($result['error']) || ($result['http_code'] ?? 0) >= 400 || ($result['http_code'] ?? 0) < 1) {
            return false;
        }
        return true;
    }

    /**
     * POST the same JSON body to several endpoints at once.
     *
     * Each base URL is tried in order. An endpoint is finished when a host
     * answers with HTTP 1-499, matching the failover of post().
     *
     * @param array $paths Keyed endpoint paths, each starting with /.
     * @param array $body JSON body.
     * @return array Keyed like $paths, each value shaped as post().
     */
    private static function post_many(array $paths, array $body): array {
        [$token, $urls] = self::token_and_urls();
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $pending = $paths;
        $done = [];
        $lasterror = [];

        foreach ($urls as $base) {
            if ($pending === []) {
                break;
            }
            $multi = curl_multi_init();
            $handles = [];
            foreach ($pending as $key => $path) {
                $curl = curl_init(rtrim($base, '/') . $path);
                curl_setopt_array($curl, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $json,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT        => 15,
                ]);
                curl_multi_add_handle($multi, $curl);
                $handles[$key] = $curl;
            }
            $running = null;
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) {
                    if (curl_multi_select($multi, 1.0) === -1) {
                        usleep(10000);
                    }
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $key => $curl) {
                $response = curl_multi_getcontent($curl);
                $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $err = curl_error($curl);
                curl_multi_remove_handle($multi, $curl);
                curl_close($curl);
                if ($code > 0 && $code < 500) {
                    $done[$key] = [
                        'data'      => json_decode((string)$response, true),
                        'http_code' => $code,
                        'error'     => $code >= 400 ? ('HTTP ' . $code) : null,
                    ];
                    unset($pending[$key]);
                    continue;
                }
                $lasterror[$key] = [
                    'data'      => null,
                    'http_code' => $code,
                    'error'     => $err !== '' ? $err : ('HTTP ' . $code),
                ];
            }
            curl_multi_close($multi);
        }

        foreach (array_keys($pending) as $key) {
            $done[$key] = $lasterror[$key] ?? [
                'data'      => null,
                'http_code' => 0,
                'error'     => 'All APIs failed',
            ];
        }
        return $done;
    }

    /**
     * Empty combined session, filled from whichever report arrived first.
     *
     * @param array $row One summarise_visionado row.
     * @return array
     */
    private static function blank_session(array $row): array {
        return [
            'when'      => (string)($row['when'] ?? ''),
            'start'     => (string)($row['start'] ?? ''),
            'end'       => (string)($row['end'] ?? ''),
            'live'      => 0,
            'delayed'   => 0,
            'scheduled' => (int)($row['scheduled'] ?? 0),
            'sort'      => self::session_sort($row),
        ];
    }

    /**
     * Join key for one class. IdReunion wins over the scheduled clock.
     *
     * @param array $row
     * @return string
     */
    private static function session_key(array $row): string {
        $reunion = trim((string)($row['reunion'] ?? ''));
        if ($reunion !== '') {
            return 'r:' . $reunion;
        }
        return 't:' . trim((string)($row['when'] ?? '')) . '|' . trim((string)($row['start'] ?? ''));
    }

    /**
     * Sort value for a class date. Missing dates sort last when newest-first.
     *
     * @param array $row
     * @return int
     */
    private static function session_sort(array $row): int {
        $when = trim((string)($row['when'] ?? ''));
        $start = trim((string)($row['start'] ?? ''));
        if ($when === '') {
            return 0;
        }
        $raw = $start === '' ? $when : ($when . ' ' . $start);
        $format = $start === '' ? 'd/m/Y' : 'd/m/Y H:i';
        $parsed = \DateTime::createFromFormat('!' . $format, $raw);
        if (!$parsed instanceof \DateTime) {
            return 0;
        }
        $errors = \DateTime::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) {
            return 0;
        }
        return $parsed->getTimestamp();
    }

    /**
     * Newest scheduled class first.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    private static function compare_sessions(array $a, array $b): int {
        return ($b['sort'] ?? 0) <=> ($a['sort'] ?? 0);
    }

    /**
     * Percentage string from General, for the requested report type.
     *
     * Live reports use PorcentajeDirecto. On-demand reports use PorcentajeDiferido.
     *
     * @param array $item General object.
     * @param int $tipo 1 live, 2 on-demand.
     * @return string|null For example "75%".
     */
    private static function extract_percent(array $item, int $tipo): ?string {
        if ($tipo === self::TIPO_DIFERIDO) {
            $key = 'PorcentajeDiferido';
        } else if ($tipo === self::TIPO_DIRECTO) {
            $key = 'PorcentajeDirecto';
        } else {
            return null;
        }
        if (!array_key_exists($key, $item) || is_array($item[$key])) {
            return null;
        }
        $value = trim((string)$item[$key]);
        if ($value === '' || $value === '-') {
            return null;
        }
        if (substr($value, -1) === '%') {
            return $value;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        $shown = abs($number - round($number)) < 0.05
            ? (string)(int)round($number)
            : rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
        return $shown . '%';
    }

    /**
     * POST JSON to the UDIMA API.
     *
     * @param string $endpoint Path starting with /.
     * @param array $body JSON body.
     * @return array{data: mixed, http_code: int, error: ?string}
     */
    private static function post(string $endpoint, array $body): array {
        [$token, $urls] = self::token_and_urls();
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $lasterror = 'All APIs failed';
        $lasturl = '';
        $lastraw = '';
        $lastcode = 0;

        foreach ($urls as $base) {
            $url = rtrim($base, '/') . $endpoint;
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $response = curl_exec($ch);
            $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = ($response === false) ? curl_error($ch) : '';
            curl_close($ch);
            $raw = is_string($response) ? substr($response, 0, 2000) : '';
            $lasturl = $url;
            $lastraw = $raw;
            $lastcode = $code;
            if ($code > 0 && $code < 500) {
                return [
                    'data'      => json_decode((string)$response, true),
                    'http_code' => $code,
                    'error'     => $code >= 400 ? ('HTTP ' . $code) : null,
                    'url'       => $url,
                    'raw'       => $raw,
                ];
            }
            $lasterror = $err ?: ('HTTP ' . $code);
        }

        return [
            'data'      => null,
            'http_code' => $lastcode,
            'error'     => $lasterror,
            'url'       => $lasturl,
            'raw'       => $lastraw,
        ];
    }

    /**
     * OAuth token and API base URLs (Zoom UDIMA or this plugin).
     *
     * @return array{0: string, 1: string[]}
     */
    private static function token_and_urls(): array {
        if (self::use_zoom_client()) {
            $client = new \block_zoom_udima\api_client();
            return [$client->get_access_token(), $client->get_api_urls()];
        }
        return [self::own_access_token(), self::own_api_urls()];
    }

    /**
     * Whether to delegate HTTP to block_zoom_udima.
     *
     * @return bool
     */
    private static function use_zoom_client(): bool {
        $reuse = get_config('report_courseradar', 'reusezoomapi');
        if ($reuse === '0') {
            return false;
        }
        return class_exists(\block_zoom_udima\api_client::class);
    }

    /**
     * Campus origin and which user field the API expects.
     *
     * @return array{origen: string, userid_field: string}
     */
    private static function campus_info(): array {
        if (self::use_zoom_client()) {
            return \block_zoom_udima\api_client::get_campus_info();
        }
        $campus = get_config('report_courseradar', 'campus') ?: 'udima';
        if ($campus === 'cef') {
            return ['origen' => 'cef', 'userid_field' => 'idnumber'];
        }
        return ['origen' => 'udima', 'userid_field' => 'username'];
    }

    /**
     * Request an OAuth token using this plugin's own settings.
     *
     * @return string
     */
    private static function own_access_token(): string {
        $cache = \cache::make('report_courseradar', 'oauth_tokens');
        $hit   = $cache->get('token');
        if (is_array($hit) && !empty($hit['access_token']) && time() < (($hit['expires_at'] ?? 0) - 300)) {
            return $hit['access_token'];
        }

        $postdata = 'grant_type=client_credentials'
            . '&client_id=' . urlencode((string)get_config('report_courseradar', 'client_id'))
            . '&client_secret=' . urlencode((string)get_config('report_courseradar', 'client_secret'))
            . '&scope=' . urlencode((string)get_config('report_courseradar', 'scope'));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => (string)get_config('report_courseradar', 'token_url'),
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => $postdata,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        $token   = $decoded['access_token'] ?? null;
        if (!$token) {
            throw new \moodle_exception('conexioneserror', 'report_courseradar', '', null, 'HTTP ' . $code);
        }
        $expires = (int)($decoded['expires_in'] ?? 3600);
        $cache->set('token', [
            'access_token' => $token,
            'expires_at'   => time() + $expires,
        ]);
        return $token;
    }

    /**
     * Parse the plugin's baseurl setting into a list of URLs.
     *
     * @return string[]
     */
    private static function own_api_urls(): array {
        $raw  = (string)get_config('report_courseradar', 'baseurl');
        $urls = [];
        foreach (preg_split('/\R/', $raw) as $url) {
            $url = trim($url);
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * Find a list of session rows inside an arbitrary JSON object.
     *
     * @param array $data Decoded payload.
     * @return array
     */
    private static function extract_list(array $data): array {
        if (self::is_list($data)) {
            return $data;
        }
        $keys = [
            'Asistencias', 'asistencias', 'Sesiones', 'sesiones',
            'Conexiones', 'conexiones', 'Informe', 'informe',
            'Items', 'items', 'Resultado', 'resultado', 'data', 'Data',
        ];
        foreach ($keys as $key) {
            $isnonemptylist = isset($data[$key]) && is_array($data[$key])
                && self::is_list($data[$key]) && $data[$key] !== [];
            if ($isnonemptylist) {
                return $data[$key];
            }
        }
        foreach ($data as $value) {
            if (is_array($value) && self::is_list($value) && $value !== [] && is_array(reset($value))) {
                return $value;
            }
        }
        return [];
    }

    /**
     * Whether the array is a JSON list (0..n keys).
     *
     * @param array $data
     * @return bool
     */
    private static function is_list(array $data): bool {
        if ($data === []) {
            return true;
        }
        return array_keys($data) === range(0, count($data) - 1);
    }

    /**
     * Best-effort duration in seconds from one object.
     *
     * @param array $item
     * @return int
     */
    private static function extract_seconds(array $item): int {
        $preferred = [
            'TiempoTotal', 'TiempoVimeo', 'TiempoParcial', 'Horas', 'HorasVimeo', 'Duracion',
        ];
        foreach ($preferred as $key) {
            if (!isset($item[$key]) || is_array($item[$key]) || self::skip_duration_key($key)) {
                continue;
            }
            $parsed = self::value_to_seconds($item[$key], true);
            if ($parsed > 0) {
                return $parsed;
            }
        }
        foreach ($item as $key => $value) {
            $k = strtolower((string)$key);
            if (is_array($value) || self::skip_duration_key($k)) {
                continue;
            }
            $isclock = strpos($k, 'inicio') !== false || strpos($k, 'fin') !== false;
            if ($isclock) {
                continue;
            }
            $lookslike = strpos($k, 'tiempo') !== false
                || strpos($k, 'duracion') !== false
                || strpos($k, 'duration') !== false
                || strpos($k, 'horas') !== false
                || strpos($k, 'vimeo') !== false;
            $parsed = self::value_to_seconds($value, $lookslike);
            if ($parsed > 0) {
                return $parsed;
            }
            if (!is_numeric($value)) {
                continue;
            }
            $num = (float)$value;
            if ($num <= 0) {
                continue;
            }
            if (strpos($k, 'min') !== false) {
                return (int)round($num * 60);
            }
            if (strpos($k, 'hora') !== false || strpos($k, 'hour') !== false) {
                return (int)round($num * 3600);
            }
            $isduration = strpos($k, 'seg') !== false
                || strpos($k, 'sec') !== false
                || strpos($k, 'tiempo') !== false
                || strpos($k, 'duracion') !== false
                || strpos($k, 'duration') !== false;
            if ($isduration) {
                return (int)round($num);
            }
        }
        return 0;
    }

    /**
     * HorasTelepresencia is the planned course length. DuracionReal is the class length.
     *
     * Neither one is the time the student was connected or watching.
     *
     * @param string $key
     * @return bool
     */
    private static function skip_duration_key(string $key): bool {
        $key = strtolower($key);
        return strpos($key, 'telepresencia') !== false || $key === 'duracionreal';
    }

    /**
     * Real class length in seconds. DuracionReal is the only field the report uses for it.
     *
     * @param array $item One asistencia.
     * @return int
     */
    private static function class_seconds(array $item): int {
        if (!isset($item['DuracionReal']) || is_array($item['DuracionReal'])) {
            return 0;
        }
        return self::visionado_seconds($item['DuracionReal']);
    }

    /**
     * Convert a scalar to seconds, or 0 if it is not a duration.
     *
     * @param mixed $value
     * @param bool $allowhhmm
     * @return int
     */
    private static function value_to_seconds($value, bool $allowhhmm): int {
        if (is_string($value)) {
            $parsed = self::parse_duration_string($value, $allowhhmm);
            return $parsed !== null ? $parsed : 0;
        }
        return 0;
    }

    /**
     * Parse HH:MM:SS or "5h 43'" into seconds.
     *
     * @param string $value
     * @param bool $allowhhmm Whether a two-part HH:MM value may be a duration.
     * @return int|null
     */
    private static function parse_duration_string(string $value, bool $allowhhmm = false): ?int {
        $value = trim($value);
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $value)) {
            $parts = array_map('intval', explode(':', $value));
            return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        }
        if ($allowhhmm && preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            $parts = array_map('intval', explode(':', $value));
            return $parts[0] * 60 + $parts[1];
        }
        if (preg_match("/^(\d+)\s*h\s*(\d+)\s*['’′´]?\s*m?$/iu", $value, $m)) {
            return ((int)$m[1] * 3600) + ((int)$m[2] * 60);
        }
        if (preg_match_all('/(\d+)\s*(hora|minuto|segundo)/iu', $value, $m, PREG_SET_ORDER)) {
            $secs = 0;
            foreach ($m as $part) {
                $n = (int)$part[1];
                $u = function_exists('mb_strtolower') ? mb_strtolower($part[2]) : strtolower($part[2]);
                if (strpos($u, 'hora') === 0) {
                    $secs += $n * 3600;
                } else if (strpos($u, 'minuto') === 0) {
                    $secs += $n * 60;
                } else {
                    $secs += $n;
                }
            }
            return $secs;
        }
        return null;
    }

    /**
     * First non-empty string among the given keys.
     *
     * @param array $item
     * @param string[] $keys
     * @return string
     */
    private static function first_string(array $item, array $keys): string {
        foreach ($keys as $key) {
            if (isset($item[$key]) && !is_array($item[$key]) && (string)$item[$key] !== '') {
                return (string)$item[$key];
            }
        }
        return '';
    }
}
