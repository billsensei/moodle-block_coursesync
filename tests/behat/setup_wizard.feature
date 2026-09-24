@block @block_coursesync
Feature: Course Sync setup wizard
  In order to pull activities from another Moodle site
  As a teacher
  I need to record where that site is and prove the connection works

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
    And the following "permission overrides" exist:
      | capability                 | permission | role           | contextlevel | reference |
      | block/coursesync:configure | Allow      | editingteacher | Course       | C1        |
    And the following "blocks" exist:
      | blockname  | contextlevel | reference | pagetypepattern | defaultregion |
      | coursesync | Course       | C1        | course-view-*   | side-pre      |

  Scenario: The block offers the wizard to a teacher but not to a student
    Given I am on the "Course 1" course page logged in as "teacher1"
    Then I should see "Set up the connection" in the "Course Sync" "block"
    And I log out
    When I am on the "Course 1" course page logged in as "student1"
    Then I should see "Course Sync — not yet configured" in the "Course Sync" "block"
    And I should not see "Set up the connection" in the "Course Sync" "block"

  Scenario: A teacher without the setup permission is told to ask a manager
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 2 | C2        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C2     | editingteacher |
    And the following "blocks" exist:
      | blockname  | contextlevel | reference | pagetypepattern | defaultregion |
      | coursesync | Course       | C2        | course-view-*   | side-pre      |
    When I am on the "Course 2" course page logged in as "teacher1"
    Then I should see "A manager has to set up the connection" in the "Course Sync" "block"
    And "Set up the connection" "link" should not exist in the "Course Sync" "block"

  Scenario: A plain HTTP address is rejected with an explanation
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I click on "Set up the connection" "link" in the "Course Sync" "block"
    Then I should see "Course Sync setup"
    When I set the field "Remote site URL" to "http://source.example.edu"
    And I press "Next"
    Then I should see "The address must start with https://"

  Scenario: A malformed address is rejected
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I click on "Set up the connection" "link" in the "Course Sync" "block"
    And I set the field "Remote site URL" to "not a url at all"
    And I press "Next"
    Then I should see "That is not a valid web address"

  Scenario: A valid address moves on to the manual steps for the other site
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I click on "Set up the connection" "link" in the "Course Sync" "block"
    And I set the field "Remote site URL" to "https://source.example.edu"
    And I press "Next"
    Then I should see "Enable web services"
    And I should see "Manage tokens"
    And I should see "https://source.example.edu"
    And I should see "Token"

  Scenario: Something that is not a token is rejected before any call is made
    Given I am on the "Course 1" course page logged in as "teacher1"
    When I click on "Set up the connection" "link" in the "Course Sync" "block"
    And I set the field "Remote site URL" to "https://source.example.edu"
    And I press "Next"
    And I set the field "Token" to "obviously-not-a-token"
    And I press "Save and test"
    Then I should see "That does not look like a Moodle token"
