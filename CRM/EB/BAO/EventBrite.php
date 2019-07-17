<?php

class CRM_EB_BAO_EventBrite extends CRM_Mailchimp_Sync {

  public static function getResponse($op, $params) {
    $client = new HttpClient('API TOKEN HERE');
    if ($op == "syncEvents") {
      $eventIds = [];
      // Get organizers for the current user.
      $organizations = $client->get("/users/me/organizations");
      foreach ($organizations['organizations'] as $org) {
        if ($org['_type'] == 'organization') {
          $orgs[] = $org['id'];
        }
      }
      foreach ($orgs as $org) {
        $events = $client->get("/organizations/$org/events");
        if (!empty($events['events'])) {
          foreach ($events['events'] as $event) {
            $eventIds[$event['id']] = $event['name']['text'];
          }
        }
        if ($events['pagination']['has_more_items']) {
          while(true) {
            $events = $client->get('/organizations/' . $org . '/events?continuation=' . $events['pagination']['continuation']);
            if (!empty($events['events'])) {
              foreach ($events['events'] as $event) {
                $eventIds[$event['id']] = $event['name']['text'];
              }
            }
            if (empty($events['pagination']['has_more_items'])) {
              break;
            }
          }
        }
      }
      return $eventIds;
    }
    if ($op == "syncContacts") {
      $ctx = $params['ctx'];
      $event = $params['event'];
      $attendees = $client->get('/events/' . $event . '/attendees');
      if ($attendees['pagination']['has_more_items'] = 1 && $attendees['pagination']['page_count'] > 1) {
        for ($i = 1; $i <= $attendees['pagination']['page_count']; $i++) {
          $multipleAttendees = $client->get('/events/' . $event . '/attendees?page=' . $i);
          $multipleAttendeeGroups[] = $multipleAttendees;
        }
        if (!empty($multipleAttendeeGroups)) {
          foreach ($multipleAttendeeGroups as $group) {
            foreach ($group['attendees'] as $attendee) {
              if (strpos($attendee['id'], '-') == false) {
                $created = date('Y-m-d', strtotime($attendee['created']));
                if ($created > "2019-04-01") {
                  $id = self::createAttendee($attendee['profile']);
                }
                //self::createAnswers($id, $attendee['answers']);
              }
            }
          }
        }
      }
      else {
        foreach ($attendees['attendees'] as $attendee) {
          if (strpos($attendee['id'], '-') == false) {
            $created = date('Y-m-d', strtotime($attendee['created']));
            if ($created > "2019-04-01") {
              $id = self::createAttendee($attendee['profile']);
            }
            //self::createAnswers($id, $attendee['answers']);
          }
        }
      }
    }
    if ($op == "syncLists") {
      // Get organizers for the current user.
      $organizations = $client->get("/users/me/organizations");
      foreach ($organizations['organizations'] as $org) {
        if ($org['_type'] == 'organization') {
          $orgs[] = $org['id'];
        }
      }
      foreach ($orgs as $org) {
        $lists = $client->get('/organizations/' . $org . '/contact_lists');
        foreach ($lists['contact_lists'] as $list) {
          $contactList[$list['id']] = ['name' => $list['name'], 'user_id' => $list['user_id']];
          $id = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_group WHERE title = %1", [1 => [$list['name'], 'String']]);
          civicrm_api3('Group', 'create', [
            'title' => $list['name'],
            'id' => $id,
            'source' => ts("EventBrite Contact List"),
            'is_active' => TRUE,
            'group_type' => "Mailing List",
            'visibility' => ["User and User Admin Only"],
          ]);
        }
      }
      return $contactList;
    }
    if ($op == "syncContactLists") {
      $ctx = $params['ctx'];
      $list = $params['list'];
      $group = $params['group'];
      $contacts = $client->get('/users/me/contact_lists/' . $list . '/contacts');
      if ($contacts['pagination']['has_more_items'] = 1 && $contacts['pagination']['page_count'] > 1) {
        for ($i = 1; $i <= $contacts['pagination']['page_count']; $i++) {
          $multipleContacts = $client->get('/users/me/contact_lists/' . $list . '/contacts?page=' . $i);
          $multipleContactGroups[] = $multipleContacts;
        }
        if (!empty($multipleContactGroups)) {
          foreach ($multipleContactGroups as $batch) {
            foreach ($batch['contacts'] as $contact) {
              self::createContact($contact, $group);
            }
          }
        }
      }
      else {
        foreach ($contacts['contacts'] as $contact) {
          self::createContact($contact, $group);
        }
      }
    }
  }

  public static function createAnswers($id, $fields) {
    $answerGroup = civicrm_api3('CustomGroup', 'getvalue', array(
      'name' => 'EventBrite_Registration_Information',
      'return' => 'id',
    ));

    foreach ($fields as $field) {
      // First check if custom field exists.
      $customField = civicrm_api3('CustomField', 'get', array(
        'name' => CRM_Utils_String::munge($field['question'], '_', 64),
        'return' => 'id',
      ));
      if (!$customField['id']) {
        $params = array(
          'custom_group_id' => $answerGroup,
          'label' => $field['question'],
          'html_type' => 'TextArea',
          'data_type' => 'Memo',
          'is_active' => 1,
        );
        $customField = civicrm_api3('CustomField', 'create', $params);
      }
      $customField = $customField['id'];
      if ($customField && !empty($field['answer'])) {
        // First check if custom value exists with same information. Then create new.
        $customValue = civicrm_api3('CustomValue', 'get', array(
          'custom_' . $customField => $field['answer'],
          'entity_id' => $id,
        ));
        if ($customValue['count'] == 0) {
          $valueParams['custom_' . $customField . '_-0'] = $field['answer'];
        }
      }
    }
    CRM_Core_BAO_CustomValueTable::postProcess($valueParams,
      'civicrm_contact',
      $id,
      'Individual'
    );
  }

