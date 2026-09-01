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

namespace report_courseradar\hook;

/**
 * Hook callbacks for report_courseradar.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class callbacks {
    /**
     * Adds a Course Radar link to the course secondary nav for students.
     *
     * Teachers already reach the report from Course administration → Reports
     * (gated by report/courseradar:view). Students do not see that menu, so
     * this is their in-Moodle entry point when bloquecero is not on the course.
     *
     * @param \core\hook\navigation\secondary_extend $hook
     */
    public static function extend_secondary(\core\hook\navigation\secondary_extend $hook): void {
        global $PAGE, $USER;

        if (!$PAGE->context || $PAGE->context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $course = $PAGE->course;
        if (empty($course->id) || $course->id == SITEID) {
            return;
        }

        $context = $PAGE->context;
        if (has_capability('report/courseradar:view', $context)) {
            return;
        }
        if (!is_enrolled($context, $USER, '', true) && !is_role_switched($course->id)) {
            return;
        }

        $node = $hook->get_secondaryview()->add(
            get_string('pluginname', 'report_courseradar'),
            new \moodle_url('/report/courseradar/index.php', ['id' => $course->id]),
            \navigation_node::TYPE_CUSTOM,
            null,
            'courseradar',
            new \pix_icon('i/report', '')
        );
        $node->set_force_into_more_menu(true);
    }
}
