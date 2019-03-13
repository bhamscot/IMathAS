<?php
/*
 * IMathAS: Assessment Settings Class
 * (c) 2019 David Lippman
 */

require_once('./AssessUtils.php');
require_once('../filter/filter.php');
require_once('../assessment/displayq2.php');

/**
 * Primary class for working with assessment records
 */
class AssessRecord
{
  private $DBH = null;
  private $curUid = null;
  private $curAid = null;
  private $assessRecord = null;
  private $assess_info = null;
  private $hasRecord = false;
  private $scoredData = null;
  private $practiceData = null;
  private $in_practice = false;
  private $status = 'no_record';
  private $now = 0;
  private $penalties = array();

  /**
   * Construct object
   * @param object $DBH PDO Database Handler
   * @param object $assess_info  AssessInfo instance
   */
  function __construct($DBH, $assess_info = null) {
    $this->DBH = $DBH;
    $this->assess_info = $assess_info;
    $this->curAid = $assess_info->getSetting('id');
    $this->now = time();
  }

  /**
   * Load an assessment record given the user id and assessment id.
   * @param  integer $userid  The user ID
   * @return void
   */
  public function loadRecord($userid) {
    $this->curUid = $userid;
    $stm = $this->DBH->prepare("SELECT * FROM imas_assessment_records WHERE userid=? AND assessmentid=?");
    $stm->execute(array($userid, $this->curAid));
    $this->assessRecord = $stm->fetch(PDO::FETCH_ASSOC);
    if ($this->assessRecord === false) {
      $this->hasRecord = false;
      $this->assessRecord = null;
    } else {
      $this->hasRecord = true;
    }
  }

  /**
   * Sets whether we're working in practice mode
   * @param boolean $in_practice  True if working in practice mode
   */
  public function setInPractice($in_practice) {
    $this->in_practice = $in_practice;
  }

  /**
   * Save the record to the database
   * @param  boolean $saveScored   Whether to save scored data (def: true)
   * @param  boolean $savePractice Whether to save practice data (def: false)
   * @return void
   */
  public function saveRecord($saveScored = true, $savePractice = false) {
    if ($this->curUid === null || $this->curAid === null) {
      // bail if the userid isn't set
      return false;
    }
    $qarr = array();
    $fields = array('lti_sourcedid', 'timeontask', 'starttime', 'lastchange',
                    'score', 'status');
    foreach ($fields as $field) {
      $qarr[':'.$field] = $this->assessRecord[$field];
    }
    if ($saveScored && $this->scoredData !== null) {
      $fields[] = 'scoreddata';
      $qarr[':scoreddata'] = gzencode(json_encode($this->scoredData));
    }
    if ($savePractice && $this->practiceData !== null) {
      $fields[] = 'practicedata';
      $qarr[':practicedata'] = gzencode(json_encode($this->practiceData));
    }

    if ($this->hasRecord) {
      // updating existing record
      $sets = array();
      foreach ($fields as $field) {
        $sets[] = $field.'=:'.$field;
      }
      $setlist = implode(',', $sets);
      $query = "UPDATE imas_assessment_records SET $setlist WHERE ";
      $query .= 'assessmentid=:aid AND ';
      $qarr[':aid'] = $this->curAid;
      if ($this->assessRecord['agroupid'] > 0) {
        $query .= "agroupid=:agroupid";
        $qarr[':agroupid'] = $this->assessRecord['agroupid'];
      } else {
        $query .= "userid=:userid";
        $qarr[':userid'] = $this->curUid;
      }
      $stm = $this->DBH->prepare($query);
      $stm->execute($qarr);
    } else {

    }
  }

  /**
   * Create a new record in the database.  Call after loadRecord.
   *
   * @param  array   $users         Array of users to create record for.
   *                                If false, current userid will be used. (def: false)
   * @param  int     $stugroupid    The stugroup ID, or 0 if not group (def: 0)
   * @param  boolean $recordStart   true to record the starttime now (def: true)
   * @param  string  $lti_sourcedid The LTI sourcedid (def: '')
   * @return void
   */
  public function createRecord($users = false, $stugroupid = 0, $recordStart = true, $lti_sourcedid = '') {
    // if group, lookup group members. Otherwise just use current user
    if ($users === false) {
      $users = array($this->curUid);
    }

    //initiale a blank record
    $this->assessRecord = array(
      'assessmentid' => $this->curAid,
      'userid' => $this->curUid,
      'agroupid' => $stugroupid,
      'lti_sourcedid' => $lti_sourcedid,
      'ver' => 2,
      'timeontask' => 0,
      'starttime' => $recordStart ? $this->now : 0,
      'lastchange' => 0,
      'score' => 0,
      'status' => 0,  // overridden later
      'scoreddata' => '',
      'practicedata' => ''
    );

    // initialize scoredData
    $this->buildAssessData(false, $recordStart);
    // if in practice, initialize practiceData
    if ($this->in_practice) {
      $this->buildAssessData(true, $recordStart);
    }

    // Save to Database
    $qarr = array();
    $vals = array();
    $scoredtosave = ($this->scoredData !== null) ? gzencode(json_encode($this->scoredData)) : '';
    $practicetosave = ($this->practiceData !== null) ? gzencode(json_encode($this->practiceData)) : '';
    foreach ($users as $uid) {
      $vals[] = '(?,?,?,?,?,?,?,?,?)';
      array_push($qarr,
        $uid,
        $this->curAid,
        $stugroupid,
        ($uid==$this->curUid) ? $lti_sourcedid : '',
        $recordStart ? $this->now : 0,
        2,
        $this->assessRecord['status'],
        $scoredtosave,
        $practicetosave
      );
    }
    $query = 'INSERT INTO imas_assessment_records (userid, assessmentid,
      agroupid, lti_sourcedid, starttime, ver, status, scoreddata, practicedata)
      VALUES '.implode(',', $vals);
    $stm = $this->DBH->prepare($query);
    $stm->execute($qarr);

