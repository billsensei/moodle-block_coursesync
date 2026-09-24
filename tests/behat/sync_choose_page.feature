@block @block_coursesync
Feature: The Choose what to copy page groups activities by whether they can be copied
  In order to see at a glance what needs a decision and what does not
  As a teacher
  I need activities not yet in my course grouped first and pre-ticked, and
  what is already here grouped after, never pre-ticked but able to be copied again

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    And the following "activities" exist:
      | activity | course | name               | intro                  | section | idnumber |
      | page     | SRC    | Existing week page | Copied last time       | 1       | SRCWEEK  |
      | page     | SRC    | Brand new page     | Not copied yet         | 1       |          |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | DEST   | editingteacher |
    And the following "permission overrides" exist:
      | capability                 | permission | role           | contextlevel | reference |
      | block/coursesync:configure | Allow      | editingteacher | Course       | DEST      |
    And the following "blocks" exist:
      | blockname  | contextlevel | reference | pagetypepattern | defaultregion |
      | coursesync | Course       | DEST      | course-view-*   | side-pre      |
    And the Course Sync block in course "DEST" points at this site
    And I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

    # Copy just the one page now, so the next visit to this page has a real
    # "already here" activity to group separately from the still-new one.
    When I am on the "DEST" "block_coursesync > Sync" page
    And I set the field "Brand new page" to ""
    And I press "Copy the ticked activities"
    Then I should see "Existing week page"

  @javascript
  Scenario: New activities are grouped first and pre-ticked, already-here activities after and unticked
    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should see "Ready to copy"
    And I should see "Already on this course"
    And "Ready to copy" "text" should appear before "Already on this course" "text"

    # The still-new page is ticked, in the ready-to-copy group.
    And the "Brand new page" "checkbox" should be enabled
    And the field "Brand new page" matches value "1"

    # The one already copied is listed after it, and can be ticked to copy
    # it again, but does not start ticked.
    And "Existing week page" "table_row" should exist
    And I should see "Already synced - if ticked, the copy here is replaced" in the "Existing week page" "table_row"
    And the "Existing week page" "checkbox" should be enabled
    And the field "Existing week page" matches value ""

    # Select all reaches only what is new or changed, never what is here.
    When I press "Select none"
    And I press "Select all"
    Then the field "Brand new page" matches value "1"
    And the field "Existing week page" matches value ""

    # Leaving types nothing here handles off the page entirely is pinned by
    # syncer_selection_test: standard Moodle no longer has such a type to
    # use here, only third-party modules do.

  Scenario: An activity changed on the source since it was copied can be ticked to replace the copy
    # Edited on the source after the Background's run copied it.
    Given the Course Sync runs in course "DEST" happened a minute ago
    And I am on the "SRCWEEK" "page activity editing" page logged in as "admin"
    And I set the field "Page content" to "Rewritten on the source"
    And I press "Save and return to course"

    When I am on the "DEST" "block_coursesync > Sync" page logged in as "teacher1"
    Then I should see "Changed since it was copied"
    And I should see "Changed - the copy here will be replaced" in the "Existing week page" "table_row"
    # Offered, but never pre-ticked: it replaces something already here.
    And the "Existing week page" "checkbox" should be enabled
    And the field "Existing week page" matches value ""

    When I set the field "Existing week page" to "1"
    And I set the field "Brand new page" to ""
    And I press "Copy the ticked activities"
    Then I should see "Updated" in the "Existing week page" "table_row"
    And I should see "The copy here was replaced with the updated version"

    # Now the copy is up to date, it is back among what is already here.
    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should not see "Changed since it was copied"
    And I should see "Already synced" in the "Existing week page" "table_row"

  Scenario: An unchanged copy nobody has used can be ticked to replace it with a fresh copy
    When I am on the "DEST" "block_coursesync > Sync" page
    And I set the field "Existing week page" to "1"
    And I set the field "Brand new page" to ""
    And I press "Copy the ticked activities"
    Then I should see "Updated" in the "Existing week page" "table_row"
    And I should see "Nobody had work or grades in the copy here, so it was replaced"
    When I am on "Destination Course" course homepage
    Then I should see "Existing week page"
    And I should not see "(copy)"

  Scenario: An unchanged copy with a student's work in it is copied again beside it as a copy
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | DEST   | student |
    And "student1" has completed the synced "Existing week page" in course "DEST"

    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should see "a separate copy is added, because people have work or grades in the copy here" in the "Existing week page" "table_row"
    When I set the field "Existing week page" to "1"
    And I set the field "Brand new page" to ""
    And I press "Copy the ticked activities"
    Then I should see "Existing week page (copy)"
    And I should see "People already had work or grades in the copy here"

    # Both are in the course: the one with the student's work, untouched,
    # and the fresh copy after it.
    When I am on "Destination Course" course homepage
    Then I should see "Existing week page (copy)"
    And "Existing week page" "text" should appear before "Existing week page (copy)" "text"
