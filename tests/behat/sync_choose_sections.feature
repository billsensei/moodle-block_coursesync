@block @block_coursesync
Feature: The sections on the Choose what to copy page can be opened and closed
  In order to scroll less on a course with many sections
  As a teacher
  I need each section's activities folded away, open where there is something new, and still able to be ticked

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    And the following "activities" exist:
      | activity | course | name                | intro        | section |
      | page     | SRC    | Old week one page   | Copied first | 1       |
      | page     | SRC    | Fresh week two page | Not yet      | 2       |
      | page     | SRC    | Old week three page | Copied first | 3       |
      | page     | SRC    | Fresh week three page | Not yet  | 3       |
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

    # Copy the two old pages now, so the next visit has some already here in sections of their own.
    When I am on the "DEST" "block_coursesync > Sync" page
    And I set the field "Fresh week two page" to ""
    And I set the field "Fresh week three page" to ""
    And I press "Copy the ticked activities"
    Then I should see "2 copied"

  @javascript
  Scenario: A section with something new starts open, and one with only what is already here starts closed
    When I am on the "DEST" "block_coursesync > Sync" page
    And I click on "Already on this course" "text"
    Then "Fresh week two page" "table_row" should be visible
    And "Fresh week three page" "table_row" should be visible
    And "Old week three page" "table_row" should be visible
    And "Old week one page" "table_row" should not be visible

  @javascript
  Scenario: A section opens and closes from its heading
    When I am on the "DEST" "block_coursesync > Sync" page
    And I click on "Already on this course" "text"
    And I click on "Section 1" "button"
    Then "Old week one page" "table_row" should be visible
    When I click on "Section 1" "button"
    Then "Old week one page" "table_row" should not be visible

  @javascript
  Scenario: Expand all and Collapse all work on every section
    When I am on the "DEST" "block_coursesync > Sync" page
    And I click on "Expand all" "button" in the "#coursesync-choose" "css_element"
    Then "Old week one page" "table_row" should be visible
    And "Fresh week two page" "table_row" should be visible
    When I click on "Collapse all" "button" in the "#coursesync-choose" "css_element"
    Then "Old week one page" "table_row" should not be visible
    And "Fresh week two page" "table_row" should not be visible
    And "Old week three page" "table_row" should not be visible

  @javascript
  Scenario: What is ticked in a closed section is still copied
    When I am on the "DEST" "block_coursesync > Sync" page
    And I click on "Section 2" "button"
    Then "Fresh week two page" "table_row" should not be visible
    When I press "Copy the ticked activities"
    Then I should see "2 copied"

  @javascript
  Scenario: A section can be ticked on its own
    When I am on the "DEST" "block_coursesync > Sync" page
    And I press "Select none"
    And I click on "Select this section" "button" in the "Section 2" "table_row"
    And I press "Copy the ticked activities"
    Then I should see "1 copied"
