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
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['available:changed'] = 'Changed';
$string['available:checking'] = 'Checking the remote course...';
$string['available:heading'] = 'Ready to sync ({$a})';
$string['available:lastchecked'] = 'Checked {$a}';
$string['available:new'] = 'New';
$string['available:none'] = 'Nothing new on the remote course.';
$string['available:notchecked'] = 'Use Check now to see what this course could pull in.';
$string['conflicts:cannotpull'] = 'The remote version cannot be taken automatically here, because the activity it would replace was not created by this block. Rename or remove the local activity first, then run a sync.';
$string['conflicts:defer'] = 'Decide later';
$string['conflicts:intro'] = 'These activities changed on the remote site, but this course’s copies are not in the state this block left them in. Nothing has been overwritten. Choose what should happen to each.';
$string['conflicts:keeplocal'] = 'Keep this course’s version';
$string['conflicts:lastpulled'] = 'Last pulled:';
$string['conflicts:localchanged'] = 'This course’s copy has been edited since it was pulled.';
$string['conflicts:localchangedon'] = 'This course’s copy was edited on {$a}.';
$string['conflicts:localdeleted'] = 'This course’s copy has been deleted.';
$string['conflicts:neverpulled'] = 'never';
$string['conflicts:none'] = 'Nothing needs review. Every activity is either in step with the remote course or has been dealt with.';
$string['conflicts:pullremote'] = 'Take the remote version';
$string['conflicts:reason:bothchanged'] = 'The activity changed on the remote site, and this course’s copy was edited independently.';
$string['conflicts:reason:namecollision'] = 'An activity with this name or ID number already exists in this course and was not created by this block, so pulling would shadow someone else’s work.';
$string['conflicts:reason:unknown'] = 'This activity needs review before it can be synced again.';
$string['conflicts:title'] = 'Conflicts needing review';
$string['conflicts:unnamed'] = 'Activity on the remote site';
$string['connectionheader'] = 'Remote site connection';
$string['connectionok'] = 'Connected to {$a->sitename}, running Moodle {$a->release}.';
$string['connectionokmissing'] = 'Connected to {$a->sitename}, running Moodle {$a->release}, but its service is missing: {$a->missing}. Ask the remote site administrator to add it.';
$string['courseok'] = 'Found "{$a->fullname}" (shortname {$a->shortname}, id {$a->id}) on the remote site.';
$string['coursesync:addinstance'] = 'Add a new Course sync block';
$string['coursesync:trigger'] = 'Trigger a course sync';
$string['coursesync:viewhistory'] = 'View course sync history';
$string['error:accessdenied'] = 'The remote site accepted the token but refused the request. The service account probably does not have access to that function or course.';
$string['error:activitynotvisible'] = 'That activity is not visible to the account the token belongs to.';
$string['error:backupfailed'] = 'The activity could not be backed up.';
$string['error:backupnotsupported'] = 'Activities of type {$a} cannot be backed up, so they cannot be synced.';
$string['error:badhttpstatus'] = 'The remote site did not return a web service response. Check that the address points at the site root.';
$string['error:conflictgone'] = 'That activity is no longer in conflict. Someone may have dealt with it already.';
$string['error:connectionfailed'] = 'Could not reach the remote site.';
$string['error:coursenotfound'] = 'No course on the remote site matches that ID or shortname.';
$string['error:coursenotunique'] = 'More than one course on the remote site matches that value.';
$string['error:downloadfailed'] = 'The activity backup could not be downloaded from the remote site.';
$string['error:downloadnotbackup'] = 'The remote site returned something other than a backup file. The token may no longer be valid.';
$string['error:extractfailed'] = 'The downloaded backup could not be unpacked.';
$string['error:functionnotinservice'] = 'The remote service does not include a function this block needs.';
$string['error:invalidresponse'] = 'The remote site returned a response this block could not read.';
$string['error:invalidtoken'] = 'The remote site rejected the token.';
$string['error:localgone'] = 'This course’s copy of that activity no longer exists, so there is nothing to keep.';
$string['error:missingcourse'] = 'Enter the ID or shortname of the course on the remote site.';
$string['error:notconfigured'] = 'This block has no verified remote connection yet, so there is nothing to sync from.';
$string['error:notinacourse'] = 'This block is not in a course, so there is nowhere to sync activities into.';
$string['error:notoken'] = 'No web service token has been stored for this block yet.';
$string['error:remotegone'] = 'That activity is no longer on the remote site.';
$string['error:remoterejected'] = 'The remote site rejected the request.';
$string['error:restorenoactivity'] = 'The backup restored without producing an activity.';
$string['error:restoreprecheck'] = 'The backup cannot be restored into this course: {$a}';
$string['error:tokenrequired'] = 'Enter the web service token issued by the remote site.';
$string['error:unexpected'] = 'Something went wrong while running that check.';
$string['error:unknownaction'] = 'That is not something this block knows how to do.';
$string['error:urlempty'] = 'Enter the address of the remote Moodle site.';
$string['error:urlhascredentials'] = 'The address must not contain a username or password.';
$string['error:urlhasquery'] = 'Enter the site address only, with no query string or anchor.';
$string['error:urlmalformed'] = 'That is not a valid site address.';
$string['error:urlnothttps'] = 'The remote site address must start with https://.';
$string['error:urlprivateaddress'] = 'That address resolves to a private, loopback or link-local address, which this block will not send requests to.';
$string['error:urlunresolvable'] = 'The host name in that address could not be resolved.';
$string['error:wsdisabled'] = 'Web services or the REST protocol are not enabled on the remote site.';
$string['history:empty'] = 'This block has not run a sync yet.';
$string['history:norelatedactivity'] = 'Activity on the remote site';
$string['history:title'] = 'Sync history';
$string['lastvalidated'] = 'Last validated';
$string['lastvalidatedvalue'] = '{$a->time} - {$a->coursename} on {$a->sitename}.';
$string['notvalidatedyet'] = 'This connection has not been verified yet.';
$string['outcome:conflict'] = 'Needs review';
$string['outcome:deferred'] = 'Left for later';
$string['outcome:error'] = 'Failed';
$string['outcome:keptlocal'] = 'Kept local version';
$string['outcome:new'] = 'Pulled';
$string['outcome:skipped'] = 'Skipped';
$string['outcome:unchanged'] = 'Unchanged';
$string['outcome:updated'] = 'Updated';
$string['pluginname'] = 'Course sync';
$string['privacy:metadata:log'] = 'A record of each sync run, so a teacher can see where the course content came from and who brought it in.';
$string['privacy:metadata:log:name'] = 'The name of the activity that was processed.';
$string['privacy:metadata:log:outcome'] = 'What happened to that activity: pulled, updated, left alone, in conflict, or failed.';
$string['privacy:metadata:log:timecreated'] = 'When the activity was processed.';
$string['privacy:metadata:log:userid'] = 'The user who triggered the sync run.';
$string['privacy:path:history'] = 'Course sync history';
$string['remotecourse'] = 'Remote course ID or shortname';
$string['remotecourse_help'] = 'The numeric ID or the shortname of the course on the remote site. The numeric ID appears in that course\'s URL, as the id parameter. Use "Validate course" to confirm it matches exactly one course before saving.';
$string['remoteurl'] = 'Remote site URL';
$string['remoteurl_help'] = 'The address of the Moodle site to pull activities from, for example https://moodle.example.edu. It must be an https address, and it must resolve to a public address: private, loopback and link-local addresses are refused, because this server makes requests to whatever is entered here.';
$string['resolve:deferred'] = 'Left for later. It will be reported again on the next sync.';
$string['resolve:failed'] = 'That activity could not be pulled. The sync history has the detail.';
$string['resolve:keptlocal'] = 'This course’s version has been kept, and the remote changes will no longer be reported.';
$string['resolve:pulledremote'] = 'The remote version has been pulled in, replacing this course’s copy.';
$string['savedandvalidated'] = 'Connection saved and verified: {$a->coursename} on {$a->sitename}.';
$string['savedbutnotvalidated'] = 'The settings were saved, but the connection could not be verified: {$a}';
$string['setup:authorise'] = 'Authorise the account on the service';
$string['setup:authorise:line1'] = 'Open Site administration > Server > Web services > External services and follow the "Authorised users" link on Course Sync Provider.';
$string['setup:authorise:line2'] = 'Add the service account there. The service is restricted to authorised users, so nobody else can use it even with a token.';
$string['setup:capabilities'] = 'Give the account only the access it needs';
$string['setup:capabilities:line1'] = 'At system level it needs webservice/rest:use, or it cannot call the REST protocol at all.';
$string['setup:capabilities:line2'] = 'On the single course being shared it needs moodle/course:view, moodle/backup:backupactivity and moodle/backup:downloadfile.';
$string['setup:capabilities:line3'] = 'Assign those through a role on that one course rather than a site-wide role, so the account can reach nothing else.';
$string['setup:createtoken'] = 'Create the token and hand it over';
$string['setup:createtoken:line1'] = 'Go to Site administration > Server > Web services > Manage tokens and create a token for that account against the Course Sync Provider service.';
$string['setup:createtoken:line2'] = 'Send the token to whoever configures this block over a channel you would trust with a password: anyone holding it can act as that account.';
$string['setup:enablews'] = 'Enable web services and the REST protocol';
$string['setup:enablews:line1'] = 'Tick "Enable web services" in Site administration > General > Advanced features.';
$string['setup:enablews:line2'] = 'Enable the REST protocol in Site administration > Server > Web services > Manage protocols.';
$string['setup:installplugin'] = 'Install this block on the remote site too';
$string['setup:installplugin:line1'] = 'Installing Course sync creates the "Course Sync Provider" external service automatically, with exactly the four functions a pull needs and file download enabled.';
$string['setup:installplugin:line2'] = 'Nothing has to be added to that service by hand, and nothing should be: the plugin keeps its function list in step with what it actually calls.';
$string['setup:intro'] = 'Send these steps to the administrator of the Moodle site you want to pull activities from. This block cannot set any of it up for you: Moodle deliberately exposes no web service function for creating external services or tokens, since that would be a way to grant yourself more access than you were given.';
$string['setup:scopenote'] = 'That service lets the holder list the activities in the shared course and take a backup of one at a time. It grants no write access to the remote site.';
$string['setup:serviceaccount'] = 'Create a dedicated service account';
$string['setup:serviceaccount:line1'] = 'Create an ordinary user account for this integration, for example coursesync.service, rather than using an administrator account.';
$string['setup:serviceaccount:line2'] = 'It never signs in interactively; it exists so the access below can be scoped to it and withdrawn in one place.';
$string['setupheader'] = 'Setting up the remote site';
$string['status:alreadyrunning'] = 'A sync is already running for this block.';
$string['status:cancel'] = 'Cancel';
$string['status:checkcancelled'] = 'That list has been cleared. Nothing was synced.';
$string['status:checknow'] = 'Check now';
$string['status:conflictspending'] = 'Activities needing review: {$a}';
$string['status:finishsetup'] = 'Finish setting this block up before syncing: use Configure on the block to point it at a course on another site.';
$string['status:from'] = 'Pulling from';
$string['status:lastrun'] = 'Last sync:';
$string['status:neverrun'] = 'Not synced yet.';
$string['status:nothingselected'] = 'No activities were ticked, so nothing was synced.';
$string['status:notconfigured'] = 'This block has not been connected to another site yet.';
$string['status:reviewconflicts'] = 'Review conflicts ({$a})';
$string['status:running'] = 'Sync in progress…';
$string['status:summary'] = '{$a->synced} synced, {$a->conflicts} needing review';
$string['status:selectall'] = 'Select all';
$string['status:selectnone'] = 'Select none';
$string['status:syncnow'] = 'Sync now';
$string['status:syncstarted'] = 'Sync started. It runs in the background; this page will update when it finishes.';
$string['status:viewhistory'] = 'View history';
$string['task:cleanupbackups'] = 'Remove uncollected Course sync activity backups';
$string['task:synccourse'] = 'Pull activities for a Course sync block';
$string['testconnection'] = 'Test connection';
$string['testingconnection'] = 'Testing the connection...';
$string['token'] = 'Web service token';
$string['token_help'] = 'The token created for this block on the remote site. It is stored encrypted, and is never sent back to your browser, so this field stays empty once a token has been saved. Leave it empty to keep the stored token, or type a new one to replace it.';
$string['tokennotstored'] = 'No token is stored for this block yet.';
$string['tokenstored'] = 'A token is stored for this block. Leave the field above empty to keep it, or type a new one to replace it.';
$string['validatecourse'] = 'Validate course';
$string['validatingcourse'] = 'Looking up the course...';
