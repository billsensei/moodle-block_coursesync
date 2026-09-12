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
 * English language strings for block_coursesync.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activitytypenotsupported'] = 'Activity type "{$a}" is not supported by block_coursesync\'s ' .
    'activity_exporter_registry.';
$string['allowinsecure'] = 'This is a development/testing connection (allow HTTP)';
$string['allowinsecure_help'] = 'Only enable this to connect to a development or testing site on a private ' .
    'network without HTTPS. It bypasses this block\'s protections against server-side request forgery (SSRF) ' .
    'for this connection. Never enable it for a real, internet-facing site.';
$string['backtocourse'] = 'Back to course';
$string['contentremote'] = 'Remote site: {$a}';
$string['coursemappingnone'] = 'No remote course mapped yet.';
$string['coursemappingok'] = 'Mapped to "{$a->fullname}" (id {$a->courseid}, shortname {$a->shortname}) on the remote site.';
$string['coursemappingstatus'] = 'Course mapping';
$string['coursemappingunverified'] = 'Course mapping not verified - check the connection status above.';
$string['coursemodulenotfound'] = 'No activity matches that course module id.';
$string['coursenotaccessible'] = 'The sync account cannot access that course.';
$string['coursenotfound'] = 'No course matches that id or shortname.';
$string['coursesync:addinstance'] = 'Add a new Course Sync block';
$string['coursesync:sync'] = 'Trigger a manual course sync';
$string['emptycourseidentifier'] = 'No course id or shortname was supplied.';
$string['err_remoteurl_malformed'] = 'Enter a well-formed URL, for example https://source.example.edu.';
$string['err_remoteurl_privaterange'] = 'That address is a loopback, link-local, or private network address, which ' .
    'is blocked by default. Enable the development/testing override below if this is intentional.';
$string['err_remoteurl_scheme'] = 'Enter an HTTPS URL (or enable the development/testing option below to allow HTTP).';
$string['err_remoteurlrequired'] = 'Enter the remote site\'s URL.';
$string['error_badtoken'] = 'The remote site rejected this token. Generate a new token on the remote site and ' .
    'paste it in again.';
$string['error_remote'] = 'The remote site reported a problem: {$a}';
$string['error_unreachable'] = 'Could not reach the remote site. Check the URL and that the remote server is online.';
$string['error_wrongurl'] = 'That address doesn\'t look like a Moodle site with Course Sync\'s web service ' .
    'enabled. Double-check the remote site URL.';
$string['forumnewsnotsynced'] = 'This is a "news" (Announcements) forum. Every course already has one of ' .
    'its own, so this isn\'t created - syncing it would leave the course with two.';
$string['neverchecked'] = 'Not yet checked. Save this block\'s settings with a remote site URL and token to test the connection.';
$string['nosynchistory'] = 'No syncs have been run yet.';
$string['notyetconfigured'] = 'Course Sync — not yet configured';
$string['pluginname'] = 'Course Sync';
$string['previewerror'] = 'Could not check for new activities - see the connection status above.';
$string['previewheading'] = '{$a} activity/activities found since last sync:';
$string['previewitem'] = '{$a->name} ({$a->modname}) - last modified {$a->time}';
$string['previewnone'] = 'No new or changed activities found since last sync.';
$string['privacy:metadata'] = 'The Course Sync block stores a remote site URL and an encrypted web service ' .
    'token for that site\'s own use - it does not store personal data about any Moodle user.';
$string['remotecourse'] = 'Remote course ID or shortname';
$string['remotecourse_help'] = 'The numeric course ID or the shortname of the course on the remote site that ' .
    'this block should track. Checked against the remote site (via the stored token) each time you save. ' .
    'Leave blank if you only want to test the connection for now.';
$string['remoteurl'] = 'Remote site URL';
$string['remoteurl_help'] = 'The full HTTPS address of the remote (source) Moodle site, for example ' .
    'https://source.example.edu. Must use HTTPS, and must not point at a loopback, link-local, or private ' .
    'network address, unless the development/testing override below is enabled.';
