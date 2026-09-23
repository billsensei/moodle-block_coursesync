@block @block_coursesync
Feature: Syncing subsections and what is inside them
  In order to keep a course's structure, not just its activities
  As a teacher
  I need a subsection to be copied, and what is inside it on the other site to
  land inside the copy

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections | initsections |
      | Destination Course | DEST      | 0        | 2           | 1            |
      | Source Course      | SRC       | 0        | 2           | 1            |
    # The subsection's own section is numbered after the two ordinary ones: 3.
    And the following "activities" exist:
      | activity   | course | name          | intro        | section |
      | subsection | SRC    | Extra reading |              | 2       |
      | page       | SRC    | Further notes | Read this    | 3       |
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

  Scenario: A subsection and the page inside it arrive together, the page inside the copy
    Given I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

    When I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    Then I should see "Created" in the "Extra reading" "table_row"
    And I should see "Created" in the "Further notes" "table_row"

    # The "activity" selector, as mod_subsection's own tests use: a
    # subsection's activity element holds what is inside it, and only that.
    # ("section" would match the ordinary section around it, which holds the
    # page whether it is inside the subsection or beside it.)
    When I am on "Destination Course" course homepage
    Then I should see "Further notes" in the "Extra reading" "activity"
