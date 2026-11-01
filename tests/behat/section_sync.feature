@block @block_coursesync
Feature: Copied activities go in the same section as on the other site
  In order to find a copied activity where it was on the other course
  As a teacher
  I need the sections it sits in to be made here when they are missing, and the
  activity placed in the one that matches

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 1           |
      | Source Course      | SRC       | 0        | 4           |
    And the following "activities" exist:
      | activity | course | name             | intro        | section |
      | page     | SRC    | Week one page    | In week one  | 1       |
      | page     | SRC    | Week three page  | In week three| 3       |
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

  Scenario: Sections missing here are made, and each activity goes in its own
    When I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    Then I should see "2 copied"
    And I should see "Sections added to match the other course: 2."

    When I am on "Destination Course" course homepage
    Then I should see "Week one page" in the "[data-for='section'][data-number='1']" "css_element"
    And I should see "Week three page" in the "[data-for='section'][data-number='3']" "css_element"
    And I should not see "Week three page" in the "[data-for='section'][data-number='1']" "css_element"
    And I should not see "Week one page" in the "[data-for='section'][data-number='3']" "css_element"
