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

/**
 * Everything the destination needs to rebuild one activity.
 *
 * Everything in here arrived from another site and is treated as untrusted,
 * whoever happens to own that site today. from_response() is the one place a
 * payload is built from a response, and it cleans every field on the way in, so
 * nothing downstream has to remember to.
 *
 * The envelope - name, intro, section, visibility - is the same for every
 * activity type. Whatever is particular to a type travels in $settings as a
 * flat map of strings, which is what lets one external function carry any
 * activity type without its return structure changing.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_payload {
    /** @var int Course module id on the source site. */
    public readonly int $cmid;

    /** @var string Activity type, for example "page". */
    public readonly string $modname;

    /** @var string Activity name. */
    public readonly string $name;

    /** @var string ID number on the source site, often empty. */
    public readonly string $idnumber;

    /** @var int Section number the activity sits in on the source site. */
    public readonly int $sectionnum;

    /** @var int Course module id on the source site of the subsection holding the activity, or 0. */
    public readonly int $subsectioncmid;

    /** @var bool Whether the activity is visible on the source site. */
    public readonly bool $visible;

    /** @var string Activity description. */
    public readonly string $intro;

    /** @var int Format of the description, a FORMAT_* constant. */
    public readonly int $introformat;

    /** @var int When the activity was last modified on the source site. */
    public readonly int $timemodified;

    /** @var array Type-specific settings, name => string value. */
    public readonly array $settings;

    /** @var array[] Metadata for files belonging to the activity; content is fetched separately. */
    public readonly array $files;

    /** @var array[] Child records, each ['type' => string, 'sortorder' => int, 'fields' => [name => string]]. */
    public readonly array $children;

    /**
     * Build a payload.
     *
     * @param int $cmid
     * @param string $modname
     * @param string $name
     * @param string $idnumber
     * @param int $sectionnum
     * @param bool $visible
     * @param string $intro
     * @param int $introformat
     * @param int $timemodified
     * @param array $settings
     * @param array[] $files
     * @param array[] $children
     */
    public function __construct(
        int $cmid,
        string $modname,
        string $name,
        string $idnumber,
        int $sectionnum,
        bool $visible,
        string $intro,
        int $introformat,
        int $timemodified,
        array $settings,
        array $files = [],
        array $children = [],
        int $subsectioncmid = 0
    ) {
        $this->cmid = $cmid;
        $this->modname = $modname;
        $this->name = $name;
        $this->idnumber = $idnumber;
        $this->sectionnum = $sectionnum;
        $this->visible = $visible;
        $this->intro = $intro;
        $this->introformat = $introformat;
        $this->timemodified = $timemodified;
        $this->settings = $settings;
        $this->files = $files;
        $this->children = $children;
        $this->subsectioncmid = $subsectioncmid;
    }

    /**
     * Rebuild a payload from what the external function returned.
     *
     * @param array $data the decoded response for one activity
     * @return self
     */
    public static function from_response(array $data): self {
        $settings = [];

        foreach ($data['settings'] ?? [] as $setting) {
            if (!isset($setting['name'])) {
                continue;
            }

            // Setting names address handler code, so they are held to the same
            // shape the source promised: letters, numbers, hyphen, underscore.
            $name = clean_param((string) $setting['name'], PARAM_ALPHANUMEXT);

            if ($name === '') {
                continue;
            }

            $settings[$name] = (string) ($setting['value'] ?? '');
        }

        $introformat = clean_param($data['introformat'] ?? FORMAT_HTML, PARAM_INT);

        return new self(
            clean_param($data['cmid'] ?? 0, PARAM_INT),
            clean_param((string) ($data['modname'] ?? ''), PARAM_PLUGIN),
            clean_param((string) ($data['name'] ?? ''), PARAM_TEXT),
            clean_param((string) ($data['idnumber'] ?? ''), PARAM_TEXT),
            max(0, clean_param($data['sectionnum'] ?? 0, PARAM_INT)),
            (bool) ($data['visible'] ?? true),
            // The description is HTML by design, so it is cleaned rather than
            // stripped: clean_text() removes scripts, event handlers and the
            // rest while leaving the markup a description legitimately uses.
            self::clean_html((string) ($data['intro'] ?? ''), $introformat),
            self::clean_format($introformat),
            max(0, clean_param($data['timemodified'] ?? 0, PARAM_INT)),
            $settings,
            self::clean_files($data['files'] ?? []),
            self::clean_children($data['children'] ?? []),
            max(0, clean_param($data['subsectioncmid'] ?? 0, PARAM_INT))
        );
    }

    /**
     * Clean a block of HTML that came from another site.
     *
     * @param string $html
     * @param int $format one of the FORMAT_* constants
     * @return string
     */
    public static function clean_html(string $html, int $format): string {
        if ($html === '') {
            return '';
        }

        if ((int) $format === (int) FORMAT_PLAIN) {
            return clean_param($html, PARAM_TEXT);
        }

        return clean_text($html, $format);
    }

    /**
     * Keep a text format to one this site actually understands.
     *
     * @param int $format
     * @return int
     */
    protected static function clean_format(int $format): int {
        $known = [(int) FORMAT_MOODLE, (int) FORMAT_HTML, (int) FORMAT_PLAIN, (int) FORMAT_MARKDOWN];

        return in_array($format, $known, true) ? $format : (int) FORMAT_HTML;
    }

    /**
     * Clean the file metadata a source site sent.
     *
     * The path and name reach the file storage API, so they are held to the
     * parameter types Moodle uses for them. A file whose name or area does not
     * survive cleaning is dropped rather than guessed at.
     *
     * @param array $files
     * @return array[]
     */
    protected static function clean_files(array $files): array {
        $cleaned = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $filename = clean_param((string) ($file['filename'] ?? ''), PARAM_FILE);
            $filearea = clean_param((string) ($file['filearea'] ?? ''), PARAM_AREA);
            $filepath = clean_param((string) ($file['filepath'] ?? '/'), PARAM_PATH);

            if ($filename === '' || $filearea === '') {
                continue;
            }

            $cleaned[] = [
                // Empty for the activity's own component, which is all an
                // older source ever sends. Checked against what this site's
                // handler declares before anything is stored.
                'component' => clean_param((string) ($file['component'] ?? ''), PARAM_COMPONENT),
                'filearea' => $filearea,
                'itemid' => max(0, clean_param($file['itemid'] ?? 0, PARAM_INT)),
                'filepath' => $filepath === '' ? '/' : $filepath,
                'filename' => $filename,
                'filesize' => max(0, clean_param($file['filesize'] ?? 0, PARAM_INT)),
                'mimetype' => clean_param((string) ($file['mimetype'] ?? ''), PARAM_TEXT),
                'sortorder' => max(0, clean_param($file['sortorder'] ?? 0, PARAM_INT)),
                'timemodified' => max(0, clean_param($file['timemodified'] ?? 0, PARAM_INT)),
                'contenthash' => clean_param((string) ($file['contenthash'] ?? ''), PARAM_ALPHANUM),
            ];
        }

        return $cleaned;
    }

    /**
     * Clean the child records a source site sent.
     *
     * Some activities are not one row: a book has chapters, an assignment has a
     * row per submission or feedback plugin setting. Those travel as a flat
     * list so that one external function still carries every activity type.
     *
     * Names address handler code and are held to the shape the source promised.
     * Values stay raw here, as settings do, because only the handler knows
     * whether a given field is a number, a title or a block of HTML; it reads
     * them through child_field(), child_int() or child_html().
     *
     * @param array $children
     * @return array[]
     */
    protected static function clean_children(array $children): array {
        $cleaned = [];

        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            $type = clean_param((string) ($child['type'] ?? ''), PARAM_ALPHANUMEXT);

            if ($type === '') {
                continue;
            }

            $fields = [];

            foreach ($child['fields'] ?? [] as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $name = clean_param((string) ($field['name'] ?? ''), PARAM_ALPHANUMEXT);

                if ($name === '') {
                    continue;
                }

                $fields[$name] = (string) ($field['value'] ?? '');
            }

            $cleaned[] = [
                'type' => $type,
                'sortorder' => max(0, clean_param($child['sortorder'] ?? 0, PARAM_INT)),
                'fields' => $fields,
            ];
        }

        // The source site's order is what the reader sees, and nothing
        // downstream should have to sort it again.
        usort($cleaned, fn(array $a, array $b) => $a['sortorder'] <=> $b['sortorder']);

        return $cleaned;
    }

    /**
     * The child records of one kind, in the order the source site had them.
     *
     * @param string $type for example 'chapter'
     * @return array[] each entry ['type' => string, 'sortorder' => int, 'fields' => [name => string]]
     */
    public function children(string $type): array {
        return array_values(array_filter(
            $this->children,
            fn(array $child) => $child['type'] === $type
        ));
    }

    /**
     * Read a field of a child record.
     *
     * @param array $child one entry from children()
     * @param string $name
     * @param string $default
     * @return string
     */
    public static function child_field(array $child, string $name, string $default = ''): string {
        return $child['fields'][$name] ?? $default;
    }

    /**
     * Read a field of a child record as a whole number.
     *
     * @param array $child one entry from children()
     * @param string $name
     * @param int $default
     * @return int
     */
    public static function child_int(array $child, string $name, int $default = 0): int {
        return isset($child['fields'][$name]) ? (int) $child['fields'][$name] : $default;
    }

    /**
     * Read a field of a child record as cleaned HTML.
     *
     * @param array $child one entry from children()
     * @param string $name
     * @param int $format one of the FORMAT_* constants
     * @return string
     */
    public static function child_html(array $child, string $name, int $format): string {
        return self::clean_html(self::child_field($child, $name), $format);
    }

    /**
     * Read a type-specific setting.
     *
     * @param string $name
     * @param string $default returned when the source site did not send this setting
     * @return string
     */
    public function setting(string $name, string $default = ''): string {
        return $this->settings[$name] ?? $default;
    }

    /**
     * Read a type-specific setting as a URL, or an empty string if it is not one.
     *
     * A handler that puts a setting somewhere Moodle will render as a link must
     * use this rather than setting(). PARAM_URL rejects javascript: and data:
     * addresses, which would otherwise become script running in a reader's
     * browser on this site.
     *
     * @param string $name
     * @return string
     */
    public function setting_url(string $name): string {
        return clean_param($this->setting($name), PARAM_URL);
    }

    /**
     * Read a type-specific setting as cleaned HTML.
     *
     * @param string $name
     * @param int $format one of the FORMAT_* constants
     * @return string
     */
    public function setting_html(string $name, int $format): string {
        return self::clean_html($this->setting($name), $format);
    }

    /**
     * Read a type-specific setting as a whole number.
     *
     * @param string $name
     * @param int $default
     * @return int
     */
    public function setting_int(string $name, int $default = 0): int {
        return isset($this->settings[$name]) ? (int) $this->settings[$name] : $default;
    }

    /**
     * Does this activity carry files of its own?
     *
     * @return bool
     */
    public function has_files(): bool {
        return $this->files !== [];
    }

    /**
     * Is there a file in one particular area of the activity's own component?
     *
     * has_files() is no test of whether an activity's package came, now that
     * the images in any activity's description travel too.
     *
     * @param string $filearea
     * @param int|null $itemid null for any item id
     * @return bool
     */
    public function has_file_in(string $filearea, ?int $itemid = null): bool {
        foreach ($this->files as $file) {
            $component = (string) ($file['component'] ?? '');

            if ($component !== '' && $component !== 'mod_' . $this->modname) {
                continue;
            }

            if ($file['filearea'] === $filearea && ($itemid === null || (int) $file['itemid'] === $itemid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does any text in this payload point at a file that is not coming with it?
     *
     * A file embedded in a text field arrives as an @@PLUGINFILE@@ link, and
     * the file itself travels only if it is in one of the areas the handler
     * declared (the description's, a page's content, a book's chapters...).
     * A link to anything else will not resolve here. The sync reports that
     * rather than quietly creating a broken activity.
     *
     * @return bool
     */
    public function references_files(): bool {
        return $this->missing_files() !== [];
    }

    /**
     * The names of files a text in this payload links to that are not coming with it.
     *
     * Matched by path and name, whichever area the file is in: the link in a
     * text does not say which area it means, only where in it the file sits.
     *
     * @return string[] file names, each once
     */
    public function missing_files(): array {
        $texts = array_merge([$this->intro], array_values($this->settings));

        foreach ($this->children as $child) {
            foreach ($child['fields'] ?? [] as $value) {
                $texts[] = (string) $value;
            }
        }

        $arriving = [];

        foreach ($this->files as $file) {
            $arriving[$file['filepath'] . $file['filename']] = true;
        }

        $missing = [];

        foreach ($texts as $text) {
            if (!preg_match_all('~@@PLUGINFILE@@(/[^"\'\s<>?#)]*)~', (string) $text, $matches)) {
                continue;
            }

            foreach ($matches[1] as $path) {
                $path = rawurldecode($path);

                // The path was percent-decoded, so it can now hold anything at
                // all - markup included. What is kept is only ever shown as a
                // file name, so it is held to what a file name may be.
                $name = clean_param(basename($path), PARAM_FILE);

                if (!isset($arriving[$path]) && $name !== '') {
                    $missing[$path] = $name;
                }
            }
        }

        return array_values(array_unique($missing));
    }
}
