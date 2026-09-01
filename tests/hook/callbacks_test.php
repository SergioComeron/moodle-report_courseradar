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

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for report_courseradar secondary navigation hook.
 *
 * @package    report_courseradar
 * @category   test
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_courseradar\hook\callbacks
 */
final class callbacks_test extends \advanced_testcase {
    /**
     * Enrol a user, set the course page, and initialise secondary nav.
     *
     * @param string $role Shortname of the role to enrol.
     * @return \navigation_node
     */
    private function init_course_secondary(string $role): \navigation_node {
        global $PAGE;

        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_and_enrol($course, $role);
        $this->setUser($user);

        $PAGE->set_course($course);
        $PAGE->set_context(\context_course::instance($course->id));
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->secondarynav->initialise();

        return $PAGE->secondarynav;
    }

    /**
     * Students get a Course Radar node forced into the More menu.
     */
    public function test_student_sees_courseradar_in_more_menu(): void {
        $this->resetAfterTest();

        $nav  = $this->init_course_secondary('student');
        $node = $nav->find('courseradar', \navigation_node::TYPE_CUSTOM);

        $this->assertNotNull($node);
        $this->assertTrue($node->forceintomoremenu);
    }

    /**
     * Teachers keep the Reports entry; they must not get a duplicate More-menu link.
     */
    public function test_teacher_does_not_get_student_nav_link(): void {
        $this->resetAfterTest();

        $nav  = $this->init_course_secondary('editingteacher');
        $node = $nav->find('courseradar', \navigation_node::TYPE_CUSTOM);

        $this->assertNull($node);
    }
}
