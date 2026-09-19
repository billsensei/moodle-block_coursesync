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
    And I should see "Sync now"
    And I should see "View sync history"

  Scenario: A student is not shown the block at all
    Given the Course sync block is configured in course "TGT1"
    When I log in as "student1"
    And I am on "Target course" course homepage
    Then I should not see "Sync now"
    And I should not see "View sync history"

  Scenario: The history lists what a run did
    Given the Course sync block is configured in course "TGT1"
    And course "TGT1" has already pulled "11" as "Week 1 reading" signal "1000"
    And the remote site offers the following activities:
      | cmid | name           | signal |
      | 11   | Week 1 reading | 1000   |
    And a course sync has run in course "TGT1"
    When I log in as "teacher1"
    And I am on "Target course" course homepage
    And I follow "View sync history"
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
