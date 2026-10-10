@block @block_coursesync
Feature: Pulling teachers' marks and feedback from the other site
  In order to have the marks and comments teachers gave on the other site
  As a teacher
  I need to preview the marks, pull them, and see which ones were kept as they were

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
      | marker1  | Mark      | Marker   | marker1@example.com  |
      | student1 | Sam       | Student  | student1@example.com |
      | student2 | Sue       | Student  | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | DEST   | editingteacher |
      | marker1  | DEST   | teacher        |
      | student1 | DEST   | student        |
      | student2 | DEST   | student        |
      | student1 | SRC    | student        |
      | student2 | SRC    | student        |
    And the following "activities" exist:
      | activity | course | name      | intro          | section | submissiondrafts | assignsubmission_onlinetext_enabled | assignfeedback_comments_enabled |
      | assign   | SRC    | Essay one | Write an essay | 1       | 0                | 1                                   | 1                               |
    And the following "permission overrides" exist:
      | capability                      | permission | role           | contextlevel | reference |
      | block/coursesync:configure      | Allow      | editingteacher | Course       | DEST      |
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
    And I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    And I should see "Essay one"
    And I log out

  Scenario: A teacher previews the marks, pulls them, and marks given here are kept
    Given Course Sync marks sharing is switched on
    And Course Sync marks pulling is switched on
    And "student1" was marked 80 with "Well argued" in "Essay one" of "SRC"
    And "student2" was marked 70 with "Needs sources" in "Essay one" of "SRC"
    And "student2" was marked 55 with "My own view" in "Essay one" of "DEST"
    When I am on the "Destination Course" course page logged in as "teacher1"
    And I follow "Pull marks and feedback"
    Then I should see "Marks to pull"
    And I should see "Sam Student" in the "coursesync-marks-written" "table"
    And I should see "Kept as it was"
    And I should see "Sue Student" in the "coursesync-marks-conflicts" "table"
    And I should see "already has a different mark or comment here" in the "Sue Student" "table_row"

    When I press "Pull the marks (1)"
    Then I should see "Marks pulled: 1 (1 new, 0 updated)."
    And I should see "Some students already had a different mark or comment here"

    # Pulling again changes nothing: Sam's mark is already here.
    When I am on the "DEST" "block_coursesync > Marks" page
    Then I should see "Nothing would change"
    And "Pull the marks" "button" should not exist

    # The grade is the assignment's own: the grader screen has it.
    When I am on the "Destination Course" course page
    And I follow "Essay one"
    And I follow "Submissions"
    Then I should see "80.00" in the "Sam Student" "table_row"

    # The pull is in the history, as counts only.
    When I am on the "DEST" "block_coursesync > History" page
    Then I should see "Marks pulled: 1, kept as they were: 1, skipped: 0"
    And I should not see "Well argued"

  Scenario: The button is only offered where marks pulling is switched on and allowed
    Given Course Sync marks sharing is switched on
    When I am on the "Destination Course" course page logged in as "teacher1"
    Then I should not see "Pull marks and feedback" in the "Course Sync" "block"
    And I log out

    Given Course Sync marks pulling is switched on
    When I am on the "Destination Course" course page logged in as "teacher1"
    Then I should see "Pull marks and feedback" in the "Course Sync" "block"
    And I log out

    # A non-editing teacher may not pull marks.
    When I am on the "Destination Course" course page logged in as "marker1"
    Then I should not see "Pull marks and feedback"

  Scenario: Sharing grades and submissions does not share marks
    Given Course Sync grade sharing is switched on
    And Course Sync submission sharing is switched on
    And Course Sync marks pulling is switched on
    When I am on the "DEST" "block_coursesync > Marks" page logged in as "teacher1"
    Then I should see "The other site does not share assignment marks"
