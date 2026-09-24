@block @block_coursesync
Feature: Syncing SCORM and IMS content packages
  In order to bring packaged learning content across, not just its settings
  As a teacher
  I need a SCORM or IMS package to arrive unpacked and working, so that the
  copy opens just as the original does

  Background:
    Given this site is set up as a Course Sync source
    And the following "courses" exist:
      | fullname           | shortname | category | numsections |
      | Destination Course | DEST      | 0        | 3           |
      | Source Course      | SRC       | 0        | 3           |
    # Both use their module's own test package: a small golf course, whose
    # first page is "Golf Explained".
    And the following "activities" exist:
      | activity | course | name          | intro                 | section |
      | scorm    | SRC    | Golf lessons  | A SCORM package       | 1       |
      | imscp    | SRC    | Golf handbook | An IMS content package | 1       |
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
  Scenario: A SCORM package and an IMS content package are copied and open
    Given I am on the "DEST" "block_coursesync > Setup" page logged in as "teacher1"
    And I enter the Course Sync token
    And I press "Save and test"
    And I follow "Next"
    And I set the field "Remote course ID or shortname" to "SRC"
    And I press "Save the course"

    When I am on the "DEST" "block_coursesync > Sync" page
    And I press "Copy the ticked activities"
    Then I should see "Golf lessons"
    And I should see "Golf handbook"
    And I should see "The package and its settings were copied"
    And I should not see "could not unpack"

    # Opened from the destination course, so it is the copy that opens, not
    # the source's own activity of the same name on this same site.
    When I change window size to "large"
    And I am on "Destination Course" course homepage
    And I click on "Golf handbook" "link" in the "region-main" "region"
    Then I should see "Golf Explained"

    When I am on "Destination Course" course homepage
    And I click on "Golf lessons" "link" in the "region-main" "region"
    And I press "Enter"
    Then "#scorm_object" "css_element" should exist
    And I should see "Golf Explained"
