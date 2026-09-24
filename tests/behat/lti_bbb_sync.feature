@block @block_coursesync
Feature: Syncing external tools and BigBlueButton rooms
  In order to bring across activities that depend on something set up on each site
  As a teacher
  I need an external tool to use this site's own matching tool, or to be told
  which tool is missing, and a BigBlueButton room to be set up afresh here

  Background:
    Given this site is set up as a Course Sync source
    And I enable "bigbluebuttonbn" "mod" plugin
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    # A site tool, as an administrator saves one: configured, with a domain.
    And the following "mod_lti > tool types" exist:
      | name        | baseurl                        | state | lti_toolurl                    | coursevisible |
      | Quiz engine | https://tool.example.com/lti   | 1     | https://tool.example.com/lti   | 2             |
    # A tool only the source course has: the destination course cannot use
    # it, just as it could not use a tool on another site.
    And the following "mod_lti > course tools" exist:
      | name            | baseurl                         | course | lti_toolurl                     |
      | Source-only kit | https://kit.example.org/launch  | SRC    | https://kit.example.org/launch  |
    And the following "mod_lti > tool instances" exist:
      | name           | tool            | course |
      | Weekly quizzes | Quiz engine     | SRC    |
      | Lab simulator  | Source-only kit | SRC    |
    And the following "activities" exist:
      | activity        | course | name           | intro             | section |
      | bigbluebuttonbn | SRC    | Weekly meeting | Join us on Friday | 1       |
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

  Scenario: A tool with a match here comes across, one without is named, and the room is set up afresh
    Given I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

    When I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"

    Then I should see "Created" in the "Weekly quizzes" "table_row"
    And I should see "uses the matching external tool set up on this site" in the "Weekly quizzes" "table_row"

    And I should see "Failed" in the "Lab simulator" "table_row"
    And I should see "no external tool set up for the address this activity uses" in the "Lab simulator" "table_row"
    And I should see "The tool it needs: Source-only kit (https://kit.example.org/launch)" in the "Lab simulator" "table_row"

    And I should see "Created" in the "Weekly meeting" "table_row"
    And I should see "set up afresh on this site's BigBlueButton server" in the "Weekly meeting" "table_row"
