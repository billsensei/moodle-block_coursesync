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
 * Course Sync block definition.
 *
 * "Sync now" lists what changed on the mapped course since lastsync, pulls
 * full content for whichever types classes/local/activity_handler_registry.php
 * currently supports (Page, URL, Label, Resource, Forum as of Phase 5,
 * Assignment/H5P as of Phase 9, Quiz - including its questions - as of
 * Phase 10, Glossary and Wiki added after, Choice/Feedback - including
 * an opt-in for anonymised aggregate response summaries, this block
 * instance's own config_includeanswers checkbox (see edit_form.php) - as
 * of Phase 12, and Book - including its chapters and any embedded file -
 * as of Phase 13), and recreates them locally - flagging anything that collides with an
 * existing local activity as a conflict rather than touching it. Every run
 * is recorded to sync history (block_coursesync_synclog), viewable from
 * history.php. This class is deliberately kept to the block lifecycle
 * (config save, sync orchestration, wiring things together) - see
 * classes/local/content_renderer.php for how results become the text shown
 * to the user, classes/local/sync_runner.php for the sync itself, and
 * classes/local/sync_history.php for the persisted record of it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Course Sync block class.
 */
class block_coursesync extends block_base {
    /**
     * Sets the block title.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_coursesync');
    }

    /**
     * Builds the block's content.
     *
     * Everything this method can show - the remote site's identity, the
     * mapped course's name, and a live preview of what's changed on it -
     * is only for whoever is allowed to actually sync. Without
     * block/coursesync:sync, a viewer (e.g. a student on the course page)
     * gets an empty block body, the same as get_footer_links() already does
     * for its own links: neither the remote site's existence nor its
     * content should be visible to, or triggerable a remote call by,
     * someone who can't use the block at all.
     *
     * @return \stdClass
     */
    public function get_content(): stdClass {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        if (!has_capability('block/coursesync:sync', $this->context)) {
            $this->content->text = '';
            return $this->content;
        }

        $remoteurl = trim((string) ($this->config->remoteurl ?? ''));
        if ($remoteurl === '') {
            $this->content->text = html_writer::tag('p', get_string('notyetconfigured', 'block_coursesync'));
            return $this->content;
        }

        $text = html_writer::tag('p', get_string('contentremote', 'block_coursesync', $remoteurl));
        $text .= html_writer::tag('p', \block_coursesync\local\content_renderer::status_text($this->config));
        $text .= html_writer::tag('p', \block_coursesync\local\content_renderer::coursemapping_text($this->config));
        $text .= $this->get_activities_preview_html();
        $this->content->text = $text;
        $this->content->footer = $this->get_footer_links();

        return $this->content;
    }

    /**
     * Intercepts the block config form's data before it is persisted.
     *
     * The plaintext token field is never stored: it is encrypted (or, if
     * left blank, the previously-stored encrypted token is kept as-is by
     * simply not touching it - lib/blocklib.php already clones the existing
     * config before merging in submitted config_* fields). The connection
     * is then tested once here, with whatever token results, and the
     * outcome is cached so get_content() doesn't need to make a live
     * remote call on every page view. If a remote course is set, it's
     * validated the same way, but only once the connection itself is
     * known to work.
     *
     * lastsync is only ever advanced by sync_now() (via save_lastsync()) -
     * saving the config here never touches it.
     *
     * @param stdClass $data
     * @param bool $nolongerused
     */
    public function instance_config_save($data, $nolongerused = false): void {
        $config = clone($data);

        if (!property_exists($config, 'lastsync')) {
            $config->lastsync = null;
        }

        $remoteurl = trim((string) ($config->remoteurl ?? ''));
        $newtoken = trim((string) ($config->token ?? ''));
        unset($config->token);

        if ($newtoken !== '') {
            $config->encryptedtoken = \core\encryption::encrypt($newtoken);
        }

        $plaintexttoken = $newtoken !== '' ? $newtoken : $this->decrypt_token($config->encryptedtoken ?? null);

        $connectionok = $this->test_connection($config, $remoteurl, $plaintexttoken);
        $this->resolve_course_mapping($config, $connectionok, $remoteurl, $plaintexttoken);

        parent::instance_config_save($config, $nolongerused);
    }

