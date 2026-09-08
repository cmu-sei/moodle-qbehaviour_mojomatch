<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
TopoMojo Question Behaviour Plugin for Moodle

Copyright 2024 Carnegie Mellon University.

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL.
CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.
Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full
terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM24-1319
*/

namespace qbehaviour_mojomatch;

use question_state;
use test_question_maker;
use qbehaviour_walkthrough_test_base;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/question/engine/tests/helpers.php');

/**
 * Walkthrough tests pinning the mojomatch penalty semantics.
 *
 * These lock the intended, cross-stack penalty model so it cannot silently
 * drift: penalty is a FRACTION (0-1) of the question mark, deducted per PRIOR
 * wrong try, floored at 0. A correct answer after N wrong tries therefore
 * scores:
 *
 *     mark = defaultmark * max(0, 1 - penalty * N)
 *
 * Because mod_topomojo maps the challenge JSON `weight` to `defaultmark` and
 * qtype_mojomatch grades binary (raw fraction 1 or 0), the effective score is
 * `weight * max(0, 1 - penalty * N)`. This is the reference the TopoMojo API
 * (Weight - Penalty*N) and topomojo-ui penalty PRs are expected to align to.
 *
 * The behaviour is selected via qtype_mojomatch_question::make_behaviour(),
 * which forces qbehaviour_mojomatch while passing the activity's
 * preferredbehaviour through, so starting an attempt at 'interactive' exercises
 * this behaviour with penalties enabled.
 *
 * NOTE: process_submit()/process_finish() emit DEBUG_DEVELOPER messages by
 * design; each step below calls resetDebugging() to consume them.
 *
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbehaviour_mojomatch
 */
final class walkthrough_test extends qbehaviour_walkthrough_test_base {

    /**
     * interactive: correct after two wrong tries scores 1 - penalty*2.
     */
    public function test_interactive_correct_after_two_wrong_applies_cumulative_penalty(): void {
        $q = test_question_maker::make_question('mojomatch', 'exactmatch');
        $q->penalty = 0.1;
        $this->start_attempt_at_question($q, 'interactive', 1);

        // Try 1: wrong. Mark 0, question stays active for another try.
        $this->process_submission(['answer' => 'zz', '-submit' => 1]);
        $this->resetDebugging();
        $this->check_current_mark(0);
        $this->check_current_state(question_state::$todo);

        // Try 2: wrong again. Still 0, still active.
        $this->process_submission(['answer' => 'zzz', '-submit' => 1]);
        $this->resetDebugging();
        $this->check_current_mark(0);
        $this->check_current_state(question_state::$todo);

        // Try 3: correct after 2 wrong -> 1 - 0.1*2 = 0.80, graded right.
        $this->process_submission(['answer' => 'cp', '-submit' => 1]);
        $this->resetDebugging();
        $this->check_current_state(question_state::$gradedright);
        $this->check_current_mark(0.8);
    }

    /**
     * interactive: correct on the first try scores full marks (no penalty).
     */
    public function test_interactive_correct_first_try_scores_full_marks(): void {
        $q = test_question_maker::make_question('mojomatch', 'exactmatch');
        $q->penalty = 0.1;
        $this->start_attempt_at_question($q, 'interactive', 1);

        $this->process_submission(['answer' => 'cp', '-submit' => 1]);
        $this->resetDebugging();
        $this->check_current_state(question_state::$gradedright);
        $this->check_current_mark(1);
    }

    /**
     * interactive: penalty is floored at 0 - a correct answer never scores
     * negative even when penalty * wrong_tries exceeds the full mark.
     */
    public function test_interactive_penalty_is_clamped_at_zero(): void {
        $q = test_question_maker::make_question('mojomatch', 'exactmatch');
        $q->penalty = 0.6; // Two wrong tries would deduct 1.2 -> clamp to 0.
        $this->start_attempt_at_question($q, 'interactive', 1);

        $this->process_submission(['answer' => 'zz', '-submit' => 1]);
        $this->resetDebugging();
        $this->process_submission(['answer' => 'zzz', '-submit' => 1]);
        $this->resetDebugging();

        // Correct after 2 wrong: max(0, 1 - 0.6*2) = 0, still graded right.
        $this->process_submission(['answer' => 'cp', '-submit' => 1]);
        $this->resetDebugging();
        $this->check_current_state(question_state::$gradedright);
        $this->check_current_mark(0);
    }
}
