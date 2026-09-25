@block @block_coursesync
Feature: Pulling students' grades from the other site
  In order to have students' grades for work they did on the other site
  As a teacher
  I need to preview the grades, pull them, and see which ones were kept as they were

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
      | activity | course | name      | intro            | section | grade |
      | assign   | SRC    | Essay one | Write an essay   | 1       | 100   |
    # Graded on the source before anything is copied: core's grade generator
    # finds a grade item by name, which is ambiguous once there are two.
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Essay one | student1 | 80    |
      | Essay one | student2 | 60    |
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
    And I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    And I should see "Essay one"
    And I log out

  Scenario: A teacher previews the grades, pulls them, and a grade given here is kept
    Given Course Sync grade sharing is switched on
    And Course Sync grade pulling is switched on
    And "student2" has a grade of "55" in the synced "Essay one" in course "DEST"
    When I am on the "Destination Course" course page logged in as "teacher1"
    And I follow "Pull grades"
    Then I should see "Grades to pull"
    And I should see "Sam Student" in the "coursesync-grades-written" "table"
    And I should see "80.00" in the "Sam Student" "table_row"
    And I should see "Kept as they were"
    And I should see "Sue Student" in the "coursesync-grades-conflicts" "table"
    And I should see "60.00" in the "Sue Student" "table_row"
    And I should see "55.00" in the "Sue Student" "table_row"

    When I press "Pull the grades (1)"
    Then I should see "Grades pulled: 1 (1 new, 0 updated)."
    And I should see "Some students already had a different grade here"
    And I should see "Sam Student" in the "coursesync-grades-written" "table"

    # Pulling again changes nothing: Sam's grade is already here.
    When I am on the "DEST" "block_coursesync > Grades" page
    Then I should see "Nothing would change"
    And "Pull the grades" "button" should not exist

    # The pull is in the history, as counts only.
    When I am on the "DEST" "block_coursesync > History" page
    Then I should see "Grades pulled: 1, kept as they were: 1, skipped: 0"
    And I should not see "Sam Student"

  Scenario: The button is only offered where grade pulling is switched on and allowed
    Given Course Sync grade sharing is switched on
    When I am on the "Destination Course" course page logged in as "teacher1"
    Then I should not see "Pull grades" in the "Course Sync" "block"
    And I log out

    Given Course Sync grade pulling is switched on
    When I am on the "Destination Course" course page logged in as "teacher1"
    Then I should see "Pull grades" in the "Course Sync" "block"
    And I log out

    # A non-editing teacher can edit grades but may not pull them.
    When I am on the "Destination Course" course page logged in as "marker1"
    Then I should not see "Pull grades"

  Scenario: The other site not sharing grades is explained
    Given Course Sync grade pulling is switched on
    When I am on the "DEST" "block_coursesync > Grades" page logged in as "teacher1"
    Then I should see "The other site does not share grades"
