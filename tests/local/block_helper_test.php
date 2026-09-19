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
 * Tests for the connection helpers shared by the form and its AJAX actions.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

use block_coursesync\sync_fixtures;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/sync_fixtures.php');

/**
 * Tests for \block_coursesync\local\block_helper.
 *
 * The behaviour under test here is a containment rule for the stored token: a
 * token issued by one site must never be sent to another, however the URL came
 * to change.
 */
#[CoversClass(block_helper::class)]
final class block_helper_test extends \advanced_testcase {
    use sync_fixtures;

    /**
     * Resolves fixture host names without needing DNS, or the network.
     */
    protected function setUp(): void {
        parent::setUp();

        url_validator::set_resolver_for_testing(fn(string $host): array => ['93.184.216.34']);
    }

    /**
     * Puts real DNS back.
     */
    protected function tearDown(): void {
        url_validator::set_resolver_for_testing(null);
        parent::tearDown();
    }

    /**
     * Builds a block configuration holding an encrypted token.
     *
     * @param string $remoteurl The site the token belongs to.
     * @param string $token The token itself.
     * @return \stdClass
     */
    private function config_with_token(string $remoteurl, string $token = 'the-stored-token'): \stdClass {
        return (object) [
            'remoteurl' => $remoteurl,
            'tokenciphertext' => token_store::encrypt($token),
        ];
    }

    /**
     * A blank token field means "carry on with the one already stored".
     */
    public function test_a_blank_field_reuses_the_stored_token_for_the_same_site(): void {
        $this->resetAfterTest();

        $config = $this->config_with_token('https://remote.example.edu');

        $this->assertSame(
            'the-stored-token',
            block_helper::effective_token($config, '', 'https://remote.example.edu')
        );
    }

    /**
     * Trailing slashes and casing are not a different site.
     */
    public function test_the_same_site_written_differently_still_matches(): void {
        $this->resetAfterTest();

        $config = $this->config_with_token('https://remote.example.edu');

        $this->assertSame(
            'the-stored-token',
            block_helper::effective_token($config, '', 'https://REMOTE.example.edu/')
        );
    }

    /**
     * Pointing the connection elsewhere must not hand that host the old token.
     *
     * Without this, anyone who can edit the block could aim it at a server of
     * their own and read a token they were never shown.
     */
    public function test_the_stored_token_is_withheld_from_a_different_site(): void {
        $this->resetAfterTest();

        $config = $this->config_with_token('https://remote.example.edu');

        $this->assertSame(
            '',
            block_helper::effective_token($config, '', 'https://attacker.example.net')
        );
    }

    /**
     * A different port on the same host is a different service.
     */
    public function test_a_different_port_is_a_different_site(): void {
        $this->resetAfterTest();

        $config = $this->config_with_token('https://remote.example.edu');

        $this->assertSame(
            '',
            block_helper::effective_token($config, '', 'https://remote.example.edu:8443')
        );
    }

    /**
     * A token typed into the form is used as typed, wherever it is going.
     */
    public function test_a_submitted_token_always_wins(): void {
        $this->resetAfterTest();

        $config = $this->config_with_token('https://remote.example.edu');

        $this->assertSame(
            'a-fresh-token',
            block_helper::effective_token($config, '  a-fresh-token  ', 'https://elsewhere.example.net')
        );
    }

    /**
     * With nothing stored there is nothing to fall back to.
     */
    public function test_no_stored_token_yields_nothing(): void {
        $this->resetAfterTest();

        $this->assertSame('', block_helper::effective_token(null, '', 'https://remote.example.edu'));
        $this->assertFalse(block_helper::is_stored_remote(null, 'https://remote.example.edu'));
    }

    /**
     * Saving the block against a new site drops the token the old one issued.
     */
    public function test_saving_a_new_remote_url_discards_the_stored_token(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();

        // Re-save pointing somewhere else, carrying the stored ciphertext
        // forward and leaving the token field blank, which is what the block
        // editing form submits when a token is already held.
        $instance = block_helper::get_instance($blockid);
        $instance->instance_config_save((object) [
            'remoteurl' => 'https://attacker.example.net',
            'tokenciphertext' => $instance->config->tokenciphertext,
        ]);

        $instance = block_helper::get_instance($blockid);
        $this->assertSame('', $instance->config->tokenciphertext);
        $this->assertSame('', block_helper::stored_token($instance->config));
    }

    /**
     * Re-saving against the same site keeps the token that is already there.
     */
    public function test_saving_the_same_remote_url_keeps_the_stored_token(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $blockid = $this->create_configured_block();

        $instance = block_helper::get_instance($blockid);
        $instance->instance_config_save((object) [
            'remoteurl' => 'https://remote.example.edu',
            'tokenciphertext' => $instance->config->tokenciphertext,
        ]);

        $instance = block_helper::get_instance($blockid);
        $this->assertSame('a-test-token', block_helper::stored_token($instance->config));
    }
}
