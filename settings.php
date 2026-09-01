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
 * Admin settings for report_courseradar.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'report_courseradar/studentviewheading',
        get_string('studentviewheading', 'report_courseradar'),
        get_string('studentviewheading_desc', 'report_courseradar')
    ));

    $checkboxes = [
        'studentshowscore',
        'studentshowcoverage',
        'studentshowcompletion',
        'studentshowdedication',
        'studentshowdaysinactive',
        'studentshowcomparison',
        'studentshowpending',
        'studentshowchart',
    ];
    foreach ($checkboxes as $name) {
        $settings->add(new admin_setting_configcheckbox(
            'report_courseradar/' . $name,
            get_string($name, 'report_courseradar'),
            get_string($name . '_desc', 'report_courseradar'),
            1
        ));
    }
}
