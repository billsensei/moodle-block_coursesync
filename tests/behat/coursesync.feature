@block @block_coursesync
Feature: Pulling activities from another site
  In order to reuse a colleague's course material
  As a teacher
  I need to see what this course pulls from, start a sync, and settle anything that clashes

  Background:
    Given the following "courses" exist:
      | fullname      | shortname | format | numsections |
      | Target course | TGT1      | topics | 3           |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | TGT1   | editingteacher |
      | student1 | TGT1   | student        |

  Scenario: A block that has not been connected yet asks to be set up
    Given an unconfigured Course sync block is in course "TGT1"
    When I log in as "teacher1"
    And I am on "Target course" course homepage
    Then I should see "Finish setting this block up"
    And I should not see "Sync now"

  Scenario: A configured block shows where it pulls from and offers a sync
    Given the Course sync block is configured in course "TGT1"
    When I log in as "teacher1"
    And I am on "Target course" course homepage
    Then I should see "Source Biology"
    And I should see "Remote Example"
    And I should see "Not synced yet"
    And I should see "Use Check now to see what this course could pull in"
    And I should see "Check now"
    And I should see "Sync now"
    And I should see "View history"

  @javascript
  Scenario: Checking lists what a sync would bring in, without bringing any of it in
    Given the Course sync block is configured in course "TGT1"
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 1000   |
      | 12   | Week 2 reading | 1000   |
    And I log in as "teacher1"
    And I am on "Target course" course homepage
    When I press "Check now"
    Then I should see "Ready to sync (2)"
    And I should see "Week 1 reading"
    And I should see "Week 2 reading"
    And I should see "New"
    And I should see "0 synced"
    And the field "Week 1 reading" matches value "1"
    And I should see "Select all"
    And I should see "Select none"
    And I should see "Cancel"

  @javascript
  Scenario: Clearing every box and syncing does nothing at all
    Given the Course sync block is configured in course "TGT1"
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 1000   |
    And I log in as "teacher1"
    And I am on "Target course" course homepage
    And I press "Check now"
    And I should see "Ready to sync (1)"
    When I press "Select none"
    Then the field "Week 1 reading" matches value ""
    When I press "Sync now"
    Then I should see "No activities were ticked, so nothing was synced"
    And I should see "Not synced yet"

  @javascript
  Scenario: Select all puts back what was cleared
    Given the Course sync block is configured in course "TGT1"
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 1000   |
      | 12   | Week 2 reading | 1000   |
    And I log in as "teacher1"
    And I am on "Target course" course homepage
    And I press "Check now"
    And I press "Select none"
    When I press "Select all"
    Then the field "Week 1 reading" matches value "1"
    And the field "Week 2 reading" matches value "1"

  @javascript
  Scenario: Cancelling clears the list and leaves the course page as it was
    Given the Course sync block is configured in course "TGT1"
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 1000   |
    And I log in as "teacher1"
    And I am on "Target course" course homepage
    And I press "Check now"
    And I should see "Ready to sync (1)"
    When I press "Cancel"
    Then I should see "That list has been cleared"
    And I should see "Use Check now to see what this course could pull in"
    And I should not see "Ready to sync"
    And I should see "Not synced yet"

  Scenario: A student is not shown the block at all
    Given the Course sync block is configured in course "TGT1"
    When I log in as "student1"
    And I am on "Target course" course homepage
    Then I should not see "Sync now"
    And I should not see "Check now"
    And I should not see "View history"

  Scenario: The history lists what a run did
    Given the Course sync block is configured in course "TGT1"
    And course "TGT1" has already pulled "11" as "Week 1 reading" signal "1000"
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 1000   |
    And a course sync has run in course "TGT1"
    When I log in as "teacher1"
    And I am on "Target course" course homepage
    And I follow "View history"
    Then I should see "Sync history"
    And I should see "Week 1 reading"
    And I should see "Unchanged"

  Scenario: An activity edited on both sides is held back for review
    Given the Course sync block is configured in course "TGT1"
    And course "TGT1" has already pulled "11" as "Week 1 reading" signal "1000"
    And the local copy of "Week 1 reading" has been edited
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 2000   |
    And a course sync has run in course "TGT1"
    When I log in as "teacher1"
    And I am on "Target course" course homepage
    Then I should see "1 needing review"
    When I follow "Review conflicts (1)"
    Then I should see "Conflicts needing review"
    And I should see "Week 1 reading"
    And I should see "edited independently"
    And I should see "Keep this course’s version"
    And I should see "Take the remote version"

  Scenario: Keeping this course's version settles the conflict
    Given the Course sync block is configured in course "TGT1"
    And course "TGT1" has already pulled "11" as "Week 1 reading" signal "1000"
    And the local copy of "Week 1 reading" has been edited
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 2000   |
    And a course sync has run in course "TGT1"
    And I log in as "teacher1"
    And I am on "Target course" course homepage
    And I follow "Review conflicts (1)"
    When I press "Keep this course’s version"
    Then I should see "has been kept"
    And I should see "Nothing needs review"

  Scenario: Deciding later leaves the conflict where it is
    Given the Course sync block is configured in course "TGT1"
    And course "TGT1" has already pulled "11" as "Week 1 reading" signal "1000"
    And the local copy of "Week 1 reading" has been edited
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 2000   |
    And a course sync has run in course "TGT1"
    And I log in as "teacher1"
    And I am on "Target course" course homepage
    And I follow "Review conflicts (1)"
    When I press "Decide later"
    Then I should see "Left for later"
    And I should see "Week 1 reading"
    And I should see "Take the remote version"

  Scenario: A student cannot reach the conflict review
    Given the Course sync block is configured in course "TGT1"
    And course "TGT1" has already pulled "11" as "Week 1 reading" signal "1000"
    And the local copy of "Week 1 reading" has been edited
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 2000   |
    And a course sync has run in course "TGT1"
    And I log in as "student1"
    When I am on "Target course" course homepage
    Then I should not see "Review conflicts"