  public static function createContact($contact, $group) {
    $params = [
      'contact_type' => 'Individual',
      'first_name' => $contact['first_name'],
      'last_time' => $contact['last_name'],
      'email' => $contact['email'],
    ];
    $params = array_filter($params);
    $dedupeParams = CRM_Dedupe_Finder::formatParams($params, 'Individual');
    $dedupeParams['check_permission'] = FALSE;
    $dupes = CRM_Dedupe_Finder::dupesByParams($dedupeParams, 'Individual');
    $cid = CRM_Utils_Array::value('0', $dupes, NULL);
    if ($cid) {
      $params['contact_id'] = $cid;
    }

    try {
      $contact = civicrm_api3('Contact', 'create', $params);

      // Add to group.
      $groupId = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_group WHERE title = %1", [1 => [$group, 'String']]);
      civicrm_api3('GroupContact', 'create', [
        'group_id' => $groupId,
        'contact_id' => $contact['id'],
        'status' => 'Added',
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      // Handle error here.
      $errorMessage = $e->getMessage();
      $errorCode = $e->getErrorCode();
      $errorData = $e->getExtraParams();
      $error = [
        'error_message' => $errorMessage,
        'error_code' => $errorCode,
        'error_data' => $errorData,
      ];
      CRM_Core_Error::debug_var('Error in processing information:', $error);
    }
    
    return $contact['id'];
  }

  public static function createAttendee($attendee) {
    $params = [
      'contact_type' => 'Individual',
      'first_name' => $attendee['first_name'],
      'last_name' => $attendee['last_name'],
      'source' => 'EventBrite Migration',
      'email' => $attendee['email'],
    ];
    $dedupeParams = CRM_Dedupe_Finder::formatParams($params, 'Individual');
    $dedupeParams['check_permission'] = FALSE;
    $dupes = CRM_Dedupe_Finder::dupesByParams($dedupeParams, 'Individual');
    $cid = CRM_Utils_Array::value('0', $dupes, NULL);
    if ($cid) {
      $params['contact_id'] = $cid;
    }
    try {
      $contact = civicrm_api3('Contact', 'create', $params);
      // Create April tag
      civicrm_api3('EntityTag', 'create', [
        'entity_id' => $contact['id'],
        'entity_table' => "civicrm_contact",
        'tag_id' => "April 1st 2019",
      ]);

      // Add phone if present.
      if (!empty($attendee['home_phone'])) {
        $phoneParams = [
          'location_type_id' => 'Home',
          'phone' => $attendee['home_phone'],
          'phone_type_id' => 'Phone',
          'contact_id' => $contact['id'],
        ];
        $phone = civicrm_api3('Phone', 'get', $phoneParams);
        if ($phone['count'] == 0) {
          civicrm_api3('Phone', 'create', $phoneParams);
        }
      }
      // Do address creation and phone creation here.
      if (!empty($attendee['addresses'])) {
        foreach ($attendee['addresses'] as $locationType => $address) {
          $addressParams = [
            'city' => $address['city'],
            'street_address' => $address['address_1'],
            'supplemental_address_1' => !empty($address['address_2']) ? $address['address_2'] : NULL,
            'contact_id' => $contact['id'],
          ];
          if ($locationType == 'bill') {
            $locationType = 'Billing';
          }
          elseif ($locationType == 'ship') {
            $locationType = 'Other';
          }
          $addressParams['location_type_id'] = ucfirst(strtolower($locationType));
          if (!empty($address['country'])) {
            $addressParams['country'] = CRM_Core_DAO::singleValueQuery("SELECT max(id) from civicrm_country where iso_code = '{$address['country']}'");
          }
          if (!empty($addressParams['country']) && !empty($address['region'])) {
            $state = CRM_Core_DAO::singleValueQuery("SELECT max(id) from civicrm_state_province where abbreviation = '{$address['region']}' AND country_id = {$addressParams['country']}");
          }
          if ($state) {
            $addressParams['state_province_id'] = $state;
          }
          $address = civicrm_api3('Address', 'get', $addressParams);
          if ($address['count'] == 0) {
            civicrm_api3('Address', 'create', $addressParams);
          }
        }
      }
    }
    catch (CiviCRM_API3_Exception $e) {
      // Handle error here.
      $errorMessage = $e->getMessage();
      $errorCode = $e->getErrorCode();
      $errorData = $e->getExtraParams();
      $error = [
        'error_message' => $errorMessage,
        'error_code' => $errorCode,
        'error_data' => $errorData,
      ];
      CRM_Core_Error::debug_var('Error in processing information:', $error);
    }

    return $contact['id'];
  }

  public static function syncEvents($ctx) {
    $response = self::getResponse('syncEvents', ['ctx' => $ctx]);
    return $response;
  }

  public static function syncLists($ctx) {
    $response = self::getResponse('syncLists', ['ctx' => $ctx]);
    return $response;
  }

  public static function syncContactLists($ctx, $list, $group) {
    $response = self::getResponse('syncContactLists', ['ctx' => $ctx, 'list' => $list, 'group' => $group]);
    return $response;
  }

  public static function syncContacts($ctx, $event) {
    $response = self::getResponse('syncContacts', ['ctx' => $ctx, 'event' => $event]);
    return $response;
  }

}
