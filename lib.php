<?php
defined('MOODLE_INTERNAL') || die();

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
