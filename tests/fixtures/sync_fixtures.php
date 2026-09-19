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
 * Shared setup for this plugin's tests.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync;

use block_coursesync\local\http\fake_transport;
use block_coursesync\local\http\transport_factory;
use block_coursesync\local\token_store;
use block_coursesync\local\url_validator;

/**
 * Builds the course, block and ledger rows the sync tests work against.
 *
 * Block configuration is written straight to the database rather than through
 * instance_config_save(), because that hook verifies the connection against a
 * live site, which is exactly what these tests exist to avoid.
 */
trait sync_fixtures {
    /** @var \stdClass The course under test. */
    protected \stdClass $course;

    /** @var \stdClass The block instance record. */
    protected \stdClass $blockinstance;

    /** @var \context_block Context of that block instance. */
    protected \context_block $blockcontext;

    /**
     * Creates a course with a configured block instance in it.
     *
     * @param array $configoverrides Any block config values to change.
     * @return int The block instance id.
     */
    protected function create_configured_block(array $configoverrides = []): int {
        global $DB;

        $this->course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $coursecontext = \context_course::instance($this->course->id);

        $record = (object) [
            'blockname' => 'coursesync',
            'parentcontextid' => $coursecontext->id,
            'showinsubcontexts' => 0,
            'requiredbytheme' => 0,
            'pagetypepattern' => 'course-view-*',
            'subpagepattern' => null,
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => '',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record('block_instances', $record);
        $this->blockcontext = \context_block::instance($record->id);

        $config = (object) array_merge([
            'remoteurl' => 'https://remote.example.edu',
            'remotecourse' => 'SRC101',
            'remotecourseid' => 42,
            'remotesitename' => 'Remote Example',
            'remotecoursefullname' => 'Source Biology',
            'tokenciphertext' => token_store::encrypt('a-test-token'),
            'lastvalidated' => time(),
        ], $configoverrides);

        $DB->set_field('block_instances', 'configdata', base64_encode(serialize($config)), ['id' => $record->id]);
        $record->configdata = base64_encode(serialize($config));

        $this->blockinstance = $record;

        return (int) $record->id;
    }

    /**
     * Installs a scripted transport so no test touches the network.
     *
     * @param array $responses Decoded response bodies keyed by web service function name.
     * @return fake_transport
     */
    protected function use_fake_transport(array $responses): fake_transport {
        // Resolution is driven too, so the fixture's host name needs no DNS and
        // the URL rules are still exercised against a public-looking address.
        url_validator::set_resolver_for_testing(fn(string $host): array => ['93.184.216.34']);

        $transport = new fake_transport($responses);
        transport_factory::set_for_testing($transport);

        return $transport;
    }

    /**
     * Puts the real transport and real DNS back.
     */
    protected function stop_faking_the_network(): void {
        transport_factory::set_for_testing(null);
        url_validator::set_resolver_for_testing(null);
    }

    /**
     * Builds a list_activities response body.
     *
     * @param array $activities Activity rows, each merged over sensible defaults.
     * @return array
     */
    protected function remote_listing(array $activities): array {
        $rows = [];
        foreach ($activities as $activity) {
            $rows[] = $activity + [
                'cmid' => 1,
                'modname' => 'page',
                'name' => 'Remote activity',
                'sectionnum' => 1,
                'idnumber' => '',
                'signal' => '1000',
                'signalmethod' => 'timemodified',
                'backupsupported' => true,
            ];
        }

        return ['courseid' => 42, 'activities' => $rows];
    }

    /**
     * Writes a ledger row describing an earlier successful pull.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid Remote course module id.
     * @param int $localcmid Local course module the pull produced.
     * @param string $remotesignal Remote signal recorded at that pull.
     * @param string $localsignal Local signal recorded at that pull.
     */
    protected function record_previous_pull(
        int $blockinstanceid,
        int $remotecmid,
        int $localcmid,
        string $remotesignal,
        string $localsignal
    ): void {
        global $DB;

        $DB->insert_record('block_coursesync_pulls', (object) [
            'blockinstanceid' => $blockinstanceid,
            'remotecmid' => $remotecmid,
            'localcmid' => $localcmid,
            'remotesignal' => $remotesignal,
            'remotesignalmethod' => 'timemodified',
            'localsignal' => $localsignal,
            'localsignalmethod' => 'timemodified',
            'status' => 'synced',
            'timepulled' => time() - HOURSECS,
            'timemodified' => time() - HOURSECS,
        ]);
    }

    /**
     * Returns the log rows a run wrote, keyed by remote course module id.
     *
     * @param int $blockinstanceid Block instance id.
     * @return \stdClass[]
     */
    protected function log_rows(int $blockinstanceid): array {
        global $DB;

        $rows = [];
        foreach ($DB->get_records('block_coursesync_log', ['blockinstanceid' => $blockinstanceid], 'id') as $row) {
            $rows[(int) $row->remotecmid] = $row;
        }

        return $rows;
    }

    /**
     * Returns the ledger row for one remote course module.
     *
     * @param int $blockinstanceid Block instance id.
     * @param int $remotecmid Remote course module id.
     * @return \stdClass|false
     */
    protected function ledger_row(int $blockinstanceid, int $remotecmid) {
        global $DB;

        return $DB->get_record('block_coursesync_pulls', [
            'blockinstanceid' => $blockinstanceid,
            'remotecmid' => $remotecmid,
        ]);
    }
}
