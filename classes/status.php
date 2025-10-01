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

/**
 * Helper class to determine the status of activities for users in a course.
 *
 * @package     local_reminders
 * @author      Alexander Van der Bellen <alexandervanderbellen@catalyst-au.net>
 * @copyright   2025 Catalyst IT Australia Pty Ltd
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status {
    /** General not submitted status. */
    public const STATUS_NOT_SUBMITTED = 1 << 0;
    /** Submitted status for assignments and quizzes. */
    public const STATUS_SUBMITTED = 1 << 1;
    /** General completed status. */
    public const STATUS_COMPLETED = 1 << 2;
    /** General completed pass status. */
    public const STATUS_COMPLETED_PASS = 1 << 3;
    /** General completed fail status. */
    public const STATUS_COMPLETED_FAIL = 1 << 4;

    /** Separator for making unique ids. */
    private const SEPARATOR = '#';

    /** @var int The course id. */
    private $courseid;
    /** @var array Submitted assignments and quizzes. */
    private $submitted = [];
    /** @var array Activity completion states. */
    private $completion = [];

    /**
     * Initialise the status object to retrieve activity completion status for users in the course.
     *
     * @param int $courseid The course id.
     */
    public function __construct(int $courseid) {
        $this->courseid = $courseid;
        $this->init_submitted();
        $this->init_completion();
    }

    /**
     * Check if the user has submitted the assignment or quiz.
     *
     * @param int $userid The user id.
     * @param int $cmid The course module id.
     * @return bool True if the user has made a submission, false otherwise.
     */
    public function is_submitted(int $userid, int $cmid): bool {
        return isset($this->submitted[$userid . self::SEPARATOR . $cmid]);
    }

    /**
     * Get the completion state of the user for the activity.
     *
     * @param int $userid The user id.
     * @param int $cmid The course module id.
     * @return int The completion state of the user for the activity.
     */
    public function get_completion(int $userid, int $cmid): int {
        return $this->completion[$userid . self::SEPARATOR . $cmid] ?? COMPLETION_INCOMPLETE;
    }

    /**
     * Get the activity status of the user.
     *
     * @param int $userid The user id.
     * @param int $cmid The course module id.
     * @return int The status of the user for the activity.
     */
    public function get_status(int $userid, int $cmid): int {
        // Default status is not submitted.
        $status = self::STATUS_NOT_SUBMITTED;

        // Check if the user has submitted the assignment.
        if ($this->is_submitted($userid, $cmid)) {
            $status = self::STATUS_SUBMITTED;
        }

        // Standard completion status has priority over submitted status.
        switch ($this->get_completion($userid, $cmid)) {
            case COMPLETION_COMPLETE:
                $status = self::STATUS_COMPLETED;
                break;
            case COMPLETION_COMPLETE_PASS:
                $status = self::STATUS_COMPLETED_PASS;
                break;
            case COMPLETION_COMPLETE_FAIL:
                $status = self::STATUS_COMPLETED_FAIL;
                break;
        }

        return $status;
    }

    /**
     * Initialise the submitted member variable.
     */
    private function init_submitted(): void {
        global $DB;

        // Find all submitted assignments in the course.
        $assignconcat = $DB->sql_concat('s.userid', "'" . self::SEPARATOR . "'", 'cm.id');
        $assignsql = "SELECT DISTINCT {$assignconcat} AS uniqueid
                        FROM {assign_submission} s
                        JOIN {course_modules} cm ON cm.instance = s.assignment
                        JOIN {modules} m ON m.id = cm.module
                       WHERE cm.course = :assigncourseid
                             AND m.name = 'assign'
                             AND s.status = 'submitted'";

        // Find all finished or abandoned quiz attempts in the course.
        $quizconcat = $DB->sql_concat('qa.userid', "'" . self::SEPARATOR . "'", 'cm.id');
        $quizsql = "SELECT DISTINCT {$quizconcat} AS uniqueid
                      FROM {quiz_attempts} qa
                      JOIN {course_modules} cm ON cm.instance = qa.quiz
                      JOIN {modules} m ON m.id = cm.module
                     WHERE cm.course = :quizcourseid
                           AND m.name = 'quiz'
                           AND qa.state IN ('finished', 'abandoned')";

        // Combine the results into one query.
        $sql = "SELECT uniqueid FROM (({$assignsql}) UNION ALL ({$quizsql})) AS allsubmissions";
        $params = [
            'assigncourseid' => $this->courseid,
            'quizcourseid' => $this->courseid,
        ];

        $records = $DB->get_records_sql($sql, $params);
        foreach ($records as $record) {
            $this->submitted[$record->uniqueid] = true;
        }
    }

    /**
     * Initialise the completion state member variable.
     */
    private function init_completion(): void {
        global $DB;

        // Find all completion states for all users and activities in the course.
        $concat = $DB->sql_concat('c.userid', "'" . self::SEPARATOR . "'", 'cm.id');
        $sql = "SELECT {$concat} AS uniqueid,
                       c.completionstate
                  FROM {course_modules_completion} c
                  JOIN {course_modules} cm ON c.coursemoduleid = cm.id
                 WHERE cm.course = :courseid";

        $records = $DB->get_records_sql($sql, ['courseid' => $this->courseid]);
        foreach ($records as $record) {
            $this->completion[$record->uniqueid] = (int) $record->completionstate;
        }
    }
}
