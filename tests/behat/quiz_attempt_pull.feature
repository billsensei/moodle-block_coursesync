@block @block_coursesync
Feature: Pulling students' quiz attempts from the other site
  In order to see in the quiz here the attempts students made on the other site
  As a teacher
  I need a pull to bring their finished attempts into the copied quiz, with
  their marks, so the quiz's own reports and grade include them

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
      | questioncategory | qtype       | name             | questiontext                 |
      | Quiz pool        | truefalse   | River question   | The Nile flows north.        |
      | Quiz pool        | shortanswer | Capital question | What is the capital of Peru? |
    And quiz "Unit quiz" contains the following questions:
      | question         | page | maxmark |
      | River question   | 1    | 2.0     |
      | Capital question | 1    | 1.0     |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
      | student2 | Sue       | Student  | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | DEST   | editingteacher |
      | student1 | SRC    | student        |
      | student2 | SRC    | student        |
      | student1 | DEST   | student        |
      | student2 | DEST   | student        |
    # Attempted on the source before anything is copied: core's quiz steps
    # find a quiz by name, which is ambiguous once there are two.
    And user "student1" has attempted "Unit quiz" with responses:
      | slot | response |
      | 1    | True     |
      | 2    | frog     |
    And user "student2" has attempted "Unit quiz" with responses:
      | slot | response |
      | 1    | False    |
      | 2    | toad     |
    And the following "permission overrides" exist:
      | capability                 | permission | role           | contextlevel | reference |
      | block/coursesync:configure | Allow      | editingteacher | Course       | DEST      |
    And the following "blocks" exist:
      | blockname  | contextlevel | reference | pagetypepattern | defaultregion |
      | coursesync | Course       | DEST      | course-view-*   | side-pre      |
    And the Course Sync block in course "DEST" points at this site
    And I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"
    And I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    And I should see "Unit quiz"
    And I log out
    And Course Sync grade sharing is switched on
    And Course Sync grade pulling is switched on

  Scenario: A teacher brings the attempts across and sees them in the quiz's own report
    When I am on the "DEST" "block_coursesync > Grades" page logged in as "teacher1"
    Then I should see "Quiz attempts"
    And I should see "Attempts to bring across"
    And I should see "Sam Student" in the "coursesync-attempts-written" "table"
    And I should see "3.00" in the "Sam Student" "table_row"
    And I should see "0.80" in the "Sue Student" "table_row"
    # The quiz works its grade out from the attempts: no override is written.
    And I should not see "Grades to pull"

    When I press "Pull grades and attempts (2)"
    Then I should see "Quiz attempts brought across: 2 new, 0 updated."
    And I should see "Attempts brought across"

    # The attempts are real attempts in the quiz, which grades them itself
    # (out of 100, the generator's default): 3 of 3 marks, and 0.8 of 3.
    When I am on the grades report for the synced quiz "Unit quiz" in course "DEST"
    Then I should see "Attempts: 2"
    And I should see "100.00" in the "Sam Student" "table_row"
    And I should see "26.67" in the "Sue Student" "table_row"

    # Pulling again brings nothing twice.
    When I am on the "DEST" "block_coursesync > Grades" page
    Then I should see "Nothing would change"
    And "Pull grades and attempts" "button" should not exist

    # The history keeps the attempts' counts apart, and no names.
    When I am on the "DEST" "block_coursesync > History" page
    Then I should see "Quiz attempts"
    And I should not see "Sam Student"

  Scenario: A copy changed here takes no attempts, and its grades come as before
    Given slot 1 of the synced quiz "Unit quiz" in "DEST" is worth "5"
    When I am on the "DEST" "block_coursesync > Grades" page logged in as "teacher1"
    Then I should see "The quiz here no longer matches the one on the other site"
    And I should see "Grades to pull"
    And I should see "Sam Student" in the "coursesync-grades-written" "table"
