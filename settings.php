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
        'report_courseradar/conexionesapiheading',
        get_string('conexionesapiheading', 'report_courseradar'),
        get_string('conexionesapiheading_desc', 'report_courseradar')
    ));
    $settings->add(new admin_setting_configcheckbox(
        'report_courseradar/reusezoomapi',
        get_string('conexionesreusezoom', 'report_courseradar'),
        get_string('conexionesreusezoom_desc', 'report_courseradar'),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'report_courseradar/client_id',
        get_string('clientid', 'report_courseradar'),
        get_string('clientid_desc', 'report_courseradar'),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'report_courseradar/client_secret',
        get_string('clientsecret', 'report_courseradar'),
        get_string('clientsecret_desc', 'report_courseradar'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'report_courseradar/token_url',
        get_string('tokenurl', 'report_courseradar'),
        get_string('tokenurl_desc', 'report_courseradar'),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configtext(
        'report_courseradar/scope',
        get_string('scope', 'report_courseradar'),
        get_string('scope_desc', 'report_courseradar'),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtextarea(
        'report_courseradar/baseurl',
        get_string('baseurl', 'report_courseradar'),
        get_string('baseurl_desc', 'report_courseradar'),
        ''
    ));
    $settings->add(new admin_setting_configselect(
        'report_courseradar/campus',
        get_string('campus', 'report_courseradar'),
        get_string('campus_desc', 'report_courseradar'),
        'udima',
        [
            'udima' => 'UDIMA',
            'cef'   => 'CEF',
        ]
    ));

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
        'studentshowconexiones',
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
