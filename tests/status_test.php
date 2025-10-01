<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_reminders;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

use advanced_testcase;
use completion_info;
use mod_assign_test_generator;

/**
 * Test status class.
 *
 * @package     local_reminders
 * @author      Alexander Van der Bellen <alexandervanderbellen@catalyst-au.net>
 * @copyright   2025 Catalyst IT Australia Pty Ltd
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status_test extends advanced_testcase {

    // Include the assign test generator helper trait.
    use mod_assign_test_generator;

    /**
     * Test return values of get_status.
     *
     * @covers ::get_status
     */
    public function test_statuses() {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Assignment that requires submission and grading to complete.
        $assign1 = $this->create_instance($course, [
            'assignsubmission_onlinetext_enabled' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
            'completionusegrade' => 1,
        ]);

        // Assignment that requires submission, grading and a passing grade to complete.
        $assign2 = $this->create_instance($course, [
            'assignsubmission_onlinetext_enabled' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
            'gradepass' => 50,
        ]);

        // Page activity that requires viewing to complete.
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1,
        ]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $student1 = $this->getDataGenerator()->create_and_enrol($course);
        $student2 = $this->getDataGenerator()->create_and_enrol($course);

        // Simulate the student viewing the page to trigger completion.
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($page->cmid);
        $completion = new completion_info($course);
        $completion->set_module_viewed($cm, $student2->id);

        // Check if the page activity is marked as completed for the student.
        $status = new status($course->id);
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student1->id, $page->cmid));
        $this->assertEquals(status::STATUS_COMPLETED, $status->get_status($student2->id, $page->cmid));

        // Check the initial status of assignments.
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student1->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student1->id, $assign2->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign2->get_course_module()->id));

        // Add a draft submission for student 1.
        $this->add_submission($student1, $assign1, 'Assignment submission text alpha.');
        $this->add_submission($student1, $assign2, 'Graded assignment submission text alpha.');

        $status = new status($course->id);
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student1->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student1->id, $assign2->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign2->get_course_module()->id));

        // Submit assignment for student 1.
        $this->submit_for_grading($student1, $assign1);
        $this->submit_for_grading($student1, $assign2);

        $status = new status($course->id);
        $this->assertEquals(status::STATUS_SUBMITTED, $status->get_status($student1->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_SUBMITTED, $status->get_status($student1->id, $assign2->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign2->get_course_module()->id));

        // Resubmit assignments for student 1 before grading.
        $this->add_submission($student1, $assign1, 'Assignment submission text beta.');
        $this->add_submission($student1, $assign2, 'Graded assignment submission text beta.');
        $this->submit_for_grading($student1, $assign1);
        $this->submit_for_grading($student1, $assign2);

        $status = new status($course->id);
        // Since the assignment has not been graded yet, the status should still be submitted.
        $this->assertEquals(status::STATUS_SUBMITTED, $status->get_status($student1->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_SUBMITTED, $status->get_status($student1->id, $assign2->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign2->get_course_module()->id));

        // Grade the assignments for student 1.
        $this->mark_submission($teacher, $assign1, $student1, 25.0);
        $this->mark_submission($teacher, $assign2, $student1, 25.0);

        $status = new status($course->id);
        $this->assertEquals(status::STATUS_COMPLETED, $status->get_status($student1->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_COMPLETED_FAIL, $status->get_status($student1->id, $assign2->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign1->get_course_module()->id));
        $this->assertEquals(status::STATUS_NOT_SUBMITTED, $status->get_status($student2->id, $assign2->get_course_module()->id));
    }
}
