<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
// we will use question APIs
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

class local_importsoal_external extends external_api {

    /**
     * Parameter definitions for create_question
     */
    public static function create_question_parameters() {
        return new external_function_parameters(array(
            'categoryid' => new external_value(PARAM_INT, 'the question category id in Moodle'),
            'qtype' => new external_value(PARAM_TEXT, 'question type: multichoice|truefalse|shortanswer'),
            'name' => new external_value(PARAM_TEXT, 'short name for question'),
            'questiontext' => new external_value(PARAM_RAW, 'full question text (HTML allowed)'),
            'answers' => new external_multiple_structure(
                new external_single_structure(array(
                    'text' => new external_value(PARAM_RAW, 'answer text'),
                    'fraction' => new external_value(PARAM_FLOAT, 'fraction (100 for correct)'),
                )),
                'array of answers',
                VALUE_DEFAULT,
                array()
            ),
            'single' => new external_value(PARAM_BOOL, 'single correct answer? (multichoice)'),
            'shuffleanswers' => new external_value(PARAM_BOOL, 'shuffle answers?'),
        ));
    }

    public static function create_question($categoryid, $qtype, $name, $questiontext, $answers = array(), $single = true, $shuffleanswers = true) {
        global $USER, $DB, $CFG;

        // validate params
        $params = self::validate_parameters(self::create_question_parameters(),
            array(
                'categoryid' => $categoryid,
                'qtype' => $qtype,
                'name' => $name,
                'questiontext' => $questiontext,
                'answers' => $answers,
                'single' => $single,
                'shuffleanswers' => $shuffleanswers,
            )
        );

        // check capability - require manage questions capability in context of category's course
        if (!$category = $DB->get_record('question_categories', array('id' => $params['categoryid']))) {
            throw new invalid_parameter_exception('Category not found');
        }

        // determine the context from category
        $context = context::instance_by_id($category->contextid);
        self::validate_context($context);
        require_capability('moodle/question:manage', $context);

        // Build question definition object (using question_bank API)
        $qtypeplugin = question_bank::get_qtype($params['qtype']);
        if (!$qtypeplugin) {
            throw new invalid_parameter_exception('Unsupported question type');
        }

        // create question object structure expected by question_bank::save_question
        $question = new stdClass();
        $question->category = $params['categoryid'];
        $question->qtype = $params['qtype'];
        $question->name = $params['name'];
        $question->questiontext = array('text' => $params['questiontext'], 'format' => FORMAT_HTML);
        $question->generalfeedback = array('text' => '', 'format' => FORMAT_HTML);
        $question->defaultmark = 1;
        $question->length = 1;
        $question->stamp = make_unique_id_code();
        $question->version = 1;

        // specific to multichoice
        if ($params['qtype'] === 'multichoice') {
            // prepare options
            $question->single = $params['single'] ? 1 : 0;
            $question->shuffleanswers = $params['shuffleanswers'] ? 1 : 0;

            // answers array
            $question->answers = array();
            $question->options = new stdClass();
            $question->options->answers = array();

            foreach ($params['answers'] as $i => $a) {
                $aid = 'answer' . $i;
                $question->answers[$aid] = new stdClass();
                $question->answers[$aid]->answer = $a['text'];
                $question->answers[$aid]->fraction = (float)$a['fraction'];
                $question->answers[$aid]->feedback = '';
            }
        }

        // You must call question_bank::save_question to persist. This API expects a question object (maybe using editors)
        $newquestionid = question_bank::save_question($question, null);

        if (!$newquestionid) {
            throw new moodle_exception('couldnotcreatequestion', 'local_questionapi');
        }

        // Return created question id
        $result = array('questionid' => $newquestionid);
        return self::validate_returnvalue(new external_single_structure(
            array('questionid' => new external_value(PARAM_INT, 'new question id'))
        ), $result);
    }

    public static function add_question_to_quiz_parameters() {
        return new external_function_parameters(array(
            'quizid' => new external_value(PARAM_INT, 'the moodle quiz id'),
            'questionid' => new external_value(PARAM_INT, 'the moodle question id'),
            'maxmark' => new external_value(PARAM_FLOAT, 'max mark'),
        ));
    }

