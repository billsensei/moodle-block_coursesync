@block @block_coursesync
Feature: Course Sync full teacher-facing flow
  In order to pull newly-added activities from another course into my own
  As a teacher
  I need to add a Course Sync block, connect it to a remote course, run a sync, and review the results

  # This plugin syncs between two DIFFERENT Moodle sites (see Phase 2's
  # REMOTE_SETUP.md - a real token wizard means logging into a second,
  # separate site's admin UI). A single Behat scenario only ever drives one
  # site, so this points the block at the SAME site running the test - the
  # "Source Course" and "Destination Course" below are two different
  # courses on that one site, and "a Course Sync token ... exists for user"
  # (see behat_block_coursesync.php) stands in for what a remote admin
  # would hand a teacher after completing the wizard by hand. Everything
  # after that - adding the block, filling in its settings, clicking Sync
  # now, reading the results and history - is the real teacher-facing UI,
  # exercised exactly as a teacher would use it.
  Background:
    Given the following "courses" exist:
      | fullname            | shortname | category |
      | Source Course       | SOURCE1   | 0        |
      | Destination Course  | DEST1     | 0        |
    And the following "activities" exist:
      | activity | course  | name               | intro          |
      | page     | SOURCE1 | Announcement page  | Page intro     |
    And a Course Sync token "behattesttoken1234567890abcdef1" exists for user "admin"
    # Core's own \curl class blocks loopback/private addresses by default
    # (independent of this plugin's own url_safety check, which only
    # decides whether to even attempt a connection) - this test site's own
    # address is exactly such an address, so that has to be relaxed too,
    # the same way this project's real destination test server needed it
    # relaxed for the exact same reason (see Phase 2 notes).
    # Web services are off, and no protocol is enabled, on a fresh site by
    # default - REMOTE_SETUP.md's wizard steps 2-3 do this by hand; this is
    # that same precondition, met directly.
    And the following config values are set as admin:
      | curlsecurityblockedhosts |      |
      | enablewebservices         | 1    |
      | webserviceprotocols       | rest |

  @javascript
  Scenario: Teacher connects a block, syncs a course, and reviews the results and history
    Given I log in as "admin"
    And I am on "Destination Course" course homepage with editing mode on
    And I add the "Course Sync" block

    # Complete the token wizard: connect to the remote (this same) site.
    When I configure the "Course Sync" block
    And I set the field "Remote site URL" to this site's own address
    And I set the field "This is a development/testing connection (allow HTTP)" to "1"
    And I set the field "Token" to "behattesttoken1234567890abcdef1"
    And I set the field "Remote course ID or shortname" to "SOURCE1"
    And I click on "Save changes" "button"

    # The block shows the connection worked and the course mapped.
    Then I should see "Connected to" in the "Course Sync" "block"
    And I should see "Mapped to" in the "Course Sync" "block"
    And I should see "Announcement page" in the "Course Sync" "block"

    # Trigger a real sync.
    When I click on "Sync now" "link" in the "Course Sync" "block"
    Then I should see "1 created"

    # The activity was actually pulled into this (destination) course.
    And I am on "Destination Course" course homepage
    And I should see "Announcement page"

    # The sync history records what happened.
    When I am on "Destination Course" course homepage
    And I click on "View sync history" "link" in the "Course Sync" "block"
    Then I should see "Completed"
    And I should see "1 created, 0 conflicts, 0 failed"
    And I should see "Announcement page (page)"