    $this->hasRecord = true;
  }

  /**
   * Build scoredData or practiceData from scratch
   * @param  boolean $ispractice  True if generating practiceData (def: false)
   * @param  boolean $recordStart True to record starttime now
   * @return void
   */
  public function buildAssessData($ispractice = false, $recordStart = true) {
    if ($ispractice && $this->practiceData !== null) {
      return false;
    } else if (!$ispractice && $this->scoredData !== null) {
      return false;
    }
    $data = array(
      'submissions' => array(),
      'autosaves' => array(),
      'scored_version' => 0,
      'assess_versions' => array()
    );
    if ($ispractice) {
      $this->practiceData = $data;
    } else {
      $this->scoredData = $data;
    }
    $this->buildNewAssessVersion($inpractice, $recordStart);
  }

  /**
   * Build a new assess_versions record in scoredData / practiceData
   * @param  boolean $ispractice  True if building practice data
   * @param  boolean $recordStart True to record starttime now
   * @return void
   */
  public function buildNewAssessVersion($ispractice = false, $recordStart = true) {
    if ($ispractice) {
      $this->parsePractice();
      $attempt = count($this->practiceData['assess_versions']);
    } else {
      $this->parseScored();
      $attempt = count($this->scoredData['assess_versions']);
    }
    // build base framework
    $out = array(
      'starttime' => $recordStart ? $this->now : 0,
      'lastchange' => 0,
      'status' => 0,
      'score' => 0,
      'questions' => array()
    );

    if ($recordStart && $this->assess_info->getSetting('timelimit') > 0) {
      //recording start and has time limit, so record end time
      $out['timelimit_end'] = $this->now + $this->assess_info->getAdjustedTimelimit();
    }

    // generate the questions and seeds
    list($oldquestions, $oldseeds) = $this->getOldQuestions($ispractice);
    list($questions, $seeds) = $this->assess_info->assignQuestionsAndSeeds($ispractice, $attempt);
    // build question data
    for ($k = 0; $k < count($questions); $k++) {
      $out['questions'][] = array(
        'score' => 0,
        'rawscore' => 0,
        'scored_version' => 0,
        'question_versions' => array(
          array(
            'qid' => $questions[$k],
            'seed' => $seeds[$k],
            'tries' => array()
          )
        )
      );
    }
    if ($ispractice) {
      $this->practiceData['assess_versions'][] = $out;
    } else {
      $this->scoredData['assess_versions'][] = $out;
    }
    $this->setStatus(true, $ispractice);
  }

  /**
   * Get old questions and seeds previously used in assessment record
   * @param  boolean $inpractice    True if in practice mode, to get practice data (def: false)
   * @return array array($questions, $seeds), where each is an array of values
   */
  public function getOldQuestions($ispractice = false) {
    $questions = array();
    $seeds = array();
    if ($ispractice) {
      $this->parsePractice();
      $data = $this->practiceData;
    } else {
      $this->parseScored();
      $data = $this->scoredData;
    }
    if ($data !== null) {
      foreach ($data['assess_versions'] as $ver) {
        foreach ($ver['question_versions'] as $qver) {
          $questions[] = $qver['qid'];
          $seeds[] = $qver['seed'];
        }
      }
    }
    return array($questions, $seeds);
  }

  /**
   * Returns whether an assessment record exists. Call after loadRecord
   * @return boolean true if an assessment record exists
   */
  public function hasRecord() {
    return ($this->hasRecord);
  }

  /**
   * Determine if there is an active assessment attempt
   * @param boolean $ispractice  True if looking at practice attempts (def: false)
   * @return boolean true if there is an active assessment attempt
   */
  public function hasActiveAttempt($ispractice = false) {
    if (empty($this->assessRecord)) {
      //no assessment record at all
      return false;
    }
    if ($ispractice) {
      return (($this->assessRecord['status']&16) !== 0);
    } else {
      // status has bitwise 1: active by-assess attempt
      // status has bitwise 2: active by-question attempt
      return (($this->assessRecord['status']&3) !== 0);
    }
  }

  /**
   * Sets overall status as active/not
   * @param boolean $active     Set true to mark as active
   * @param boolean $ispractice Set true if practice (def: false)
   * @return void
   */
  public function setStatus($active, $ispractice = false) {
    if (empty($this->assessRecord)) {
      //no assessment record at all
      return false;
    }
    if ($ispractice) {
      if ($active) {
        $this->assessRecord['status'] |= 16;
      } else {
        $this->assessRecord['status'] = $this->assessRecord['status'] & ~16;
      }
    } else {
      // turn off both
      $this->assessRecord['status'] = $this->assessRecord['status'] & ~3;
      // status has bitwise 1: active by-assess attempt
      // status has bitwise 2: active by-question attempt
      if ($active) {
        $submitby = $this->assess_info->getSetting('submitby');
        if ($submitby == 'by_assessment') {
          $this->assessRecord['status'] |= 1;
        } else if ($submitby == 'by_question') {
          $this->assessRecord['status'] |= 2;
        }
      }
    }
  }

  /**
   * Determine if there is an unsubmitted assessment attempt
   * This includes not-yet-opened assessment attempts
   * @param boolean $ispractice  True if looking at practice attempts (def: false)
   * @return boolean true if there is an unsubmitted assessment attempt
   */
  public function hasUnsubmittedAttempt($ispractice = false) {
    if (empty($this->assessRecord)) {
      //no assessment record at all
      return false;
    }
    if ($ispractice) {
      $this->parsePratice();
      $data = $this->practiceData;
    } else {
      $this->parseScored();
      $data = $this->scoredData;
    }
    if ($data === null) {
      return false;
    }
    if (count($data['assess_versions']) == 0) {
      return false;
    }
    $last_attempt = $data['assess_versions'][count($data['assess_versions'])-1];
    return ($last_attempt['status'] === 0);
  }

  /**
   * Check whether user is allowed to create a new assessment version
   * @param  boolean $is_practice True if in practice mode
   * @return boolean              True if OK to create a new assess version
   */
  public function canMakeNewAttempt($is_practice) {
    if ($is_practice) {
      // if in practice, can make new if we don't have one
      return ($this->practiceData === null);
    } else {
      if ($this->scoredData === null) {
        // if no data, can make new
        return true;
      }
      $this->parseScored();
      $submitby = $this->assess_info->getSetting('submitby');
      $prev_attempt_cnt = count($this->scoredData['assess_versions']);
      // if by-question, then we can if we have no versions yet
      if ($submitby == 'by_question' && $prev_attempt_cnt == 0) {
        return true;
      }
      if ($submitby == 'by_assessment') {
        $allowed = $this->assess_info->getSettings('allowed_attempts');
        if ($prev_attempt_cnt < $allowed) {
          return true;
        }
      }
    }
    return false;
  }


  /**
   * Get data on submitted assessment attempts
   *
   * @param boolean $includeScores  Whether to include scores. Default: false
   * @return array        An array of previous attempt info.  Each element is an
   *                      array containing key 'date', and 'score' if the
   *                      settings allow it
   */
  public function getSubmittedAttempts($includeScores = false) {
    if (empty($this->assessRecord)) {
      //no assessment record at all
      return array();
    }
    $this->parseScored();

    $out = array();
    foreach ($this->scoredData['assess_versions'] as $k=>$ver) {
      if ($ver['status'] == 1) {  // if it's a submitted version
        $out[$k] = array(
          'date' => $ver['lastchange'],
        );
        if ($includeScores) {
          $out[$k]['score'] = $ver['score'];
        }
      }
    }
    return $out;
    //TODO:  Need to report teacher score override somehow
  }

  /**
   * Get the scored attempt version and score
   * @return array  'kept': version # or 'override' if instructor override
   *                        may not be set if using average
   *                'score': the final assessment score
   */
  public function getScoredAttempt() {
    if (empty($this->assessRecord)) {
      //no assessment record at all
      return array();
    }
    $this->parseScored();

    $out = array('score' => $this->assessRecord['score']);
    if (isset($this->scoredData['scoreoverride'])) {
      $out['kept'] = 'override';
    } else if (isset($this->scoredData['scored_version'])) {
      $out['kept'] = $this->scoredData['scored_version'];
    }
    return $out;
  }

  /**
   * Get group members. Only works if group record is loaded.
   *
   * @return array        An array of group member names
   */
  public function getGroupMembers() {
    if (empty($this->assessRecord)) {
      //no assessment record at all
      return array();
    }
    $names = AssessUtils::getGroupMembersByGroupId($this->assessRecord['agroupid']);

    return $names;
  }

  /**
   * Get the timestamp for when the current attempt time limit expires
   *
   * @return integer  timestamp for when  the current attempt time limit expires
   *                  will be 0 if there is no time limit
   */
  public function getTimeLimitExpires() {
    if (empty($this->assessRecord)) {
      return false;
    }
    $this->parseScored();
    if (count($this->scoredData['assess_versions']) == 0) {
      return false;
    }
    //grab value from last (current) assess attempt
    $lastvernum = count($this->scoredData['assess_versions']) - 1;
    $lastver = $this->scoredData['assess_versions'][$lastvernum];
    return $lastver['timelimit_end'];
  }


  /**
   * Generate the question API object
   * @param  int  $qn            Question number (0 indexed)
   * @param  boolean $is_practice Whether to use practice data (def: false)
   * @param  boolean $include_scores Whether to include scores (def: false)
   * @param  boolean $include_parts  True to include part scores and details, false for just total score (def false)
   * @param  boolean $generate_html Whether to generate question HTML (def: false)
   * @param int  $ver               Which version to grab data for, or 'last' for most recent
   * @return array  The question object
   */
  public function getQuestionObject($qn, $is_practice = false, $include_scores = false, $include_parts = false, $generate_html = false, $ver = 'last') {
    $by_question = ($this->assess_info->getSetting('submitby') == 'by_question');
    $due_date = $this->assess_info->getSetting('original_enddate');

    // get data structure for this question
    $aver = $this->getAssessVer($is_practice, $ver);
    $question_versions = $aver['questions'][$qn]['question_versions'];
    if (!$by_question || $ver === 'last') {
      $curq = $question_versions[count($question_versions) - 1];
    } else {
      $curq = $question_versions[$ver];
    }

    if ($is_practice) {
      $data = &$this->practiceData;
    } else {
      $data = &$this->scoredData;
    }

    // get basic settings
    $out = $this->assess_info->getQuestionSettings($curq['qid']);

    // get regen number, and gradebook score for by_question
    if ($by_question) {
      if ($ver === 'last') {
        $regen = count($aver['questions'][$qn]['question_versions']);
      } else {
        $regen = $ver;
      }
      $out['regen'] = $regen;
      $out['gbscore'] = $aver['questions'][$qn]['score'];
      $out['gbrawscore'] = $aver['questions'][$qn]['rawscore'];
    } else {
      if ($ver === 'last') {
        $regen = count($data['assess_versions']);
      } else {
        $regen = $ver;
      }
    }

    // set tries
    $parts = array();
    $score = -1;
    $try = 0;
    $status = 'unattempted';
    if (count($curq['tries']) == 0) {
      // no tries yet
      $parts[0] = array('try' => 0);
      if ($include_scores) {
        $parts[0]['score'] = 0;
        $parts[0]['rawscore'] = 0;
      }
    } else {
      // treat everything like multipart
      $try = 1e10;
      $score = 0;
      $raw = 0;
      $status = 'attempted';
      if ($include_scores) {
        list($score, $raw, $parts) = $this->getQuestionPartScores($qn, $is_practice, $ver, 'last');
      } else if ($include_parts) {
        $answeights = isset($curq['answeights']) ? $curq['answeights'] : array(1);
        $answeightTot = array_sum($answeights);
      }
      for ($pn = 0; $pn < count($curq['tries']); $pn++) {
        // get part details
        $parttry = count($curq['tries'][$pn]);
        $try = min($try, $parttry);
        if ($parttry === 0) {
          // if any parts are unattempted, mark question as such
          $status = 'unattempted';
        }
        if ($include_scores) {
          if ($status != 'unattempted') {
            if ($parts[$pn]['rawscore'] > .99) {
              $status = ($status === 'incorrect' || $status === 'partial') ? 'partial': 'correct';
            } else if ($parts[$pn]['rawscore'] < .01) {
              $status = ($status === 'correct' || $status === 'partial') ? 'partial': 'incorrect';
            } else {
              $status = 'partial';
            }
          }
        } else if ($include_parts) {
          $parts = array(
            'try' => $parttry,
            'points_possible' => $out['points_possible'] * $answeights[$pn]/$answeightTot
          );
        }
      }
    }
    $out['try'] = $try;
    if ($include_parts) {
      $out['parts'] = $parts;
    }
    $out['status'] = $status;
    if ($include_scores && $score != -1) {
      $out['score'] = $score;
      // TODO:  Do we want to return score saved in gb too?
    }

    if ($generate_html) {
      list($out['html'], $out['answeights']) = $this->getQuestionHtml($qn, $is_practice, $ver);
    } else {
      $out['html'] = null;
    }

    return $out;
  }

  /**
   * Generate the question API object for all questions
   * @param  boolean $is_practice Whether to use practice data (def: false)
   * @param  boolean $include_scores Whether to include scores (def: false)
   * @param  boolean $include_parts  True to include part scores and details, false for just total score (def false)
   * @param  boolean $generate_html Whether to generate question HTML (def: false)
   * @param int  $ver               Which version to grab data for, or 'last' for most recent
   * @return array  The question object
   */
  public function getAllQuestionObjects($is_practice = false, $include_scores = false, $include_parts = false, $generate_html = false, $ver = 'last') {
    $out = array();
    // get data structure for current version
    $assessver = $this->getAssessVer($is_practice, $ver);
    for ($qn = 0; $qn < count($assessver['questions']); $qn++) {
      $out[$qn] = $this->getQuestionObject($qn, $is_practice, $include_scores, $include_parts, $generate_html, $ver);
    }
    return $out;
  }

  /**
   * get question part score details
   * @param  int  $qn             The question number
   * @param  boolean $is_practice Whether using practice data
   * @param  string  $ver         The attempt to use, or 'last' for most recent
   * @param  string  $try         'last' for last try, or 'all' to pick best try per-part
   * @return array (question Score, question Rawscore, parts details)
   *    Where part details is array by part, with (try, score, rawscore, penalties, points_possible)
   */
  public function getQuestionPartScores ($qn, $is_practice, $ver = 'last', $try = 'last') {
    $by_question = ($this->assess_info->getSetting('submitby') == 'by_question');
    $due_date = $this->assess_info->getSetting('original_enddate');
    $starttime = $this->assessRecord['starttime'];

    if ($is_practice) {
      $assessver = $this->practiceData['assess_versions'][0];
    } else {
      if ($by_question || $ver === 'last') {
        $assessver = $this->scoredData['assess_versions'][count($this->scoredData['assess_versions']) - 1];
        if (!$by_question) {
          $regen = count($this->scoredData['assess_versions']);
        }
      } else {
        $assessver = $this->scoredData['assess_versions'][$ver];
        $regen = $ver;
      }
    }

    // get data structure for this question
    $question_versions = $assessver['questions'][$qn]['question_versions'];
    if (!$by_question || $ver === 'last') {
      $qver = $question_versions[count($question_versions) - 1];
      if ($by_question) {
        $regen = count($question_versions);
      }
    } else {
      $qver = $question_versions[$ver];
      $regen = $ver;
    }

    $qsettings = $this->assess_info->getQuestionSettings($qver['qid']);
    $this->penalties = array(
      'retry_penalty' => $qsettings['retry_penalty'],
      'retry_penalty_after' => $qsettings['retry_penalty_after'],
      'regen_penalty' => $qsettings['regen_penalty'],
      'regen_penalty_after' => $qsettings['regen_penalty_after'],
      'exceptionpenalty' => $this->assess_info->getSetting('exceptionpenalty')
    );
    $answeights = isset($qver['answeights']) ? $qver['answeights'] : array(1);
    $answeightTot = array_sum($answeights);
    $partscores = array_fill(0, count($answeights), 0);
    $partrawscores = array_fill(0, count($answeights), 0);
    $parts = array();
    // loop over each part
    for ($pn = 0; $pn < count($qver['tries']); $pn++) {
      $max = count($qver['tries'][$pn]) - 1;
      if ($max == -1) {
        // no tries yet
        $parts[$pn] = array(
          'try' => 0,
          'points_possible' => $qsettings['points_possible'] * $answeights[$pn]/$answeightTot
        );
        continue;
      }
      if ($try === 'last') {
        $min = $max;
      } else {
        $min = 0;
      }
      $penaltyList = array();
      for ($pa = $min; $pa <= $max; $pa++) {
        $parttry = $qver['tries'][$pn][$pa];
        if ($parttry['raw'] > 0) {
          list($scoreAfterPenalty,$penaltyList) = $this->scoreAfterPenalty(
            $parttry['raw'],   // score
            $qsettings['points_possible'] * $answeights[$pn]/$answeightTot,
                                // points possible
            $pa,                 // the try number
            $regen,             // the regen number
            $due_date,           // the due date
            $starttime + $data['submissions'][$parttry['sub']], // submission time
            true
          );
          if ($scoreAfterPenalty > $partscores[$pn]) {
            $partscores[$pn] = $scoreAfterPenalty;
            $partrawscores[$pn] = $parttry['raw']*1;
          }
        }
      }
      $parts[$pn] = array(
        'try' => count($qver['tries'][$pn]),
        'score' => $partscores[$pn],
        'rawscore' => $partrawscores[$pn],
        'penalties' => $penaltyList,
        'points_possible' => $qsettings['points_possible'] * $answeights[$pn]/$answeightTot
      );
    }
    $qScore = array_sum($partscores);
    $qRawscore = array_sum($partrawscores);
    return array($qScore, $qRawscore, $parts);
  }

  /**
   * generate the question HTML
   * @param  int  $qn               Question #
   * @param  boolean $is_practice   Whether to use practice data
   * @param  string  $ver           Version to use, 'last' for current (def: 'last')
   * @param  boolean $clearans      true to clear answer (def: false)
   * @param  boolean $force_scores  force display of scores (def: false)
   * @param  boolean $force_answers force display of answers (def: false)
   * @return array (questionhtml, answeights)
   */
  public function getQuestionHtml($qn, $is_practice=false, $ver = 'last', $clearans = false, $force_scores = false, $force_answers = false) {
    // get assessment attempt data for given version
    $qver = $this->getQuestionVer($qn, $is_practice, $ver);
    // get the question settings
    $qsettings = $this->assess_info->getQuestionSettings($qver['qid']);
    $showscores = ($force_scores || ($this->assess_info->getSetting('showscores') === 'during'));
    // see if there is autosaved answers to redisplay
    $autosave = $this->getAutoSaves($qn, $is_practice);

    $partattemptn = array();
    $qcolors = array();
    $lastans = array();
    $showansparts = array();
    $showans = true;
    $trylimit = $qsettings['tries_max'];

    for ($pn = 0; $pn < count($qver['tries']); $pn++) {
      // figure out try #
      $partattemptn[$pn] = count($qver['tries'][$pn]);
      if ($clearans) {
        $lastans[$pn] = '';
      } else if (isset($autosave['stuans'][$pn])) {
        $lastans[$pn] = $autosave['stuans'][$pn];
      } else if ($partattemptn[$pn] > 0) {
        $lastans[$pn] = $qver['tries'][$pn][$partattemptn[$pn] - 1]['stuans'];
      } else {
        $lastans[$pn] = '';
      }

      // figure out if we should show answers
      if ($force_answers) {
        $showansparts[$pn] = true;
      } else if ($qsettings['showans'] === 'after_lastattempt' && $partattemptn[$pn] === $trylimit) {
        $showansparts[$pn] = true;  // show after last attempt
      } else if ($qsettings['showans'] === 'with_score' && $showscores && $partattemptn[$pn] > 0) {
        $showansparts[$pn] = true; // show with score
      } else if ($qsettings['showans'] === 'after_n' && $partattemptn[$pn] > $qsettings['showans_aftern']) {
        $showansparts[$pn] = true; // show after n attempts
      } else {
        $showansparts[$pn] = false;
        $showans = false;
      }
      if ($showscores && $partattemptn[$pn] > 0) {
        $qcolors[$pn] = $qver['tries'][$pn][$partattemptn[$pn] - 1]['score'];
      }
    }
    $attemptn = (count($partattemptn) == 0) ? 0 : min($partattemptn);

    // TODO: move this to displayq input
    // TODO: pass stuanswers, stuanswersval
    $GLOBALS['qdatafordisplayq'] = $this->assess_info->getQuestionSetData($qsettings['questionsetid']);
    $qout = displayq(
        $qn,                            // question number
        $qsettings['questionsetid'],    // questionset ID
        $qver['seed'],                  // seed
        $showans,                       // whether to show answers
        $qsettings['showhints'],        // whether to show hints
        $attemptn,                      // the attempt number //TODO: make by-part
        true,                           // return question text rather than echo
        $clearans,                      // whether to clear last ans //TODO: move here
        false,                          // seqinactive //TODO: deprecate
        $qcolors                        // array of part scores for score marking
    );
    // need to extract answeights to provide to frontend
    $answeights = array(1);
    return array($qout, $answeights);
  }

  /**
   * Add a new submission record
   * @param int $time   The current timestamp
   * @param boolean $is_practice   If practice submission
   * @return int  submission number, to record with try data
   */
  public function addSubmission($time, $is_practice=false) {
      $seconds = $time - $this->assessRecord['starttime'];
      if ($is_practice) {
        $this->practiceData['submissions'][] = $seconds;
        return count($this->practiceData['submissions']) - 1;
      } else {
        $this->scoredData['submissions'][] = $seconds;
        return count($this->scoredData['submissions']) - 1;
      }
  }

  /**
   * Score a question
   * @param  int  $qn             Question number
   * @param  int  $submission     The submission number, from addSubmission
   * @param  array $parts_to_score  an array, true if part is to be scored/recorded
   * @param  boolean $is_practice True if recording as practice
   * @return void
   */
  public function scoreQuestion($qn, $submission, $parts_to_score=true, $is_practice=false) {
    $qver = $this->getQuestionVer($qn, $is_practice);
    // get the question settings
    $qsettings = $this->assess_info->getQuestionSettings($qver['qid']);

    $partattemptn = array();
    for ($pn = 0; $pn < count($qver['tries']); $pn++) {
      // figure out try #
      $partattemptn[$pn] = count($qver['tries'][$pn]);
    }
    $attemptn = (count($partattemptn) == 0) ? 0 : min($partattemptn);

    $data = array();

    // TODO: move this to displayq input
    // TODO: pass stuanswers, stuanswersval

    $GLOBALS['qdatafordisplayq'] = $this->assess_info->getQuestionSetData($qsettings['questionsetid']);
    list($scores, $rawscores) = scoreq(
      $qn,                            // question number
      $qsettings['questionsetid'],    // questionset ID
      $qver['seed'],                  // seed
      $_POST['qn'.$qn],               // the default answerbox
      $attemptn,                      // the attempt number //TODO: make by-part
      $qsettings['points_possible']   // points possible for the question
    );


    // TODO need better way to get student's answer and unrand and such
    // TODO: rework this to handle singlescore questions
    $rawparts = explode('~', $rawscores);
    $scores = explode('~', $scores);
    foreach ($rawparts as $k=>$v) {
      if ($parts_to_score === true || $parts_to_score[$k] === true) {
        $data[$k] = array(
          'sub' => $submission,
          'raw' => $v,
          'time' => 0, // TODO
          'stuans' => $_POST['qn'.$qn]   // TODO: this is wrong for most types
        );
      }
    }
    $this->recordTry($qn, $data);
  }

  /**
   * Generate $stuanswers and $stuanswersval for the last tries
   * @param  boolean $is_practice Whether to pull from practice data
   * @param  string  $ver         Version to grab from, or 'last' for latest
   * @return array  ($stuanswers, $stuanswersval)
   */
  public function getStuanswers($is_practice=false, $ver = 'last') {
    $by_question = ($this->assess_info->getSetting('submitby') == 'by_question');
    // get data structure for this question
    $assessver = $this->getAssessVer($is_practice, $ver);
    $stuanswers = array();
    $stuanswerval = array();
    for ($qn = 0; $qn < count($assessver['questions']); $qn++) {
      $question_versions = $assessver['questions'][$qn]['question_versions'];
      if (!$by_question || $ver === 'last') {
        $curq = $question_versions[count($question_versions) - 1];
      } else {
        $curq = $question_versions[$ver];
      }
      $stuansparts = array();
      $stuansvalparts = array();
      for ($pn = 0; $pn < $curq['tries']; $pn++) {
        $lasttry = $curq['tries'][$pn][count($curq['tries'][$pn]) - 1];
        $stuansparts[$pn] = isset($lasttry['unrand']) ? $lasttry['unrand'] : $lasttry['stuans'];
        $stuansvalparts[$pn] = isset($lasttry['stuansval']) ? $lasttry['stuansval'] : null;
      }
      if (count($stuansparts) > 1) {
        $stuanswers[$qn] = $stuansparts;
        $stuanswersval[$qn] = $stuansvalparts;
      } else {
        $stuanswers[$qn] = $stuansparts[0];
        $stuanswersval[$qn] = $stuansvalparts[0];
      }
    }
    return array($stuanswers, $stuanswerval);
  }

  /**
   * Gets the question ID for the given question number
   * @param  int  $qn             Question Number
   * @param  boolean $is_practice Whether is from practice data
   * @param  string  $ver         version #, or 'last'
   * @return int  question ID
   */
  public function getQuestionId($qn, $is_practice = false, $ver = 'last') {
    $curq = $this->getQuestionVer($qn, $is_practice, $ver);
    return $curq['qid'];
  }

  /**
   * Gets the question IDs for the given question numbers
   * @param  array  $qns           Array of Question Numbers
   * @param  boolean $is_practice Whether is from practice data
   * @param  string  $ver         version #, or 'last'
   * @return array  question IDs, indexed by question number
   */
  public function getQuestionIds($qns, $is_practice = false, $ver = 'last') {
    $assessver = $this->getAssessVer($is_practice, $ver);
    $out = array();
    foreach ($qns as $qn) {
      $question_versions = $assessver['questions'][$qn]['question_versions'];
      if (!$by_question || $ver === 'last') {
        $curq = $question_versions[count($question_versions) - 1];
      } else {
        $curq = $question_versions[$ver];
      }
      $out[$qn] = $curq['qid'];
    }
    return $out;
  }

  /**
   * Recalculate the assessment total score, updating the record
   * @param  boolean $in_practice Whether to total practice data
   * @param  mixed  $rescoreQs   'all' to rescore all, or array of question numbers to re-score
   * @return float   The final assessment total
   */
  public function reTotalAssess($in_practice, $rescoreQs = 'all') {
    $by_question = ($this->assess_info->getSetting('submitby') == 'by_question');

    $points = $this->assess_info->getAllQuestionPoints();
    if ($is_practice) {
      $this->parsePractice();
      $data = &$this->practiceData;
    } else {
      $this->parseScored();
      $data = &$this->scoredData;
    }

    $maxAscore = 0;
    $aScoredVer = 0;
    // loop through all the assessment versions
    for ($av = 0; $av < count($data['assess_versions']); $av++) {
      $curAver = &$data['assess_versions'][$av];

      // loop through the question numbers
      $aVerScore = 0;
      for ($qn = 0; $qn < count($curAver['questions']); $qn++) {
        if ($rescoreQs !== 'all' && !in_array($qn, $rescoreQs)) {
          $aVerScore += $curAver['questions'][$qn]['score'];
          continue;
        }
        // loop through the question versions
        $maxQscore = 0;
        $maxQrawscore = 0;
        $qScoredVer = 0;
        for ($qv = 0; $qv < count($curAver['questions'][$qn]['question_versions']); $qv++) {
          $curQver = &$curAver['questions'][$qn]['question_versions'][$qv];
          if (isset($curQver['scoreoverride'])) {
            $qScore = $curQver['scoreoverride'];
          } else {
            list($qScore, $qRawscore, $parts) = $this->getQuestionPartScores($qn, $in_practice, max($av,$qv), 'all');
          }
          if ($qScore >= $maxQscore) {
            $maxQscore = $qScore;
            $maxQrawscore = $qRawscore;
            $qScoredVer = $qv;
          }

        } // end loop over question versions
        if ($by_question) {
          $curAver['questions'][$qn]['scoredversion'] = $qScoredVer;
        }
        $curAver['questions'][$qn]['score'] = $maxQscore;
        $curAver['questions'][$qn]['rawscore'] = $maxQrawscore;
        $aVerScore += $maxQscore;
      } // end loop over questions
      $curAver['score'] = $aVerScore;
      if ($aVerScore >= $maxAscore) {
        $maxAscore = $aVerScore;
        $aScoredVer = $av;
      }
    } // end loop over assessment versions
    if (!$by_question) {
      $data['scored_version'] = $aScoredVer;
    }
    if (!$in_practice) {
      $this->assessRecord['score'] = $maxAscore;
    }

    return $maxAscore;
  }

  /**
   * Find out if a submission is allowed per-part
   * @param  int  $qn             Question #
   * @param  int  $qid            Question ID
   * @param  boolean $is_practice Whether using practice data
   * @return array indexed by part number; true if submission allowed
   */
  public function isSubmissionAllowed($qn, $qid, $is_practice = false) {
    if ($is_practice) {
      $this->parsePractice();
      $data = $this->practiceData;
    } else {
      $this->parseScored();
      $data = $this->scoredData;
    }
    $by_question = ($this->assess_info->getSetting('submitby') == 'by_question');
    if ($by_question) {
      $qvers = $data['assess_versions'][0]['questions'][$qn];
      $tries = $qvers[count($qvers) - 1]['tries'];
    } else {
      $aver = $data['assess_versions'][count($data['assess_versions']) - 1];
      $tries = $aver['questions'][$qn][0]['tries'];
    }
    $tries_max = $this->assess_info->getQuestionSetting($qid, 'tries_max');
    $out = array();
    if (count($tries) === 0) {
      // if no tries yet, just return true to say can record all
      return true;
    }
    foreach ($tries as $pn=>$data) {
      $out[$pn] = (count($data) < $tries_max);
    }
    return $out;
  }

  /**
   * Calculate the score on a question after applying penalties
   * @param  float $score    Raw score, 0-1
   * @param  float $points   Points possible
   * @param  int $try        The try number
   * @param  int $regen      The regen number
   * @param  int $duedate    Original due date timestamp
   * @param  int $subtime    Timestamp question was submitted
   * @param boolean $returnPenalties  Set true to return array of penalties applied (def: false)
   * @return float  score after penalties if $returnPenalties = false
   *         array(score, array of penalties) if $returnPenalties = true
   */
  private function scoreAfterPenalty($score, $points, $try, $regen, $duedate, $subtime, $returnPenalties = false) {
    $base = $score * $points;
    $penalties = array();
    if ($this->penalties['retry_penalty'] > 0) {
      $triesOver = $try + 1 - (isset($this->penalties['retry_penalty_after']) ? $this->penalties['retry_penalty_after'] : 1);
      if ($triesOver > 0) {
        $base *= (1 - $triesOver * $this->penalties['retry_penalty']/100);
        $penalties[] = array('retry', $triesOver * $this->penalties['retry_penalty']);
      }
    }
    if ($this->penalties['regen_penalty'] > 0) {
      $regensOver = $regen + 1 - (isset($this->penalties['regen_penalty_after']) ? $this->penalties['regen_penalty_after'] : 1);
      if ($regensOver > 0) {
        $base *= (1 - $regenOver * $this->penalties['regen_penalty']/100);
        $penalties[] = array('regen', $regenOver * $this->penalties['regen_penalty']);
      }
    }
    if ($this->penalties['exceptionpenalty'] > 0 && $subtime > $duedate) {
      $base *= (1 - $this->penalties['exceptionpenalty'] / 100);
      $penalties[] = array('late', $this->penalties['exceptionpenalty']);
    }
    if ($returnPenalties) {
      return array($base, $penalties);
    } else {
      return $base;
    }
  }


  /**
   * Get the specified version of assessment data
   * @param  boolean $is_practice Whether to grab from practice data
   * @param  string  $ver         The assessment attempt to grab, or 'last'
   * @return object  assessment data for that take
   */
  private function getAssessVer($is_practice=false, $ver = 'last') {
    $by_question = ($this->assess_info->getSetting('submitby') == 'by_question');
    if ($is_practice) {
      $this->parsePractice();
      $assessver = $this->practiceData['assess_versions'][0];
    } else {
      $this->parseScored();
      if ($by_question || $ver === 'last') {
        $assessver = $this->scoredData['assess_versions'][count($this->scoredData['assess_versions']) - 1];
      } else {
        $assessver = $this->scoredData['assess_versions'][$ver];
      }
    }
    return $assessver;
  }

  /**
   * Returns the specified version of question attempt data
   * @param  int  $qn          The question number
   * @param  boolean $is_practice Whether to grab from practice data
   * @param  string  $ver         The assessment attempt to grab, or 'last'
   * @return object   question data for that version
   */
  private function getQuestionVer($qn, $is_practice=false, $ver = 'last') {
    $by_question = ($this->assess_info->getSetting('submitby') == 'by_question');
    if ($is_practice) {
      $this->parsePractice();
      $assessver = $this->practiceData['assess_versions'][0];
    } else {
      $this->parseScored();
      if ($by_question || $ver === 'last') {
        $assessver = $this->scoredData['assess_versions'][count($this->scoredData['assess_versions']) - 1];
      } else {
        $assessver = $this->scoredData['assess_versions'][$ver];
      }
    }
    $question_versions = $assessver['questions'][$qn]['question_versions'];
    if (!$by_question || $ver === 'last') {
      $curq = $question_versions[count($question_versions) - 1];
    } else {
      $curq = $question_versions[$ver];
    }
    return $curq;
  }

  /**
   * Get Autosave info for the given question
   * @param  int  $qn         The question number
   * @param  boolean $is_practice Whether we're looking at practice data
   * @return array of autosave data
   */
  private function getAutoSaves($qn, $is_practice=false) {
    if ($is_practice) {
      $this->parsePractice();
      if (isset($this->practiceData['autosaves'][$qn])) {
        return $this->practiceData['autosaves'][$qn];
      }
    } else {
      $this->parseScored();
      if (isset($this->scoredData['autosaves'][$qn])) {
        return $this->scoredData['autosaves'][$qn];
      }
    }
    return array();
  }

  /**
   * Record a try on a question
   * @param  int $qn      Question number
   * @param  array $data  Array of part datas
   * @param  mixed $ver   Version number to record this on, or 'last'
   * @return void
   */
  private function recordTry($qn, $data, $ver='last') {
    $by_question = ($this->assess_info->getSetting('submitby') == 'by_question');
    if ($is_practice) {
      $this->parsePractice();
      $assessver = &$this->practiceData['assess_versions'][0];
    } else {
      $this->parseScored();
      if ($by_question || $ver === 'last') {
        $assessver = &$this->scoredData['assess_versions'][count($this->scoredData['assess_versions']) - 1];
      } else {
        $assessver = &$this->scoredData['assess_versions'][$ver];
      }
    }
    $question_versions = &$assessver['questions'][$qn]['question_versions'];
    if (!$by_question || $ver === 'last') {
      $curq = &$question_versions[count($question_versions) - 1];
    } else {
      $curq = &$question_versions[$ver];
    }
    foreach ($data as $pn=>$partdata) {
      $curq['tries'][$pn][] = $partdata;
    }
  }

  /**
   * uncompress and decode scoredData
   * @return void
   */
  private function parseScored () {
    if ($this->scoredData === null) {
      $this->scoredData = json_decode(gzdecode($this->assessRecord['scoreddata']), true);
      if ($this->scoredData === null) {
        $this->scoredData = array();
      }
    }
  }

  /**
   * uncompress and decode practiceData
   * @return void
   */
  private function parsePractice () {
    if ($this->practiceData === null) {
      $this->practiceData = json_decode(gzdecode($this->assessRecord['practicedata']), true);
      if ($this->practiceData === null) {
        $this->practiceData = array();
      }
    }
  }

  /**
   * Calculate score on an assessment version
   * @param  int $vernum  The version number (0-index)
   * @param  array $verdata The attempt data for the version
   * @return float          The total score on the version, after assessment
   *                        settings are applied
   */
  private function calcAssessVersionScores($vernum, $verdata) {
    //TODO
    return 0;
  }

}
