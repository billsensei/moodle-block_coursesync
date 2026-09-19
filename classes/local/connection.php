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
 * Connection checks shared by the configuration form and its AJAX actions.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

/**
 * Answers the two questions the configuration form asks of a remote site:
 * is this the site I think it is, and does the course I named exist on it.
 */
class connection {
    /**
     * Identifies the remote site and reports any functions the service lacks.
     *
     * @param remote_client $client Client for the remote site.
     * @return array With keys 'sitename', 'release' and 'missingfunctions'.
     * @throws remote_exception If the site cannot be reached or rejects the token.
     */
    public static function describe_site(remote_client $client): array {
        $info = $client->get_site_info();

        $available = [];
        foreach ($info['functions'] ?? [] as $function) {
            if (isset($function['name'])) {
                $available[] = $function['name'];
            }
        }

        return [
            'sitename' => (string) ($info['sitename'] ?? ''),
            'release' => (string) ($info['release'] ?? ''),
            'missingfunctions' => array_values(array_diff(remote_client::REQUIRED_FUNCTIONS, $available)),
        ];
    }

    /**
     * Resolves a course ID or shortname to exactly one course on the remote site.
     *
     * A value made up only of digits is looked up as an ID first and then as a
     * shortname, since a shortname may legitimately be numeric.
     *
     * @param remote_client $client Client for the remote site.
     * @param string $course Course ID or shortname as entered by the user.
     * @return array With keys 'id', 'shortname' and 'fullname'.
     * @throws remote_exception If the course does not resolve to exactly one course.
     */
    public static function resolve_course(remote_client $client, string $course): array {
        $value = trim($course);
        if ($value === '') {
            throw new remote_exception('error:coursenotfound');
        }

        $fields = ctype_digit($value) ? ['id', 'shortname'] : ['shortname'];

        foreach ($fields as $field) {
            $courses = $client->get_courses_by_field($field, $value);

            if (count($courses) > 1) {
                throw new remote_exception('error:coursenotunique');
            }

            if (count($courses) === 1) {
                $found = reset($courses);
                return [
                    'id' => (int) ($found['id'] ?? 0),
                    'shortname' => (string) ($found['shortname'] ?? ''),
                    'fullname' => (string) ($found['fullname'] ?? ''),
                ];
            }
        }

        throw new remote_exception('error:coursenotfound');
    }
}
