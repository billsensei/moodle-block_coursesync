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

use advanced_testcase;
use block_coursesync\activity_payload;
use block_coursesync\external\get_activity;
use block_coursesync\external\get_activity_file;
use block_coursesync\local\handler\activity_handler;
use block_coursesync\local\handler\handler_registry;
use core\http_client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;

/**
 * Tests for files embedded in text fields: an image in a description, in a
 * page's content, in a book chapter, in a feedback label, in a workshop
 * criterion.
 *
 * Each one is copied the whole way: exported as the source would, rebuilt as
 * the destination would, and its files fetched through file_sync from a
 * "source" that answers each request by running the real
 * block_coursesync_get_activity_file on this same test site. So the source's
 * listing, its refusal rules, the transfer and the destination's filing are
 * all exercised together, not one at a time.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(file_sync::class)]
#[CoversClass(activity_handler::class)]
#[CoversClass(activity_payload::class)]
#[CoversClass(get_activity::class)]
#[CoversClass(get_activity_file::class)]
final class embedded_files_test extends advanced_testcase {
    /** @var string[] Every file the fake source was asked for, as component/area/name. */
    protected array $requested = [];

    /**
     * A client whose "source site" is this test site: each file request is
     * answered by the real web service function, as the remote end would.
     *
     * @return http_client
     */
    protected function local_source(): http_client {
        return new http_client(['mock' => function (RequestInterface $request) {
            parse_str((string) $request->getBody(), $params);

            $this->requested[] = ($params['component'] ?? '') . '/' . $params['filearea'] . '/' . $params['filename'];

            try {
                $body = get_activity_file::execute(
                    (int) $params['cmid'],
                    (string) $params['filearea'],
                    (int) $params['itemid'],
                    (string) $params['filepath'],
                    (string) $params['filename'],
                    (int) $params['offset'],
                    (int) $params['length'],
                    (string) ($params['component'] ?? '')
                );
            } catch (\moodle_exception $e) {
                $body = ['exception' => get_class($e), 'errorcode' => $e->errorcode, 'message' => $e->getMessage()];
            }

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
        }]);
    }

    /**
     * Copy an activity into another course, files and all.
     *
     * @param int $cmid the activity on the "source"
     * @param \stdClass $target the course to copy it into
     * @return array [the new course_modules record, the payload]
     */
    protected function copy(int $cmid, \stdClass $target): array {
        $payload = activity_payload::from_response(get_activity::execute($cmid));
        $handler = handler_registry::get($payload->modname);

        $cm = $handler->create_from_remote_data($target, $payload, 'coursesync-' . $cmid);
        file_sync::copy_files($cm, $handler, $payload, 'https://source.example.edu', 'token', $this->local_source());
        $handler->post_files($cm, $payload);

        return [$cm, $payload];
    }

    /**
     * Store a file against an activity.
     *
     * @param int $cmid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filename
     * @return void
     */
    protected function store(int $cmid, string $component, string $filearea, int $itemid, string $filename): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cmid)->id,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'bytes of ' . $filename);
    }

    /**
     * The content of the one file stored at a place in the copy, or null.
     *
     * @param int $cmid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filename
     * @return string|null
     */
    protected function stored(int $cmid, string $component, string $filearea, int $itemid, string $filename): ?string {
        $file = get_file_storage()->get_file(
            \context_module::instance($cmid)->id,
            $component,
            $filearea,
            $itemid,
            '/',
            $filename
        );

        return $file ? $file->get_content() : null;
    }

    /**
     * A page's images - in its description and in its content - both arrive,
     * and the links to them are left as they were, so they resolve here.
     */
    public function test_a_pages_description_and_content_images_arrive(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $source->id,
            'intro' => '<p><img src="@@PLUGINFILE@@/banner.png" alt="Banner"></p>',
            'content' => '<p><img src="@@PLUGINFILE@@/my%20diagram.png" alt="Diagram"></p>',
        ]);
        $this->store((int) $page->cmid, 'mod_page', 'intro', 0, 'banner.png');
        $this->store((int) $page->cmid, 'mod_page', 'content', 0, 'my diagram.png');

        [$cm, $payload] = $this->copy((int) $page->cmid, $target);

        // Nothing to warn about: every link has its file, the encoded
        // space in one of them included.
        $this->assertFalse($payload->references_files());
        $this->assertSame([], $payload->missing_files());

        $this->assertSame('bytes of banner.png', $this->stored((int) $cm->id, 'mod_page', 'intro', 0, 'banner.png'));
        $this->assertSame(
            'bytes of my diagram.png',
            $this->stored((int) $cm->id, 'mod_page', 'content', 0, 'my diagram.png')
        );

        $copy = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('@@PLUGINFILE@@/banner.png', $copy->intro);
        $this->assertStringContainsString('@@PLUGINFILE@@/my%20diagram.png', $copy->content);
    }

    /**
     * A text and media area is nothing but its description, so this is the
     * case that showed the gap most: every image in one arrived broken.
     */
    public function test_a_text_and_media_areas_images_arrive(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $source->id,
            'intro' => '<img src="@@PLUGINFILE@@/one.png"><img src="@@PLUGINFILE@@/two.jpg">',
        ]);
        $this->store((int) $label->cmid, 'mod_label', 'intro', 0, 'one.png');
        $this->store((int) $label->cmid, 'mod_label', 'intro', 0, 'two.jpg');

        [$cm, $payload] = $this->copy((int) $label->cmid, $target);

        $this->assertFalse($payload->references_files());
        $this->assertSame('bytes of one.png', $this->stored((int) $cm->id, 'mod_label', 'intro', 0, 'one.png'));
        $this->assertSame('bytes of two.jpg', $this->stored((int) $cm->id, 'mod_label', 'intro', 0, 'two.jpg'));
    }

    /**
     * A book's description images arrive alongside its chapters'. A book
     * files every one of its own files under a chapter, so the description's
     * have to be settled before the book is asked, or they would be dropped
     * as belonging to no chapter.
     */
    public function test_a_books_description_images_are_not_mistaken_for_a_chapters(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $source->id,
            'intro' => '<img src="@@PLUGINFILE@@/cover.png">',
        ]);
        $chapter = $this->getDataGenerator()->get_plugin_generator('mod_book')->create_chapter([
            'bookid' => $book->id,
            'pagenum' => 1,
            'title' => 'One',
            'content' => '<img src="@@PLUGINFILE@@/figure.png">',
        ]);
        $this->store((int) $book->cmid, 'mod_book', 'intro', 0, 'cover.png');
        $this->store((int) $book->cmid, 'mod_book', 'chapter', (int) $chapter->id, 'figure.png');

        [$cm, $payload] = $this->copy((int) $book->cmid, $target);

        $this->assertFalse($payload->references_files());
        $this->assertSame('bytes of cover.png', $this->stored((int) $cm->id, 'mod_book', 'intro', 0, 'cover.png'));

        $localchapter = $DB->get_record('book_chapters', ['bookid' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame(
            'bytes of figure.png',
            $this->stored((int) $cm->id, 'mod_book', 'chapter', (int) $localchapter->id, 'figure.png')
        );
    }

    /**
     * A feedback label item's image is filed under the item created here for
     * it, not under the source's item id.
     */
    public function test_a_feedback_label_items_image_follows_the_item(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $feedback = $this->getDataGenerator()->create_module('feedback', ['course' => $source->id]);
        $item = $this->getDataGenerator()->get_plugin_generator('mod_feedback')->create_item_label($feedback, [
            'presentation' => '<p><img src="@@PLUGINFILE@@/scale.png"></p>',
        ]);
        $this->store((int) $feedback->cmid, 'mod_feedback', 'item', (int) $item->id, 'scale.png');

        [$cm, $payload] = $this->copy((int) $feedback->cmid, $target);

        $this->assertFalse($payload->references_files());

        $localitem = $DB->get_record('feedback_item', ['feedback' => $cm->instance, 'typ' => 'label'], '*', MUST_EXIST);
        $this->assertNotEquals($item->id, $localitem->id);
        $this->assertSame(
            'bytes of scale.png',
            $this->stored((int) $cm->id, 'mod_feedback', 'item', (int) $localitem->id, 'scale.png')
        );
    }

    /**
     * A workshop criterion's image lives under its grading strategy's own
     * component, not the workshop's, and is filed under the criterion created
     * here - the one case that needs the component to travel.
     */
    public function test_a_workshop_criterions_image_arrives_under_its_strategy(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_course();

        $workshop = $this->getDataGenerator()->create_module('workshop', [
            'course' => $source->id,
            'strategy' => 'accumulative',
        ]);
        $dimensionid = (int) $DB->insert_record('workshopform_accumulative', (object) [
            'workshopid' => $workshop->id,
            'sort' => 1,
            'description' => '<p>Is the diagram right? <img src="@@PLUGINFILE@@/model.png"></p>',
            'descriptionformat' => FORMAT_HTML,
            'grade' => 10,
            'weight' => 1,
        ]);
        $this->store((int) $workshop->cmid, 'workshopform_accumulative', 'description', $dimensionid, 'model.png');

        [$cm, $payload] = $this->copy((int) $workshop->cmid, $target);

        $this->assertFalse($payload->references_files());
        $this->assertContains('workshopform_accumulative/description/model.png', $this->requested);

        $localdimension = $DB->get_record('workshopform_accumulative', ['workshopid' => $cm->instance], '*', MUST_EXIST);
        $this->assertNotEquals($dimensionid, (int) $localdimension->id);
        $this->assertSame(
            'bytes of model.png',
            $this->stored((int) $cm->id, 'workshopform_accumulative', 'description', (int) $localdimension->id, 'model.png')
        );
    }

    /**
     * A link whose file is not coming is named, and only that one - not every
     * link in the activity, as the old warning was.
     */
    public function test_only_the_links_whose_files_are_missing_are_reported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->getDataGenerator()->create_course();

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $source->id,
            'intro' => '<img src="@@PLUGINFILE@@/here.png">',
            'content' => '<img src="@@PLUGINFILE@@/gone.png"><a href="@@PLUGINFILE@@/sub/notes%20v2.pdf?forcedownload=1">Notes</a>',
        ]);
        $this->store((int) $page->cmid, 'mod_page', 'intro', 0, 'here.png');

        $payload = activity_payload::from_response(get_activity::execute((int) $page->cmid));

        $this->assertTrue($payload->references_files());
        $this->assertSame(['gone.png', 'notes v2.pdf'], $payload->missing_files());
    }

    /**
     * The destination decides where a file may go. A source that lists a
     * file in some other component's area - whatever it claims - has it left
     * out, and is never even asked for its content.
     */
    public function test_a_file_in_an_area_this_site_does_not_declare_is_not_taken(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $target = $this->getDataGenerator()->create_course();

        $payload = activity_payload::from_response([
            'cmid' => 5,
            'modname' => 'page',
            'name' => 'Page',
            'idnumber' => '',
            'sectionnum' => 0,
            'visible' => true,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'timemodified' => time(),
            'settings' => [['name' => 'content', 'value' => ''], ['name' => 'contentformat', 'value' => '1']],
            'children' => [],
            'files' => [[
                'component' => 'mod_forum',
                'filearea' => 'attachment',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => 'planted.php',
                'filesize' => 10,
                'mimetype' => 'text/plain',
                'sortorder' => 0,
                'timemodified' => time(),
                'contenthash' => sha1('x'),
            ]],
        ]);

        $handler = handler_registry::get('page');
        $cm = $handler->create_from_remote_data($target, $payload, 'coursesync-5');

        $stored = file_sync::copy_files($cm, $handler, $payload, 'https://source.example.edu', 'token', $this->local_source());

        $this->assertSame(0, $stored);
        $this->assertSame([], $this->requested, 'the source should never have been asked for it');
        $this->assertNull($this->stored((int) $cm->id, 'mod_forum', 'attachment', 0, 'planted.php'));
    }
}
