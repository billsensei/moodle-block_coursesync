@block @block_coursesync
Feature: Syncing books, folders, assignments, quizzes and wikis
  In order to rebuild more of a course than its pages and links
  As a teacher
  I need the other activity types to come across too, and to be told what
  could not come with them

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    And the following "activities" exist:
      | activity    | course | name             | intro                   | section |
      | book        | SRC    | Course handbook  | Read this first         | 1       |
      | folder      | SRC    | Week 1 handouts  | Everything for week one | 1       |
      | assign      | SRC    | Essay one        | Write about it          | 2       |
      | quiz        | SRC    | End of unit test | Twenty minutes          | 2       |
      | wiki        | SRC    | Group notes      | Write these together    | 3       |
      | h5pactivity | SRC    | Interactive bit  | Try this exercise       | 3       |
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

  Scenario: All five of the newer activity types are copied, with their limits stated
    Given I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

    # The confirmation names what this plugin can bring across.
    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should see "Book"
    And I should see "Folder"
    And I should see "Assignment"
    And I should see "Quiz"
    And I should see "Wiki"
    And I should see "H5P"

    When I press "Copy the ticked activities"
    Then I should see "copied"
    And I should see "Course handbook"
    And I should see "Week 1 handouts"
    And I should see "Essay one"
    And I should see "End of unit test"
    And I should see "Group notes"
    And I should see "Interactive bit"

    # Nothing is copied without saying what stayed behind.
    And I should see "no questions came with it"
    And I should see "Student submissions, grades and feedback stay on the other site"
    And I should see "The pages people wrote in it stay on the other site"

    # They really are in the destination course.
    When I am on "Destination Course" course homepage
    Then I should see "Course handbook"
    And I should see "Week 1 handouts"
    And I should see "Essay one"
    And I should see "End of unit test"
    And I should see "Group notes"
    And I should see "Interactive bit"

  Scenario: A copied book opens and shows its chapters
    Given the following "mod_book > chapters" exist:
      | book            | title        | content            | pagenum |
      | Course handbook | Introduction | Start here please  | 1       |
      | Course handbook | Assessment   | How you are marked | 2       |
    And I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"
    And I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    Then I should see "Course handbook"

    # Opening the copy shows the first chapter, and both are in the contents.
    When I am on "Destination Course" course homepage
    And I follow "Course handbook"
    Then I should see "Introduction"
    And I should see "Start here please"
    And I should see "Assessment"

    # The second chapter came across with its own content, under its own title.
    When I follow "Assessment"
    Then I should see "How you are marked"
