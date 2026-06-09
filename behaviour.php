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

defined('MOODLE_INTERNAL') || die();

/**
 * Question behaviour for interactive with multiple tries.
 *
 * The student can submit their response multiple times and get immediate feedback.
 * Based on the interactive behaviour but customized for TopoMojo integration.
 *
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class qbehaviour_mojomatch extends question_behaviour_with_multiple_tries {

    /** @var string The preferred behaviour for this attempt */
    protected $preferredbehaviour;

    /** @var int Cached max tries value */
    protected $maxtries = null;

    public function __construct(question_attempt $qa, $preferredbehaviour) {
        parent::__construct($qa, $preferredbehaviour);
        $this->preferredbehaviour = $preferredbehaviour;
    }

    /**
     * Get the maximum number of tries allowed for this question.
     * Returns the 'submissions' setting from the TopoMojo activity.
     * 0 = unlimited tries.
     */
    public function get_max_tries() {
        // Return cached value if already looked up
        if ($this->maxtries !== null) {
            return $this->maxtries;
        }

        // Get the topomojo activity setting via global lookup
        // The question belongs to a topomojo activity context
        global $DB, $PAGE;

        // Try to get context from PAGE (when rendering challenge page)
        $context = $PAGE->context;

        if ($context && $context->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('topomojo', $context->instanceid);
            if ($cm) {
                $topomojo = $DB->get_record('topomojo', ['id' => $cm->instance]);
                if ($topomojo) {
                    // Cache and return
                    $this->maxtries = (int)$topomojo->submissions;
                    return $this->maxtries;
                }
            }
        }

        // Default: unlimited tries
        $this->maxtries = 0;
        return 0;
    }

    /**
     * Adjust the fraction for penalty.
     * Applies the penalty from the question's penalty field (read from TopoMojo challenge JSON).
     * Penalty is deducted for each incorrect attempt beyond the first.
     */
    /**
     * Whether penalties apply under the activity's configured behaviour.
     *
     * Penalty is a multiple-try concept: it only makes sense when the student
     * can submit a question more than once. Single-shot modes (deferred and
     * immediate feedback) must never apply a penalty, and adaptivenopenalty
     * disables it by definition.
     *
     * @return bool
     */
    protected function penalty_applies() {
        $penalised = ['interactive', 'interactivecountback', 'adaptive'];
        return in_array($this->preferredbehaviour, $penalised, true);
    }

    /**
     * Whether the activity behaviour grades on a per-question Check button.
     *
     * Interactive, immediate-feedback and adaptive modes grade as soon as the
     * student submits a question. Deferred feedback does not - the student only
     * saves responses and everything is graded when the attempt is finished.
     *
     * @return bool
     */
    public function grades_on_check() {
        $immediate = ['interactive', 'interactivecountback', 'immediatefeedback', 'adaptive'];
        return in_array($this->preferredbehaviour, $immediate, true);
    }

    /**
     * Whether the behaviour allows multiple tries per question.
     *
     * Only interactive/adaptive modes keep a question active for further tries
     * after a wrong answer. Immediate feedback grades exactly once.
     *
     * @return bool
     */
    protected function allows_retries() {
        $multitry = ['interactive', 'interactivecountback', 'adaptive'];
        return in_array($this->preferredbehaviour, $multitry, true);
    }

    public function adjust_fraction($fraction, question_attempt_pending_step $pendingstep) {
        // Only penalise under behaviours that allow multiple tries. Deferred and
        // immediate feedback give a single attempt, so no penalty applies;
        // adaptivenopenalty disables penalties by definition.
        if (!$this->penalty_applies()) {
            return $fraction;
        }

        // Apply a cumulative penalty for each wrong try already used, matching
        // standard Moodle interactive behaviour. This applies even when the
        // current answer is correct: a correct answer after N wrong tries
        // scores (fraction - penalty * N), floored at 0.
        //
        // adjust_fraction() runs in process_submit() BEFORE the current try's
        // _try var is incremented, so get_last_behaviour_var('_try') returns
        // the number of tries already used prior to this submission - exactly
        // the multiplier we want.
        $prevtries = $this->qa->get_last_behaviour_var('_try', 0);

        if ($prevtries > 0) {
            $penalty = $this->question->penalty * $prevtries;
            $fraction = max(0, $fraction - $penalty);
        }

        return $fraction;
    }

    public function is_compatible_question(question_definition $question) {
        return $question instanceof question_automatically_gradable;
    }

    public function get_min_fraction() {
        return $this->question->get_min_fraction();
    }

    public function get_expected_data() {
        // Only expose the per-question Check button ('submit') for modes that
        // grade immediately. Deferred feedback saves responses and grades at
        // finish, so it must not offer a Check button.
        if ($this->grades_on_check() && $this->qa->get_state()->is_active()) {
            return array(
                'answer' => PARAM_RAW_TRIMMED,
                'submit' => PARAM_BOOL,
            );
        }
        return parent::get_expected_data();
    }

    public function get_right_answer_summary() {
        global $PAGE;
        if ($PAGE->pagetype != 'question-bank-previewquestion-preview') {
                $answer = $this->question->get_rightanswer_topomojo($this->qa);
        } else {
                $answer = $this->question->get_right_answer_summary();
        }
        return $answer;
    }

    public function get_state_string($showcorrectness) {
        $state = $this->qa->get_state();

        // If question is active and has been graded, show tries remaining (matches standard interactive)
        if ($state->is_active() && $state == question_state::$todo) {
            $laststep = $this->qa->get_last_step();
            if ($laststep->has_behaviour_var('_try')) {
                $current_try = $laststep->get_behaviour_var('_try');
                $max_tries = $this->get_max_tries();

                if ($max_tries > 0) {
                    $tries_left = $max_tries - $current_try;
                    return get_string('triesremaining', 'qbehaviour_mojomatch', $tries_left);
                }
            }
        }

        // Otherwise use parent behavior
        return parent::get_state_string($showcorrectness);
    }

    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        } else if ($pendingstep->has_behaviour_var('submit')) {
            // Process Check button (immediate feedback like TopoMojo)
            return $this->process_submit($pendingstep);
        } else if ($pendingstep->has_behaviour_var('comment')) {
            return $this->process_comment($pendingstep);
        } else {
            return $this->process_save($pendingstep);
        }
    }

    public function process_submit(question_attempt_pending_step $pendingstep) {
        // Interactive mode: Check button grades immediately
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$invalid);
        } else {
            $response = $pendingstep->get_qt_data();
            list($rawfraction, $state) = $this->question->grade_response_qa($response, $this->qa);

            // Determine correctness from the RAW grade, before any penalty is
            // applied. A correct answer after wrong tries is penalized below but
            // must still be treated as correct for state/try purposes.
            $iscorrect = ($rawfraction >= 1);

            // Apply cumulative penalty for prior wrong tries (may reduce a
            // correct answer's score, e.g. 1 - 0.1*2 = 0.80).
            $fraction = $this->adjust_fraction($rawfraction, $pendingstep);
            $pendingstep->set_fraction($fraction);

            if (!$iscorrect && $this->allows_retries()) {
                // Multi-try modes (interactive/adaptive): keep the question
                // active for another try if tries remain.
                $prevtries = $this->qa->get_last_behaviour_var('_try', 0);
                $newtry = $prevtries + 1;
                $pendingstep->set_behaviour_var('_try', $newtry);

                // Check if more tries available
                $max_tries = $this->get_max_tries();
                debugging("Question {$this->qa->get_slot()}: try {$newtry}/{$max_tries}, fraction {$fraction}", DEBUG_DEVELOPER);

                if ($max_tries == 0 || $newtry < $max_tries) {
                    // More tries available - keep question active in todo state (matches standard interactive behavior)
                    $pendingstep->set_state(question_state::$todo);
                    debugging("Question {$this->qa->get_slot()}: keeping active, more tries available", DEBUG_DEVELOPER);
                } else {
                    // No more tries - mark as finished wrong
                    $pendingstep->set_state(question_state::$gradedwrong);
                    debugging("Question {$this->qa->get_slot()}: max tries reached, marking finished", DEBUG_DEVELOPER);
                }
            } else if (!$iscorrect) {
                // Single-try modes (immediate feedback): one grade, then done.
                $pendingstep->set_state(question_state::$gradedwrong);
                debugging("Question {$this->qa->get_slot()}: wrong answer, single-try mode, marking finished", DEBUG_DEVELOPER);
            } else {
                // Correct answer - mark as finished right
                $pendingstep->set_state(question_state::$gradedright);
                debugging("Question {$this->qa->get_slot()}: correct answer, marking finished right", DEBUG_DEVELOPER);
            }

            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }
        return question_attempt::KEEP;
    }

    /*
     * Like the parent method, except that when a respones is gradable, but not
     * completely, we move it to the invalid state.
     *
     * TODO refactor, to remove the duplication.
     */
    public function process_save(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        } else if (!$this->qa->get_state()->is_active()) {
            throw new coding_exception('Question is not active, cannot process_actions.');
        }

        if ($this->is_same_response($pendingstep)) {
            return question_attempt::DISCARD;
        }

        if ($this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$complete);
        } else if ($this->question->is_gradable_response($pendingstep->get_qt_data())) {
            $pendingstep->set_state(question_state::$invalid);
        } else {
            $pendingstep->set_state(question_state::$todo);
        }
        return question_attempt::KEEP;
    }

    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);
        } else if ($step->has_behaviour_var('finish')) {
            return $this->summarise_finish($step);
        } else {
            return $this->summarise_save($step);
        }
    }

    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $response = $this->qa->get_last_step()->get_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);
        } else {
            //list($fraction, $state) = $this->question->grade_response($response);
            list($fraction, $state) = $this->question->grade_response_qa($response, $this->qa);
            // Apply the same cumulative penalty as the Check button, so finishing
            // the quiz with a correct answer after wrong tries is penalized
            // identically (e.g. correct after 2 wrong tries = 1 - 0.1*2 = 0.80).
            $fraction = $this->adjust_fraction($fraction, $pendingstep);
            $pendingstep->set_fraction($fraction);
            $pendingstep->set_state($state);
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }
}
