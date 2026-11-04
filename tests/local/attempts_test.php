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

namespace block_coursesync\local;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the form on the pull pages that chooses every attempt.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(attempts::class)]
final class attempts_test extends advanced_testcase {
    /**
     * Not offered unless this site lets teachers pull every attempt.
     */
    public function test_the_choice_is_only_offered_where_it_is_allowed(): void {
        $this->resetAfterTest();
        $url = new \moodle_url('/blocks/coursesync/submissions.php', ['instanceid' => 5, 'courseid' => 6]);

        $this->assertSame('', attempts::choice_form($url, false));

        set_config('allowattemptspull', 1, 'block_coursesync');

        $this->assertNotSame('', attempts::choice_form($url, false));
    }

    /**
     * A form that only asks for another preview: sent by GET, carrying the
     * page's own parameters, with the box as it is now.
     */
    public function test_the_choice_form_asks_for_another_preview(): void {
        $this->resetAfterTest();
        set_config('allowattemptspull', 1, 'block_coursesync');
        $url = new \moodle_url('/blocks/coursesync/marks.php', ['instanceid' => 5, 'courseid' => 6]);

        $off = attempts::choice_form($url, false);
        $on = attempts::choice_form($url, true);

        $this->assertStringContainsString('method="get"', $off);
        $this->assertStringContainsString('/blocks/coursesync/marks.php"', $off);
        $this->assertMatchesRegularExpression('/name="instanceid"[^>]*value="5"|value="5"[^>]*name="instanceid"/', $off);
        $this->assertMatchesRegularExpression('/name="courseid"[^>]*value="6"|value="6"[^>]*name="courseid"/', $off);
        $this->assertStringContainsString('name="allattempts"', $off);
        $this->assertStringNotContainsString('checked', $off);
        $this->assertStringContainsString('checked', $on);
        $this->assertStringNotContainsString('sesskey', $off);
    }
}
