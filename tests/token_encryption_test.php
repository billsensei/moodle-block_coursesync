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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the token encryption round-trip (Phase 2): instance_config_save()
 * encrypts a submitted token with \core\encryption, and decrypt_token()
 * recovers it exactly - and, just as importantly, the plaintext never
 * touches the stored config at all.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\block_coursesync::class)]
final class token_encryption_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Adds a real coursesync block instance to a course, the same way
     * deploy_test.py's own "block-addable" live check does.
     *
     * @return \block_coursesync
     */
    protected function add_block_instance(): \block_coursesync {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $page = new \moodle_page();
        $page->set_context($context);
        $page->set_course($course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view');
        $page->blocks->add_region('side-pre');
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*', null);

        global $DB;
        $bi = $DB->get_record('block_instances', ['blockname' => 'coursesync', 'parentcontextid' => $context->id], '*', MUST_EXIST);

        return block_instance('coursesync', $bi);
    }

    /**
     * Reloads a block instance fresh from the database - instance_config_save()
     * doesn't update $this->config on the same in-memory object (except
     * save_lastsync(), which does so deliberately), so a real reload is
     * what a subsequent page view would actually see.
     *
     * @param \block_coursesync $block
     * @return \block_coursesync
     */
    protected function reload(\block_coursesync $block): \block_coursesync {
        global $DB;

        $bi = $DB->get_record('block_instances', ['id' => $block->instance->id], '*', MUST_EXIST);

        return block_instance('coursesync', $bi);
    }

    /**
     * Simulates what a real form submission actually hands to
     * instance_config_save(): lib/blocklib.php clones the block's
     * *existing* config, then overlays only the fields that came through
     * as config_* form inputs - so any field we don't declare a form
     * input for (encryptedtoken, laststatus*, ...) survives untouched
     * from the old config, and every field we DO declare (remoteurl,
     * token, allowinsecure, remotecourse) is fully replaced even if blank.
     * Calling instance_config_save() directly, as every other test in
     * this class does, skips that merge entirely - fine for testing a
     * fresh save, but wrong for testing what "leave blank to keep it"
     * actually depends on.
     *
     * @param \block_coursesync $block
     * @param array $submitted config_* values, without the 'config_' prefix.
     */
    protected function save_via_form(\block_coursesync $block, array $submitted): void {
        $config = clone($block->config);
        foreach ($submitted as $field => $value) {
            $config->$field = $value;
        }
        $block->instance_config_save($config);
    }

    /**
     * Invokes the protected decrypt_token() the same way get_content() and
     * sync_now() do internally - via reflection, since there's no public
     * equivalent (and shouldn't be: nothing outside this class needs a
     * plaintext token).
     *
     * @param \block_coursesync $block
     * @param string|null $encryptedtoken
     * @return string|null
     */
    protected function decrypt(\block_coursesync $block, ?string $encryptedtoken): ?string {
        $method = new \ReflectionMethod($block, 'decrypt_token');
        $method->setAccessible(true);

        return $method->invoke($block, $encryptedtoken);
    }

    /**
     * The core round-trip: a token saved through instance_config_save()
     * decrypts back to exactly the same string.
     */
    public function test_token_round_trips_exactly(): void {
        $block = $this->add_block_instance();
        $plaintext = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

        $data = new \stdClass();
        $data->remoteurl = '';
        $data->token = $plaintext;
        $block->instance_config_save($data);

        $reloaded = $this->reload($block);
        $this->assertNotEmpty($reloaded->config->encryptedtoken);

        $decrypted = $this->decrypt($reloaded, $reloaded->config->encryptedtoken);
        $this->assertSame($plaintext, $decrypted);
    }

    /**
     * The plaintext token must never appear in the stored config blob -
     * only the encrypted form. This is what actually matters for Phase 7's
     * "never persisted in plaintext" requirement; the round-trip test
     * above only proves decryption works, not that storage is safe.
     */
    public function test_plaintext_token_is_never_stored(): void {
        global $DB;

        $block = $this->add_block_instance();
        $plaintext = 'super-secret-token-value-xyz987';

        $data = new \stdClass();
        $data->remoteurl = '';
        $data->token = $plaintext;
        $block->instance_config_save($data);

        $rawconfigdata = $DB->get_field('block_instances', 'configdata', ['id' => $block->instance->id]);
        $this->assertStringNotContainsString($plaintext, $rawconfigdata);

        // The decoded config object shouldn't have a 'token' property at
        // all - only 'encryptedtoken' - confirming it's not merely hidden
        // inside some other field either.
        $config = unserialize(base64_decode($rawconfigdata));
        $this->assertObjectNotHasProperty('token', $config);
        $this->assertNotEmpty($config->encryptedtoken);
        $this->assertStringNotContainsString($plaintext, $config->encryptedtoken);
    }

    /**
     * Saving again with the token field left blank keeps the existing
     * encrypted token untouched, rather than clearing or corrupting it -
     * the "leave blank to keep it" behaviour edit_form.php's help text
     * promises.
     */
    public function test_leaving_token_blank_on_resave_keeps_the_existing_one(): void {
        $block = $this->add_block_instance();
        $plaintext = 'keep-this-token-0000000000000000';

        $first = new \stdClass();
        $first->remoteurl = '';
        $first->token = $plaintext;
        $block->instance_config_save($first);

        // A second save - as a real form submission would produce, with
        // every declared field present (blank token included) but nothing
        // carrying the old encryptedtoken forward except the merge itself.
        $reloaded = $this->reload($block);
        $this->save_via_form($reloaded, [
            'remoteurl' => '',
            'token' => '',
            'allowinsecure' => 0,
            'remotecourse' => '',
        ]);

        $reloadedagain = $this->reload($block);
        $decrypted = $this->decrypt($reloadedagain, $reloadedagain->config->encryptedtoken);
        $this->assertSame($plaintext, $decrypted);
    }

    /**
     * decrypt_token() fails closed (returns null, doesn't throw) when
     * there's nothing to decrypt - the normal "no token saved yet" state.
     */
    public function test_decrypt_of_empty_token_returns_null(): void {
        $block = $this->add_block_instance();

        $this->assertNull($this->decrypt($block, null));
        $this->assertNull($this->decrypt($block, ''));
    }
}