    /**
     * Tests the connection (unless the URL is unsafe - see below) and fills
     * in $config's laststatus* fields accordingly. Split out of
     * instance_config_save() purely to keep that method readable.
     *
     * Defense in depth: edit_form.php's validation() is the primary defence
     * against a loopback/private-range remoteurl (it blocks the save
     * outright, with a clear error) - but that's formslib-specific and only
     * runs for a real form submission. Nothing stops some other caller
     * reaching instance_config_save() directly with a URL that was never
     * validated (this plugin's own tests do exactly that throughout). So
     * even here, without an explicit override, a dangerous URL is simply
     * never connected to - same as if it were empty, except it gets its own
     * errorcode ('privaterange') rather than looking like "never checked".
     *
     * @param stdClass $config Mutated in place.
     * @param string $remoteurl
     * @param string|null $plaintexttoken
     * @return bool Whether the connection is known to work.
     */
    protected function test_connection(stdClass $config, string $remoteurl, ?string $plaintexttoken): bool {
        $allowinsecure = !empty($config->allowinsecure);
        $urlissafe = $remoteurl === '' || $allowinsecure
            || !\block_coursesync\local\url_safety::is_private_or_loopback($remoteurl);

        if (!$urlissafe) {
            $config->laststatus = 0;
            $config->laststatuserrorcode = 'privaterange';
            $config->laststatustechnical = null;
            $config->laststatustime = time();
            $config->laststatussitename = null;
            return false;
        }

        if ($remoteurl === '' || empty($plaintexttoken)) {
            $config->laststatus = null;
            $config->laststatuserrorcode = null;
            $config->laststatustechnical = null;
            $config->laststatustime = null;
            $config->laststatussitename = null;
            return false;
        }

        $result = $this->get_remote_client($remoteurl, $plaintexttoken)->ping();
        $config->laststatus = $result['success'] ? 1 : 0;
        $config->laststatuserrorcode = $result['errorcode'];
        $config->laststatustechnical = $result['technical'];
        $config->laststatustime = time();
        $config->laststatussitename = $result['success'] ? ($result['data']['sitename'] ?? '') : '';

        return $result['success'];
    }

    /**
     * Fills in $config's coursemapping* fields, in place, from its remotecourse field.
     *
     * Split out of instance_config_save() purely to keep that method readable -
     * this is still part of the same save, not a separate seam.
     *
     * @param stdClass $config Mutated in place.
     * @param bool $connectionok Whether the ping just above this succeeded.
     * @param string $remoteurl
     * @param string|null $plaintexttoken
     */
    protected function resolve_course_mapping(
        stdClass $config,
        bool $connectionok,
        string $remoteurl,
        ?string $plaintexttoken
    ): void {
        $config->coursemappingcourseid = null;
        $config->coursemappingfullname = null;
        $config->coursemappingshortname = null;
        $config->coursemappingerrorcode = null;
        $config->coursemappingtechnical = null;

        $remotecourse = trim((string) ($config->remotecourse ?? ''));
        if ($remotecourse === '') {
            return;
        }

        if (!$connectionok) {
            // Can't validate a course against a connection that isn't working.
            $config->coursemappingerrorcode = 'unverified';
            return;
        }

        $courseresult = $this->get_remote_client($remoteurl, $plaintexttoken)->check_course($remotecourse);
        if ($courseresult['success']) {
            $config->coursemappingcourseid = $courseresult['data']['courseid'] ?? null;
            $config->coursemappingfullname = $courseresult['data']['fullname'] ?? '';
            $config->coursemappingshortname = $courseresult['data']['shortname'] ?? '';
        } else {
            $config->coursemappingerrorcode = $courseresult['remoteerrorcode'] ?? $courseresult['errorcode'];
            $config->coursemappingtechnical = $courseresult['technical'];
        }
    }

