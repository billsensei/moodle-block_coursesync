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

namespace block_coursesync;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for how a run's notes are turned into text for a page.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync_result::class)]
#[CoversClass(activity_payload::class)]
final class sync_result_test extends advanced_testcase {
    /**
     * A note's parameter can come from the other site, and the pages print
     * a note as HTML, so the parameter is escaped.
     */
    public function test_a_note_parameter_is_escaped(): void {
        $html = sync_result::describe_note(['syncfilesmissing', '<img src=x onerror=alert(1)>']);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    /**
     * A count is still a count.
     */
    public function test_a_numeric_note_parameter_is_unchanged(): void {
        $this->assertSame(
            get_string('syncqbankunsupportedcount', 'block_coursesync', 3),
            sync_result::describe_note(['syncqbankunsupportedcount', 3])
        );
    }

    /**
     * A plain key needs no parameter.
     */
    public function test_a_plain_note_is_its_string(): void {
        $this->assertSame(
            get_string('syncscaledropped', 'block_coursesync'),
            sync_result::describe_note('syncscaledropped')
        );
    }

    /**
     * Percent-encoded markup in an embedded file link does not come out of
     * decoding as markup.
     */
    public function test_a_percent_encoded_file_name_cannot_carry_markup(): void {
        $payload = new activity_payload(5, 'page', 'Page', '', 0, true, '', FORMAT_HTML, 0, [
            'content' => '<img src="@@PLUGINFILE@@/%3Cimg%20src%3Dx%20onerror%3Dalert%281%29%3E">',
        ]);

        $missing = $payload->missing_files();

        $this->assertCount(1, $missing);
        $this->assertStringNotContainsString('<', $missing[0]);
        $this->assertStringNotContainsString('>', $missing[0]);
    }
}
