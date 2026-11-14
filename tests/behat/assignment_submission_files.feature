@block @block_coursesync
Feature: Choosing which of a student's files to pull from the other site
  In order to bring across only the files I need
  As a teacher
  I need a tick box for each file a student handed in, and to be able to add the others later

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
      | activity | course | name      | intro        | section | submissiondrafts | assignsubmission_onlinetext_enabled | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes |
      | assign   | SRC    | Portfolio | Hand in work | 1       | 0                | 0                                   | 1                             | 3                              | 0                                  |
    # Two files, so one of them can be left behind. Paths are relative to the Moodle root.
    And the following "mod_assign > submissions" exist:
      | assign    | user     | file                                             |
      | Portfolio | student1 | lib/tests/fixtures/1.jpg,lib/tests/fixtures/2.jpg |
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
    And I should see "Portfolio"
    And I log out

  Scenario: A teacher pulls one of two files and adds the other later
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    Then I should see "1.jpg" in the "#coursesync-student-1" "css_element"
    And I should see "2.jpg" in the "#coursesync-student-1" "css_element"
    And the field "1.jpg" matches value "1"
    And the field "2.jpg" matches value "1"

    When I set the field "2.jpg" to ""
    And I press "Pull the ticked work"
    Then I should see "Submissions pulled: 1 (1 new, 0 updated)."

    # The file left out is offered again, and the one already here is shown as here, with no box.
    When I am on the "DEST" "block_coursesync > Submissions" page
    Then I should see "An earlier pull left some of these files out" in the "#coursesync-student-1" "css_element"
    And I should see "already here" in the "#coursesync-student-1" "css_element"
    And "1.jpg" "field" should not exist
    And the field "2.jpg" matches value "1"

    When I press "Pull the ticked work"
    Then I should see "Submissions pulled: 1 (0 new, 1 updated)."

    # Both files are here now, so there is nothing more to offer.
    When I am on the "DEST" "block_coursesync > Submissions" page
    Then I should see "Nothing would change"

  Scenario: A teacher unticks every file of work that has nothing else and nothing is pulled
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    And I set the field "1.jpg" to ""
    And I set the field "2.jpg" to ""
    And I press "Pull the ticked work"
    Then I should see "You left this unticked" in the "#coursesync-student-1" "css_element"
    And I should not see "Submissions pulled: 1"

    # Offered again as it was.
    When I am on the "DEST" "block_coursesync > Submissions" page
    Then the field "1.jpg" matches value "1"
    And the field "2.jpg" matches value "1"