    /**
     * Factory for the remote_client used above - a single seam so tests
     * can exercise this class's real logic against a stub client instead
     * of a live network call.
     *
     * @param string $remoteurl
     * @param string $plaintexttoken
     * @return \block_coursesync\local\remote_client
     */
    protected function get_remote_client(string $remoteurl, string $plaintexttoken): \block_coursesync\local\remote_client {
        return new \block_coursesync\local\remote_client($remoteurl, $plaintexttoken);
    }

    /**
     * Decrypts a stored token, if there is one.
     *
     * @param string|null $encryptedtoken
     * @return string|null Plaintext token, or null if there is none or it can't be decrypted.
     */
    protected function decrypt_token(?string $encryptedtoken): ?string {
        if (empty($encryptedtoken)) {
            return null;
        }
        try {
            return \core\encryption::decrypt($encryptedtoken);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Renders a live "activities changed since lastsync" preview for the mapped
     * course - a debug-style list, not the polished sync UI a later phase adds.
     *
     * Makes a real remote call on every render (there is nothing to usefully
     * cache: unlike the connection/mapping status, "what's changed since
     * lastsync" can change between page views without this block's own
     * config changing at all). Never lets a remote failure break the page.
     *
     * @return string
     */
    protected function get_activities_preview_html(): string {
        if (empty($this->config->coursemappingcourseid) || empty($this->config->laststatus)) {
            return '';
        }

        $remoteurl = trim((string) ($this->config->remoteurl ?? ''));
        $plaintexttoken = $this->decrypt_token($this->config->encryptedtoken ?? null);
        if ($remoteurl === '' || empty($plaintexttoken)) {
            return '';
        }

        $since = (int) ($this->config->lastsync ?? 0);

        try {
            $result = $this->get_remote_client($remoteurl, $plaintexttoken)
                ->get_modified_activities((string) $this->config->coursemappingcourseid, $since);
        } catch (\Throwable $e) {
            return html_writer::tag('p', get_string('previewerror', 'block_coursesync'));
        }

        if (!$result['success']) {
            return html_writer::tag('p', get_string('previewerror', 'block_coursesync'));
        }

        return \block_coursesync\local\content_renderer::activities_list_html($result['data']['activities'] ?? []);
    }

    /**
     * Renders the block footer's action links, for viewers allowed to sync:
     * "Sync now" only while this instance is fully configured and working,
     * "View sync history" always (past runs are worth seeing even if the
     * connection is currently broken).
     *
     * @return string
     */
    protected function get_footer_links(): string {
        if (!has_capability('block/coursesync:sync', $this->context)) {
            return '';
        }

        $links = [];

        if (!empty($this->config->laststatus) && !empty($this->config->coursemappingcourseid)) {
            $syncurl = new moodle_url('/blocks/coursesync/sync.php', [
                'instanceid' => $this->instance->id,
                'sesskey' => sesskey(),
            ]);
            $links[] = html_writer::link($syncurl, get_string('syncnowbutton', 'block_coursesync'));
        }

        $historyurl = new moodle_url('/blocks/coursesync/history.php', ['instanceid' => $this->instance->id]);
        $links[] = html_writer::link($historyurl, get_string('viewsynchistory', 'block_coursesync'));

        return implode(' | ', $links);
    }

    /**
     * Runs a real sync: lists what's changed on the mapped course since
     * lastsync, pulls and creates whatever activity types this plugin
     * currently supports (see classes/local/activity_handler_registry.php),
     * flags anything that collides with an existing local activity as a
     * conflict instead of touching it, and advances lastsync - but only
     * over what was actually handled (see sync_runner::compute_new_lastsync()),
     * never silently past something left behind or flagged.
     *
     * Every call is logged to sync history (see sync_history::record()) -
     * including a precondition failure, since that's still a result the
     * teacher asked for and got - except when there's no course to log it
     * against at all (get_owning_course() failing; see that method).
     *
     * config_includeanswers (Phase 12) is read fresh here each run, not
     * cached anywhere - so flipping it in this block's settings takes
     * effect on the very next "Sync now", with no re-save/reconnect step
     * needed.
     *
     * @return array Same shape as sync_runner::run(), or a precondition
     *               failure shaped the same way (empty activity lists,
     *               newlastsync null) so content_renderer::sync_result_text()
     *               can handle both.
     */
    public function sync_now(): array {
        $course = $this->get_owning_course();
        if (!$course) {
            // Nothing sensible to log this against - see get_owning_course().
            return $this->sync_precondition_failure('syncnocourse');
        }

        $remoteurl = trim((string) ($this->config->remoteurl ?? ''));
        $plaintexttoken = $this->decrypt_token($this->config->encryptedtoken ?? null);

        if ($remoteurl === '' || empty($plaintexttoken) || empty($this->config->laststatus)) {
            return $this->sync_precondition_failure('syncnotconfigured', $course);
        }

        $remotecourseid = $this->config->coursemappingcourseid ?? null;
        if (empty($remotecourseid)) {
            return $this->sync_precondition_failure('syncnocoursemapping', $course);
        }

        $since = (int) ($this->config->lastsync ?? 0);
        $client = $this->get_remote_client($remoteurl, $plaintexttoken);
        $includeanswers = !empty($this->config->includeanswers);
        $runner = new \block_coursesync\local\sync_runner(
            $client,
            (int) $course->id,
            (string) $remotecourseid,
            $includeanswers
        );
        $result = $runner->run($since);

        if ($result['success']) {
            $this->save_lastsync($result['newlastsync']);
        }

        $this->record_history($course->id, $result);

        return $result;
    }

    /**
     * A sync_now() result shape for a precondition that failed before any
     * remote call was made - nothing configured/mapped/working yet to sync.
     * Logged to sync history when there's a course to log it against.
     *
     * @param string $errorcode
     * @param \stdClass|null $course Pass when known, so this precondition failure gets logged too.
     * @return array
     */
    protected function sync_precondition_failure(string $errorcode, ?stdClass $course = null): array {
        $result = [
            'success' => false, 'errorcode' => $errorcode, 'technical' => null,
            'since' => null, 'newlastsync' => null,
            'created' => [], 'conflicts' => [], 'unsupported' => [], 'failed' => [],
        ];

        if ($course !== null) {
            $this->record_history($course->id, $result);
        }

        return $result;
    }

    /**
     * Writes one sync_now() result to sync history.
     *
     * @param int $courseid
     * @param array $result
     */
    protected function record_history(int $courseid, array $result): void {
        global $USER;

        \block_coursesync\local\sync_history::record((int) $this->instance->id, $courseid, (int) $USER->id, $result);
    }

    /**
     * Turns a sync_now() result into one plain-language line for the UI.
     *
     * @param array $result
     * @return string
     */
    public function describe_sync_result(array $result): string {
        return \block_coursesync\local\content_renderer::sync_result_text($result);
    }

    /**
     * Persists a new lastsync value without re-running the connection/course
     * mapping checks instance_config_save() does - those haven't changed.
     * parent:: here always means block_base, regardless of which method
     * this is called from, so this deliberately bypasses this class's own
     * instance_config_save() override.
     *
     * @param int $lastsync
     */
    protected function save_lastsync(int $lastsync): void {
        $config = clone($this->config);
        $config->lastsync = $lastsync;
        parent::instance_config_save($config);
        $this->config = $config;
    }

    /**
     * Finds the course this block instance lives in.
     *
     * @return \stdClass|null Null if this instance somehow isn't on a course page
     *                        (shouldn't happen given applicable_formats(), but sync.php
     *                        needs a course to sync into, so this is checked explicitly
     *                        rather than assumed).
     */
    protected function get_owning_course(): ?stdClass {
        global $DB;

        $parentcontext = $this->context->get_parent_context();
        if (!$parentcontext || $parentcontext->contextlevel != CONTEXT_COURSE) {
            return null;
        }

        return $DB->get_record('course', ['id' => $parentcontext->instanceid], '*', IGNORE_MISSING) ?: null;
    }

    /**
     * Allows multiple instances of this block on the same page.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Restricts this block to course pages.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'course-view' => true,
            'site' => false,
            'mod' => false,
            'my' => false,
        ];
    }
}
