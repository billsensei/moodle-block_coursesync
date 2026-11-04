@block @block_coursesync
Feature: Pulling every attempt of an assignment from the other site
  In order to have the whole history of a student's work and marks, not only the latest attempt
  As a teacher
  I need to choose to include every attempt, see them in the preview, and pull them

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | DEST   | editingteacher |
      | student1 | DEST   | student        |
      | student1 | SRC    | student        |
    And the following "activities" exist:
      | activity | course | name      | intro          | section | submissiondrafts | assignsubmission_onlinetext_enabled | assignfeedback_comments_enabled | maxattempts | attemptreopenmethod |
      | assign   | SRC    | Essay one | Write an essay | 1       | 0                | 1                                   | 1                               | -1          | manual              |
    # Two goes on the source, each marked, before anything is copied.
    And the following "mod_assign > submissions" exist:
      | assign    | user     | onlinetext          |
      | Essay one | student1 | First draft of it   |
    And "student1" was marked 40 with "Try again" in "Essay one" of "SRC"
    And "student1" has handed in a further attempt "Second draft of it" to "Essay one" of "SRC"
    And "student1" was marked 70 with "Much better" in "Essay one" of "SRC"
    And the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | block/coursesync:configure     | Allow      | editingteacher | Course       | DEST      |
      | mod/assign:editothersubmission | Allow      | editingteacher | Course       | DEST      |
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
    And I should see "Essay one"
    And I log out

  Scenario: A teacher chooses to pull every attempt of a submission, and the history says they are attempts
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    And Course Sync attempts sharing is switched on
    And Course Sync attempts pulling is switched on
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    Then I should see "Include every attempt each student handed in, not only the latest"
    # To begin with only the latest attempt is shown: attempt 2.
    And "//table[@id='coursesync-submissions-written']//tr[td[normalize-space(.)='Sam Student'] and td[3][normalize-space(.)='2']]" "xpath_element" should exist
    And "//table[@id='coursesync-submissions-written']//tr[td[normalize-space(.)='Sam Student'] and td[3][normalize-space(.)='1']]" "xpath_element" should not exist

    When I set the field "Include every attempt each student handed in, not only the latest" to "1"
    And I press "Update the preview"
    Then I should see "every attempt they handed in"
    And I should see "The counts below are of attempts, not students."
    And "//table[@id='coursesync-submissions-written']//tr[td[normalize-space(.)='Sam Student'] and td[3][normalize-space(.)='1']]" "xpath_element" should exist
    And "//table[@id='coursesync-submissions-written']//tr[td[normalize-space(.)='Sam Student'] and td[3][normalize-space(.)='2']]" "xpath_element" should exist
    # Previewing wrote nothing.
    And I should not see "Submission attempts pulled"

    When I press "Pull the ticked assignments"
    Then I should see "Submission attempts pulled: 2 (2 new, 0 updated)."
    And "student1" has 2 attempts in the synced "Essay one" in course "DEST", the last of them the latest

    # The history keeps counts only, and says they are attempts.
    When I am on the "DEST" "block_coursesync > History" page
    Then I should see "Submission attempts pulled: 2, kept as they were: 0, skipped: 0"
    And I should not see "Sam Student"
    And I should not see "draft of it"

    # Pulling only the latest would write it over attempt 1, so it is left alone.
    When I am on the "DEST" "block_coursesync > Submissions" page
    Then I should see "Earlier attempts of this student were pulled before"
    And I should see "Nothing would change"

  Scenario: A teacher pulls the mark of every attempt, each on its own attempt
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    And Course Sync marks sharing is switched on
    And Course Sync marks pulling is switched on
    And Course Sync attempts sharing is switched on
    And Course Sync attempts pulling is switched on
    # The attempts come first: a mark goes onto work that is the same attempt.
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    And I set the field "Include every attempt each student handed in, not only the latest" to "1"
    And I press "Update the preview"
    And I press "Pull the ticked assignments"
    Then I should see "Submission attempts pulled: 2"

    When I am on the "DEST" "block_coursesync > Marks" page
    Then I should see "Include every attempt each student handed in, not only the latest"
    # The latest alone would put a mark on the wrong attempt, so it is left.
    And I should see "Marks for several attempts of this student were pulled before, or their attempts were" in the "coursesync-marks-skipped" "table"

    When I set the field "Include every attempt each student handed in, not only the latest" to "1"
    And I press "Update the preview"
    Then I should see "the mark for every attempt each student handed in"
    And I should see "Attempt" in the "coursesync-marks-written" "table"
    And "//table[@id='coursesync-marks-written']//tr[td[normalize-space(.)='Sam Student'] and td[3][normalize-space(.)='1']]" "xpath_element" should exist
    And "//table[@id='coursesync-marks-written']//tr[td[normalize-space(.)='Sam Student'] and td[3][normalize-space(.)='2']]" "xpath_element" should exist

    When I press "Pull the ticked assignments"
    Then I should see "Mark attempts pulled: 2 (2 new, 0 updated)."
    And "student1" has a mark of 40 for attempt 1 in the synced "Essay one" in course "DEST"
    And "student1" has a mark of 70 for attempt 2 in the synced "Essay one" in course "DEST"

    When I am on the "DEST" "block_coursesync > History" page
    Then I should see "Mark attempts pulled: 2, kept as they were: 0, skipped: 0"

  Scenario: A source that does not share every attempt is not asked, and the teacher can go back to the latest
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    And Course Sync attempts pulling is switched on
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    And I set the field "Include every attempt each student handed in, not only the latest" to "1"
    And I press "Update the preview"
    Then I should see "The other site cannot share every attempt"
    And I should not see "Sam Student"

    When I follow "Preview the latest attempts instead"
    Then I should see "Sam Student" in the "coursesync-submissions-written" "table"

  Scenario: A warning where this assignment allows fewer attempts than the other site has
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    And Course Sync attempts sharing is switched on
    And Course Sync attempts pulling is switched on
    And the synced "Essay one" in course "DEST" allows 1 attempt
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    And I set the field "Include every attempt each student handed in, not only the latest" to "1"
    And I press "Update the preview"
    Then I should see "Essay one: the other site has students with 2 attempts, but this assignment allows 1 here."
    # A warning, not a stop: the attempts are still offered.
    And "Pull the ticked assignments" "button" should exist

  Scenario: The choice is not offered where this site has not allowed it
    Given Course Sync submission sharing is switched on
    And Course Sync submission pulling is switched on
    And Course Sync marks sharing is switched on
    And Course Sync marks pulling is switched on
    And Course Sync attempts sharing is switched on
    When I am on the "DEST" "block_coursesync > Submissions" page logged in as "teacher1"
    Then I should not see "Include every attempt each student handed in"
    And I should see "Sam Student" in the "coursesync-submissions-written" "table"

    When I am on the "DEST" "block_coursesync > Marks" page
    Then I should not see "Include every attempt each student handed in"
