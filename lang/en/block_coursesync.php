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
 * Language strings for block_coursesync.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activityfetched'] = 'Fetched {$a}.';
$string['conflictchangedupstream'] = 'This was copied here by an earlier sync and has since changed on the other site. The copy in this course has been left exactly as it is; compare the two and update it by hand if you want the change.';
$string['conflictlocalactivity'] = 'An activity already in this course carries this activity\'s identity, but Course Sync did not put it there. Nothing was changed or copied; check the existing activity before deciding what to do.';
$string['coursemapped'] = 'Mapped to {$a->fullname} ({$a->shortname}) on the other site.';
$string['coursesync:addinstance'] = 'Add a new Course Sync block';
$string['coursesync:sync'] = 'Trigger a course sync';
$string['erroractivitynotfound'] = 'That activity is no longer on the other site. It may have been deleted since the list was drawn up.';
$string['errorbadremoteurl'] = 'The other site sent an address that is not a usable web address, so the activity was not created.';
$string['errorbadrequest'] = 'The other site could not make sense of the request. This usually means the two sites are running different versions of Course Sync.';
$string['errorbadresponse'] = 'The other site answered, but not in the way Course Sync expected. It may be running a different version of the plugin.';
$string['errorbadtoken'] = 'The other site did not accept the security token. Generate a new token there and paste it in again.';
$string['errorblocked'] = 'This site is not allowed to make connections to that address. Ask an administrator to permit outgoing requests to that address and port.';
$string['errorcertificate'] = 'The other site\'s security certificate could not be verified, so the connection was stopped. Check that its certificate is valid and issued for the address you entered.';
$string['errorcoursenotfound'] = 'No course on the other site matches that id or shortname, or the sync account cannot see it. Check the value and that the account has the "Trigger a course sync" permission in that course.';
$string['errorcoursenotvisible'] = 'The sync account on the other site is not allowed to see that course. Give it the "View courses without participation" permission (moodle/course:view), or enrol it in the course.';
$string['errorcourserefempty'] = 'Enter the id or shortname of the course on the other site.';
$string['errorcreatefailed'] = 'The activity could not be created in this course. Nothing was left half-made.';
$string['errorfilecorrupt'] = 'A file did not arrive intact from the other site, so the activity was not created. Try the sync again.';
$string['errorfilenotallowed'] = 'That file is not part of the activity being synced, so it was not sent.';
$string['errorfilenotfound'] = 'A file the other site listed is no longer there. It may have been changed since the list was drawn up.';
$string['errorfiletoobig'] = 'A file is too large for Course Sync to copy.';
$string['errorfiletransfer'] = 'A file could not be copied from the other site, so the activity was not created.';
$string['errormaintenance'] = 'The other site is in maintenance mode at the moment. Try again once it is back.';
$string['errornopackage'] = 'The other site did not send the H5P package this activity is built from, so nothing was created. Check that the activity still has its file on that site.';
$string['errornopermission'] = 'The account the token belongs to is not allowed to use Course Sync on the other site. Give that account the "Trigger a course sync" permission there.';
$string['errornosyncpermission'] = 'You do not have permission to use this connection, so the course could not be checked against the other site.';
$string['errornotmapped'] = 'No course on the other site has been chosen yet. Finish the setup wizard first.';
$string['errornotmoodle'] = 'Nothing that looks like a Moodle site answered at that address. Check the remote site URL: it should be the address of the site\'s front page, with nothing after it.';
$string['errornotokenyet'] = 'Save the remote site address first, then use the setup wizard to add a token. The course can only be checked once there is a token to check it with.';
$string['errorpluginmissing'] = 'The other site answered, but it does not have Course Sync installed. Install this plugin there before connecting.';
$string['errorremoterefused'] = 'The other site refused the request. Check with its administrator that Course Sync is set up correctly.';
$string['errorserviceunavailable'] = 'The token was accepted, but the Course Sync service is not switched on for it. On the other site, enable the Course Sync external service and make sure the token is issued for it.';
$string['errorsitechanged'] = 'The remote site address is being changed in this save, so the stored token cannot confirm the course. Save the address first, re-run the setup wizard, then set the course.';
$string['errortokenempty'] = 'Paste the token generated on the other site.';
$string['errortokenmalformed'] = 'That does not look like a Moodle token. A token is 32 letters and numbers, with no spaces.';
$string['errortokenmissing'] = 'No token has been saved yet. Go back a step and paste the token from the other site.';
$string['errortokenunreadable'] = 'The saved token could not be read on this site. This usually means the site was restored from a backup without its encryption key. Paste the token again.';
$string['errorunreachable'] = 'The other site could not be reached. It may be switched off, or something between the two sites may be blocking the connection.';
$string['errorunsupportedtype'] = 'Course Sync cannot rebuild that type of activity yet.';
$string['errorurlbadscheme'] = 'The address must be a web address starting with https://. Other kinds of address are not accepted.';
$string['errorurlcredentials'] = 'Remove the username and password from the address. Access is handled by the token, not by the URL.';
$string['errorurlempty'] = 'Enter the address of the other Moodle site.';
$string['errorurlmalformed'] = 'That is not a valid web address. It should look like https://moodle.example.edu';
$string['errorurlnothttps'] = 'The address must start with https:// so that the token is never sent unencrypted.';
$string['errorurlprivate'] = 'That address is on a private or loopback network, which this site will not connect to. If the other site really is on your private network, an administrator can allow it by setting $CFG->block_coursesync_allowprivateurls in config.php.';
$string['errorurlunresolvable'] = 'That address could not be looked up, so it cannot be checked or reached. Check the spelling of the site name.';
$string['errorwronghandler'] = 'The other site sent a different type of activity than expected, so it was not created.';
$string['historyconflicts'] = 'Flagged for review';
$string['historycounts'] = '{$a->pulled} pulled, {$a->conflicts} flagged';
$string['historyempty'] = 'This block has not run a sync yet.';
$string['historylookedall'] = 'Looked at every activity in the other course.';
$string['historylookedsince'] = 'Looked at activities changed since {$a}.';
$string['historynothing'] = 'The other course had nothing to consider.';
$string['historyothers'] = 'Everything else considered';
$string['historypulled'] = 'Copied into this course';
$string['historystartedby'] = 'Started by {$a->user} on {$a->when}.';
$string['historystatusfailed'] = 'Failed';
$string['historystatusok'] = 'Completed';
$string['historystatusreview'] = 'Needs review';
$string['historytitle'] = 'Sync history';
$string['historyunknownuser'] = 'a user who no longer exists';
$string['historyview'] = 'View sync history';
$string['lastchecked'] = 'Last checked {$a}';
$string['lastcheckedlabel'] = 'Last checked';
$string['lastsyncat'] = 'Last synced {$a}';
$string['lastsynclabel'] = 'Last synced';
$string['lastsyncnever'] = 'Never synced';
$string['notconfigured'] = 'Course Sync — not yet configured';
$string['pluginname'] = 'Course Sync';
$string['previewchanges'] = 'See what has changed';
$string['previewcolcmid'] = 'Remote ID';
$string['previewcolidnumber'] = 'ID number';
$string['previewcolmodified'] = 'Modified';
$string['previewcolname'] = 'Activity';
$string['previewcoltype'] = 'Type';
$string['previewcountever'] = '{$a} activities found in the other course.';
$string['previewcountsince'] = '{$a->count} activities modified since {$a->since}.';
$string['previewnoneever'] = 'The other course has no activities yet.';
$string['previewnonesince'] = 'Nothing has changed in the other course since {$a}.';
$string['previewnotsynced'] = 'Nothing has been copied. This is a preview only; pulling activities starts in a later version, and until then the last synced time is deliberately left untouched.';
$string['previewrefresh'] = 'Check again';
$string['previewtitle'] = 'Changes on the other site';
$string['privacy:metadata'] = 'The Course Sync block stores the address of another Moodle site and a token used to reach it. It does not store any personal data.';
$string['privacy:metadata:run'] = 'A record of each time activities were pulled from the other site.';
$string['privacy:metadata:run:conflicts'] = 'The activities the run flagged for someone to look at.';
$string['privacy:metadata:run:pulled'] = 'The activities the run copied into the course.';
$string['privacy:metadata:run:status'] = 'Whether the run finished cleanly, needs review, or failed.';
$string['privacy:metadata:run:timefinished'] = 'When the run finished.';
$string['privacy:metadata:run:timestarted'] = 'When the run was started.';
$string['privacy:metadata:run:userid'] = 'The user who started the run.';
$string['privacy:path:runs'] = 'Course Sync runs';
$string['remotecourse'] = 'Remote course ID or shortname';
$string['remotecourse_help'] = 'Which course on the other site activities should be pulled from.

