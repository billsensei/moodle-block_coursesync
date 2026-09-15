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

/**
 * Cleans remote-sourced values before every activity_handler writes them to
 * the database - name, intro/content HTML, URLs, and resource file
 * name/path. Used regardless of how much the remote site is trusted today:
 * the whole point of a "source" site is that it's a different Moodle
 * install, possibly run by someone else, so its data is treated the same
 * as any other untrusted input.
 *
 * This is deliberately storage-time cleaning, on top of (not instead of)
 * Moodle's normal output-time defences (format_text() etc., which core's
 * own activity view pages already apply when displaying what gets stored
 * here) - see moodlelib.php's PARAM_CLEANHTML docblock, which explains why
 * that's normally the *only* line of defence and this is the rare
 * exception to it.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sanitizer {
    /**
     * Maximum size, in bytes, of one embedded file pulled from a remote
     * site and written directly into local storage (book chapters,
     * resource, wiki attachments, glossary entries). A cap here, on top of
     * core's usual upload-time checks, because these files never go
     * through a normal upload path at all - file_save_draft_area_files()'s
     * own maxbytes enforcement never runs on them, they're written
     * straight from a remote site's response via create_file_from_string().
     * Without this, a malicious or compromised source site could return an
     * arbitrarily large file and exhaust this site's storage/memory on
     * every sync. 20MB comfortably covers a legitimate embedded image/PDF;
     * an oversized file is skipped the same way a malformed one already is
     * (see each handler's store_files()/store_chapter_files()).
     */
    public const MAX_EMBEDDED_FILE_BYTES = 20 * 1024 * 1024;

    /**
     * Cleans a plain-text field (activity names, and other short text
     * columns that pass their own $maxlength).
     *
     * $maxlength is opt-in (default null = no truncation, the original
     * behaviour) rather than a single hard-coded default, because this
     * method backs fields with genuinely different column widths across
     * this plugin's various *_add_instance()/insert_record() targets - a
     * caller that knows its target column's width (e.g. feedback_item.label
     * is char(255), unlike most *.name columns' char(1333)) should pass it,
     * to truncate gracefully instead of letting an oversized remote value
     * throw a DB exception on insert.
     *
     * @param mixed $value
     * @param int|null $maxlength
     * @return string
     */
    public static function text($value, ?int $maxlength = null): string {
        $cleaned = clean_param((string) $value, PARAM_TEXT);

        return $maxlength !== null ? \core_text::substr($cleaned, 0, $maxlength) : $cleaned;
    }

    /**
     * Cleans an HTML fragment (intro/content bodies) - strips script tags,
     * event handler attributes, and similar (see clean_text()).
     *
     * @param mixed $value
     * @return string
     */
    public static function html($value): string {
        return clean_param((string) $value, PARAM_CLEANHTML);
    }

    /**
     * Cleans a URL (mod_url's externalurl).
     *
     * @param mixed $value
     * @return string
     */
    public static function url($value): string {
        return clean_param((string) $value, PARAM_URL);
    }

    /**
     * Cleans a bare filename (no path separators allowed in it).
     *
     * @param mixed $value
     * @return string
     */
    public static function filename($value): string {
        $cleaned = clean_param((string) $value, PARAM_FILE);

        return $cleaned !== '' ? $cleaned : 'file';
    }

    /**
     * Cleans a Moodle file API filepath, e.g. "/" or "/subdir/" - always
     * returns a leading- and trailing-slash path with every segment run
     * through the same rules as filename() (see PARAM_PATH).
     *
     * @param mixed $value
     * @return string
     */
    public static function filepath($value): string {
        $cleaned = clean_param((string) $value, PARAM_PATH);

        if ($cleaned === '' || $cleaned[0] !== '/') {
            $cleaned = '/' . $cleaned;
        }
        if (substr($cleaned, -1) !== '/') {
            $cleaned .= '/';
        }

        return $cleaned;
    }

    /**
     * Cleans an integer field (display options, ...), with a fallback for
     * anything that isn't a plain integer.
     *
     * @param mixed $value
     * @param int $default
     * @return int
     */
    public static function integer($value, int $default = 0): int {
        if (!is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Cleans a float field (question defaultmark/penalty/fraction/tolerance,
     * ...), with a fallback for anything that isn't numeric.
     *
     * @param mixed $value
     * @param float $default
     * @return float
     */
    public static function float($value, float $default = 0.0): float {
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Cleans a FORMAT_* constant (introformat/contentformat) - anything
     * other than one of Moodle's own known values falls back to
     * FORMAT_HTML rather than being stored as-is and trusted later.
     *
     * @param mixed $value
     * @return int
     */
    public static function textformat($value): int {
        $known = [FORMAT_MOODLE, FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN];
        $value = self::integer($value, FORMAT_HTML);

        return in_array($value, $known, true) ? $value : FORMAT_HTML;
    }

    /**
     * Cleans a short field whose exact punctuation is load-bearing - e.g.
     * mod_feedback's feedback_item.presentation/options/dependvalue, which
     * pack a subtype flag and a list of option labels together using fixed
     * separator strings (see feedback_activity_exporter's docblock) that a
     * generic tag-stripping clean would corrupt: PARAM_TEXT's strip_tags()
     * call, given a lone unbalanced run of '<' characters like the
     * horizontal-layout suffix "<<<<<1", deletes from that '<' to the end
     * of the string (PHP's strip_tags() treats an unterminated '<' as an
     * open tag extending to the end of input), silently truncating the
     * value rather than raising anything - actually verified against
     * PARAM_TEXT's implementation (lib/classes/param.php), not assumed.
     *
     * PARAM_RAW_TRIMMED only fixes encoding and trims - no tag-stripping,
     * so the separator syntax survives intact. Safe here specifically
     * because nothing in mod_feedback ever renders this field as raw HTML
     * (noclean/FORMAT_HTML) - every call site parses it into option
     * labels first and displays each through format_string(), which
     * applies its own output-time escaping - the same defence intro/name
     * fields ALSO get, just with storage-time cleaning layered on top of
     * it too since those genuinely are rendered as raw HTML. A stray
     * length cap still guards against an unbounded remote payload.
     *
     * @param mixed $value
     * @param int $maxlength
     * @return string
     */
    public static function structured($value, int $maxlength = 1333): string {
        $cleaned = clean_param((string) $value, PARAM_RAW_TRIMMED);

        return \core_text::substr($cleaned, 0, $maxlength);
    }
}
