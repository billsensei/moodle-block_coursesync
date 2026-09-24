@block @block_coursesync
Feature: Syncing a question bank
  In order to bring a shared bank of questions across, not just an empty quiz
  As a teacher
  I need a Question bank activity to copy its categories and questions, and
  to be told about anything it could not

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    And the following "activities" exist:
      | activity | name              | course | idnumber |
      | qbank    | Reading questions | SRC    | qbank1   |
    And the following "question categories" exist:
      | contextlevel    | reference | name        |
      | Activity module | qbank1    | Week 1      |
    And the following "questions" exist:
      | questioncategory | qtype       | name             | questiontext                 |
      | Week 1           | truefalse   | River question   | The Nile flows north.        |
      | Week 1           | shortanswer | Capital question | What is the capital of Peru? |
      | Week 1           | shortanswer | Reading notes    | This type is not supported.  |
    And the question "Reading notes" is of a question type this site does not have
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

  @javascript
  Scenario: A question bank is copied with its categories and its questions, unsupported types counted
    Given I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should see "Question bank"

    When I press "Copy the ticked activities"
    Then I should see "copied"
    And I should see "Reading questions"

    # The XML fragment for the unsupported type still travelled - it is left
    # out on arrival, not filtered out on the way - and the teacher is told.
    And I should see "1 question(s) of a type Course Sync does not support yet were left out"

    # The run is recorded against this course's connection - scoped by
    # instanceid, not by name, so it is unambiguous even though the source
    # course has an activity of the same name. Each run is a collapsed
    # <details> element, so it has to be opened before its body is visible.
    When I am on the "DEST" "block_coursesync > History" page
    And I click on "details summary" "css_element"
    Then I should see "Reading questions"
    And I should see "Copied into this course"

    # It really is in the destination course, with its category and the two
    # supported questions - not the unsupported one. Reached by this
    # plugin's own step rather than core's "question bank" page type - that
    # one resolves an activity by name alone, and the source course has a
    # "Reading questions" of its own, on this same self-synced site.
    When I change window size to "large"
    And I am on the question bank page for the synced "Reading questions" in "DEST"
    And I apply question bank filter "Category" with value "Week 1"
    Then I should see "River question"
    And I should see "Capital question"
    And I should not see "Reading notes"
