@block @block_coursesync
Feature: Course Sync block placeholder
  In order to prepare a course for syncing activities from another site
  As a teacher
  I need to be able to add the Course Sync block to a course

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  @javascript
  Scenario: A teacher adds the block and sees the placeholder message
    Given I am on the "Course 1" course page logged in as "teacher1"
    And I turn editing mode on
    When I add the "Course Sync" block
    Then I should see "Course Sync — not yet configured" in the "Course Sync" "block"

  Scenario: A student enrolled in the course sees the placeholder message
    Given the following "blocks" exist:
      | blockname  | contextlevel | reference | pagetypepattern | defaultregion |
      | coursesync | Course       | C1        | course-view-*   | side-pre      |
    When I am on the "Course 1" course page logged in as "student1"
    Then I should see "Course Sync — not yet configured" in the "Course Sync" "block"
