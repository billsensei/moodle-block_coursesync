@block @block_coursesync
Feature: Recognising activities already in the course
  In order not to be offered again what my course already has
  As a teacher
  I need activities built here by hand, restored or imported to be listed as
  already here when the other course has the same type and name

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    And the following "activities" exist:
      | activity | course | name          | intro              | section | idnumber |
      | page     | SRC    | Week 1 Notes  | Reading for week 1 | 1       |          |
      | page     | SRC    | Reading list  | Books              | 1       |          |
      | page     | SRC    | Week 2 Notes  | Reading for week 2 | 2       |          |
      | page     | SRC    | Handout       | First handout      | 2       |          |
      | page     | SRC    | Handout       | Second handout     | 3       |          |
      | page     | DEST   | week 1  notes | Made here by hand  | 1       |          |
      | page     | DEST   | Reading list  | Made here by hand  | 1       | MYLIST   |
      | page     | DEST   | Handout       | Made here by hand  | 2       |          |
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
    And I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

  Scenario: The first sync lists what is already here, and offers only what is not
    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should see "Ready to copy"
    And I should see "Already on this course"
    # Built here by hand, same type and name: already here, to be linked.
    And I should see "It will be linked to the original when you sync" in the "Week 1 Notes" "table_row"
    # Same, but it has an ID number of its own here: listed, never linked.
    And I should see "has its own ID number here" in the "Reading list" "table_row"
    # Not here at all: offered, ticked.
    And the field "Week 2 Notes" matches value "1"
    # One "Handout" here, but two over there: not guessed at, and not ticked.
    And I should see "did not guess which is which"
    And "Handout" "table_row" should exist
    And I should not see "Already here" in the "Handout" "table_row"

  Scenario: Syncing links what is already here, and from then on it is Course Sync's copy
    When I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    Then I should see "Already here and now linked to the original: 1."
    And I should see "Linked" in the "Week 1 Notes" "table_row"
    And I should not see "Failed"
    And I should see "Week 2 Notes"

    # Next time, the linked page is recognised by its marker, like any copy;
    # the one with its own ID number is still left alone.
    When I am on the "DEST" "block_coursesync > Sync" page
    Then I should see "Already synced - if ticked, the copy here is replaced" in the "Week 1 Notes" "table_row"
    And I should see "has its own ID number here" in the "Reading list" "table_row"

    When I am on the "DEST" "block_coursesync > History" page
    Then I should see "now linked to the original"