$string['statusfail'] = '{$a->message} (last checked {$a->time})';
$string['statusok'] = 'Connected to "{$a->sitename}" (last checked {$a->time}).';
$string['synclogconflictitem'] = '{$a->name} ({$a->modname}) - matches existing "{$a->conflictname}" (course module id {$a->conflictcmid})';
$string['synclogconflictsheading'] = 'Flagged as conflicts - not created, needs manual review';
$string['synclogcounts'] = '{$a->created} created, {$a->conflicts} conflicts, {$a->failed} failed';
$string['synclogcreatedheading'] = 'Created';
$string['synclogcreateditem'] = '{$a->name} ({$a->modname})';
$string['synclogfailed'] = 'Failed';
$string['synclogfailedheading'] = 'Failed';
$string['synclogfaileditem'] = '{$a->name} ({$a->modname}): {$a->message}';
$string['synclogsuccess'] = 'Completed';
$string['synclogtriggeredby'] = 'Triggered by {$a}.';
$string['syncnocourse'] = 'Could not determine which course this block belongs to.';
$string['syncnocoursemapping'] = 'Set a working remote course mapping in this block\'s settings before syncing.';
$string['syncnotconfigured'] = 'Set a remote site and token, and confirm the connection works, before syncing.';
$string['syncnothingtodo'] = 'Nothing to sync - no new or changed activities of a supported type were found.';
$string['syncnowbutton'] = 'Sync now';
$string['syncsummary'] = '{$a->created} created, {$a->conflicts} flagged as conflicts, {$a->unsupported} not yet ' .
    'supported (left for a later sync), {$a->failed} failed.';
$string['token'] = 'Token';
$string['token_help'] = 'The web service token generated on the remote site in step 7 of the setup wizard ' .
    'above. Stored encrypted. Leave this field blank when editing an existing, working connection to keep the ' .
    'current token.';
$string['tokenstatus'] = 'Current token';
$string['tokenstatusnone'] = 'No token saved yet.';
$string['tokenstatussaved'] = 'A token is saved (encrypted). Leave the field below blank to keep it.';
$string['unknownuser'] = 'Unknown user';
$string['viewsynchistory'] = 'View sync history';
$string['wizarddocnote'] = 'These same steps are also written out in REMOTE_SETUP.md, included with this ' .
    'plugin, in case the wizard above is hard to follow exactly.';
$string['wizardheading'] = 'Connect to a remote site';
$string['wizardintro'] = 'Before this block can talk to another Moodle site, an administrator on that ' .
    'REMOTE site needs to complete these steps once:';
$string['wizardstep1'] = 'Log in to the REMOTE (source) site as a site administrator.';
$string['wizardstep2'] = 'Go to Site administration ▸ General ▸ Web services ▸ Overview, and enable web ' .
    'services if they are not already enabled.';
$string['wizardstep3'] = 'Go to Site administration ▸ Plugins ▸ Web services ▸ Manage protocols, and enable ' .
    'the REST protocol.';
$string['wizardstep4'] = 'Go to Site administration ▸ Plugins ▸ Web services ▸ External services. Find ' .
    '"Course Sync" in the list - it appears automatically once this plugin is installed there - click Edit, ' .
    'tick Enabled, and Save.';
$string['wizardstep5'] = 'Still on that service\'s page, click "Authorised users" and add the account that ' .
    'will act as the sync account (a dedicated account, or an existing admin/teacher).';
$string['wizardstep6'] = 'Make sure that account\'s role grants the "Trigger a manual course sync" ' .
    '(block/coursesync:sync) capability - for example under Site administration ▸ Users ▸ Define roles, allow ' .
    'that capability on a role, then assign that role to the account under Site administration ▸ Users ▸ ' .
    'Permissions ▸ Assign system roles.';
$string['wizardstep7'] = 'Go to Site administration ▸ Plugins ▸ Web services ▸ Manage tokens, and create a ' .
    'new token for that account and the "Course Sync" service.';
$string['wizardstep8'] = 'Copy the generated token.';
$string['wizardstep9'] = 'Paste this REMOTE site\'s full HTTPS address and the token into the fields below.';
