@block @block_coursesync
Feature: Syncing choices, glossaries, feedback, databases, workshops and lessons
  In order to rebuild the rest of a course
  As a teacher
  I need the activities that are made of more than one record to come across
  whole, and to be told what could not come with them

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    And the following "activities" exist:
      | activity | course | name            | intro               | section | option                     |
      | choice   | SRC    | Pick a workshop | Choose one          | 1       | Monday, Tuesday, Wednesday |
      | glossary | SRC    | Key terms       | Words you need      | 1       |                            |
      | feedback | SRC    | Course feedback | Tell us how it went | 2       |                            |
      | data     | SRC    | Reading list    | Add what you read   | 2       |                            |
      | workshop | SRC    | Peer review     | Review each other   | 3       |                            |
      | lesson   | SRC    | Safety briefing | Work through this   | 3       |                            |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | DEST   | editingteacher |
    And the following "blocks" exist:
      | blockname  | contextlevel | reference | pagetypepattern | defaultregion |
      | coursesync | Course       | DEST      | course-view-*   | side-pre      |
    And the Course Sync block in course "DEST" points at this site

  Scenario: All six are copied, and each says what stayed behind
    Given I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

    # The confirmation names what this plugin can bring across.
    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should see "Choice"
    And I should see "Glossary"
    And I should see "Feedback"
    And I should see "Database"
    And I should see "Workshop"
    And I should see "Lesson"

    When I press "Copy the ticked activities"
    Then I should see "copied"
    And I should see "Pick a workshop"
    And I should see "Key terms"
    And I should see "Course feedback"
    And I should see "Reading list"
    And I should see "Peer review"
    And I should see "Safety briefing"

    # Nothing arrives incomplete without saying so.
    And I should see "The answers people gave stay on the other site"
    And I should see "The entries people wrote stay on the other site"
    And I should see "The responses people gave stay on the other site"
    And I should see "Submissions, assessments and grades stay on the other site"
    And I should see "What students did in it stays on the other site"

    # They really are in the destination course.
    When I am on "Destination Course" course homepage
    Then I should see "Pick a workshop"
    And I should see "Key terms"
    And I should see "Course feedback"
    And I should see "Reading list"
    And I should see "Peer review"
    And I should see "Safety briefing"

  Scenario: A copied choice offers the same options
    Given I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"
    And I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    Then I should see "Pick a workshop"

    When I am on "Destination Course" course homepage
    And I follow "Pick a workshop"
    Then I should see "Monday"
    And I should see "Tuesday"
    And I should see "Wednesday"
