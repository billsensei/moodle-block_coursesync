@block @block_coursesync
Feature: Syncing quiz questions
  In order to bring a quiz's questions across, not just its settings
  As a teacher
  I need a synced quiz's fixed and random slots to arrive with real
  questions and their marks, and to be told about anything it could not
  rebuild

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    And the following "activities" exist:
      | activity | name      | course | idnumber |
      | quiz     | Unit quiz | SRC    | quiz1    |
    And the following "question categories" exist:
      | contextlevel    | reference | name      |
      | Activity module | quiz1     | Quiz pool |
    And the following "questions" exist:
      | questioncategory | qtype       | name               | questiontext                 |
      | Quiz pool         | truefalse   | River question     | The Nile flows north.        |
      | Quiz pool         | shortanswer | Capital question   | What is the capital of Peru? |
      | Quiz pool         | random      | Random (Quiz pool) | 0                             |
    # The core step below only applies a custom "maxmark" to a fixed slot -
    # for a random one it is silently ignored, so the random slot below
    # keeps the engine's own default of 1.00. That default travelling
    # across correctly is still a real proof that per-slot marks are read
    # from the actual source value, not hardcoded on the way in.
    And quiz "Unit quiz" contains the following questions:
      | question            | page | maxmark |
      | River question       | 1    | 2.0     |
      | Random (Quiz pool)   | 2    |         |
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
  Scenario: A quiz's fixed and random slots arrive as real questions with their marks intact
    Given I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should see "Quiz"

    When I press "Copy the ticked activities"
    Then I should see "copied"
    And I should see "Unit quiz"

    # Questions arrived - this is the "there are attempts left behind, not
    # questions" note, not the "brought across empty" one.
    And I should see "Its categories and questions were copied"

    # Open the copy itself and look at its actual Questions screen, not just
    # the database - this proves add_random_questions()/quiz_add_quiz_question()
    # were driven correctly, marks and all.
    When I am on "Destination Course" course homepage
    And I click on "Unit quiz" "link" in the "page-content" "region"
    And I follow "Questions"
    Then I should see "River question" on quiz page "1"
    And I should see "Random (Quiz pool)" on quiz page "2"
    And I should see "2.00"
    And I should see "1.00"
    And I should see "Total of marks: 3.00"
