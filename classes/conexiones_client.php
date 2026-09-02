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

/**
 * Client for POST /Informes/GetInformeConexionesAlumno.
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
        $cachekey = $course->id . '_' . $user->id . '_' . $tipo . '_v2';
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

        $summary = self::summarise($result['data'] ?? null);
        $summary['request'] = $request;
        $cache->set($cachekey, $summary);
        return $summary;
    }

    /**
     * Turn an unknown API payload into a short label plus optional rows.
     *
     * @param mixed $data Decoded JSON.
     * @return array
     */
    public static function summarise($data): array {
        $out = [
            'ok'      => true,
            'label'   => '0',
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
        $list        = self::extract_list($data);
        $seconds     = 0;
        $rows        = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $secs = self::extract_seconds($item);
            $seconds += $secs;
            $when  = self::first_string($item, ['Dia', 'Fecha', 'FechaInicio', 'FechaSesion', 'Date']);
            $title = self::first_string($item, [
                'Sesion', 'Sesión', 'Titulo', 'Título', 'Nombre', 'Descripcion', 'Descripción',
            ]);
            $rows[] = [
                'title'    => $title !== '' ? $title : $when,
                'when'     => $when,
                'duration' => $secs > 0 ? \report_courseradar_format_dedication($secs) : '',
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
        return $out;
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
            if (isset($data[$key]) && is_array($data[$key]) && self::is_list($data[$key])) {
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
        foreach ($item as $key => $value) {
            $k = strtolower((string)$key);
            if (is_array($value)) {
                continue;
            }
            if (is_string($value)) {
                $parsed = self::parse_duration_string($value);
                if ($parsed !== null) {
                    return $parsed;
                }
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
     * Parse HH:MM:SS or "5h 43'" into seconds.
     *
     * @param string $value
     * @return int|null
     */
    private static function parse_duration_string(string $value): ?int {
        $value = trim($value);
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
            $parts = array_map('intval', explode(':', $value));
            if (count($parts) === 3) {
                return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
            }
            return $parts[0] * 60 + $parts[1];
        }
        if (preg_match("/^(\d+)\s*h\s*(\d+)\s*['m]?$/iu", $value, $m)) {
            return ((int)$m[1] * 3600) + ((int)$m[2] * 60);
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
