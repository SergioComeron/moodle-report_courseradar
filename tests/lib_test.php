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
 * @covers     \report_courseradar_barclass
 * @covers     \report_courseradar_get_students
 * @covers     \report_courseradar_atrisk
 * @covers     \report_courseradar_engagement_scores
 * @covers     \report_courseradar_score_bands
 * @covers     \report_courseradar_scatter_data
 * @covers     \report_courseradar_timesource
 * @covers     \report_courseradar_dedication
 * @covers     \report_courseradar_dedication_from_block
 * @covers     \report_courseradar_dedication_from_attendanceregister
 * @covers     \report_courseradar_dedication_average
 * @covers     \report_courseradar_format_dedication
 * @covers     \report_courseradar_student_display
 * @covers     \report_courseradar\conexiones_client
 * @covers     \report_courseradar\conexiones_store
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

    /**
     * Suspended accounts and suspended enrolments are not listed.
     */
    public function test_get_students_excludes_suspended(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $active = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $accountsuspended = $this->getDataGenerator()->create_and_enrol($course, 'student', ['suspended' => 1]);
        $enrolsuspended = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $context = \context_course::instance($course->id);

        global $DB;
        $ue = $DB->get_record('user_enrolments', ['userid' => $enrolsuspended->id], '*', MUST_EXIST);
        $ue->status = ENROL_USER_SUSPENDED;
        $DB->update_record('user_enrolments', $ue);

        $students = report_courseradar_get_students($context);

        $this->assertArrayHasKey($active->id, $students);
        $this->assertArrayNotHasKey($accountsuspended->id, $students);
        $this->assertArrayNotHasKey($enrolsuspended->id, $students);
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

    // Tests for report_courseradar_format_dedication.

    /**
     * Test that hours and minutes are both shown when present.
     */
    public function test_format_dedication_hours_and_minutes(): void {
        $this->assertEquals('3h 12m', report_courseradar_format_dedication((3 * HOURSECS) + (12 * MINSECS)));
        $this->assertEquals('2h', report_courseradar_format_dedication(2 * HOURSECS));
    }

    /**
     * Test that under one hour only minutes are shown.
     */
    public function test_format_dedication_minutes_only(): void {
        $this->assertEquals('45m', report_courseradar_format_dedication(45 * MINSECS));
        $this->assertEquals('1m', report_courseradar_format_dedication(90));
    }

    /**
     * Test that sub-minute and empty values are rendered as placeholders.
     */
    public function test_format_dedication_edge_values(): void {
        $this->assertEquals('< 1m', report_courseradar_format_dedication(30));
        $this->assertEquals('—', report_courseradar_format_dedication(0));
        $this->assertEquals('—', report_courseradar_format_dedication(-100));
    }

    // Tests for report_courseradar_dedication_average.

    /**
     * Test that the average only counts students with recorded time.
     */
    public function test_dedication_average_ignores_students_without_data(): void {
        $dedication = [1 => 3600, 2 => 1800, 3 => 0];
        $this->assertEquals(2700, report_courseradar_dedication_average($dedication));
    }

    /**
     * Test that an empty or all-zero dataset averages to zero.
     */
    public function test_dedication_average_without_data(): void {
        $this->assertEquals(0, report_courseradar_dedication_average([]));
        $this->assertEquals(0, report_courseradar_dedication_average([1 => 0, 2 => 0]));
    }

    // Tests for report_courseradar_dedication.

    /**
     * Test that no query is attempted when there are no students.
     */
    public function test_dedication_without_students(): void {
        $this->assertSame([], report_courseradar_dedication(1, []));
    }

    /**
     * Test that sessions are summed per student and filtered by date range.
     */
    public function test_dedication_sums_sessions_in_range(): void {
        global $DB;
        if (!report_courseradar_dedication_available()) {
            $this->markTestSkipped('block_dedication is not installed.');
        }
        $this->resetAfterTest();
        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other   = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $week    = time() - WEEKSECS;

        foreach ([[$student->id, 600, $week], [$student->id, 900, $week + DAYSECS], [$other->id, 300, $week]] as $row) {
            $DB->insert_record('block_dedication', (object)[
                'userid'    => $row[0],
                'courseid'  => $course->id,
                'timespent' => $row[1],
                'timestart' => $row[2],
            ]);
        }

        $all = report_courseradar_dedication($course->id, [$student->id, $other->id]);
        $this->assertEquals(1500, $all[$student->id]);
        $this->assertEquals(300, $all[$other->id]);

        // Only the second session falls inside this range.
        $ranged = report_courseradar_dedication($course->id, [$student->id], $week + HOURSECS);
        $this->assertEquals(900, $ranged[$student->id]);

        // A range that ends before any session returns nothing.
        $empty = report_courseradar_dedication($course->id, [$student->id], 0, $week - DAYSECS);
        $this->assertArrayNotHasKey($student->id, $empty);
    }

    /**
     * Unsaved timesource defaults to the Dedication block.
     */
    public function test_timesource_defaults_to_dedication(): void {
        $this->resetAfterTest();
        $this->assertEquals('dedication', report_courseradar_timesource());
    }

    /**
     * The configured timesource is the only plugin that counts as available.
     */
    public function test_dedication_available_follows_timesource_setting(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();

        set_config('timesource', 'dedication', 'report_courseradar');
        $this->assertEquals(
            class_exists('\block_dedication\lib\utils') && $dbman->table_exists('block_dedication'),
            report_courseradar_dedication_available()
        );

        set_config('timesource', 'attendanceregister', 'report_courseradar');
        $this->assertEquals(
            $dbman->table_exists('attendanceregister') && $dbman->table_exists('attendanceregister_session'),
            report_courseradar_dedication_available()
        );
    }

    /**
     * Attendance Register sessions are summed per student and filtered by date.
     */
    public function test_dedication_from_attendanceregister_sums_sessions_in_range(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('timesource', 'attendanceregister', 'report_courseradar');
        if (!report_courseradar_dedication_available()) {
            $this->markTestSkipped('mod_attendanceregister is not installed.');
        }
        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other   = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $week    = time() - WEEKSECS;

        $registerid = $DB->insert_record('attendanceregister', (object)[
            'course' => $course->id,
            'name' => 'Register',
            'type' => 'course',
            'offlinesessions' => 0,
            'sessiontimeout' => 30,
            'dayscertificable' => 10,
            'offlinecomments' => 0,
            'mandatoryofflinecomm' => 0,
            'offlinespecifycourse' => 0,
            'mandofflspeccourse' => 0,
            'timemodified' => time(),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'pendingrecalc' => 0,
            'completiontotaldurationmins' => 0,
        ]);
        $sessions = [
            [$student->id, 600, $week, 1],
            [$student->id, 900, $week + DAYSECS, 1],
            [$student->id, 1200, $week, 0],
            [$other->id, 300, $week, 1],
        ];
        foreach ($sessions as $row) {
            $DB->insert_record('attendanceregister_session', (object)[
                'register' => $registerid,
                'userid' => $row[0],
                'duration' => $row[1],
                'login' => $row[2],
                'logout' => $row[2] + $row[1],
                'onlinesess' => $row[3],
            ]);
        }

        $all = report_courseradar_dedication($course->id, [$student->id, $other->id]);
        $this->assertEquals(1500, $all[$student->id]);
        $this->assertEquals(300, $all[$other->id]);

        $ranged = report_courseradar_dedication($course->id, [$student->id], $week + HOURSECS);
        $this->assertEquals(900, $ranged[$student->id]);

        $empty = report_courseradar_dedication($course->id, [$student->id], 0, $week - DAYSECS);
        $this->assertArrayNotHasKey($student->id, $empty);
    }

    // Tests for report_courseradar_student_display.

    /**
     * Unsaved settings default to enabled so existing student views keep all sections.
     */
    public function test_student_display_defaults_all_enabled(): void {
        $this->resetAfterTest();
        $display = report_courseradar_student_display();
        $this->assertTrue($display['studentshowscore']);
        $this->assertTrue($display['studentshowcoverage']);
        $this->assertTrue($display['studentshowcompletion']);
        $this->assertTrue($display['studentshowdedication']);
        $this->assertTrue($display['studentshowdaysinactive']);
        $this->assertTrue($display['studentshowcomparison']);
        $this->assertTrue($display['studentshowpending']);
        $this->assertTrue($display['studentshowchart']);
    }

    /**
     * A checkbox stored as 0 hides that section and leaves the others on.
     */
    public function test_student_display_respects_disabled_setting(): void {
        $this->resetAfterTest();
        set_config('studentshowscore', 0, 'report_courseradar');
        set_config('studentshowcomparison', 0, 'report_courseradar');
        $display = report_courseradar_student_display();
        $this->assertFalse($display['studentshowscore']);
        $this->assertFalse($display['studentshowcomparison']);
        $this->assertTrue($display['studentshowcoverage']);
        $this->assertTrue($display['studentshowpending']);
    }

    /**
     * Connection payload with session list and HH:MM:SS is summarised to seconds.
     */
    public function test_conexiones_summarise_session_list(): void {
        $data = [
            'Sesiones' => [
                ['Titulo' => 'S1', 'Fecha' => '2026-01-10', 'TiempoTotal' => '01:00:00'],
                ['Titulo' => 'S2', 'Fecha' => '2026-01-17', 'DuracionSegundos' => 120],
            ],
        ];
        $out = \report_courseradar\conexiones_client::summarise($data);
        $this->assertTrue($out['ok']);
        $this->assertSame(2, $out['count']);
        $this->assertSame(3720, $out['seconds']);
        $this->assertSame('S1', $out['rows'][0]['title']);
    }

    /**
     * Real UDIMA payload (datosInforme + Asistencias + TiempoVimeo) is summarised.
     */
    public function test_conexiones_summarise_datos_informe_asistencias(): void {
        $data = [
            'correcto' => true,
            'datosInforme' => [
                'General' => [
                    'Horas' => "0h 0'",
                    'HorasVimeo' => "5h 43'",
                    'Duracion' => 0,
                ],
                'Asistencias' => [
                    ['Dia' => '30/09/2025', 'TiempoVimeo' => '00:51:34'],
                    ['Dia' => '07/10/2025', 'TiempoVimeo' => '00:14:07'],
                ],
                'Conexiones' => [],
            ],
        ];
        $out = \report_courseradar\conexiones_client::summarise($data);
        $this->assertTrue($out['ok']);
        $this->assertSame(2, $out['count']);
        $this->assertSame(5 * 3600 + 43 * 60, $out['seconds']);
        $this->assertSame('30/09/2025', $out['rows'][0]['when']);
    }

    /**
     * Live Asistencias: Inicio 15:59 is a clock, TiempoTotal is the duration.
     */
    public function test_conexiones_summarise_does_not_treat_clock_as_duration(): void {
        $data = [
            'datosInforme' => [
                'General' => ['Horas' => "2h 40'"],
                'Asistencias' => [
                    [
                        'Dia' => '10/03/2026',
                        'Inicio' => '15:59',
                        'Fin' => '16:54',
                        'TiempoTotal' => '55 minutos y 7 segundos',
                    ],
                ],
                'Conexiones' => [],
            ],
        ];
        $out = \report_courseradar\conexiones_client::summarise($data);
        $this->assertSame(1, $out['count']);
        $this->assertSame(2 * 3600 + 40 * 60, $out['seconds']);
        $this->assertSame('15:59', $out['rows'][0]['start']);
        $this->assertSame('55m', $out['rows'][0]['duration']);
    }

    /**
     * DuracionReal is the class length. It stays off the student total.
     */
    public function test_conexiones_summarise_keeps_class_duration_apart(): void {
        $live = \report_courseradar\conexiones_client::summarise([
            'datosInforme' => [
                'General' => ['Horas' => "0h 59'"],
                'Asistencias' => [
                    [
                        'Dia' => '28/09/2026',
                        'Inicio' => '13:33',
                        'Fin' => '14:33',
                        'DuracionReal' => "1h 3'",
                        'TiempoTotal' => '59 minutos y 50 segundos',
                    ],
                ],
            ],
        ], \report_courseradar\conexiones_client::TIPO_DIRECTO);
        $this->assertSame('59m', $live['rows'][0]['duration']);
        $this->assertSame('1h 3m', $live['rows'][0]['classduration']);
        $this->assertSame(63 * 60, $live['rows'][0]['classseconds']);
        $this->assertSame(59 * 60 + 50, $live['seconds']);

        $delayed = \report_courseradar\conexiones_client::summarise([
            'datosInforme' => [
                'General' => [
                    'Horas' => "0h 0'",
                    'HorasVimeo' => "1h 14'",
                ],
                'Asistencias' => [
                    ['Dia' => '28/09/2026', 'DuracionReal' => "1h 3'"],
                    ['Dia' => '05/10/2026', 'DuracionReal' => "2h 31'"],
                ],
            ],
        ], \report_courseradar\conexiones_client::TIPO_DIFERIDO);
        $this->assertSame(74 * 60, $delayed['seconds']);
        $this->assertSame('', $delayed['rows'][0]['duration']);
        $this->assertSame('1h 3m', $delayed['rows'][0]['classduration']);
        $this->assertSame('2h 31m', $delayed['rows'][1]['classduration']);
    }

    /**
     * Visionado rows pick up DuracionReal from the connection report, by day.
     */
    public function test_apply_class_durations_matches_informe_by_day(): void {
        $sessions = [
            ['when' => '28/09/2026', 'start' => '13:30', 'live' => 59 * 60, 'delayed' => 0],
            ['when' => '05/10/2026', 'start' => '13:30', 'live' => 0, 'delayed' => 74 * 60],
            ['when' => '08/10/2026', 'start' => '18:30', 'live' => 50 * 60, 'delayed' => 0],
            ['when' => '08/10/2026', 'start' => '10:30', 'live' => 40 * 60, 'delayed' => 0],
        ];
        $live = [
            ['when' => '28/09/2026', 'start' => '13:33', 'classseconds' => 63 * 60],
            ['when' => '08/10/2026', 'start' => '10:28', 'classseconds' => 50 * 60],
            ['when' => '08/10/2026', 'start' => '18:33', 'classseconds' => 90 * 60],
        ];
        $delayed = [
            ['when' => '28/09/2026', 'start' => '', 'classseconds' => 63 * 60],
            ['when' => '05/10/2026', 'start' => '', 'classseconds' => 151 * 60],
        ];
        $rows = \report_courseradar\conexiones_client::apply_class_durations($sessions, $live, $delayed);
        $this->assertSame('1h 3m', $rows[0]['classlabel']);
        $this->assertSame(63 * 60, $rows[0]['classseconds']);
        $this->assertSame('2h 31m', $rows[1]['classlabel']);
        $this->assertSame('1h 30m', $rows[2]['classlabel']);
        $this->assertSame('50m', $rows[3]['classlabel']);
    }

    /**
     * Live and on-demand totals keep the API percentage after the time.
     */
    public function test_conexiones_summarise_appends_percentages(): void {
        $live = \report_courseradar\conexiones_client::summarise([
            'datosInforme' => [
                'General' => [
                    'Horas' => "2h 40'",
                    'PorcentajeDirecto' => '75%',
                    'PorcentajeDiferido' => '10%',
                ],
                'Asistencias' => [],
            ],
        ], \report_courseradar\conexiones_client::TIPO_DIRECTO);
        $this->assertSame('2h 40m · 75%', $live['label']);
        $this->assertSame('75%', $live['percent']);

        $delayed = \report_courseradar\conexiones_client::summarise([
            'datosInforme' => [
                'General' => [
                    'HorasVimeo' => "5h 43'",
                    'PorcentajeDirecto' => '75%',
                    'PorcentajeDiferido' => '40%',
                ],
                'Asistencias' => [],
            ],
        ], \report_courseradar\conexiones_client::TIPO_DIFERIDO);
        $this->assertSame('5h 43m · 40%', $delayed['label']);
        $this->assertSame('40%', $delayed['percent']);
    }

    /**
     * HorasTelepresencia is the planned length. Zero watched time stays zero.
     */
    public function test_conexiones_summarise_ignores_telepresencia(): void {
        $data = [
            'datosInforme' => [
                'General' => [
                    'Horas' => "0h 0'",
                    'HorasVimeo' => "0h 0'",
                    'HorasTelepresencia' => "1h 30'",
                    'PorcentajeDiferido' => '0%',
                ],
                'Asistencias' => [],
            ],
        ];
        $out = \report_courseradar\conexiones_client::summarise(
            $data,
            \report_courseradar\conexiones_client::TIPO_DIFERIDO
        );
        $this->assertSame(0, $out['seconds']);
        $this->assertSame('0 · 0%', $out['label']);

        $live = \report_courseradar\conexiones_client::summarise([
            'datosInforme' => [
                'General' => [
                    'Horas' => "0h 32'",
                    'HorasVimeo' => "0h 0'",
                    'HorasTelepresencia' => "1h 30'",
                    'PorcentajeDirecto' => '36%',
                ],
                'Asistencias' => [],
            ],
        ], \report_courseradar\conexiones_client::TIPO_DIRECTO);
        $this->assertSame(32 * 60, $live['seconds']);
        $this->assertSame('32m · 36%', $live['label']);
    }

    /**
     * Live visionado is one row per class. The clock in FechaInicio is not a duration.
     */
    public function test_visionado_directo_scheduled_class(): void {
        $data = [
            'General' => ['TiempoTotal' => '1h 30\''],
            'Grabaciones' => [
                [
                    'IdReunion' => '8841',
                    'Fecha' => '13/10/2026',
                    'FechaInicio' => '13/10/2026 18:00',
                    'FechaFin' => '13/10/2026 20:00',
                    'TiempoTotalUnico' => '00:45:00',
                ],
            ],
        ];
        $out = \report_courseradar\conexiones_client::summarise_visionado(
            $data,
            \report_courseradar\conexiones_client::TIPO_DIRECTO
        );
        $this->assertTrue($out['ok']);
        $this->assertSame(1, $out['count']);
        $this->assertSame(90 * 60, $out['seconds']);
        $this->assertSame('1h 30m', $out['label']);
        $this->assertSame('13/10/2026', $out['rows'][0]['when']);
        $this->assertSame('18:00', $out['rows'][0]['start']);
        $this->assertSame('20:00', $out['rows'][0]['end']);
        $this->assertSame('45m', $out['rows'][0]['duration']);
        $this->assertSame(45 * 60, $out['rows'][0]['seconds']);
        $this->assertSame(2 * 3600, $out['rows'][0]['scheduled']);
        $this->assertSame('8841', $out['rows'][0]['reunion']);
        $this->assertSame([], $out['rows'][0]['recordings']);
    }

    /**
     * On-demand visionado has no FechaFin and may list several recordings for one class.
     */
    public function test_visionado_diferido_recordings_and_wrapper(): void {
        $data = [
            'datosInforme' => [
                'Grabaciones' => [
                    [
                        'IdReunion' => 12,
                        'Fecha' => '07/10/2025',
                        'FechaInicio' => '07/10/2025 16:30:00',
                        'TiempoTotalUnico' => 125,
                        'IdsGrabacion' => [10, '11'],
                    ],
                ],
            ],
        ];
        $out = \report_courseradar\conexiones_client::summarise_visionado(
            $data,
            \report_courseradar\conexiones_client::TIPO_DIFERIDO
        );
        $this->assertSame(1, $out['count']);
        $this->assertSame(125, $out['seconds']);
        $this->assertSame('2m', $out['label']);
        $this->assertSame('16:30', $out['rows'][0]['start']);
        $this->assertSame('', $out['rows'][0]['end']);
        $this->assertSame(125, $out['rows'][0]['seconds']);
        $this->assertSame(0, $out['rows'][0]['scheduled']);
        $this->assertSame(['10', '11'], $out['rows'][0]['recordings']);
        $this->assertSame('12', $out['rows'][0]['reunion']);
    }

    /**
     * Live and recording times for the same class share one row. Newest class first.
     */
    public function test_visionado_merges_live_and_recording_by_reunion(): void {
        $live = \report_courseradar\conexiones_client::summarise_visionado([
            'Grabaciones' => [
                [
                    'IdReunion' => '10',
                    'Fecha' => '01/10/2026',
                    'FechaInicio' => '01/10/2026 18:00',
                    'FechaFin' => '01/10/2026 20:00',
                    'TiempoTotalUnico' => '00:45:00',
                ],
                [
                    'IdReunion' => '11',
                    'Fecha' => '08/10/2026',
                    'FechaInicio' => '08/10/2026 18:00',
                    'FechaFin' => '08/10/2026 19:00',
                    'TiempoTotalUnico' => '00:20:00',
                ],
            ],
        ], \report_courseradar\conexiones_client::TIPO_DIRECTO);
        $delayed = \report_courseradar\conexiones_client::summarise_visionado([
            'Grabaciones' => [
                [
                    'IdReunion' => 10,
                    'Fecha' => '01/10/2026',
                    'FechaInicio' => '01/10/2026 18:00',
                    'TiempoTotalUnico' => '01:10:00',
                    'IdsGrabacion' => [3],
                ],
            ],
        ], \report_courseradar\conexiones_client::TIPO_DIFERIDO);

        $rows = \report_courseradar\conexiones_client::merge_session_rows($live['rows'], $delayed['rows']);
        $this->assertCount(2, $rows);
        $this->assertSame('08/10/2026', $rows[0]['when']);
        $this->assertSame(20 * 60, $rows[0]['live']);
        $this->assertSame(0, $rows[0]['delayed']);
        $this->assertSame('—', $rows[0]['delayedlabel']);
        $this->assertSame(3600, $rows[0]['scheduled']);
        $this->assertSame('01/10/2026', $rows[1]['when']);
        $this->assertSame(45 * 60, $rows[1]['live']);
        $this->assertSame(70 * 60, $rows[1]['delayed']);
        $this->assertSame('1h 10m', $rows[1]['delayedlabel']);
        $this->assertSame(2 * 3600, $rows[1]['scheduled']);
        $this->assertSame('18:00', $rows[1]['start']);
        $this->assertSame('20:00', $rows[1]['end']);
    }

    /**
     * Without General.TiempoTotal the class times are added together.
     */
    public function test_visionado_sums_rows_when_general_is_missing(): void {
        $out = \report_courseradar\conexiones_client::summarise_visionado([
            'Grabaciones' => [
                ['Fecha' => '01/10/2026', 'FechaInicio' => '01/10/2026 09:00', 'TiempoTotalUnico' => '00:10:00'],
                ['Fecha' => '08/10/2026', 'FechaInicio' => '08/10/2026 09:00', 'TiempoTotalUnico' => '00:20:00'],
            ],
        ], \report_courseradar\conexiones_client::TIPO_DIFERIDO);
        $this->assertSame(30 * 60, $out['seconds']);
        $this->assertSame(2, $out['count']);
    }

    /**
     * Empty or unknown payload does not throw and reports zero.
     */
    public function test_conexiones_summarise_empty(): void {
        $out = \report_courseradar\conexiones_client::summarise(null);
        $this->assertTrue($out['ok']);
        $this->assertSame(0, $out['count']);
        $this->assertSame(0, $out['seconds']);
    }

    /**
     * API is not configured without Zoom or local OAuth settings.
     */
    public function test_conexiones_not_configured_by_default(): void {
        $this->resetAfterTest();
        set_config('reusezoomapi', 0, 'report_courseradar');
        $this->assertFalse(\report_courseradar\conexiones_client::is_configured());
    }

    /**
     * Queue rows are created and freshness follows TTL.
     */
    public function test_conexiones_store_ask_and_freshness(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user   = $this->getDataGenerator()->create_user();
        \report_courseradar\conexiones_store::ask_many((int)$course->id, [(int)$user->id]);
        $row = \report_courseradar\conexiones_store::get((int)$course->id, (int)$user->id);
        $this->assertNotNull($row);
        $this->assertFalse(\report_courseradar\conexiones_store::is_fresh($row));
        $row->timefetched = time();
        $row->livelabel = '1h';
        \report_courseradar\conexiones_store::save((int)$course->id, (int)$user->id, [
            'live'    => ['ok' => true, 'label' => '1h', 'seconds' => 3600],
            'delayed' => ['ok' => true, 'label' => '0', 'seconds' => 0],
        ]);
        $fresh = \report_courseradar\conexiones_store::get((int)$course->id, (int)$user->id);
        $this->assertTrue(\report_courseradar\conexiones_store::is_fresh($fresh));
        $this->assertSame('1h', $fresh->livelabel);
    }
}
