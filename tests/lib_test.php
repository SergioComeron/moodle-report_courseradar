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

global $CFG;
require_once($CFG->dirroot . '/report/courseradar/locallib.php');

/**
 * Unit tests for report_courseradar locallib.php
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::report_courseradar_barclass
 * @covers     ::report_courseradar_get_students
 * @covers     ::report_courseradar_atrisk
 */
final class lib_test extends \advanced_testcase {

    // ── report_courseradar_barclass ───────────────────────────────────────────

    /**
     * Test that 70% or above returns the success class.
     */
    public function test_barclass_high_returns_success(): void {
        $this->assertEquals('bg-success', report_courseradar_barclass(70));
        $this->assertEquals('bg-success', report_courseradar_barclass(100));
    }

    /**
     * Test that 30–69% returns the warning class.
     */
    public function test_barclass_medium_returns_warning(): void {
        $this->assertEquals('bg-warning', report_courseradar_barclass(30));
        $this->assertEquals('bg-warning', report_courseradar_barclass(69));
    }

    /**
     * Test that below 30% returns the danger class.
     */
    public function test_barclass_low_returns_danger(): void {
        $this->assertEquals('bg-danger', report_courseradar_barclass(0));
        $this->assertEquals('bg-danger', report_courseradar_barclass(29));
    }

    // ── report_courseradar_atrisk ─────────────────────────────────────────────

    /**
     * Test that a student with zero views is placed in the none bucket.
     */
    public function test_atrisk_no_activity(): void {
        $student = (object)['id' => 1, 'firstname' => 'Ana', 'lastname' => 'García'];
        $students = [1 => $student];
        $result = report_courseradar_atrisk($students, [], 10);
        $this->assertArrayHasKey(1, $result['none']);
        $this->assertEmpty($result['low']);
    }

    /**
     * Test that a student visiting fewer than 30% of resources is low-risk.
     */
    public function test_atrisk_low_engagement(): void {
        $student = (object)['id' => 2, 'firstname' => 'Juan', 'lastname' => 'López'];
        $students = [2 => $student];
        // 2 out of 10 modules visited = 20%.
        $studentlog = [2 => [1 => 3, 2 => 1]];
        $result = report_courseradar_atrisk($students, $studentlog, 10);
        $this->assertEmpty($result['none']);
        $this->assertArrayHasKey(2, $result['low']);
    }

    /**
     * Test that a student visiting 30% or more is not at risk.
     */
    public function test_atrisk_adequate_engagement(): void {
        $student = (object)['id' => 3, 'firstname' => 'María', 'lastname' => 'Ruiz'];
        $students = [3 => $student];
        // 3 out of 10 modules = 30%.
        $studentlog = [3 => [1 => 1, 2 => 1, 3 => 1]];
        $result = report_courseradar_atrisk($students, $studentlog, 10);
        $this->assertEmpty($result['none']);
        $this->assertEmpty($result['low']);
    }

    /**
     * Test that with zero modules no student is flagged as low-risk.
     */
    public function test_atrisk_zero_modules(): void {
        $student = (object)['id' => 4, 'firstname' => 'Pedro', 'lastname' => 'Sanz'];
        $students = [4 => $student];
        $result = report_courseradar_atrisk($students, [], 0);
        $this->assertEmpty($result['low']);
    }

    // ── report_courseradar_get_students ───────────────────────────────────────

    /**
     * Test that teachers are excluded and students are included.
     */
    public function test_get_students_excludes_teachers(): void {
        $this->resetAfterTest();

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $context = \context_course::instance($course->id);

        $students = report_courseradar_get_students($context);

        $this->assertArrayHasKey($student->id, $students);
        $this->assertArrayNotHasKey($teacher->id, $students);
    }

    /**
     * Test that editing teachers are excluded.
     */
    public function test_get_students_excludes_editing_teachers(): void {
        $this->resetAfterTest();

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $context = \context_course::instance($course->id);

        $students = report_courseradar_get_students($context);

        $this->assertArrayHasKey($student->id, $students);
        $this->assertArrayNotHasKey($teacher->id, $students);
    }

    /**
     * Test that students are sorted by lastname then firstname.
     */
    public function test_get_students_sorted_by_lastname(): void {
        $this->resetAfterTest();

        $course   = $this->getDataGenerator()->create_course();
        $context  = \context_course::instance($course->id);

        $s1 = $this->getDataGenerator()->create_and_enrol($course, 'student',
            ['firstname' => 'Ana', 'lastname' => 'Zorro']);
        $s2 = $this->getDataGenerator()->create_and_enrol($course, 'student',
            ['firstname' => 'Luis', 'lastname' => 'Álvarez']);

        $students = report_courseradar_get_students($context);
        $keys = array_keys($students);

        $this->assertEquals($s2->id, $keys[0]);
        $this->assertEquals($s1->id, $keys[1]);
    }

    // ── Capability checks ─────────────────────────────────────────────────────

    /**
     * Test that teachers have the view capability.
     */
    public function test_capability_view_granted_to_teacher(): void {
        $this->resetAfterTest();

        $course  = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $context = \context_course::instance($course->id);

        $this->assertTrue(has_capability('report/courseradar:view', $context, $teacher->id));
    }

    /**
     * Test that editing teachers have the view capability.
     */
    public function test_capability_view_granted_to_editing_teacher(): void {
        $this->resetAfterTest();

        $course  = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $context = \context_course::instance($course->id);

        $this->assertTrue(has_capability('report/courseradar:view', $context, $teacher->id));
    }

    /**
     * Test that students do not have the view capability.
     */
    public function test_capability_view_denied_to_student(): void {
        $this->resetAfterTest();

        $course   = $this->getDataGenerator()->create_course();
        $student  = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $context  = \context_course::instance($course->id);

        $this->assertFalse(has_capability('report/courseradar:view', $context, $student->id));
    }
}
