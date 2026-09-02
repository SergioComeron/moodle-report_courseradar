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

namespace report_courseradar\task;

/**
 * Refresh queued Zoom/Vimeo connection totals.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_conexiones extends \core\task\scheduled_task {
    /**
     * Task name shown in the scheduled-task UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrefreshconexiones', 'report_courseradar');
    }

    /**
     * Process a limited slice of the student queue.
     */
    public function execute(): void {
        $done = \report_courseradar\conexiones_store::process_queue();
        mtrace('Refreshed ' . $done . ' student connection records.');
    }
}
