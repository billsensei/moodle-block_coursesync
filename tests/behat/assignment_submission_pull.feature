@block @block_coursesync
Feature: Pulling students' assignment submissions from the other site
  In order to have the work students handed in on the other site
  As a teacher
  I need to preview the submissions, pull them, and see which ones were kept as they were

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
      | activity | course | name      | intro          | section | submissiondrafts | assignsubmission_onlinetext_enabled |
      | assign   | SRC    | Essay one | Write an essay | 1       | 0                | 1                                   |
    # Handed in on the source before anything is copied: core's submission
    # generator finds an assignment by name, which is ambiguous once there are two.
    And the following "mod_assign > submissions" exist:
      | assign    | user     | onlinetext          |
      | Essay one | student1 | Sam's finished essay |
      | Essay one | student2 | Sue's finished essay |
    And the following "permission overrides" exist:
      | capability                      | permission | role           | contextlevel | reference |
      | block/coursesync:configure      | Allow      | editingteacher | Course       | DEST      |
      # No role may edit another student's submission until an administrator
      # says so, and a pull writes submissions on the students' behalf.
      | mod/assign:editothersubmission | Allow      | editingteacher | Course       | DEST      |
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

  Scenario: A teacher previews the submissions, pulls them, and work already here is kept
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    And "student2" has handed in "Sue's own words" to the synced "Essay one" in course "DEST"
    When I am on the "Destination Course" course page logged in as "teacher1"
    And I follow "Pull submissions"
    Then I should see "Submissions to pull"
    And I should see "Sam Student" in the "coursesync-submissions-written" "table"
    And I should see "Kept as it was"
    And I should see "Sue Student" in the "coursesync-submissions-conflicts" "table"
    And I should see "already has different work here" in the "Sue Student" "table_row"

    When I press "Pull the submissions (1)"
    Then I should see "Submissions pulled: 1 (1 new, 0 updated)."
    And I should see "Some students already had work here that differs"
    And I should see "Sam Student" in the "coursesync-submissions-written" "table"

    # Pulling again changes nothing: Sam's work is already here.
    When I am on the "DEST" "block_coursesync > Submissions" page
    Then I should see "Nothing would change"
    And "Pull the submissions" "button" should not exist

    # The pull is in the history, as counts only.
    When I am on the "DEST" "block_coursesync > History" page
    Then I should see "Submissions pulled: 1, kept as they were: 1, skipped: 0"
    And I should not see "Sam Student"
    And I should not see "finished essay"

  Scenario: The button is only offered where submission pulling is switched on and allowed
    Given Course Sync submission sharing is switched on
    When I am on the "Destination Course" course page logged in as "teacher1"
    Then I should not see "Pull submissions" in the "Course Sync" "block"
    And I log out

    Given Course Sync submission pulling is switched on
    When I am on the "Destination Course" course page logged in as "teacher1"
    Then I should see "Pull submissions" in the "Course Sync" "block"
    And I log out

    # A non-editing teacher may not pull submissions.
    When I am on the "Destination Course" course page logged in as "marker1"
    Then I should not see "Pull submissions"

  Scenario: Switching on grade sharing alone does not share submissions
    Given Course Sync grade sharing is switched on
    And Course Sync submission pulling is switched on
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    Then I should see "The other site does not share assignment submissions"
