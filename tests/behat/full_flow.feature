@block @block_coursesync
Feature: The whole teacher-facing Course Sync flow
  In order to bring activities across from another Moodle site
  As a teacher
  I need to connect the block, map a course, sync, and see what happened

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname          | shortname | category | numsections |
      | Destination Course | DEST     | 0        | 3           |
      | Source Course      | SRC      | 0        | 3           |
    And the following "activities" exist:
      | activity | course | name              | intro                  | section |
      | page     | SRC    | Week 1 Notes      | Reading for week one   | 1       |
      | label    | SRC    | Unit introduction | Everything below counts | 1      |
      | url      | SRC    | Reference link    | Further reading        | 2       |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | DEST   | editingteacher |

  @javascript
  Scenario: A teacher adds the block to their course
    Given I am on the "Destination Course" course page logged in as "teacher1"
    And I turn editing mode on
    When I add the "Course Sync" block
    Then I should see "Course Sync — not yet configured" in the "Course Sync" "block"
    And I should see "Set up the connection" in the "Course Sync" "block"

  Scenario: A teacher connects the block, syncs a course, and reads the history
    # The block is placed by a generator here rather than through the interface,
    # because adding a block needs JavaScript and the rest of this scenario does
    # not. Adding it by hand is covered by the scenario above.
    Given the following "blocks" exist:
      | blockname  | contextlevel | reference | pagetypepattern | defaultregion |
      | coursesync | Course       | DEST      | course-view-*   | side-pre      |
    And the Course Sync block in course "DEST" points at this site
    And I am on the "Destination Course" course page logged in as "teacher1"
    When I am on the "DEST" "block_coursesync > Setup" page
    Then I should see "Enable web services"
    And I should see "Manage tokens"

    # Paste the token and test the connection for real.
    When I enter the Course Sync token
    And I press "Save and test"
    Then I should see "Connected to"

    # Map the course on the other site, which is the last wizard step.
    When I follow "Next"
    Then I should see "Course"
    When I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"
    Then I should see "Mapped to Source Course (SRC)"

    # Sync, and see what it did.
    When I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    Then I should see "copied"
    And I should see "Week 1 Notes"
    And I should see "Unit introduction"
    And I should see "Reference link"

    # The activities really are in the destination course now.
    When I am on "Destination Course" course homepage
    Then I should see "Week 1 Notes"
    And I should see "Reference link"

    # And the run is written down.
    When I am on the "DEST" "block_coursesync > History" page
    Then I should see "Sync history"
    And I should see "Copied into this course"
    And I should see "Week 1 Notes"
    And I should see "Started by Tina Teacher"

  Scenario: Running the same sync twice does not copy anything a second time
    Given the following "blocks" exist:
      | blockname  | contextlevel | reference | pagetypepattern | defaultregion |
      | coursesync | Course       | DEST      | course-view-*   | side-pre      |
    And the Course Sync block in course "DEST" points at this site
    And I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"
    And I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    Then I should see "copied"

    # A second pass over the same ground has nothing left to offer, because
    # what is already here is not listed.
    When I am on the "DEST" "block_coursesync > Sync" page
    And I follow "Check everything again"
    Then I should see "Everything in the other course is already here"
    And I should not see "Copy the ticked activities"
