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

use cm_info;
use completion_info;
use core_component;
use local_reminders\interfaces\status_provider;

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

    /** @var array A cache of instantiated status providers. */
    private static $statusproviders = [];

    /**
     * Get the activity status of the user.
     *
     * @param int $userid The user id.
     * @param int $cmid The course module id.
     * @return int The status of the user for the activity.
     */
    public static function get_status(int $userid, cm_info $cm): int {
        global $DB;

        // Default status is not submitted.
        $status = self::STATUS_NOT_SUBMITTED;

        // Check if the user has submitted the activity, if the module type is supported.
        if (self::is_submitted($userid, $cm)) {
            $status = self::STATUS_SUBMITTED;
        }

        // Standard completion status has priority over submitted status.
        $completionstate = self::get_completion($userid, $cm);
        switch ($completionstate) {
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
     * Check if the user has made a submission for the activity.
     *
     * @param int $userid The user id.
     * @param cm_info $cm The course module info.
     * @return bool True if the user has submitted the activity, false otherwise.
     */
    private static function is_submitted(int $userid, cm_info $cm): bool {
        $statusprovider = self::get_status_provider($cm->modname);
        if ($statusprovider) {
            return $statusprovider->is_submitted($userid, $cm);
        }
        return false;
    }

    /**
     * Get the completion state of the user for the activity.
     *
     * @param int $userid The user id.
     * @param int $cmid The course module id.
     * @return int The completion state of the user for the activity.
     */
    private static function get_completion(int $userid, cm_info $cm): int {
        $completion = new completion_info($cm->get_course());

        if ($completion->is_enabled($cm)) {
            return (int) $completion->get_data($cm, false, $userid)->completionstate;
        }

        return COMPLETION_INCOMPLETE;
    }

    /**
     * Get a submission status status_provider for a given module name.
     *
     * @param string $modname The name of the module (e.g. 'assign', 'quiz').
     * @return status_provider|null A status_provider instance, or null if not supported.
     */
    private static function get_status_provider(string $modname): ?status_provider {

        if (isset(self::$statusproviders[$modname])) {
            return self::$statusproviders[$modname];
        }

        // Check if this is a valid, installed Moodle plugin component.
        if (empty(core_component::get_component_directory("mod_{$modname}"))) {
            self::$statusproviders[$modname] = null;
            return null;
        }

        $classname = __NAMESPACE__ . "\\status\\mod_{$modname}";
        if (!class_exists($classname)) {
            self::$statusproviders[$modname] = null;
            return null;
        }

        $statusprovider = new $classname();
        if (!$statusprovider instanceof status_provider) {
            self::$statusproviders[$modname] = null;
            return null;
        }

        self::$statusproviders[$modname] = $statusprovider;
        return $statusprovider;
    }
}
