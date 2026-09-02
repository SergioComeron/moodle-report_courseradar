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
 * Persistent cache of connection totals and the refresh queue.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conexiones_store {
    /** Freshness window in seconds. */
    public const TTL = 900;

    /** Max students fetched per scheduled-task run. */
    public const TASK_LIMIT = 40;

    /** Drop queue rows not requested for this many seconds. */
    public const KEEP = 14 * DAYSECS;

    /**
     * Whether the cache table exists (false until Notifications upgrade).
     *
     * @return bool
     */
    public static function table_exists(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('report_courseradar_conex');
    }

    /**
     * Mark students as requested so the cron will fill missing rows.
     *
     * @param int $courseid
     * @param int[] $userids
     */
    public static function ask_many(int $courseid, array $userids): void {
        global $DB;
        if (!self::table_exists()) {
            return;
        }
        $now = time();
        foreach ($userids as $userid) {
            $userid = (int)$userid;
            $row = $DB->get_record('report_courseradar_conex', [
                'courseid' => $courseid,
                'userid'   => $userid,
            ]);
            if ($row) {
                $row->timeasked = $now;
                $DB->update_record('report_courseradar_conex', $row);
                continue;
            }
            $DB->insert_record('report_courseradar_conex', (object) [
                'courseid'        => $courseid,
                'userid'          => $userid,
                'timeasked'       => $now,
                'timefetched'     => 0,
                'livelabel'       => '',
                'liveseconds'     => 0,
                'delayedlabel'    => '',
                'delayedseconds'  => 0,
            ]);
        }
    }

    /**
     * Stored row for one student, or null.
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get(int $courseid, int $userid): ?\stdClass {
        global $DB;
        if (!self::table_exists()) {
            return null;
        }
        $row = $DB->get_record('report_courseradar_conex', [
            'courseid' => $courseid,
            'userid'   => $userid,
        ]);
        return $row ?: null;
    }

    /**
     * All stored rows for a course, keyed by userid.
     *
     * @param int $courseid
     * @return array<int,\stdClass>
     */
    public static function get_course(int $courseid): array {
        global $DB;
        if (!self::table_exists()) {
            return [];
        }
        $records = $DB->get_records('report_courseradar_conex', ['courseid' => $courseid]);
        $out = [];
        foreach ($records as $row) {
            $out[(int)$row->userid] = $row;
        }
        return $out;
    }

    /**
     * Whether the row was fetched within the TTL.
     *
     * @param \stdClass|null $row
     * @return bool
     */
    public static function is_fresh(?\stdClass $row): bool {
        if (!$row || (int)$row->timefetched <= 0) {
            return false;
        }
        return (time() - (int)$row->timefetched) < self::TTL;
    }

    /**
     * Persist API totals for one student.
     *
     * @param int $courseid
     * @param int $userid
     * @param array $data fetch_user() payload
     */
    public static function save(int $courseid, int $userid, array $data): void {
        global $DB;
        if (!self::table_exists()) {
            return;
        }
        $now = time();
        $row = $DB->get_record('report_courseradar_conex', [
            'courseid' => $courseid,
            'userid'   => $userid,
        ]);
        $live    = $data['live'] ?? [];
        $delayed = $data['delayed'] ?? [];
        $ok      = !empty($live['ok']) || !empty($delayed['ok']);
        $fields  = ['timeasked' => $now];
        if ($ok) {
            $fields['timefetched']    = $now;
            $fields['livelabel']      = (string)($live['label'] ?? '');
            $fields['liveseconds']    = (int)($live['seconds'] ?? 0);
            $fields['delayedlabel']   = (string)($delayed['label'] ?? '');
            $fields['delayedseconds'] = (int)($delayed['seconds'] ?? 0);
        }
        if ($row) {
            foreach ($fields as $k => $v) {
                $row->$k = $v;
            }
            $DB->update_record('report_courseradar_conex', $row);
            return;
        }
        $insert = [
            'courseid'        => $courseid,
            'userid'          => $userid,
            'timeasked'       => $now,
            'timefetched'     => 0,
            'livelabel'       => '',
            'liveseconds'     => 0,
            'delayedlabel'    => '',
            'delayedseconds'  => 0,
        ];
        $DB->insert_record('report_courseradar_conex', (object)($insert + $fields));
    }

    /**
     * JSON payload for the AJAX endpoint / template.
     *
     * @param \stdClass|null $row
     * @return array
     */
    public static function export(?\stdClass $row): array {
        $fetched = $row ? (int)$row->timefetched : 0;
        $live = ($row && (string)$row->livelabel !== '') ? (string)$row->livelabel : '…';
        $delayed = ($row && (string)$row->delayedlabel !== '') ? (string)$row->delayedlabel : '…';
        return [
            'live' => [
                'ok'      => $fetched > 0,
                'label'   => $live,
                'seconds' => $row ? (int)$row->liveseconds : 0,
            ],
            'delayed' => [
                'ok'      => $fetched > 0,
                'label'   => $delayed,
                'seconds' => $row ? (int)$row->delayedseconds : 0,
            ],
            'timefetched' => $fetched,
            'stale'       => !self::is_fresh($row),
        ];
    }

    /**
     * Call the API, store, and return the export payload.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @return array
     */
    public static function refresh_user(\stdClass $course, \stdClass $user): array {
        $data = conexiones_client::fetch_user($course, $user, true);
        self::save((int)$course->id, (int)$user->id, $data);
        $out = self::export(self::get((int)$course->id, (int)$user->id));
        $out['table'] = self::table_exists();
        $out['live'] = [
            'ok'      => !empty($data['live']['ok']),
            'label'   => (string)($data['live']['label'] ?? $out['live']['label']),
            'seconds' => (int)($data['live']['seconds'] ?? 0),
            'error'   => (string)($data['live']['error'] ?? ''),
            'request' => $data['live']['request'] ?? null,
        ];
        $out['delayed'] = [
            'ok'      => !empty($data['delayed']['ok']),
            'label'   => (string)($data['delayed']['label'] ?? $out['delayed']['label']),
            'seconds' => (int)($data['delayed']['seconds'] ?? 0),
            'error'   => (string)($data['delayed']['error'] ?? ''),
            'request' => $data['delayed']['request'] ?? null,
        ];
        if (!empty($data['live']['ok']) || !empty($data['delayed']['ok'])) {
            $out['timefetched'] = time();
            $out['stale'] = false;
        }
        return $out;
    }

    /**
     * Fetch pending/stale queue rows. Returns how many students were refreshed.
     *
     * @param int $limit
     * @return int
     */
    public static function process_queue(int $limit = self::TASK_LIMIT): int {
        global $DB;
        if (!self::table_exists()) {
            return 0;
        }

        $DB->delete_records_select(
            'report_courseradar_conex',
            'timeasked < :cutoff',
            ['cutoff' => time() - self::KEEP]
        );

        if (!conexiones_client::is_configured()) {
            return 0;
        }

        $stale = time() - self::TTL;
        $sql = "SELECT *
                  FROM {report_courseradar_conex}
                 WHERE timefetched = 0 OR timefetched < :stale
              ORDER BY timefetched ASC, timeasked DESC";
        $rows = $DB->get_records_sql($sql, ['stale' => $stale], 0, $limit);
        $done = 0;
        foreach ($rows as $row) {
            $course = $DB->get_record('course', ['id' => $row->courseid]);
            $user   = $DB->get_record('user', ['id' => $row->userid, 'deleted' => 0]);
            if (!$course || !$user) {
                $DB->delete_records('report_courseradar_conex', ['id' => $row->id]);
                continue;
            }
            self::refresh_user($course, $user);
            $done++;
        }
        return $done;
    }
}
