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
      | assign   | SRC    | Essay two | Write another  | 1       | 0                | 1                                   |
    # Handed in on the source before anything is copied: core's submission
    # generator finds an assignment by name, which is ambiguous once there are two.
    And the following "mod_assign > submissions" exist:
      | assign    | user     | onlinetext          |
      | Essay one | student1 | Sam's finished essay |
      | Essay one | student2 | Sue's finished essay |
      | Essay two | student1 | Sam's second essay   |
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
    And I follow "Pull Submissions"
    Then I should see "2 to pull" in the "#coursesync-student-section-1" "css_element"
    And I should see "Sam Student" in the "#coursesync-student-section-1" "css_element"
    And I should see "Kept as they were"
    And I should see "Sue Student" in the "#coursesync-student-section-2" "css_element"
    And I should see "1 kept" in the "#coursesync-student-section-2" "css_element"
    And I should see "already has different work here" in the "#coursesync-student-2" "css_element"

    When I press "Pull the ticked work"
    Then I should see "Submissions pulled: 2 (2 new, 0 updated)."
    And I should see "Some students already had work here that differs"
    And I should see "2 pulled" in the "#coursesync-student-section-1" "css_element"

    # Pulling again changes nothing: Sam's work is already here.
    When I am on the "DEST" "block_coursesync > Submissions" page
    Then I should see "Nothing would change"
    And "Pull the ticked work" "button" should not exist

    # The pull is in the history, as counts only.
    When I am on the "DEST" "block_coursesync > History" page
    Then I should see "Submissions pulled: 2, kept as they were: 1, skipped: 0"
    And I should not see "Sam Student"
    And I should not see "finished essay"

  Scenario: A teacher leaves one student's work unticked and it is not pulled
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    Then the field "Pull Essay one for Sam Student" matches value "1"
    And the field "Pull Essay two for Sam Student" matches value "1"
    And the field "Pull Essay one for Sue Student" matches value "1"

    When I set the field "Pull Essay two for Sam Student" to ""
    And I press "Pull the ticked work"
    Then I should see "Submissions pulled: 2 (2 new, 0 updated)."
    And I should see "1 skipped" in the "#coursesync-student-section-1" "css_element"
    And I should see "You left this unticked" in the "#coursesync-student-1" "css_element"

    # Nothing was remembered about Sam's second essay, so it is offered again.
    When I am on the "DEST" "block_coursesync > Submissions" page
    Then the field "Pull Essay two for Sam Student" matches value "1"
    And "Pull Essay one for Sam Student" "field" should not exist

    When I set the field "Pull Essay two for Sam Student" to ""
    And I press "Pull the ticked work"
    Then I should see "Nothing was ticked, so nothing was pulled."
    And I should not see "Submissions pulled"

  @javascript
  Scenario: The tick boxes can be switched for everything or for one student
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    And I set the field "Select all for Sue Student" to ""
    Then the field "Pull Essay one for Sue Student" matches value ""
    And the field "Pull Essay one for Sam Student" matches value "1"

    When I set the field "Select all / none" to ""
    Then the field "Pull Essay one for Sam Student" matches value ""
    And the field "Select all for Sam Student" matches value ""

    When I set the field "Select all / none" to "1"
    Then the field "Pull Essay one for Sam Student" matches value "1"
    And the field "Pull Essay one for Sue Student" matches value "1"

  Scenario: The button is only offered where submission pulling is switched on and allowed
    Given Course Sync submission sharing is switched on
    When I am on the "Destination Course" course page logged in as "teacher1"
    Then I should not see "Pull Submissions" in the "Course Sync" "block"
    And I log out

    Given Course Sync submission pulling is switched on
    When I am on the "Destination Course" course page logged in as "teacher1"
    Then I should see "Pull Submissions" in the "Course Sync" "block"
    And I log out

    # A non-editing teacher may not pull submissions.
    When I am on the "Destination Course" course page logged in as "marker1"
    Then I should not see "Pull Submissions"

  Scenario: Switching on grade sharing alone does not share submissions
    Given Course Sync grade sharing is switched on
    And Course Sync submission pulling is switched on
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    Then I should see "The other site does not share assignment submissions"
