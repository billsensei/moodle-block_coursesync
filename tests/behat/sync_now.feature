@block @block_coursesync
Feature: Sync now
  In order to bring new activities across from another site
  As a teacher
  I need a sync that refuses to run until it knows what it is syncing

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
    And the following "blocks" exist:
      | blockname  | contextlevel | reference | pagetypepattern | defaultregion |
      | coursesync | Course       | C1        | course-view-*   | side-pre      |

  Scenario: Sync refuses to run before a course is mapped
    Given I am on the "C1" "block_coursesync > Sync" page logged in as "teacher1"
    Then I should see "No course on the other site has been chosen yet"
    And I should not see "Copy new activities into this course now?"

  Scenario: An unconfigured block does not offer to sync
    Given I am on the "Course 1" course page logged in as "teacher1"
    Then I should see "Course Sync — not yet configured" in the "Course Sync" "block"
    And I should not see "Sync now" in the "Course Sync" "block"

  Scenario: A student is offered nothing
    Given I am on the "Course 1" course page logged in as "student1"
    Then I should not see "Sync now" in the "Course Sync" "block"
    And I should not see "See what has changed" in the "Course Sync" "block"
