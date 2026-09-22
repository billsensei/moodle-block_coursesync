@block @block_coursesync
Feature: Mapping a remote course
  In order to know what is waiting to be pulled
  As a teacher
  I need to record which course on the other site this one follows

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

  Scenario: The preview says so when no course has been mapped
    Given I am on the "C1" "block_coursesync > Preview" page logged in as "teacher1"
    Then I should see "No course on the other site has been chosen yet"
    And I should see "Manage the connection"

  Scenario: A student is not offered the preview at all
    Given I am on the "Course 1" course page logged in as "student1"
    Then I should see "Course Sync" in the "Course Sync" "block"
    And I should not see "See changes" in the "Course Sync" "block"
    And I should not see "Set up the connection" in the "Course Sync" "block"

  Scenario: A course cannot be mapped before a token exists
    Given I am on the "C1" "block_coursesync > Setup" page logged in as "teacher1"
    And I set the field "Remote site URL" to "https://source.example.edu"
    And I press "Next"
    Then I should see "Token"
    But I should not see "Remote course ID or shortname"

  Scenario: The block configuration form offers the remote course field
    Given I am on the "Course 1" course page logged in as "teacher1"
    And I turn editing mode on
    When I open the "Course Sync" blocks action menu
    And I follow "Configure Course Sync block"
    Then I should see "Remote site URL"
    And I should see "Remote course ID or shortname"

  Scenario: Saving a course reference with no token explains what to do first
    Given I am on the "Course 1" course page logged in as "teacher1"
    And I turn editing mode on
    When I open the "Course Sync" blocks action menu
    And I follow "Configure Course Sync block"
    And I set the field "Remote site URL" to "https://source.example.edu"
    And I set the field "Remote course ID or shortname" to "REMOTE1"
    And I press "Save changes"
    Then I should see "Save the remote site address first"
