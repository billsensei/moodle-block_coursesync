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

use block_coursesync\remote_client;
use block_coursesync\syncer;

/**
 * Which section here stands for which section on the source site.
 *
 * A run is given the source's section list (prime()), and a handler that is
 * about to create an activity asks local_number() where it goes. The answer
 * comes from a stored pairing of source section id to local section id, so it
 * survives either site inserting or moving a section.
 *
 * Matching, in this order, for a source section with no pairing yet:
 *
 * 1. The section here with the same number, if nothing else has claimed it.
 *    That is where earlier versions put the activity, so a course that has been
 *    syncing already keeps its activities beside their new siblings. It is
 *    renamed only if nobody has named it.
 * 2. Otherwise a new section. Every missing section up to the one asked for is
 *    created, in source order, so that numbers here match numbers there.
 *
 * Sections a teacher has named or arranged are never renamed, hidden or moved.
 * Sections this created are named after the source's, and hidden if the source's
 * is - after the run, so hiding does not reach into activities still being
 * placed.
 *
 * Only ordinary sections are ever looked at or made. A subsection's delegated
 * section is numbered after them, and mistaking one for an ordinary section is
 * what once put an activity inside an unrelated subsection.
 *
 * @package    block_coursesync
 * @copyright  2026 Bill White
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_map {
    /** @var int The most files one section description is given. */
    protected const MAX_FILES = 50;

    /** @var int The largest file one section description is given, in bytes. */
    protected const MAX_FILE_BYTES = 52428800;

    /** @var int The most characters Moodle 5.0 allows in a section name. */
    protected const MAX_NAME = 255;

    /** @var array[] Per course id: the block instance id and the source's sections keyed by number. */
    protected static array $primed = [];

    /** @var int[] Per course id: how many sections this run created. */
    protected static array $created = [];

    /** @var int[][] Per course id: ids of created sections whose source counterpart is hidden. */
    protected static array $tohide = [];

    /** @var int[] Per course id: how many existing sections the teacher asked to have updated and were. */
    protected static array $updated = [];

    /** @var int[] Per course id: how many description files could not be fetched. */
    protected static array $filesfailed = [];

    /** @var int How many without_creating() scopes are open; while any is, nothing is paired or made. */
    protected static int $frozen = 0;

    /**
     * Give a run the source's sections.
     *
     * Does nothing, and says so, for a course format that has no sections to
     * place things in; the activities then land as they always did.
     *
     * @param int $blockinstanceid the block that syncs the course
     * @param \stdClass $course the destination course
     * @param array[] $remote what remote_client::get_sections() returned
     * @param array|null $source how to reach the source for a description's files:
     *      baseurl, token, courseid and client; null leaves the files behind
     * @return bool whether sections will be used for this run
     */
    public static function prime(int $blockinstanceid, \stdClass $course, array $remote, ?array $source = null): bool {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        self::release((int) $course->id);

        if (!course_get_format($course)->uses_sections()) {
            return false;
        }

        $sections = [];

        foreach ($remote as $row) {
            // The general section is the same everywhere and is left alone.
            if ((int) $row['section'] < 1 || (int) $row['id'] < 1) {
                continue;
            }

            $sections[(int) $row['section']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'visible' => (bool) $row['visible'],
                // Null from a source that cannot say, so nothing is copied.
                'summary' => $row['summary'] ?? null,
                'summaryformat' => (int) ($row['summaryformat'] ?? FORMAT_HTML),
                'cmids' => $row['cmids'] ?? null,
                'files' => $row['files'] ?? [],
            ];
        }

        self::$primed[(int) $course->id] = ['block' => $blockinstanceid, 'sections' => $sections, 'source' => $source];

        return $sections !== [];
    }

    /**
     * Do something that must not pair or make sections, and return what it returns.
     *
     * For rebuilding an activity that is already here, in the section it is
     * already in: a replacement is made like a new copy and then moved back to
     * the old one's place, so asking where it goes would make every section up to
     * the source's number for nothing. Sections already paired are still used.
     *
     * @param callable $work
     * @return mixed
     */
    public static function without_creating(callable $work) {
        self::$frozen++;

        try {
            return $work();
        } finally {
            self::$frozen--;
        }
    }

    /**
     * Forget a run's sections, ready for the next.
     *
     * @param int $courseid
     * @return void
     */
    public static function release(int $courseid): void {
        unset(
            self::$primed[$courseid],
            self::$created[$courseid],
            self::$tohide[$courseid],
            self::$updated[$courseid],
            self::$filesfailed[$courseid]
        );
    }

    /**
     * How many sections this run has created in a course.
     *
     * @param int $courseid
     * @return int
     */
    public static function created_count(int $courseid): int {
        return self::$created[$courseid] ?? 0;
    }

    /**
     * How many existing sections this run brought into line with the source.
     *
     * @param int $courseid
     * @return int
     */
    public static function updated_count(int $courseid): int {
        return self::$updated[$courseid] ?? 0;
    }

    /**
     * How many files used by section descriptions could not be fetched.
     *
     * @param int $courseid
     * @return int
     */
    public static function files_failed_count(int $courseid): int {
        return self::$filesfailed[$courseid] ?? 0;
    }

    /**
     * Where a new activity goes within its section, so it sits where it did on the source.
     *
     * The first activity that follows it on the source, in the same source
     * section, and is already in the same section here - so wherever the run
     * happens to copy them, they end up in the source's order, and an
     * arrangement a teacher made here is never disturbed: only the new
     * activity is placed. Null puts it at the end, as before.
     *
     * @param \stdClass $course the destination course
     * @param string $idnumber the ID number the new activity carries (see syncer::build_idnumber())
     * @param int $localsectionnum the section here it is going in
     * @return int|null the course module id here to put it before
     */
    public static function before_cmid(\stdClass $course, string $idnumber, int $localsectionnum): ?int {
        global $DB;

        $state = self::$primed[(int) $course->id] ?? null;

        $ours = preg_match('/^coursesync-(\d+)$/', $idnumber, $found) === 1;

        if ($state === null || self::$frozen > 0 || !$ours) {
            return null;
        }

        $remotecmid = (int) $found[1];
        $sectionid = $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => $localsectionnum]);

        foreach ($state['sections'] as $remote) {
            $cmids = $remote['cmids'];
            $at = is_array($cmids) ? array_search($remotecmid, $cmids, true) : false;

            if ($at === false) {
                continue;
            }

            foreach (array_slice($cmids, $at + 1) as $following) {
                $local = syncer::find_existing((int) $course->id, (int) $following);

                if (
                    $local > 0
                    && (int) $DB->get_field('course_modules', 'section', ['id' => $local]) === (int) $sectionid
                ) {
                    return $local;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Bring the sections already paired up to date with the source.
     *
     * Only when a teacher asks for it: pairs every source section first (an
     * unnamed one here is adopted, a missing one made), then gives each its
     * source name and description. A name or description the source has not
     * got is never used to blank one here, and visibility is left alone.
     *
     * @param \stdClass $course the destination course
     * @return void
     */
    public static function update_existing(\stdClass $course): void {
        global $DB;

        $state = self::$primed[(int) $course->id] ?? null;

        if ($state === null || $state['sections'] === [] || self::$frozen > 0) {
            return;
        }

        self::pair_through($course, $state, max(array_keys($state['sections'])));

        // Every pairing, including those made a moment ago. A section just
        // made or adopted already has the source's name and description, so
        // nothing is changed again for it.
        $pairs = $DB->get_records('block_coursesync_section', [
            'blockinstanceid' => $state['block'],
            'courseid' => $course->id,
        ]);
        $fresh = [];

        foreach ($pairs as $pair) {
            $fresh[(int) $pair->remotesectionid] = (int) $pair->localsectionid;
        }

        $modinfo = get_fast_modinfo($course->id);

        foreach ($state['sections'] as $remote) {
            $localid = $fresh[$remote['id']] ?? null;
            $info = $localid === null ? null : $modinfo->get_section_info_by_id($localid);

            if (!$info) {
                continue;
            }

            $changes = [];
            $name = \core_text::substr(trim($remote['name']), 0, self::MAX_NAME);

            if ($name !== '' && $name !== trim((string) $info->name)) {
                $changes['name'] = $name;
            }

            if ($changes !== []) {
                \core_courseformat\formatactions::section($course)->update($info, $changes);
            }

            $summarychanged = self::description_differs($course, $info, $remote);

            if ($summarychanged) {
                self::copy_description($course, $state, $info, $remote);
            }

            if ($changes !== [] || $summarychanged) {
                self::$updated[(int) $course->id] = (self::$updated[(int) $course->id] ?? 0) + 1;
            }
        }
    }

    /**
     * The number, here, of the section an activity from a source section goes in.
     *
     * Looked up each time rather than remembered: a section's number changes
     * whenever one is inserted or moved ahead of it.
     *
     * @param \stdClass $course the destination course
     * @param int $remotenum the activity's section number on the source site
     * @return int|null null when sections are not in use for this run, or this
     *                  one cannot be had; the caller places it as it always did
     */
    public static function local_number(\stdClass $course, int $remotenum): ?int {
        global $DB;

        $state = self::$primed[(int) $course->id] ?? null;

        if ($state === null || !isset($state['sections'][$remotenum])) {
            return null;
        }

        $remoteid = $state['sections'][$remotenum]['id'];
        $localid = self::paired_local_id($state['block'], $remoteid, (int) $course->id);

        if ($localid === null && self::$frozen === 0) {
            self::pair_through($course, $state, $remotenum);
            $localid = self::paired_local_id($state['block'], $remoteid, (int) $course->id);
        }

        if ($localid === null) {
            return null;
        }

        $number = $DB->get_field('course_sections', 'section', ['id' => $localid, 'course' => $course->id]);

        return $number === false ? null : (int) $number;
    }

    /**
     * Hide the sections this run created whose source counterpart is hidden.
     *
     * Called once the run's activities are in place. Hiding a section hides what
     * is in it and remembers each activity's own setting; doing that while
     * activities were still arriving would leave the late ones without it.
     *
     * @param \stdClass $course the destination course
     * @return void
     */
    public static function apply_visibility(\stdClass $course): void {
        $ids = self::$tohide[(int) $course->id] ?? [];

        if ($ids === []) {
            return;
        }

        global $DB;

        $modinfo = get_fast_modinfo($course->id);

        foreach ($ids as $id) {
            $info = $modinfo->get_section_info_by_id($id);

            if (!$info || !$info->visible) {
                continue;
            }

            // An activity that came from a hidden source section arrives
            // hidden, remembering that its own setting is visible. Hiding a
            // section records each activity's current setting as the one to
            // go back to, which for these would be "hidden". Set them visible
            // first, so the hide records what they really are - and in one
            // transaction with the hide, so a failure between the two cannot
            // leave activities that were hidden on the source visible here.
            $transaction = $DB->start_delegated_transaction();

            $DB->set_field_select(
                'course_modules',
                'visible',
                1,
                'section = :section AND visible = 0 AND visibleold = 1',
                ['section' => $id]
            );

            \core_courseformat\formatactions::section($course)->update($info, ['visible' => 0]);

            $transaction->allow_commit();
        }

        rebuild_course_cache($course->id, true);

        unset(self::$tohide[(int) $course->id]);
    }

    /**
     * Forget every pairing a block holds, when the block goes away.
     *
     * @param int $blockinstanceid
     * @return void
     */
    public static function delete_for_block_instance(int $blockinstanceid): void {
        global $DB;

        $DB->delete_records('block_coursesync_section', ['blockinstanceid' => $blockinstanceid]);
    }

    /**
     * The local section id a source section is paired with, if that still exists.
     *
     * A pairing whose section has since been deleted is dropped, so the source
     * section is matched afresh instead of pointing at nothing.
     *
     * @param int $blockinstanceid
     * @param int $remoteid the section id on the source site
     * @param int $courseid
     * @return int|null
     */
    protected static function paired_local_id(int $blockinstanceid, int $remoteid, int $courseid): ?int {
        global $DB;

        $row = $DB->get_record('block_coursesync_section', [
            'blockinstanceid' => $blockinstanceid,
            'remotesectionid' => $remoteid,
        ]);

        if (!$row) {
            return null;
        }

        $exists = $DB->record_exists_select(
            'course_sections',
            'id = :id AND course = :course AND component IS NULL',
            ['id' => $row->localsectionid, 'course' => $courseid]
        );

        if (!$exists) {
            $DB->delete_records('block_coursesync_section', ['id' => $row->id]);

            return null;
        }

        return (int) $row->localsectionid;
    }

    /**
     * Pair every unpaired source section up to a number, creating those missing here.
     *
     * Up to that number, in source order, so a course that had nothing ends up
     * with the same numbers as the source rather than whatever order activities
     * happened to be copied in.
     *
     * @param \stdClass $course the destination course
     * @param array $state this run's state for the course
     * @param int $throughnum the highest source section number to pair
     * @return void
     */
    protected static function pair_through(\stdClass $course, array $state, int $throughnum): void {
        global $DB;

        $cap = self::section_limit();
        $naming = [];
        $describe = [];
        $touched = false;

        ksort($state['sections']);

        foreach ($state['sections'] as $remotenum => $remote) {
            if ($remotenum > $throughnum) {
                break;
            }

            if (self::paired_local_id($state['block'], $remote['id'], (int) $course->id) !== null) {
                continue;
            }

            $adopted = self::unclaimed_section_numbered($state['block'], (int) $course->id, $remotenum);

            if ($adopted !== null) {
                // Only an unnamed section takes the source's name. One somebody
                // named is theirs.
                if ($adopted->name === null || trim((string) $adopted->name) === '') {
                    $naming[$adopted->id] = $remote['name'];
                }

                $localid = (int) $adopted->id;

                // Likewise a description: only one nobody has written.
                if (trim((string) $adopted->summary) === '') {
                    $describe[$localid] = $remote;
                }
            } else {
                if (self::ordinary_section_count((int) $course->id) >= $cap) {
                    // The site's limit on sections. This one and any after it
                    // are placed as they always were.
                    break;
                }

                $localid = self::create_section($course);
                $naming[$localid] = $remote['name'];
                $describe[$localid] = $remote;
                self::$created[(int) $course->id] = (self::$created[(int) $course->id] ?? 0) + 1;

                if (!$remote['visible']) {
                    self::$tohide[(int) $course->id][] = $localid;
                }
            }

            $DB->insert_record('block_coursesync_section', (object) [
                'blockinstanceid' => $state['block'],
                'courseid' => (int) $course->id,
                'remotesectionid' => $remote['id'],
                'localsectionid' => $localid,
                'timesynced' => time(),
            ]);

            $touched = true;
        }

        if (!$touched) {
            return;
        }

        rebuild_course_cache($course->id, true);

        $modinfo = get_fast_modinfo($course->id);

        foreach ($naming as $localid => $name) {
            $name = \core_text::substr(trim($name), 0, self::MAX_NAME);
            $info = $modinfo->get_section_info_by_id($localid);

            if ($name !== '' && $info) {
                \core_courseformat\formatactions::section($course)->update($info, ['name' => $name]);
            }
        }

        foreach ($describe as $localid => $remote) {
            $info = $modinfo->get_section_info_by_id($localid);

            if ($info && self::description_differs($course, $info, $remote)) {
                self::copy_description($course, $state, $info, $remote);
            }
        }
    }

    /**
     * Whether the source has a description that this section does not.
     *
     * @param \stdClass $course
     * @param \section_info $info the section here
     * @param array $remote the source's section
     * @return bool
     */
    protected static function description_differs(\stdClass $course, \section_info $info, array $remote): bool {
        // A source that did not send one, or sent nothing, is not a reason to
        // blank what is here.
        if ($remote['summary'] === null || trim($remote['summary']) === '') {
            return false;
        }

        [$text, $format] = self::stored_summary($remote);

        return $text !== (string) $info->summary || $format !== (int) $info->summaryformat;
    }

    /**
     * What is kept of a source section's description, and its format.
     *
     * The format is the source's to name, so it is held to the ones Moodle has;
     * anything else is read as HTML. HTML is cleaned here, and Markdown is turned
     * into HTML and cleaned. Plain text is kept as sent: it is escaped whenever
     * it is shown. Each is cleaned again for display, as every description is.
     *
     * @param array $remote the source's section
     * @return array [text, format]
     */
    protected static function stored_summary(array $remote): array {
        $format = (int) $remote['summaryformat'];

        $known = [(int) FORMAT_MOODLE, (int) FORMAT_HTML, (int) FORMAT_PLAIN, (int) FORMAT_MARKDOWN];

        if (!in_array($format, $known, true)) {
            $format = (int) FORMAT_HTML;
        }

        $text = (string) $remote['summary'];

        if ($format === (int) FORMAT_MARKDOWN) {
            // Turned into HTML and cleaned, and kept as HTML, so what is stored
            // is never raw remote markup labelled as something else.
            $text = clean_text(markdown_to_html($text), FORMAT_HTML);
            $format = (int) FORMAT_HTML;
        } else if ($format === (int) FORMAT_HTML || $format === (int) FORMAT_MOODLE) {
            $text = clean_text($text, $format);
        }

        return [$text, $format];
    }

    /**
     * Give a section the source's description, and the files it uses.
     *
     * The files are fetched first and the old ones removed only when they have
     * all arrived, so a failed transfer leaves the section as it was. A file
     * that cannot be fetched leaves the description without it and is counted,
     * so the run says so; it never fails the run.
     *
     * @param \stdClass $course the destination course
     * @param array $state this run's state for the course
     * @param \section_info $info the section here
     * @param array $remote the source's section
     * @return void
     */
    protected static function copy_description(\stdClass $course, array $state, \section_info $info, array $remote): void {
        $files = [];
        $source = $state['source'] ?? null;

        if ($source !== null) {
            foreach (array_slice($remote['files'], 0, self::MAX_FILES) as $file) {
                if ($file['filesize'] > self::MAX_FILE_BYTES || $file['filename'] === '') {
                    self::$filesfailed[(int) $course->id] = (self::$filesfailed[(int) $course->id] ?? 0) + 1;

                    continue;
                }

                try {
                    $files[] = [
                        'file' => $file,
                        'path' => file_sync::download(
                            static fn(int $offset) => remote_client::get_section_file(
                                $source['baseurl'],
                                $source['token'],
                                $source['courseid'],
                                $remote['id'],
                                $file['filepath'],
                                $file['filename'],
                                $offset,
                                file_sync::CHUNK,
                                $source['client']
                            ),
                            $file['contenthash'],
                            $file['filesize']
                        ),
                    ];
                } catch (\Throwable $e) {
                    self::$filesfailed[(int) $course->id] = (self::$filesfailed[(int) $course->id] ?? 0) + 1;
                }
            }
        }

        [$text, $format] = self::stored_summary($remote);

        \core_courseformat\formatactions::section($course)->update($info, [
            'summary' => $text,
            'summaryformat' => $format,
        ]);

        // Files are touched only when some arrived: a description whose files
        // could not be fetched keeps whatever it had.
        if ($files === []) {
            return;
        }

        $fs = get_file_storage();
        $contextid = \context_course::instance($course->id)->id;
        $fs->delete_area_files($contextid, 'course', 'section', $info->id);

        foreach ($files as $item) {
            try {
                $fs->create_file_from_pathname((object) [
                    'contextid' => $contextid,
                    'component' => 'course',
                    'filearea' => 'section',
                    'itemid' => (int) $info->id,
                    'filepath' => $item['file']['filepath'],
                    'filename' => $item['file']['filename'],
                    'timecreated' => time(),
                    'timemodified' => $item['file']['timemodified'] ?: time(),
                ], $item['path']);
            } finally {
                @unlink($item['path']);
            }
        }
    }

    /**
     * The ordinary section here with a given number, if no pairing has claimed it.
     *
     * @param int $blockinstanceid
     * @param int $courseid
     * @param int $number
     * @return \stdClass|null the course_sections row
     */
    protected static function unclaimed_section_numbered(int $blockinstanceid, int $courseid, int $number): ?\stdClass {
        global $DB;

        $section = $DB->get_record_select(
            'course_sections',
            'course = :course AND section = :section AND component IS NULL',
            ['course' => $courseid, 'section' => $number]
        );

        if (!$section) {
            return null;
        }

        $claimed = $DB->record_exists('block_coursesync_section', [
            'blockinstanceid' => $blockinstanceid,
            'localsectionid' => $section->id,
        ]);

        return $claimed ? null : $section;
    }

    /**
     * Add an ordinary section after the last one.
     *
     * Placed by number, after the ordinary sections and so before any delegated
     * ones, which are pushed down to make room.
     *
     * @param \stdClass $course the destination course
     * @return int the new section's id
     */
    protected static function create_section(\stdClass $course): int {
        global $DB;

        $last = (int) $DB->get_field_select(
            'course_sections',
            'MAX(section)',
            'course = :course AND component IS NULL',
            ['course' => $course->id]
        );

        $hasdelegated = $DB->record_exists_select(
            'course_sections',
            'course = :course AND component IS NOT NULL',
            ['course' => $course->id]
        );

        $section = \core_courseformat\formatactions::section($course)->create($last + 1, !$hasdelegated);

        return (int) $section->id;
    }

    /**
     * How many ordinary sections the course has, not counting the general one.
     *
     * @param int $courseid
     * @return int
     */
    protected static function ordinary_section_count(int $courseid): int {
        global $DB;

        return $DB->count_records_select(
            'course_sections',
            'course = :course AND component IS NULL AND section > 0',
            ['course' => $courseid]
        );
    }

    /**
     * The most sections a course may have, as the site is set up.
     *
     * @return int
     */
    protected static function section_limit(): int {
        $limit = get_config('moodlecourse', 'maxsections');

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 52;
    }
}
