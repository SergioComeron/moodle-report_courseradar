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
 * Library functions for report_courseradar.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Adds the Course Radar link to the course navigation reports menu.
 *
 * @param navigation_node $navigation The navigation node to extend.
 * @param stdClass        $course     The course object.
 * @param context_course  $context    The course context.
 */
function report_courseradar_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('report/courseradar:view', $context)) {
        $url = new moodle_url('/report/courseradar/index.php', ['id' => $course->id]);
        $navigation->add(
            get_string('pluginname', 'report_courseradar'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            null,
            new pix_icon('i/report', '')
        );
    }
}
