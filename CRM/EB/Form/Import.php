<?php
/**
 * @file
 * This imports events into CiviCRM from EventBrite.
 */

class CRM_EB_Form_Import extends CRM_Core_Form {

  const QUEUE_NAME = 'eb-pull';
  const END_URL    = 'civicrm/eventbrite/import';
  const END_PARAMS = 'state=done';
  /**
   * Function to pre processing
   *
   * @return None
   * @access public
   */
  function preProcess() {
    parent::preProcess();
  }

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {
    $fields = [
      'contacts' => ts('EventBrite Attendees as Contacts'),
    ];
    foreach ($fields as $field => $title) {
      $this->addElement('checkbox', $field, $title);
    }
    $this->assign('importFields', array_keys($fields));
    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Import'),
        'isDefault' => TRUE,
      ),
      array(
        'type' => 'cancel',
        'name' => ts('Cancel'),
      ),
    ));
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    $submitValues = $this->_submitValues;
    $runner = self::getRunner($submitValues);
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      CRM_Core_Session::setStatus(ts('Nothing to import. Make sure you have selected required option to pull from EventBrite.'));
    }
  }

  /**
   * Set up the queue.
   */
  public static function getRunner($submitValues) {
    $syncProcess = array(
      'contacts' => 'syncEventBriteContacts',
    );
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));
    foreach ($syncProcess as $key => $value) {
      if (!empty($submitValues[$key])) {
        $task  = new CRM_Queue_Task(
          ['CRM_EB_Form_Import', $value],
          [$key],
          "Import {$key} from EventBrite."
        );
        $queue->createItem($task);
      }
    }
    // Setup the Runner
    $runnerParams = array(
      'title' => ts('EventBrite Pull Sync: update CiviCRM Contacts from EventBrite'),
      'queue' => $queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE),
    );

    $runner = new CRM_Queue_Runner($runnerParams);
    return $runner;
  }

  public static function syncEventBriteContacts(CRM_Queue_TaskContext $ctx) {
    $eventIds = CRM_EB_BAO_EventBrite::syncEvents($ctx);
    foreach ($eventIds as $eventId => $eventName) {
      $ctx->queue->createItem( new CRM_Queue_Task(
        array('CRM_EB_Form_Import', 'createUpdateContacts'),
        [$eventId],
        "Adding contacts from EventBrite - $eventName to CiviCRM... "
      ));
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  public static function createUpdateContacts(CRM_Queue_TaskContext $ctx, $eventId) {
    CRM_EB_BAO_EventBrite::syncContacts($ctx, $eventId);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

}
