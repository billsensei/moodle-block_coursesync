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
 * Tests for token encryption at rest.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \block_coursesync\local\token_store.
 */
#[CoversClass(token_store::class)]
final class token_store_test extends \advanced_testcase {
    /**
     * A token survives a trip through encryption unchanged.
     */
    public function test_round_trip_returns_the_original_token(): void {
        $this->resetAfterTest();

        $token = 'ec4dc12377d05baed01c548df42554d5';
        $ciphertext = token_store::encrypt($token);

        $this->assertSame($token, token_store::decrypt($ciphertext));
    }

    /**
     * The stored form does not contain the token.
     */
    public function test_ciphertext_does_not_expose_the_token(): void {
        $this->resetAfterTest();

        $token = 'a-secret-token-value';
        $ciphertext = token_store::encrypt($token);

        $this->assertStringNotContainsString($token, $ciphertext);
        $this->assertNotSame($token, $ciphertext);
    }

    /**
     * Encrypting twice gives different ciphertext, so the value cannot be matched by eye.
     */
    public function test_encryption_is_not_deterministic(): void {
        $this->resetAfterTest();

        $token = 'a-secret-token-value';

        $this->assertNotSame(token_store::encrypt($token), token_store::encrypt($token));
    }

    /**
     * An empty token stays empty rather than becoming ciphertext for nothing.
     */
    public function test_empty_token_round_trips_as_empty(): void {
        $this->resetAfterTest();

        $this->assertSame('', token_store::encrypt(''));
        $this->assertSame('', token_store::decrypt(''));
    }

    /**
     * Rubbish in the stored field yields an empty token rather than an exception.
     *
     * A block whose configuration was restored onto a site with a different key
     * must fail closed, not take the whole page down.
     */
    public function test_undecryptable_value_yields_an_empty_token(): void {
        $this->resetAfterTest();

        $this->assertSame('', token_store::decrypt('sodium:not-really-base64-ciphertext'));
        $this->assertDebuggingCalled();
    }

    /**
     * Tokens of the length Moodle actually issues round trip.
     */
    public function test_a_realistic_moodle_token_round_trips(): void {
        $this->resetAfterTest();

        $token = md5('moodle-token-' . random_int(1, 1000));

        $this->assertSame(32, strlen($token));
        $this->assertSame($token, token_store::decrypt(token_store::encrypt($token)));
    }
}