Enter either the numeric course id (from the course URL on that site) or its shortname. The value is checked against the other site when you save, so a typo is reported straight away.';
$string['remotestep1'] = 'Sign in to {$a} as an administrator. All of the remaining steps happen on that site, not this one.';
$string['remotestep2'] = 'Go to Site administration > Advanced features, tick "Enable web services", and save.';
$string['remotestep3'] = 'Go to Site administration > Server > Web services > Manage protocols and enable the REST protocol.';
$string['remotestep4'] = 'Go to Site administration > Server > Web services > External services. "Course Sync" is listed there because the plugin is installed. Edit it and tick "Enabled".';
$string['remotestep5'] = 'Still on the Course Sync service, choose "Authorised users" and add the account that will be used for syncing. That account needs three permissions at site level: "Trigger a course sync" (block/coursesync:sync), "Use REST protocol" (webservice/rest:use), and "View courses without participation" (moodle/course:view), which lets it read courses it is not enrolled in. Moodle warns you on this screen if the first two are missing.';
$string['remotestep6'] = 'Go to Site administration > Server > Web services > Manage tokens, create a token for that account and the Course Sync service, then copy it and paste it below.';
$string['remoteurl'] = 'Remote site URL';
$string['remoteurl_help'] = 'The address of the Moodle site that activities will be pulled from, for example https://moodle.example.edu

