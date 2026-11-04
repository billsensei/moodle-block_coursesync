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
 * Tests for what a page does before work that can take minutes.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(long_run::class)]
final class long_run_test extends advanced_testcase {
    /**
     * The time limit is asked to be lifted, and the memory limit is raised to
     * at least the "extra" figure, whatever it was.
     */
    public function test_the_limits_are_raised(): void {
        $this->resetAfterTest();

        $memory = ini_get('memory_limit');
        \core_php_time_limit::get_and_clear_unit_test_data();

        try {
            // Just above what this run already uses (PHP refuses a limit below
            // that), so well under the figure begin() raises it to.
            $low = (int) ceil((memory_get_peak_usage(true) + 32 * 1024 * 1024) / (1024 * 1024)) . 'M';
            ini_set('memory_limit', $low);
            $this->assertLessThan(get_real_size('512M'), get_real_size(ini_get('memory_limit')));

            long_run::begin();

            // 0 is "no limit"; core does nothing in a command line script, so
            // the request is what can be checked.
            $this->assertSame([0], \core_php_time_limit::get_and_clear_unit_test_data());
            $this->assertGreaterThanOrEqual(get_real_size('512M'), get_real_size(ini_get('memory_limit')));
        } finally {
            ini_set('memory_limit', $memory);
        }
    }
}
