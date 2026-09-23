@block @block_coursesync
Feature: The Choose what to copy page groups activities by whether they can be copied
  In order to see at a glance what needs a decision and what does not
  As a teacher
  I need activities not yet in my course grouped first and pre-ticked, what
  is already accounted for grouped after and never ticked, and anything this
  plugin cannot copy left off the page entirely

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
      | lti      | SRC    | An external tool   | A type nothing handles | 1       |          |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | DEST   | editingteacher |
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
  Scenario: New activities are grouped first and pre-ticked, already-here activities after and unticked, unsupported types not shown at all
    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should see "Ready to copy"
    And I should see "Already on this course"
    And "Ready to copy" "text" should appear before "Already on this course" "text"

    # The still-new page is ticked, in the ready-to-copy group.
    And the "Brand new page" "checkbox" should be enabled
    And the field "Brand new page" matches value "1"

    # The one already copied is listed for reference, unticked and disabled.
    And "Existing week page" "table_row" should exist
    And the "Existing week page" "checkbox" should be disabled

    # A type nothing here handles is not on this page anywhere - not in
    # either group, not named in a footnote.
    And I should not see "An external tool"

  Scenario: An activity changed on the source since it was copied can be ticked to replace the copy
    # Edited on the source after the Background's run copied it.
    Given I am on the "SRCWEEK" "page activity editing" page logged in as "admin"
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
    And the "Existing week page" "checkbox" should be disabled
