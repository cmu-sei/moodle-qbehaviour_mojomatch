@qbehaviour @qbehaviour_mojomatch
Feature: The MojoMatch question behaviour is installed but not offered on its own
  In order for TopoMojo questions to be graded the way TopoMojo grades them
  As an admin
  I need the MojoMatch behaviour installed, tied to the TopoMojo question type, and kept out of
  the behaviours a quiz author can choose

  # The behaviour is only ever used by a TopoMojo (qtype_mojomatch) question, which picks it in
  # make_behaviour(). Showing it in action means rendering that question, and on a test site
  # with no TopoMojo configured qtype_mojomatch_question::setup() prints debugging() output on
  # every render, which fails the step. So these scenarios stay on the pages that describe the
  # behaviour; the grading itself is covered by tests/walkthrough_test.php.

  Scenario: MojoMatch is listed as a behaviour the TopoMojo question type requires
    Given I log in as "admin"
    When I navigate to "Plugins > Question behaviours > Manage question behaviours" in site administration
    Then I should see "TopoMojo" in the "MojoMatch" "table_row"
    # The question type requires it, so it cannot be uninstalled on its own.
    And I should not see "Uninstall" in the "MojoMatch" "table_row"

  Scenario: MojoMatch is not one of the behaviours a quiz can be set to use
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "activities" exist:
      | activity | name   | course | idnumber |
      | quiz     | Quiz 1 | C1     | quiz1    |
    When I am on the "Quiz 1" "quiz activity editing" page logged in as "admin"
    Then the "How questions behave" select box should contain "Deferred feedback"
    And the "How questions behave" select box should not contain "MojoMatch"
    And the "How questions behave" select box should not contain "mojomatch"