It must start with https://. Enter the address of the site\'s front page, with nothing after it.';
$string['remoteurlwizardhint'] = 'Use the setup wizard to enter the token and test the connection.';
$string['setupback'] = 'Back a step';
$string['setupchangetoken'] = 'Change the token';
$string['setupdocshint'] = 'These steps are also written down in the plugin\'s docs/REMOTE_SETUP.md, in case the screens on the other site do not look quite like this.';
$string['setupfinish'] = 'Return to the course';
$string['setupmanage'] = 'Manage the connection';
$string['setupnext'] = 'Next';
$string['setupsaveandtest'] = 'Save and test';
$string['setupsavecourse'] = 'Save the course';
$string['setupstart'] = 'Set up the connection';
$string['setupstep1'] = 'Remote site';
$string['setupstep1intro'] = 'Enter the address of the Moodle site that activities will be pulled from. You will need administrator access to that site for the next step.';
$string['setupstep2'] = 'Token';
$string['setupstep2intro'] = 'Course Sync cannot change settings on {$a} for you, so a few steps have to be done there by hand. Work through the list, then paste the token you end up with.';
$string['setupstep3'] = 'Test';
$string['setupstep4'] = 'Course';
$string['setupstep4intro'] = 'Choose the course on the other site that activities should be pulled from. It is checked against that site before it is saved.';
$string['setuptitle'] = 'Course Sync setup';
$string['statusconnected'] = 'Connected to {$a}';
$string['statuscourse'] = 'Pulling from {$a}';
$string['statusnocourse'] = 'No remote course chosen yet';
$string['statusproblem'] = 'The connection is not working';
$string['statusuntested'] = 'The connection has not been tested yet';
$string['syncassignnosubmissions'] = 'Its settings were copied. Student submissions, grades and feedback stay on the other site.';
$string['syncbacktocourse'] = 'Back to the course';
$string['syncchoicenoanswers'] = 'Its question and options were copied. The answers people gave stay on the other site.';
$string['syncchoosecaption'] = 'Activities in the other course, and whether each is already in this one';
$string['syncchoosecolumn'] = 'Copy';
$string['syncchooseheading'] = 'Choose what to copy';
$string['syncchooseintro'] = 'Everything in the other course is listed below. Only activities not already in this course can be copied, and those start ticked; untick anything you do not want. The rest are shown for reference and cannot be ticked.';
$string['syncchoosesubmit'] = 'Copy the ticked activities';
$string['synccollisions'] = '{$a->count} activities below already carry the identity of an activity on the other site, but Course Sync did not put them there: {$a->list}. Nothing will be changed or copied over them. Check them before syncing.';
$string['syncconfirm'] = 'This will copy new activities from {$a->course} into this course. Only these activity types are copied for now: {$a->types}.';
$string['syncconfirmnever'] = 'Nothing has been synced yet, so everything found will be copied.';
$string['syncconfirmsince'] = 'Only activities changed since {$a} will be considered.';
$string['syncconflicted'] = 'Flagged';
$string['synccreated'] = 'Created';
$string['syncdatacodetemplates'] = 'Its custom CSS and JavaScript were not copied. Those run as code on whichever site holds them, so they are not taken from another site. Copy them across by hand if you wrote them.';
$string['syncdatanoentries'] = 'Its fields and layout were copied. The entries people added stay on the other site.';
$string['syncdetail'] = 'Notes';
$string['syncfailed'] = 'Failed';
$string['syncfeedbacknoresponses'] = 'Its questions were copied. The responses people gave stay on the other site.';
$string['syncfilewarning'] = 'Its content refers to embedded files, which are not copied yet, so those links will not work.';
$string['syncfullrecheck'] = 'Check everything again';
$string['syncfullrecheckhint'] = 'Lists every activity in the other course, not only what has changed. Anything already here still will not be copied twice.';
$string['syncglossarynoentries'] = 'Its settings were copied. The entries people wrote stay on the other site.';
$string['synch5pnoattempts'] = 'Its content and settings were copied. What students did in it stays on the other site.';
$string['synclastsyncheld'] = 'The last synced time has been left where it was, because not everything could be copied. The activities that failed will be tried again next time.';
$string['synclastsyncmoved'] = 'The last synced time has been moved forward, so the next sync will only look at what changes from now on.';
$string['synclessonnoattempts'] = 'Its pages and the paths between them were copied. What students did in it stays on the other site.';
$string['synclessonnopassword'] = 'It was password protected on the other site. The password was not copied, so the copy is not protected. Set one here if you need it.';
$string['syncneedsreview'] = 'Some activities were flagged for you to look at. Nothing in this course was changed or overwritten.';
$string['syncnothingatall'] = 'There is nothing in the other course to copy.';
$string['syncnothingnew'] = 'Everything in the other course is already here. {$a} activities were checked and none of them are new.';
$string['syncnothingtodo'] = 'Nothing new to copy.';
$string['syncnow'] = 'Sync now';
$string['syncoutcome'] = 'Outcome';
$string['syncquiznoquestions'] = 'Its settings were copied, but not its questions: quiz questions live in a question bank that is particular to each site. Add the questions on this site.';
$string['syncscaledropped'] = 'Its grading used a marking scale that does not exist on this site, so the copy was created ungraded.';
$string['syncskipped'] = 'Skipped';
$string['syncskippeddeselected'] = 'You chose not to copy this one. It will be offered again next time.';
$string['syncskippedpresent'] = 'This is already in this course, so it was not offered and nothing was changed.';
$string['syncskippedtype'] = 'Course Sync cannot copy this type of activity yet.';
$string['syncstatuscollision'] = 'Needs review';
$string['syncstatuscolumn'] = 'Status';
$string['syncstatusnew'] = 'New';
$string['syncstatuspresent'] = 'Already synced';
$string['syncstatusunsupported'] = 'Not supported';
$string['syncsummary'] = '{$a->created} copied, {$a->conflicts} flagged, {$a->skipped} skipped, {$a->failed} failed.';
$string['synctitle'] = 'Sync activities';
$string['syncunsupportedhere'] = '{$a->count} activities cannot be copied because Course Sync does not handle their type: {$a->list}. Move those across by hand.';
$string['syncwikinopages'] = 'Its settings were copied. The pages people wrote in it stay on the other site.';
$string['syncworkshopnosubmissions'] = 'Its settings and assessment form were copied. Submissions, assessments and grades stay on the other site.';
$string['testconnection'] = 'Test the connection';
$string['teststatusok'] = 'Connected to {$a->sitename}, running Moodle {$a->release}.';
$string['teststatusuntested'] = 'The connection has not been tested yet. Choose "Test the connection" below.';
$string['token'] = 'Token';
$string['token_help'] = 'The web service token generated on the other Moodle site. It is stored encrypted and is never shown again once saved.';
$string['tokennotstored'] = 'No token stored';
$string['tokenstored'] = 'Stored, ending {$a}';
$string['wikifirstpagedefault'] = 'First page';
