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
    And I should not see "Choose what to copy"

  Scenario: An unconfigured block does not offer to sync
    Given I am on the "Course 1" course page logged in as "teacher1"
    Then I should see "Course Sync — not yet configured" in the "Course Sync" "block"
    And I should not see "Check now" in the "Course Sync" "block"

  Scenario: A student is offered nothing
    Given I am on the "Course 1" course page logged in as "student1"
    Then I should not see "Check now" in the "Course Sync" "block"
    And I should not see "See changes" in the "Course Sync" "block"

  Scenario: A teacher chooses what to copy, and what is left out comes back
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname      | shortname | category | numsections |
      | Source Course | SRC       | 0        | 3           |
    And the following "activities" exist:
      | activity | course | name          | intro            | section |
      | page     | SRC    | Wanted notes  | Copy this one    | 1       |
      | page     | SRC    | Unwanted bits | Leave this one   | 1       |
    And the Course Sync block in course "C1" points at this site
    And I am on the "C1" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

    # Both are on offer, because neither is in this course yet.
    When I am on the "C1" "block_coursesync > Sync" page
    Then I should see "Choose what to copy"
    And I should see "Wanted notes"
    And I should see "Unwanted bits"

    # Untick one and copy the rest.
    When I set the field "Unwanted bits" to ""
    And I press "Copy the ticked activities"
    Then I should see "Wanted notes"
    And I should see "You chose not to copy this one"

    # Only the ticked one arrived.
    When I am on "Course 1" course homepage
    Then I should see "Wanted notes"
    And I should not see "Unwanted bits"

    # And the one left out is offered again rather than quietly passed over.
    # The one already copied is still listed - the picker shows every
    # activity, not only what is new - but marked "Already synced" and
    # cannot be ticked again.
    When I am on the "C1" "block_coursesync > Sync" page
    Then I should see "Unwanted bits"
    And "Wanted notes" "table_row" should exist
    And the "Wanted notes" "checkbox" should be disabled
