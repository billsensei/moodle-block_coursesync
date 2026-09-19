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
 * What the block itself shows on the course page.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\output;

use block_coursesync\local\sync\status;

/**
 * The block body: where this course pulls from, how it stands, and what to do next.
 */
class block_content implements \renderable, \templatable {
    /**
     * Constructor.
     *
     * @param int $blockinstanceid Block instance being shown.
     * @param \context_block $context The block's context, for capability checks.
     * @param \stdClass|null $config The block instance configuration.
     * @param \moodle_url $returnurl Where the sync action should send the user back to.
     */
    public function __construct(
        /** @var int Block instance being shown. */
        private readonly int $blockinstanceid,
        /** @var \context_block The block's context, for capability checks. */
        private readonly \context_block $context,
        /** @var \stdClass|null The block instance configuration. */
        private readonly ?\stdClass $config,
        /** @var \moodle_url Where the sync action should send the user back to. */
        private readonly \moodle_url $returnurl,
    ) {
    }

    /**
     * Builds the template context.
     *
     * @param \renderer_base $output The renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $state = status::for_block($this->blockinstanceid, $this->config);

        $cantrigger = has_capability('block/coursesync:trigger', $this->context);
        $canviewhistory = has_capability('block/coursesync:viewhistory', $this->context);

        $params = ['id' => $this->blockinstanceid];

        return [
            'uniqid' => \html_writer::random_id('block_coursesync_status'),
            'blockid' => $this->blockinstanceid,
            'configured' => $state['configured'],
            'cansetup' => $cantrigger,

            'remotesitename' => $state['remotesitename'],
            'remotecoursename' => $state['remotecoursename'],
            'hasremote' => $state['remotesitename'] !== '' || $state['remotecoursename'] !== '',

            'synced' => $state['synced'],
            'conflicts' => $state['conflicts'],
            'hasconflicts' => $state['conflicts'] > 0,
            'summary' => get_string('status:summary', 'block_coursesync', (object) [
                'synced' => $state['synced'],
                'conflicts' => $state['conflicts'],
            ]),

            'haslastrun' => $state['lastrun'] > 0,
            'lastrun' => $state['lastrun'] ? userdate($state['lastrun']) : '',

            'running' => $state['running'],
            'cantrigger' => $cantrigger,
            'syncurl' => (new \moodle_url('/blocks/coursesync/sync.php'))->out(false),
            'returnurl' => $this->returnurl->out_as_local_url(false),
            'sesskey' => sesskey(),

            'canviewhistory' => $canviewhistory,
            'historyurl' => (new \moodle_url('/blocks/coursesync/history.php', $params))->out(false),

            'canresolve' => $cantrigger,
            'conflictsurl' => (new \moodle_url('/blocks/coursesync/conflicts.php', $params))->out(false),
        ];
    }
}
