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
 * @covers     ::report_courseradar_engagement_scores
 * @covers     ::report_courseradar_score_bands
 * @covers     ::report_courseradar_scatter_data
 */
final class lib_test extends \advanced_testcase {
    // Tests for report_courseradar_barclass.

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

    // Tests for report_courseradar_atrisk.

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

    // Tests for report_courseradar_get_students.

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

        $s1 = $this->getDataGenerator()->create_and_enrol(
            $course,
            'student',
            ['firstname' => 'Ana', 'lastname' => 'Zorro']
        );
        $s2 = $this->getDataGenerator()->create_and_enrol(
            $course,
            'student',
            ['firstname' => 'Luis', 'lastname' => 'Alvarez']
        );

        $students = report_courseradar_get_students($context);
        $keys = array_keys($students);

        $this->assertEquals($s2->id, $keys[0]);
        $this->assertEquals($s1->id, $keys[1]);
    }

    // Capability checks.

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

    // Tests for report_courseradar_days_inactive.

    /**
     * Test that a timestamp of zero returns -1 (never accessed).
     */
    public function test_days_inactive_never_accessed(): void {
        $this->assertEquals(-1, report_courseradar_days_inactive(0));
    }

    /**
     * Test that a recent timestamp returns the correct number of days.
     */
    public function test_days_inactive_three_days_ago(): void {
        $lastaccess = time() - 3 * DAYSECS;
        $this->assertEquals(3, report_courseradar_days_inactive($lastaccess));
    }

    /**
     * Test that today's timestamp returns zero days inactive.
     */
    public function test_days_inactive_today(): void {
        $this->assertEquals(0, report_courseradar_days_inactive(time()));
    }

    // Tests for report_courseradar_inactive_class.

    /**
     * Test that -1 (never) returns the danger class.
     */
    public function test_inactive_class_never(): void {
        $this->assertStringContainsString('bg-danger', report_courseradar_inactive_class(-1));
    }

    /**
     * Test that 7 days or fewer returns the success class.
     */
    public function test_inactive_class_recent(): void {
        $this->assertStringContainsString('bg-success', report_courseradar_inactive_class(7));
        $this->assertStringContainsString('bg-success', report_courseradar_inactive_class(0));
    }

    /**
     * Test that 8-14 days returns the warning class.
     */
    public function test_inactive_class_moderate(): void {
        $this->assertStringContainsString('bg-warning', report_courseradar_inactive_class(8));
        $this->assertStringContainsString('bg-warning', report_courseradar_inactive_class(14));
    }

    /**
     * Test that more than 14 days returns the danger class.
     */
    public function test_inactive_class_long(): void {
        $this->assertStringContainsString('bg-danger', report_courseradar_inactive_class(15));
        $this->assertStringContainsString('bg-danger', report_courseradar_inactive_class(60));
    }

    // Tests for report_courseradar_top_unseen.

    /**
     * Test that modules with 100% coverage are excluded.
     */
    public function test_top_unseen_excludes_fully_seen(): void {
        $cm = (object)['id' => 1, 'visible' => 1];
        $logdata = [1 => (object)['uniqueusers' => 5]];
        $result  = report_courseradar_top_unseen([1 => $cm], $logdata, 5);
        $this->assertEmpty($result);
    }

    /**
     * Test that hidden modules are excluded.
     */
    public function test_top_unseen_excludes_hidden(): void {
        $cm = (object)['id' => 2, 'visible' => 0];
        $result = report_courseradar_top_unseen([2 => $cm], [], 5);
        $this->assertEmpty($result);
    }

    /**
     * Test that results are sorted by coverage ascending (least seen first).
     */
    public function test_top_unseen_sorted_ascending(): void {
        $cm1 = (object)['id' => 1, 'visible' => 1];
        $cm2 = (object)['id' => 2, 'visible' => 1];
        $logdata = [
            1 => (object)['uniqueusers' => 8],
            2 => (object)['uniqueusers' => 2],
        ];
        $result = report_courseradar_top_unseen([1 => $cm1, 2 => $cm2], $logdata, 10);
        $this->assertEquals(2, $result[0]['cm']->id);
        $this->assertEquals(1, $result[1]['cm']->id);
    }

    /**
     * Test that the limit parameter is respected.
     */
    public function test_top_unseen_limit(): void {
        $cms = [];
        $logdata = [];
        for ($i = 1; $i <= 15; $i++) {
            $cms[$i] = (object)['id' => $i, 'visible' => 1];
        }
        $result = report_courseradar_top_unseen($cms, $logdata, 10, 5);
        $this->assertCount(5, $result);
    }

    /**
     * Test that zero students returns an empty array.
     */
    public function test_top_unseen_no_students(): void {
        $cm = (object)['id' => 1, 'visible' => 1];
        $result = report_courseradar_top_unseen([1 => $cm], [], 0);
        $this->assertEmpty($result);
    }

    // Tests for report_courseradar_engagement_scores.

    /**
     * Test that a student who never accessed scores 0.
     */
    public function test_engagement_scores_never_accessed(): void {
        $student  = (object)['id' => 1, 'firstname' => 'Ana', 'lastname' => 'García'];
        $students = [1 => $student];
        $scores   = report_courseradar_engagement_scores(
            $students,
            [],
            [1 => -1],
            10,
            false,
            0,
            []
        );
        $this->assertEquals(0, $scores[1]);
    }

    /**
     * Test that visiting all resources and accessing today gives 100.
     */
    public function test_engagement_scores_full_engagement(): void {
        $student    = (object)['id' => 1, 'firstname' => 'Ana', 'lastname' => 'García'];
        $students   = [1 => $student];
        $studentlog = [1 => array_fill_keys(range(1, 10), 1)];
        $scores     = report_courseradar_engagement_scores(
            $students,
            $studentlog,
            [1 => 0],
            10,
            false,
            0,
            []
        );
        $this->assertEquals(100, $scores[1]);
    }

    /**
     * Test that completion weight is applied when tracking is enabled.
     */
    public function test_engagement_scores_with_completion(): void {
        $student    = (object)['id' => 1, 'firstname' => 'Ana', 'lastname' => 'García'];
        $students   = [1 => $student];
        $studentlog = [1 => array_fill_keys(range(1, 10), 1)];
        $scores     = report_courseradar_engagement_scores(
            $students,
            $studentlog,
            [1 => 0],
            10,
            true,
            10,
            [1 => 10]
        );
        $this->assertEquals(100, $scores[1]);
    }

    /**
     * Test that score is capped at 100.
     */
    public function test_engagement_scores_capped_at_100(): void {
        $student    = (object)['id' => 1, 'firstname' => 'Ana', 'lastname' => 'García'];
        $students   = [1 => $student];
        $studentlog = [1 => array_fill_keys(range(1, 20), 5)];
        $scores     = report_courseradar_engagement_scores(
            $students,
            $studentlog,
            [1 => 0],
            10,
            false,
            0,
            []
        );
        $this->assertLessThanOrEqual(100, $scores[1]);
    }

    /**
     * Test that zero modules gives score 0 regardless of recency.
     */
    public function test_engagement_scores_zero_modules(): void {
        $student  = (object)['id' => 1, 'firstname' => 'Ana', 'lastname' => 'García'];
        $students = [1 => $student];
        $scores   = report_courseradar_engagement_scores(
            $students,
            [],
            [1 => 0],
            0,
            false,
            0,
            []
        );
        $this->assertEquals(50, $scores[1]);
    }

    // Tests for report_courseradar_score_bands.

    /**
     * Test that empty scores return all bands at zero.
     */
    public function test_score_bands_empty(): void {
        $result = report_courseradar_score_bands([]);
        $this->assertEquals([0 => 0, 20 => 0, 40 => 0, 60 => 0, 80 => 0], $result);
    }

    /**
     * Test that each score falls in the correct band.
     */
    public function test_score_bands_distribution(): void {
        $scores = [1 => 10, 2 => 25, 3 => 45, 4 => 65, 5 => 85];
        $result = report_courseradar_score_bands($scores);
        $this->assertEquals(1, $result[0]);
        $this->assertEquals(1, $result[20]);
        $this->assertEquals(1, $result[40]);
        $this->assertEquals(1, $result[60]);
        $this->assertEquals(1, $result[80]);
    }

    /**
     * Test that a score of 100 falls in the 80-100 band.
     */
    public function test_score_bands_score_100_in_top_band(): void {
        $result = report_courseradar_score_bands([1 => 100]);
        $this->assertEquals(1, $result[80]);
    }

    /**
     * Test that boundary value 20 falls in the 20-39 band.
     */
    public function test_score_bands_boundary_20(): void {
        $result = report_courseradar_score_bands([1 => 20]);
        $this->assertEquals(1, $result[20]);
        $this->assertEquals(0, $result[0]);
    }

    // Tests for report_courseradar_scatter_data.

    /**
     * Test that x is the correct % of resources visited.
     */
    public function test_scatter_data_visited_percentage(): void {
        $this->resetAfterTest();
        $course     = $this->getDataGenerator()->create_course();
        $student    = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $students   = [$student->id => $student];
        $studentlog = [$student->id => [1 => 2, 2 => 1]];
        $riskscores = [$student->id => 55];

        $result = report_courseradar_scatter_data($students, $studentlog, $riskscores, 10, $course->id);

        $this->assertCount(1, $result);
        $this->assertEquals(20, $result[0]['x']);
        $this->assertEquals(55, $result[0]['y']);
    }

    /**
     * Test that x is 0 when there are no modules.
     */
    public function test_scatter_data_zero_modules(): void {
        $this->resetAfterTest();
        $course     = $this->getDataGenerator()->create_course();
        $student    = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $students   = [$student->id => $student];
        $riskscores = [$student->id => 0];

        $result = report_courseradar_scatter_data($students, [], $riskscores, 0, $course->id);

        $this->assertEquals(0, $result[0]['x']);
    }

    /**
     * Test that the profile URL contains the student and course IDs.
     */
    public function test_scatter_data_url_contains_ids(): void {
        $this->resetAfterTest();
        $course     = $this->getDataGenerator()->create_course();
        $student    = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $students   = [$student->id => $student];
        $riskscores = [$student->id => 30];

        $result = report_courseradar_scatter_data($students, [], $riskscores, 5, $course->id);

        $this->assertStringContainsString('id=' . $student->id, $result[0]['url']);
        $this->assertStringContainsString('course=' . $course->id, $result[0]['url']);
    }
}
