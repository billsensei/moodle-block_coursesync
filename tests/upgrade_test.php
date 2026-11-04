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
use PHPUnit\Framework\Attributes\CoversNothing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->libdir . '/ddllib.php');
require_once(__DIR__ . '/../db/upgrade.php');

/**
 * Tests for the upgrade step that makes the pull records per attempt.
 *
 * The step changes a unique key on tables that already hold rows, so it is
 * tried from the old shape with awkward rows in it, and run again from places
 * a stopped upgrade could have been left.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class upgrade_test extends advanced_testcase {
    /** @var int The version before the step. */
    protected const BEFORE = 2026110301;

    /** @var string[] Table => the column pointing at the local row. */
    protected const TABLES = [
        'block_coursesync_submission' => 'submissionid',
        'block_coursesync_mark' => 'gradeid',
    ];

    /**
     * The step changes tables, which a rolled-back test transaction cannot undo.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest();
    }

    /**
     * Whatever a test did, leave the tables as the current version has them.
     */
    protected function tearDown(): void {
        global $DB;

        // A test may leave rows the step would refuse to make fit; only the
        // tables matter here, and the data is reset after every test.
        foreach (array_keys(self::TABLES) as $name) {
            $DB->delete_records($name);
        }

        $this->run_step();
        parent::tearDown();
    }

    /**
     * Run the upgrade as Moodle would from the old version: the stored
     * version is the old one, or the savepoint would refuse to move it.
     *
     * @return void
     */
    protected function run_step(): void {
        set_config('version', self::BEFORE, 'block_coursesync');
        xmldb_block_coursesync_upgrade(self::BEFORE);
    }

    /**
     * Put the tables back the way they were before the step: no attempt
     * column, one record for each student and assignment.
     *
     * @param bool $dropfield false to leave the new column, as a stopped upgrade could
     * @return void
     */
    protected function make_old_shape(bool $dropfield = true): void {
        global $DB;

        $dbman = $DB->get_manager();

        foreach (array_keys(self::TABLES) as $name) {
            $table = new \xmldb_table($name);
            $new = new \xmldb_index('assignid-userid-attemptnumber', XMLDB_INDEX_UNIQUE, ['assignid', 'userid', 'attemptnumber']);

            if ($dbman->index_exists($table, $new)) {
                $dbman->drop_index($table, $new);
            }

            if ($dropfield) {
                $field = new \xmldb_field('attemptnumber');

                if ($dbman->field_exists($table, $field)) {
                    $dbman->drop_field($table, $field);
                }
            }

            $old = new \xmldb_index('assignid-userid', XMLDB_INDEX_UNIQUE, ['assignid', 'userid']);

            if (!$dbman->index_exists($table, $old)) {
                $dbman->add_index($table, $old);
            }
        }
    }

    /**
     * Insert a record as the old version wrote it.
     *
     * @param string $table
     * @param array $values
     * @return void
     */
    protected function legacy(string $table, array $values): void {
        global $DB;

        $DB->insert_record($table, (object) ($values + [
            'blockinstanceid' => 1,
            'courseid' => 2,
            'remotecmid' => 9,
            'remoteattempt' => 0,
            'fingerprint' => sha1('there'),
            'localfingerprint' => sha1('here'),
            'remotetime' => 1750000000,
            'timeimported' => 1750000100,
        ]));
    }

    /**
     * Rows as an old pull left them, with the awkward ones.
     *
     * @return int[] the two assignments' ids and the students' ids, keyed by name
     */
    protected function legacy_rows(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $assign = $generator->create_module('assign', ['course' => $course->id]);
        $sam = $generator->create_user();
        $pat = $generator->create_user();
        $now = time();

        $submission = static fn(int $attempt) => $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $sam->id, 'timecreated' => $now, 'timemodified' => $now,
            'timestarted' => $now, 'status' => 'submitted', 'groupid' => 0, 'attemptnumber' => $attempt,
            'latest' => $attempt ? 1 : 0,
        ]);
        $grade = static fn(int $attempt) => $DB->insert_record('assign_grades', (object) [
            'assignment' => $assign->id, 'userid' => $sam->id, 'timecreated' => $now, 'timemodified' => $now,
            'grader' => 2, 'grade' => 50, 'attemptnumber' => $attempt,
        ]);

        $first = $submission(0);
        $submission(1);
        $grade(0);
        $second = $grade(1);

        // The source called it attempt 2, but here it is the first attempt;
        // a newer attempt exists and must not change that.
        $this->legacy('block_coursesync_submission', [
            'userid' => $sam->id, 'assignid' => $assign->id, 'submissionid' => $first, 'remoteattempt' => 2,
        ]);
        // Written to the student's second attempt here.
        $this->legacy('block_coursesync_mark', [
            'userid' => $sam->id, 'assignid' => $assign->id, 'gradeid' => $second, 'remoteattempt' => 0,
        ]);
        // Whose submission and grade have since been deleted.
        $this->legacy('block_coursesync_submission', [
            'userid' => $pat->id, 'assignid' => $assign->id, 'submissionid' => 987654, 'remoteattempt' => 3,
        ]);
        $this->legacy('block_coursesync_mark', [
            'userid' => $pat->id, 'assignid' => $assign->id, 'gradeid' => 987654, 'remoteattempt' => 3,
        ]);

        return ['assign' => (int) $assign->id, 'sam' => (int) $sam->id, 'pat' => (int) $pat->id];
    }

    /**
     * The attempt number each row has, by table and student.
     *
     * @param array $ids from legacy_rows()
     * @return int[][]
     */
    protected function attempts_now(array $ids): array {
        global $DB;

        $found = [];

        foreach (array_keys(self::TABLES) as $table) {
            foreach (['sam', 'pat'] as $who) {
                $found[$table][$who] = (int) $DB->get_field($table, 'attemptnumber', ['userid' => $ids[$who]], MUST_EXIST);
            }
        }

        return $found;
    }

    /**
     * Rows are given the attempt they were actually written to - read from the
     * submission or grade they point at, not from the other site's number and
     * not from the newest attempt - or 0 if that row has gone.
     */
    public function test_rows_get_the_attempt_they_were_written_to(): void {
        $this->make_old_shape();
        $ids = $this->legacy_rows();

        $this->run_step();

        $this->assertSame([
            'block_coursesync_submission' => ['sam' => 0, 'pat' => 0],
            'block_coursesync_mark' => ['sam' => 1, 'pat' => 0],
        ], $this->attempts_now($ids));
    }

    /**
     * After the step the key is for each attempt: a second attempt may be
     * recorded, the same attempt twice may not, and the old key is gone.
     */
    public function test_the_key_is_for_each_attempt(): void {
        global $DB;

        $this->make_old_shape();
        $ids = $this->legacy_rows();
        $this->run_step();
        $dbman = $DB->get_manager();

        foreach (self::TABLES as $name => $column) {
            $table = new \xmldb_table($name);

            $this->assertTrue($dbman->index_exists($table, new \xmldb_index(
                'assignid-userid-attemptnumber',
                XMLDB_INDEX_UNIQUE,
                ['assignid', 'userid', 'attemptnumber']
            )));
            $this->assertFalse($dbman->index_exists($table, new \xmldb_index(
                'assignid-userid',
                XMLDB_INDEX_UNIQUE,
                ['assignid', 'userid']
            )));

            $row = $DB->get_record($name, ['userid' => $ids['sam']], '*', MUST_EXIST);
            $count = $DB->count_records($name);
            unset($row->id);
            $row->attemptnumber = (int) $row->attemptnumber + 1;
            $DB->insert_record($name, $row);
            $this->assertSame($count + 1, $DB->count_records($name));
        }

        // The same attempt twice is still refused.
        $row = $DB->get_record('block_coursesync_mark', ['userid' => $ids['sam'], 'attemptnumber' => 1], '*', MUST_EXIST);
        unset($row->id);
        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('block_coursesync_mark', $row);
    }

    /**
     * Running the step a second time changes nothing.
     */
    public function test_running_it_again_changes_nothing(): void {
        global $DB;

        $this->make_old_shape();
        $ids = $this->legacy_rows();

        $this->run_step();
        $once = $this->attempts_now($ids);
        $rows = $DB->count_records('block_coursesync_mark');

        $this->run_step();

        $this->assertSame($once, $this->attempts_now($ids));
        $this->assertSame($rows, $DB->count_records('block_coursesync_mark'));
    }

    /**
     * An upgrade that stopped after adding the column - before the rows were
     * filled in, or between the two keys - finishes cleanly.
     */
    public function test_it_finishes_from_a_half_done_state(): void {
        global $DB;

        $this->make_old_shape();
        $ids = $this->legacy_rows();
        $dbman = $DB->get_manager();

        // Column added, rows not filled, old key still there.
        foreach (array_keys(self::TABLES) as $name) {
            $dbman->add_field(
                new \xmldb_table($name),
                new \xmldb_field('attemptnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0')
            );
        }

        $this->run_step();

        $this->assertSame([
            'block_coursesync_submission' => ['sam' => 0, 'pat' => 0],
            'block_coursesync_mark' => ['sam' => 1, 'pat' => 0],
        ], $this->attempts_now($ids));

        // And with both keys in place, as if it stopped between adding the new and dropping the old.
        foreach (array_keys(self::TABLES) as $name) {
            $dbman->add_index(
                new \xmldb_table($name),
                new \xmldb_index('assignid-userid', XMLDB_INDEX_UNIQUE, ['assignid', 'userid'])
            );
        }

        $this->run_step();

        foreach (array_keys(self::TABLES) as $name) {
            $this->assertFalse($dbman->index_exists(
                new \xmldb_table($name),
                new \xmldb_index('assignid-userid', XMLDB_INDEX_UNIQUE, ['assignid', 'userid'])
            ));
        }
    }

    /**
     * After the step the tables are what a fresh install makes.
     */
    public function test_the_result_matches_a_fresh_install(): void {
        global $CFG, $DB;

        $this->make_old_shape();
        $this->run_step();

        $file = new \xmldb_file($CFG->dirroot . '/blocks/coursesync/db/install.xml');
        $file->loadXMLStructure();
        $structure = $file->getStructure();
        $problems = $DB->get_manager()->check_database_schema($structure);

        foreach (array_keys(self::TABLES) as $name) {
            $this->assertArrayNotHasKey($name, $problems, implode('; ', $problems[$name] ?? []));
        }

        // The comparison above does not look at keys, so look at what a fresh
        // install is told to make.
        foreach (array_keys(self::TABLES) as $name) {
            $indexes = [];

            foreach ($structure->getTable($name)->getIndexes() as $index) {
                $indexes[$index->getName()] = [(bool) $index->getUnique(), $index->getFields()];
            }

            $this->assertSame([true, ['assignid', 'userid', 'attemptnumber']], $indexes['assignid-userid-attemptnumber'] ?? null);
            $this->assertArrayNotHasKey('assignid-userid', $indexes);
        }
    }
}