    public static function add_question_to_quiz($quizid, $questionid, $maxmark = 1.0) {
        global $DB;
        $params = self::validate_parameters(self::add_question_to_quiz_parameters(),
            array('quizid' => $quizid, 'questionid' => $questionid, 'maxmark' => $maxmark)
        );

        // validate quiz exists
        if (!$quiz = $DB->get_record('quiz', array('id' => $params['quizid']))) {
            throw new invalid_parameter_exception('Quiz not found');
        }

        $course = $DB->get_record('course', array('id' => $quiz->course));
        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('mod/quiz:manage', $context);

        // add question to quiz - use mod_quiz API
        // create structure for question usage if needed and insert question into quiz:
        // The recommended approach: use quiz_add_quiz_question
        quiz_add_quiz_question($params['quizid'], $params['questionid'], $params['maxmark']);

        return self::validate_returnvalue(new external_single_structure(
            array('status' => new external_value(PARAM_TEXT, 'ok'))
        ), array('status' => 'ok'));
    }

    public static function create_quiz_parameters() {
        return new external_function_parameters(array(
            'courseid' => new external_value(PARAM_INT, 'course id'),
            'name' => new external_value(PARAM_TEXT, 'quiz name'),
            'intro' => new external_value(PARAM_RAW, 'intro (HTML)'),
            'timelimit' => new external_value(PARAM_INT, 'timelimit in seconds', VALUE_DEFAULT, 0),
            'shufflequestions' => new external_value(PARAM_BOOL, 'shuffle questions', VALUE_DEFAULT, false),
            'shuffleanswers' => new external_value(PARAM_BOOL, 'shuffle answers', VALUE_DEFAULT, true),
        ));
    }

    public static function create_quiz($courseid, $name, $intro = '', $timelimit = 0, $shufflequestions = false, $shuffleanswers = true) {
        global $DB;
        $params = self::validate_parameters(self::create_quiz_parameters(),
            array(
                'courseid' => $courseid,
                'name' => $name,
                'intro' => $intro,
                'timelimit' => $timelimit,
                'shufflequestions' => $shufflequestions,
                'shuffleanswers' => $shuffleanswers
            )
        );

        if (!$course = $DB->get_record('course', array('id' => $params['courseid']))) {
            throw new invalid_parameter_exception('Course not found');
        }

        $context = context_course::instance($course->id);
        self::validate_context($context);
        require_capability('moodle/course:update', $context);

        // create quiz instance using mod_quiz API
        $quiz = new stdClass();
        $quiz->course = $params['courseid'];
        $quiz->name = $params['name'];
        $quiz->intro = $params['intro'];
        $quiz->timeopen = 0;
        $quiz->timeclose = 0;
        $quiz->timelimit = $params['timelimit'];
        $quiz->attempts = 1;
        $quiz->shuffleanswers = $params['shuffleanswers'] ? 1 : 0;
        $quiz->shufflequestions = $params['shufflequestions'] ? 1 : 0;
        $quiz->questionsperpage = 0;
        $quiz->preferredbehaviour = '';
        $quiz->questiondecimalpoints = 2;

        // insert into quiz table and add module to course (use quiz_add_instance)
        $quizid = quiz_add_instance($quiz, null);

        return self::validate_returnvalue(new external_single_structure(
            array('quizid' => new external_value(PARAM_INT, 'new quiz id'))
        ), array('quizid' => $quizid));
    }

    public static function get_categories_parameters() {
        return new external_function_parameters(array(
            'courseid' => new external_value(PARAM_INT, 'course id', VALUE_DEFAULT, 0)
        ));
    }

    public static function get_categories($courseid = 0) {
        global $DB;
        $params = self::validate_parameters(self::get_categories_parameters(), array('courseid' => $courseid));
        $result = array();

        if ($params['courseid']) {
            $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
            $context = context_course::instance($course->id);
        } else {
            $context = context_system::instance();
        }
        self::validate_context($context);
        require_capability('moodle/question:manage', $context);

        $categories = $DB->get_records('question_categories', ['contextid' => $context->id]);
        foreach ($categories as $cat) {
            $result[] = array('id' => $cat->id, 'name' => $cat->name);
        }
        return self::validate_returnvalue(new external_multiple_structure(
            new external_single_structure(array(
                'id' => new external_value(PARAM_INT, 'category id'),
                'name' => new external_value(PARAM_TEXT, 'category name')
            ))
        ), $result);
    }
}
